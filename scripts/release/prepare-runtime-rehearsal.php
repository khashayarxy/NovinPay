<?php

declare(strict_types=1);

require_once __DIR__.'/runtime-release.php';

use function NovinPay\Release\command;
use function NovinPay\Release\expectedTriggerContracts;
use function NovinPay\Release\migrationInventory;
use function NovinPay\Release\requireGate;
use function NovinPay\Release\runtimeGit;
use function NovinPay\Release\runtimeInventory;
use function NovinPay\Release\runtimePackages;
use function NovinPay\Release\writeNew;

// Existing CI job only: synthetic manifests never authorize production execution.
requireGate(getenv('CI') === 'true' && getenv('RUNNER_TEMP') !== false, 'rehearsal_ci_only', 'existing isolated CI runner', [], 'Do not run this preparation against hosting.');
$repo = str_replace('\\', '/', getcwd());
$root = getenv('RUNNER_TEMP').'/NovinPay-SecureAccess/releases/runtime-'.bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$package = $root.'/package';
mkdir($package, 0700);
$base = 'ecf672110214a24a9171d942a2730d9bfad5f65c';
$target = trim(runtimeGit($repo, ['rev-parse', 'HEAD']));
foreach (['baseline' => $base, 'candidate' => $target] as $name => $sha) {
    $build = $root.'/'.$name;
    mkdir($build, 0700);
    runtimeGit($repo, ['archive', '--format=zip', '--output='.$root.'/'.$name.'-source.zip', $sha]);
    $sourceZip = new ZipArchive;
    requireGate($sourceZip->open($root.'/'.$name.'-source.zip') === true, 'rehearsal_source_zip', 'real Git archive', [], 'Inspect source preparation.');
    $sourceZip->extractTo($build);
    $sourceZip->close();
    // The prepared archive was built on Windows. Composer's documented full
    // bin compatibility reproduces its .bat proxies on this Linux rehearsal.
    // This affects only the disposable build, never repository/server metadata.
    $composer = json_decode(file_get_contents($build.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $composer['config']['bin-compat'] = 'full';
    file_put_contents($build.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    // Lock only; no scripts/plugins, no application boot and no database creation.
    $r = command(['composer', 'install', '--working-dir='.$build, '--no-dev', '--no-scripts', '--no-plugins', '--prefer-dist', '--optimize-autoloader', '--no-interaction', '--no-progress'], $repo, 'rehearsal_locked_vendor', 180);
    requireGate($r['exit'] === 0, 'rehearsal_locked_vendor', 'locked production dependencies built on CI', [], 'Inspect Composer output privately.');
}
$archives = [];
$application = $root.'/application-assets';
mkdir($application, 0700);
// The approved Vite archive was built at c9b2220; 9f2e6a4 subsequently
// refreshed ONLY the support builder/cache policy, not Vite CSS/JS.
runtimeGit($repo, ['archive', '--format=zip', '--output='.$root.'/application-assets.zip', 'c9b222013d216f0bba310a9c2f2e882f7b08a903']);
$applicationZip = new ZipArchive;
requireGate($applicationZip->open($root.'/application-assets.zip') === true, 'rehearsal_application_archive', 'approved application payload', [], 'Preserve the application/qualification SHA distinction.');
$applicationZip->extractTo($application);
$applicationZip->close();
file_put_contents($application.'/scripts/build-support-assets.mjs', runtimeGit($repo, ['show', '9f2e6a48481c8527d538de9e4ba24a2ca211ee8f:scripts/build-support-assets.mjs']));
// These 48 existing Windows working-tree files had CRLF in the verified
// artifact. Reproduce those bytes in this disposable build only. Source Git
// blobs stay LF; no files or assertions are omitted from the exact-byte gate.
foreach (json_decode(file_get_contents($repo.'/tests/Fixtures/runtime-release-support-eol.json'), true, 16, JSON_THROW_ON_ERROR) as $path => $runs) {
    NovinPay\Release\runtimePath($path);
    $file = $application.'/resources/support-chat/'.$path;
    $bytes = file_get_contents($file);
    requireGate(! str_contains($bytes, "\r"), 'rehearsal_eol_source', 'canonical LF source before explicit historical build transformation', [], 'Inspect changed source bytes.');
    $lines = explode("\n", $bytes);
    foreach ($runs as [$first, $last]) {
        for ($line = $first; $line <= $last; $line++) {
            requireGate(isset($lines[$line]), 'rehearsal_eol_range', 'historical newline position exists', [], 'Inspect changed source.');
            $lines[$line] .= "\r";
        }
    }
    file_put_contents($file, implode("\n", $lines));
}
requireGate(symlink($repo.'/node_modules', $application.'/node_modules') && symlink($root.'/candidate/vendor', $application.'/vendor'),
    'rehearsal_build_links', 'existing locked build dependencies in the isolated approved application tree', [], 'Inspect local CI capabilities.');
$buildAssets = command(['npm', 'run', 'build'], $application, 'rehearsal_approved_assets', 180);
requireGate($buildAssets['exit'] === 0, 'rehearsal_approved_assets', 'compile approved application without later tooling entering Tailwind scanning', [], 'Inspect build reproducibility.');
runtimeGit($repo, ['archive', '--format=zip', '--output='.$package.'/source.zip', $target]);
$z = new ZipArchive;
$z->open($package.'/source.zip');
$source = [];
for ($i = 0; $i < $z->numFiles; $i++) {
    if (! str_ends_with($z->getNameIndex($i), '/')) {
        $source[$z->getNameIndex($i)] = hash('sha256', $z->getFromIndex($i));
    }
}
$z->close();
$archives['source'] = ['file' => 'source.zip', 'sha256' => hash_file('sha256', $package.'/source.zip'), 'files' => $source];
foreach (['vendor' => [$root.'/candidate/vendor', 'vendor/'], 'assets' => [$application.'/public/build', '']] as $kind => [$dir, $prefix]) {
    $z = new ZipArchive;
    $z->open($package.'/'.$kind.'.zip', ZipArchive::CREATE | ZipArchive::EXCL);
    $files = [];
    foreach (runtimeInventory($dir) as $path => $hash) {
        $z->addFile($dir.'/'.$path, $prefix.$path);
        $files[$prefix.$path] = $hash;
    }
    $z->close();
    $archives[$kind] = ['file' => $kind.'.zip', 'sha256' => hash_file('sha256', $package.'/'.$kind.'.zip'), 'files' => $files];
}
// Public digests only, not a private deployment manifest. Rehearsal must use the
// already prepared Phase 4 vendor/assets BYTES, not merely equivalent versions.
foreach (['vendor' => '99c37e170854a4cf4c87ab6caea86e7043db8014606c2573af198d8bfea5b8a7',
    'assets' => 'edce0360dcb5e9d02eb04b137f723a3117aa0ce31af8a53c4f11272f0e92c9e7'] as $kind => $expected) {
    $files = $archives[$kind]['files'];
    ksort($files);
    $observed = hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if (! hash_equals($expected, $observed)) {
        $generated = $kind === 'assets' ? $files : array_filter($files, fn ($p) => str_starts_with($p, 'vendor/composer/') || str_starts_with($p, 'vendor/bin/'), ARRAY_FILTER_USE_KEY);
        echo json_encode(['result' => 'REHEARSAL_PAYLOAD_MISMATCH', 'kind' => $kind, 'files' => count($files), 'generatedFileHashes' => $generated], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        if ($kind === 'assets') {
            foreach (glob($application.'/public/build/assets/app-*.css') as $css) {
                echo json_encode(['result' => 'REHEARSAL_PUBLIC_CSS_DIAGNOSTIC', 'file' => basename($css), 'gzipBase64' => base64_encode(gzencode(file_get_contents($css), 9))], JSON_THROW_ON_ERROR).PHP_EOL;
            }
        }
    }
    requireGate(hash_equals($expected, $observed), 'rehearsal_exact_'.$kind.'_bytes', 'same file inventory as approved private Phase 4 artifact', ['observedInventorySha256' => $observed], 'Investigate build reproducibility; do not qualify a different payload.');
}
$migrations = migrationInventory($repo.'/database/migrations');
$triggers = expectedTriggerContracts($migrations);
$m = ['format' => 'novinpay-runtime-release-v1', 'baseline' => $base, 'target' => $target,
    'applicationCommit' => '9f2e6a48481c8527d538de9e4ba24a2ca211ee8f', 'sourceBranch' => 'synthetic', 'targetBranch' => 'codex/confirmed-audit-remediation',
    'releaseName' => 'runtime-ci', 'stateDirectory' => '/home/novinpay/novinpay-direct-pr13', 'publicLink' => '/home/novinpay/novinpay/public',
    'backup' => ['directory' => '/home/novinpay/novinpay-private-backups/recovery-synthetic-ci', 'restoreDatabase' => 'novinpay_restoresynthetic', 'serverKeySha256' => str_repeat('0', 64)],
    'credentialsSha256' => str_repeat('0', 64), 'maintenanceHashes' => ['state.json' => str_repeat('0', 64), 'audit.jsonl' => str_repeat('0', 64)], 'retainedFiles' => [],
    'migrations' => $migrations, 'expectedTriggers' => 13, 'triggers' => $triggers, 'migrationsApplied' => 0,
    'archives' => $archives, 'lockSha256' => hash_file('sha256', $repo.'/composer.lock'),
    'productionPackages' => runtimePackages(file_get_contents($repo.'/composer.lock'), file_get_contents($root.'/candidate/vendor/composer/installed.json')),
    'dependencyChanges' => ['laravel/framework' => ['13.35.0', '13.40.0']],
    'baselineFiles' => array_fill_keys(['public/build/manifest.json', 'public/build/support-chat/manifest.json', 'bootstrap/cache/routes-v7.php'], str_repeat('0', 64)),
    'protectedFiles' => array_fill_keys(['.env', 'public/.htaccess', 'bootstrap/cache/config.php', 'storage/app/private/support-chat/installation.env'], str_repeat('0', 64)),
    'httpChecks' => ['/' => ['status' => 200], '/login' => ['status' => 200]], 'activationReady' => false, 'scope' => 'SYNTHETIC_CI_ONLY'];
writeNew($package.'/runtime-ci.json', $m);
foreach (['success' => false, 'rollback' => true] as $name => $force) {
    $config = ['repository' => $repo, 'manifest' => $package.'/runtime-ci.json', 'package' => $package, 'baselineVendor' => $root.'/baseline/vendor', 'workspace' => $root.'/'.$name, 'forceFailure' => $force];
    writeNew($root.'/'.$name.'-config.json', $config);
    $result = command([PHP_BINARY, __DIR__.'/rehearse-runtime.php', $root.'/'.$name.'-config.json'], $repo, 'rehearsal_'.$name, 300);
    echo $result['stdout'];
    requireGate($result['exit'] === 0, 'rehearsal_'.$name, 'real release rehearsal passes', [], 'Preserve runner evidence and inspect the named failure.');
}
