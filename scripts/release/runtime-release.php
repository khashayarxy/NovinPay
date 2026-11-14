<?php

declare(strict_types=1);

namespace NovinPay\Release;

require_once __DIR__.'/recovery-contract.php';

/** Explicit schema-neutral extension; neither legacy contract accepts this format. */
function runtimeReleaseContract(array $m): void
{
    requireGate(($m['format'] ?? '') === 'novinpay-runtime-release-v1', 'runtime_format', 'versioned runtime contract', [], 'Use the reviewed schema-neutral contract.');
    $recovery = $m;
    $recovery['format'] = 'novinpay-recovery-v1';
    recoveryManifest($recovery);
    requireGate(preg_match('/\A[a-z0-9-]{1,64}\z/D', $m['releaseName'] ?? '') === 1
        && preg_match('~\Acodex/[a-z0-9/-]+\z~D', $m['targetBranch'] ?? '') === 1,
        'runtime_identity', 'safe release and branch names', [], 'Reject an unbound release.');
    requireGate(count($m['migrations']) === 24 && $m['expectedTriggers'] === 13
        && ($m['migrationsApplied'] ?? null) === 0 && ($m['applicationCommit'] ?? '') === '9f2e6a48481c8527d538de9e4ba24a2ca211ee8f'
        && ($m['baseline'] ?? '') === 'ecf672110214a24a9171d942a2730d9bfad5f65c',
        'runtime_schema_neutral', 'approved baseline, application and unchanged 24/13 schema', [], 'Review a different release separately.');
    foreach (['source', 'vendor', 'assets'] as $kind) {
        $a = $m['archives'][$kind] ?? [];
        requireGate(preg_match('/\A[a-z0-9-]+\.zip\z/D', $a['file'] ?? '') === 1
            && preg_match('/\A[a-f0-9]{64}\z/D', $a['sha256'] ?? '') === 1
            && is_array($a['files'] ?? null) && count($a['files']) > 0,
            'runtime_archive_contract', 'pinned nonempty file inventory', [], 'Rebuild the private manifest.');
        foreach ($a['files'] as $path => $hash) {
            runtimePath($path);
            requireGate(preg_match('/\A[a-f0-9]{64}\z/D', $hash) === 1, 'runtime_file_pin', 'SHA-256 file pin', [], 'Reject incomplete inventory.');
            requireGate($kind !== 'vendor' || str_starts_with($path, 'vendor/'), 'runtime_vendor_prefix', 'vendor-only archive', [], 'Reject unexpected archive destinations.');
            requireGate($kind !== 'assets' || $path === 'manifest.json' || str_starts_with($path, 'assets/') || str_starts_with($path, 'support-chat/'), 'runtime_asset_prefix', 'build assets only', [], 'Reject unexpected public paths.');
        }
    }
    requireGate(count($m['productionPackages'] ?? []) === 76
        && ($m['dependencyChanges'] ?? []) === ['laravel/framework' => ['13.35.0', '13.40.0']],
        'runtime_dependency_scope', 'only approved framework update; 76 production packages', [], 'Review dependency changes.');
    foreach (['baselineFiles', 'protectedFiles'] as $key) {
        requireGate(is_array($m[$key] ?? null) && count($m[$key]) >= 3, 'runtime_protected_inventory', 'explicit protected baseline pins', [], 'Reconcile protected files.');
        foreach ($m[$key] as $path => $hash) {
            runtimePath($path);
            requireGate(preg_match('/\A[a-f0-9]{64}\z/D', $hash) === 1, 'runtime_protected_hash', 'protected hash', [], 'Reconcile protected files.');
        }
    }
    foreach (['.env', 'public/.htaccess', 'bootstrap/cache/config.php', 'storage/app/private/support-chat/installation.env', 'storage/framework/site-maintenance/state.json', 'storage/framework/site-maintenance/audit.jsonl'] as $path) {
        requireGate(isset($m['protectedFiles'][$path]), 'runtime_protected_required', 'environment, handler, cached configuration and support identity pinned', [], 'Reconcile the protected inventory.');
    }
    foreach (['state.json', 'audit.jsonl'] as $file) {
        requireGate($m['protectedFiles']['storage/framework/site-maintenance/'.$file] === $m['maintenanceHashes'][$file], 'runtime_maintenance_pin', 'same exact maintenance evidence', [], 'Reconcile state and preserved audit history.');
    }
    requireGate(($m['publicLink'] ?? null) === '/home/novinpay/novinpay/public', 'runtime_public_link', 'unchanged hosting link target', [], 'Inspect hosting identity.');
    $triggers = $m['triggers'];
    ksort($triggers);
    requireGate($triggers === expectedTriggerContracts($m['migrations']), 'runtime_trigger_contract', 'reviewed unchanged trigger bodies', [], 'Reject trigger contract drift.');
    requireGate(is_array($m['httpChecks'] ?? null) && isset($m['httpChecks']['/'], $m['httpChecks']['/login']), 'runtime_http_contract', 'explicit public HTTPS acceptance', [], 'Prepare read-only smoke checks.');
}

function runtimePath(string $path): void
{
    requireGate(preg_match('~\A[A-Za-z0-9_.@+/-]+\z~D', $path) === 1 && ! str_starts_with($path, '/')
        && ! preg_match('~(?:\A|/)(?:\.{1,2}|\.git)(?:/|\z)|//~', $path),
        'runtime_unsafe_path', 'normalized relative regular-file path', [], 'Preserve and reject the unsafe artifact.');
}

/** Parse all entries before extraction; exact inventory also excludes extra secrets. */
function runtimeExtract(string $archive, array $contract, string $destination): void
{
    requireGate(is_file($archive) && ! is_link($archive) && hash_equals($contract['sha256'], hash_file('sha256', $archive)),
        'runtime_archive_hash', 'exact artifact hash', [], 'Do not retry a modified package.');
    $zip = new \ZipArchive;
    requireGate($zip->open($archive) === true, 'runtime_archive_open', 'readable ZIP', [], 'Preserve damaged artifacts.');
    try {
        $seen = [];
        $directories = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $s = $zip->statIndex($i);
            $path = $s['name'];
            $zip->getExternalAttributesIndex($i, $os, $attributes);
            $type = ($attributes >> 16) & 0170000;
            if (str_ends_with($path, '/')) {
                runtimePath(substr($path, 0, -1));
                requireGate(in_array($type, [0, 0040000], true) && $s['size'] === 0 && ! isset($directories[strtolower($path)])
                    && count(array_filter(array_keys($contract['files']), fn ($f) => str_starts_with($f, $path))) > 0,
                    'runtime_archive_directory', 'unique empty ancestor of inventoried files only', [], 'Reject unsafe or unrelated archive directories.');
                $directories[strtolower($path)] = true;

                continue;
            }
            runtimePath($path);
            requireGate(in_array($type, [0, 0100000], true) && ! isset($seen[strtolower($path)])
                && isset($contract['files'][$path]) && $s['size'] <= 33554432,
                'runtime_archive_entry', 'unique inventoried regular file', [], 'Reject links, directories, unexpected or oversized entries.');
            $total += $s['size'];
            requireGate($total <= 536870912, 'runtime_archive_size', 'bounded extracted size', [], 'Review package size.');
            $seen[strtolower($path)] = true;
            requireGate(hash_equals($contract['files'][$path], hash('sha256', $zip->getFromIndex($i))),
                'runtime_archive_file_hash', 'exact member bytes', [], 'Reject changed package members.');
        }
        requireGate(count($seen) === count($contract['files']), 'runtime_archive_missing', 'all inventory members present', [], 'Reject incomplete package.');
        requireGate(! file_exists($destination), 'runtime_stage_exists', 'new private stage', [], 'Preserve previous stages.');
        mkdir($destination, 0700, true);
        foreach ($contract['files'] as $path => $hash) {
            runtimeWrite($destination.'/'.$path, $zip->getFromName($path));
        }
    } finally {
        $zip->close();
    }
    runtimeVerify($destination, $contract['files'], true);
}

/** Never follow a symlink, including an ancestor of a destination. */
function runtimeNoLinks(string $path): void
{
    for ($p = $path; dirname($p) !== $p; $p = dirname($p)) {
        requireGate(! is_link($p), 'runtime_symlink', 'no link in managed path', [], 'Inspect the destination.');
    }
}

function runtimeWrite(string $path, string $bytes, int $mode = 0644): void
{
    runtimeNoLinks($path);
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    $tmp = dirname($path).'/.np-'.bin2hex(random_bytes(12));
    $h = fopen($tmp, 'xb');
    try {
        requireGate(is_resource($h) && fwrite($h, $bytes) === strlen($bytes) && fflush($h) && fsync($h),
            'runtime_write', 'durable complete file', [], 'Keep maintenance and inspect the journal.');
    } finally {
        if (is_resource($h)) {
            fclose($h);
        }
    }
    chmod($tmp, $mode);
    requireGate(rename($tmp, $path), 'runtime_publish', 'atomic same-directory publication', [], 'Keep maintenance and inspect publication.');
}

function runtimeInventory(string $root): array
{
    runtimeNoLinks($root);
    requireGate(is_dir($root), 'runtime_tree_missing', 'existing tree', [], 'Reconcile the filesystem.');
    $out = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        requireGate(! $file->isLink() && $file->isFile(), 'runtime_tree_type', 'regular files only', [], 'Inspect unexpected filesystem objects.');
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        runtimePath($path);
        $out[$path] = hash_file('sha256', $file->getPathname());
    }
    ksort($out);

    return $out;
}

function runtimeVerify(string $root, array $files, bool $exact = false): void
{
    foreach ($files as $path => $hash) {
        runtimePath($path);
        runtimeNoLinks($root.'/'.$path);
        requireGate(is_file($root.'/'.$path) && hash_equals($hash, hash_file('sha256', $root.'/'.$path)),
            'runtime_installed_hash', 'all installed bytes match', [], 'Keep traffic closed until matched source and dependencies are established.');
    }
    if ($exact) {
        $actual = runtimeInventory($root);
        ksort($files);
        requireGate($actual === $files, 'runtime_installed_inventory', 'no extra files', [], 'Inspect unexpected installed files.');
    }
}

function runtimePackages(string $lockBytes, string $installedBytes): array
{
    $lock = json_decode($lockBytes, true, 512, JSON_THROW_ON_ERROR);
    $installed = json_decode($installedBytes, true, 512, JSON_THROW_ON_ERROR);
    requireGate(($installed['dev'] ?? null) === false && ($installed['dev-package-names'] ?? []) === [],
        'runtime_vendor_dev', 'production-only Composer artifact', [], 'Build locked production dependencies offline.');
    $map = static function (array $rows): array {
        $out = [];
        foreach ($rows as $p) {
            requireGate(! isset($out[$p['name']]), 'runtime_package_duplicate', 'unique package', [], 'Reject ambiguous dependencies.');
            $out[$p['name']] = ['version' => $p['version'], 'source' => $p['source']['reference'] ?? null, 'dist' => $p['dist']['reference'] ?? null];
        }
        ksort($out);

        return $out;
    };
    $expected = $map($lock['packages']);
    requireGate($expected === $map($installed['packages']), 'runtime_vendor_lock', 'exact names, versions and source/dist references', [], 'Reject mismatched vendor.');

    return $expected;
}

/** Validate every graph edge, not just entry-point CSS. Also covers dynamic chunks. */
function runtimeAssets(string $stage, array $files): void
{
    $manifest = json_decode(file_get_contents($stage.'/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    requireGate(is_array($manifest) && count($manifest) > 0, 'runtime_asset_manifest', 'nonempty Vite graph', [], 'Reject missing Vite manifest.');
    foreach ($manifest as $node) {
        foreach (array_merge([$node['file'] ?? ''], $node['css'] ?? [], $node['assets'] ?? []) as $path) {
            runtimePath($path);
            requireGate(isset($files[$path]), 'runtime_asset_dependency', 'every referenced asset packaged', [], 'Include missing chunks, CSS and fonts.');
        }
        foreach (array_merge($node['imports'] ?? [], $node['dynamicImports'] ?? []) as $key) {
            requireGate(isset($manifest[$key]), 'runtime_asset_import', 'all graph nodes packaged', [], 'Include dynamic imports.');
        }
    }
    foreach (['assets/.htaccess', 'support-chat/.htaccess', 'support-chat/manifest.json'] as $path) {
        requireGate(isset($files[$path]), 'runtime_asset_cache_policy', 'scoped cache policies and support manifest', [], 'Include reviewed cache files.');
    }
    $support = json_decode(file_get_contents($stage.'/support-chat/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    requireGate(preg_match('/\A[a-f0-9]{24}\z/D', $support['version'] ?? '') === 1, 'runtime_support_manifest', 'versioned support assets', [], 'Reject incomplete support assets.');
    foreach ($support['files'] ?? [] as $path) {
        runtimePath($path);
        requireGate(isset($files['support-chat/'.$support['version'].'/'.$path]), 'runtime_support_dependency', 'every support manifest member packaged', [], 'Include missing customer and operator assets.');
    }
    // The support builder's manifest structure is versioned independently; every
    // shipped support member is still checked byte-for-byte by the archive pin.
    $policy = file_get_contents($stage.'/support-chat/.htaccess');
    requireGate(str_contains($policy, 'manifest.json') && str_contains($policy, 'must-revalidate'),
        'runtime_manifest_freshness', 'unhashed manifest revalidation', [], 'Preserve cache correctness.');
}

function runtimeGit(string $app, array $args): string
{
    $result = command(array_merge(['git'], $args), $app, 'runtime_git');
    requireGate($result['exit'] === 0, 'runtime_git', 'Git operation succeeds', [], 'Inspect the journal and Git state privately.');

    return $result['stdout'];
}

/** Bind archives to real Git objects, including unchanged migration bytes. */
function runtimeSourceContract(string $app, array $m, string $source, string $vendor): void
{
    requireGate(trim(runtimeGit($app, ['rev-parse', 'HEAD'])) === $m['baseline'], 'runtime_baseline', 'exact active baseline', [], 'Reconcile production, never replay.');
    runtimeGit($app, ['merge-base', '--is-ancestor', $m['baseline'], $m['target']]);
    runtimeGit($app, ['merge-base', '--is-ancestor', $m['applicationCommit'], $m['target']]);
    $delta = trim(runtimeGit($app, ['diff', '--name-only', $m['applicationCommit'], $m['target']]));
    foreach (preg_split('/\r?\n/', $delta, -1, PREG_SPLIT_NO_EMPTY) as $path) {
        requireGate(preg_match('~\A(?:scripts/release/|tests/|docs/)~', $path) === 1 || $path === '.github/workflows/ci.yml',
            'runtime_application_payload_changed', 'tooling, tests and documentation only after approved application SHA', [], 'Review application changes separately.');
    }
    requireGate(trim(runtimeGit($app, ['diff', '--name-only', $m['baseline'], $m['target'], '--', 'database/migrations', 'composer.json', 'package.json', 'package-lock.json', 'public/.htaccess'])) === '',
        'runtime_schema_diff', 'unchanged migrations, root handler and dependency metadata', [], 'Reject schema or unrelated dependency changes.');
    $tree = preg_split('/\r?\n/', trim(runtimeGit($app, ['ls-tree', '-r', $m['target']])), -1, PREG_SPLIT_NO_EMPTY);
    $objects = [];
    foreach ($tree as $line) {
        requireGate(preg_match('/\A(100644|100755) blob ([a-f0-9]{40})\t(.+)\z/D', $line, $match) === 1, 'runtime_git_file_type', 'regular Git blobs only', [], 'Reject links and submodules.');
        $objects[$match[3]] = $match[2];
    }
    $paths = array_keys($objects);
    $expected = array_keys($m['archives']['source']['files']);
    $excluded = array_diff($paths, $expected);
    sort($excluded);
    requireGate(array_values($excluded) === ['.github/workflows/ci.yml'] && array_diff($expected, $paths) === [],
        'runtime_source_members', 'entire Git archive except established CI export-ignore', [], 'Rebind source artifact to the exact commit.');
    foreach ($expected as $path) {
        requireGate(! preg_match('~\A(?:vendor/|public/build/|\.env\z)~', $path)
            && (! preg_match('~\A(?:storage/|bootstrap/cache/)~', $path) || basename($path) === '.gitignore'),
            'runtime_source_secret', 'no private or generated runtime data', [], 'Reject unsafe source archive.');
        $bytes = file_get_contents($source.'/'.$path);
        requireGate(hash_equals($objects[$path], sha1('blob '.strlen($bytes)."\0".$bytes)),
            'runtime_source_blob', 'archive equals target Git blobs', [], 'Rebind package bytes.');
    }
    $names = array_map(fn ($p) => basename($p, '.php'), array_filter($paths, fn ($p) => str_starts_with($p, 'database/migrations/')));
    sort($names);
    requireGate($names === $m['migrations'], 'runtime_migration_names', '24 exact unchanged names', [], 'Reject a schema-bearing release.');
    requireGate(hash_equals($m['lockSha256'], hash_file('sha256', $source.'/composer.lock')), 'runtime_lock_hash', 'pinned lockfile', [], 'Reject changed dependencies.');
    $packages = runtimePackages(file_get_contents($source.'/composer.lock'), file_get_contents($vendor.'/vendor/composer/installed.json'));
    requireGate($packages === $m['productionPackages'], 'runtime_package_inventory', 'all production packages pinned', [], 'Rebind the package inventory.');
    $before = json_decode(runtimeGit($app, ['show', $m['baseline'].':composer.lock']), true, 512, JSON_THROW_ON_ERROR);
    $after = json_decode(file_get_contents($source.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $a = array_column($before['packages'], null, 'name');
    $b = array_column($after['packages'], null, 'name');
    $changed = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $name) {
        if (($a[$name] ?? null) !== ($b[$name] ?? null)) {
            $changed[$name] = [ltrim($a[$name]['version'] ?? '', 'v'), ltrim($b[$name]['version'] ?? '', 'v')];
        }
    }
    requireGate($changed === $m['dependencyChanges'] && ($before['packages-dev'] ?? []) === ($after['packages-dev'] ?? []),
        'runtime_lock_delta', 'framework-only lock delta; development dependencies unchanged', [], 'Review unexpected lock changes.');
}

function runtimeInodes(array $proof, int $required): void
{
    requireGate(($proof['underInodeQuota'] ?? null) === true && is_int($proof['inodeLimit'] ?? null)
        && is_int($proof['inodesRemaining'] ?? null) && $proof['inodeLimit'] >= 0 && $proof['inodesRemaining'] >= 0
        && (($proof['inodeLimit'] === 0 && ($proof['inodeUnlimited'] ?? null) === true)
            || ($proof['inodeLimit'] > 0 && $proof['inodesRemaining'] >= $required + 4096)),
        'runtime_inode_headroom', 'authenticated explicit inode quota with reserve', [], 'Obtain current cPanel inode evidence; zero remaining alone is not proof of unlimited quota.');
}

/** Same transaction is used by the fixed hosting entry point and isolated rehearsal.
 * Hooks supply environment evidence, not an alternate publication implementation.
 * They must throw on uncertainty. There is deliberately no resume/replay option.
 */
function runtimeActivate(string $app, string $kit, string $publicLink, array $m, array $hooks): array
{
    runtimeReleaseContract($m);
    foreach (['preflight', 'quota', 'down', 'cache', 'web', 'ready', 'up', 'smoke', 'baselineReady'] as $name) {
        requireGate(isset($hooks[$name]) && is_callable($hooks[$name]), 'runtime_hook_missing', 'complete environment adapter', [], 'Do not run a partially implemented activation.');
    }
    runtimeNoLinks($kit);
    $lockPath = $kit.'/.operation.lock';
    runtimeNoLinks($lockPath);
    $lock = fopen($lockPath, 'c+b');
    if (PHP_OS_FAMILY !== 'Windows') {
        requireGate(is_resource($lock) && fileowner($lockPath) === posix_geteuid() && (fileperms($lockPath) & 0777) === 0600, 'runtime_lock_identity', 'same-UID private operation lock', [], 'Preserve and inspect the existing lock.');
    }
    requireGate(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'runtime_operation_locked', 'exclusive shared operation lock', [], 'Do not terminate or overlap an operation.');
    $journal = $kit.'/'.$m['releaseName'].'-attempt';
    $phase = 'identity';
    $maintenance = false;
    $switched = false;
    $vendorMoved = false;
    $vendorInstalled = false;
    $created = false;
    $traffic = false;
    $assets = [];
    try {
        requireGate(! file_exists($journal), 'runtime_attempt_exists', 'new unique journal', [], 'Inspect the preserved attempt; never replay.');
        mkdir($journal, 0700);
        $created = true;
        writeNew($journal.'/identity.json', ['format' => 'novinpay-runtime-journal-v1', 'manifestSha256' => $m['_sha256'], 'baseline' => $m['baseline'], 'target' => $m['target'], 'automaticDatabaseRestore' => false]);
        requireGate(is_link($publicLink) && str_replace('\\', '/', readlink($publicLink)) === $app.'/public', 'runtime_public_link_changed', 'original link identity', [], 'Stop without altering hosting links.');
        requireGate(! is_file($app.'/storage/framework/down') && ! is_file($app.'/public/hot'), 'runtime_initial_maintenance', 'baseline online without hot dev server', [], 'Reconcile maintenance before a release.');
        $dirty = trim(runtimeGit($app, ['status', '--porcelain', '--untracked-files=no', '--', '.', ':(exclude)public/.htaccess']));
        requireGate($dirty === '' && trim(runtimeGit($app, ['diff', '--cached', '--name-only'])) === '', 'runtime_dirty_source', 'clean baseline except pinned hosting handler', [], 'Preserve unrelated work.');
        runtimeVerify($app, $m['baselineFiles']);
        runtimeVerify($app, $m['protectedFiles']);
        $hooks['preflight']();
        $phase = 'quota';
        $hooks['quota']();
        $phase = 'stage';
        foreach (['source', 'vendor', 'assets'] as $kind) {
            runtimeExtract($kit.'/'.$m['archives'][$kind]['file'], $m['archives'][$kind], $journal.'/'.$kind);
        }
        runtimeSourceContract($app, $m, $journal.'/source', $journal.'/vendor');
        runtimeAssets($journal.'/assets', $m['archives']['assets']['files']);
        runtimeGit($app, ['archive', '--format=zip', '--output='.$journal.'/baseline-source.zip', $m['baseline']]);
        $baselineSourceHash = hash_file('sha256', $journal.'/baseline-source.zip');
        $baselineArchive = new \ZipArchive;
        requireGate($baselineArchive->open($journal.'/baseline-source.zip', \ZipArchive::CHECKCONS) === true, 'runtime_baseline_source_archive', 'verified Git baseline rollback archive', [], 'Do not enter maintenance without source recovery material.');
        $baselineArchive->close();
        writeNew($journal.'/baseline-source.json', ['commit' => $m['baseline'], 'sha256' => $baselineSourceHash, 'hostingHandlerMustNotBeRestoredFromGit' => true]);
        $baselineVendor = runtimeInventory($app.'/vendor');
        runtimePackages(file_get_contents($app.'/composer.lock'), file_get_contents($app.'/vendor/composer/installed.json'));
        writeNew($journal.'/baseline-vendor.json', $baselineVendor);
        // Full verified rollback copy exists BEFORE any vendor is moved.
        foreach ($baselineVendor as $path => $hash) {
            runtimeWrite($journal.'/rollback-vendor/'.$path, file_get_contents($app.'/vendor/'.$path));
        }
        runtimeVerify($journal.'/rollback-vendor', $baselineVendor, true);
        $cache = [];
        $cacheModes = [];
        foreach (glob($app.'/bootstrap/cache/*.php') as $file) {
            $cache[basename($file)] = hash_file('sha256', $file);
            $cacheModes[basename($file)] = fileperms($file) & 0777;
            runtimeWrite($journal.'/rollback-cache/'.basename($file), file_get_contents($file), 0600);
        }
        runtimeVerify($journal.'/rollback-cache', $cache, true);
        $views = runtimeInventory($app.'/storage/framework/views');
        foreach ($views as $path => $hash) {
            runtimeWrite($journal.'/rollback-views/'.$path, file_get_contents($app.'/storage/framework/views/'.$path));
        }
        foreach ($m['archives']['assets']['files'] as $path => $hash) {
            $target = $app.'/public/build/'.$path;
            runtimeNoLinks($target);
            $mutable = in_array($path, ['manifest.json', 'assets/.htaccess', 'support-chat/.htaccess', 'support-chat/manifest.json'], true);
            $old = is_file($target) ? hash_file('sha256', $target) : null;
            requireGate($old === null || $mutable || hash_equals($old, $hash), 'runtime_hashed_asset_collision', 'immutable existing asset bytes', [], 'Reject asset-name collisions.');
            $assets[$path] = ['before' => $old, 'after' => $hash];
            if ($old !== null && $mutable) {
                runtimeWrite($journal.'/rollback-assets/'.$path, file_get_contents($target));
            }
        }
        writeNew($journal.'/rollback.json', ['sourceArchiveSha256' => $baselineSourceHash, 'cache' => $cache, 'cacheModes' => $cacheModes, 'views' => $views, 'assets' => $assets, 'vendor' => $baselineVendor]);
        $hooks['quota'](); // Fresh evidence after staging, immediately before maintenance.
        runtimeVerify($app, $m['protectedFiles']);
        $phase = 'maintenance';
        // Set ownership before invoking down: even a failed hook may have fenced traffic.
        $maintenance = true;
        $hooks['down']();
        requireGate(is_file($app.'/storage/framework/down'), 'runtime_down_unconfirmed', 'maintenance established', [], 'Keep traffic closed.');
        writeNew($journal.'/maintenance.json', ['confirmed' => true]);
        $phase = 'source';
        runtimeGit($app, ['merge', '--ff-only', '--no-edit', $m['target']]);
        $switched = true;
        $phase = 'vendor';
        runtimeVerify($app.'/vendor', $baselineVendor, true);
        requireGate(rename($app.'/vendor', $journal.'/original-vendor'), 'runtime_vendor_move', 'baseline vendor retained', [], 'Keep maintenance.');
        $vendorMoved = true;
        requireGate(rename($journal.'/vendor/vendor', $app.'/vendor'), 'runtime_vendor_switch', 'staged vendor switch', [], 'Restore matched baseline before reopening.');
        $vendorInstalled = true;
        $vendorFiles = [];
        foreach ($m['archives']['vendor']['files'] as $path => $hash) {
            requireGate(str_starts_with($path, 'vendor/'), 'runtime_vendor_prefix', 'vendor-only artifact', [], 'Reject unexpected package paths.');
            $vendorFiles[substr($path, 7)] = $hash;
        }
        runtimeVerify($app.'/vendor', $vendorFiles, true);
        $phase = 'assets';
        $paths = array_keys($assets);
        usort($paths, fn ($a, $b) => (int) str_ends_with($a, 'manifest.json') <=> (int) str_ends_with($b, 'manifest.json'));
        foreach ($paths as $path) {
            runtimeWrite($app.'/public/build/'.$path, file_get_contents($journal.'/assets/'.$path));
        }
        $phase = 'cache';
        try {
            $hooks['cache']();
        } finally {
            writeNew($journal.'/candidate-views.json', runtimeInventory($app.'/storage/framework/views'));
        }
        $phase = 'web';
        $hooks['web']();
        $phase = 'ready';
        runtimeVerify($app, $m['protectedFiles']);
        runtimeVerify($app, array_diff_key($m['archives']['source']['files'], ['public/.htaccess' => true]));
        runtimeVerify($app.'/public/build', $m['archives']['assets']['files']);
        requireGate(trim(runtimeGit($app, ['rev-parse', 'HEAD'])) === $m['target'] && str_replace('\\', '/', readlink($publicLink)) === $app.'/public', 'runtime_final_identity', 'exact final source and public link', [], 'Keep maintenance.');
        $hooks['ready']();
        writeNew($journal.'/ready.json', ['target' => $m['target'], 'migrationsApplied' => 0]);
        $phase = 'reopen';
        $traffic = true;
        $hooks['up']();
        requireGate(! is_file($app.'/storage/framework/down'), 'runtime_up_unconfirmed', 'maintenance disabled', [], 'Inspect protected state.');
        $maintenance = false;
        $phase = 'https';
        $hooks['smoke']();
        $receipt = ['result' => 'RUNTIME_RELEASE_PASS', 'commit' => $m['target'], 'migrationsApplied' => 0, 'maintenanceActive' => false, 'sendingExecuted' => false];
        writeNew($journal.'/complete.json', $receipt);

        return $receipt;
    } catch (\Throwable $error) {
        // No speculative rollback after admitting traffic, nor any database restore.
        $restored = false;
        if ($traffic && $created) {
            try {
                $hooks['down']();
                $maintenance = is_file($app.'/storage/framework/down');
            } catch (\Throwable) {
                $maintenance = is_file($app.'/storage/framework/down');
            }
        }
        if (! $traffic && $maintenance && is_file($journal.'/rollback.json')) {
            try {
                requireGate(is_file($app.'/storage/framework/down'), 'runtime_rollback_fence', 'maintenance still active', [], 'Reconcile the protected state.');
                $plan = json_decode(privateRead($journal.'/rollback.json'), true, 512, JSON_THROW_ON_ERROR);
                requireGate(hash_equals($plan['sourceArchiveSha256'], hash_file('sha256', $journal.'/baseline-source.zip')), 'runtime_rollback_source_archive', 'preserved exact baseline source backup', [], 'Keep maintenance and inspect recovery material.');
                if ($switched) {
                    requireGate(trim(runtimeGit($app, ['rev-parse', 'HEAD'])) === $m['target'], 'runtime_rollback_head', 'owned target only', [], 'Inspect external changes.');
                    runtimeVerify($app, array_diff_key($m['archives']['source']['files'], ['public/.htaccess' => true]));
                    runtimeGit($app, ['switch', '--detach', $m['baseline']]);
                }
                if ($vendorInstalled) {
                    requireGate(! file_exists($journal.'/failed-vendor') && rename($app.'/vendor', $journal.'/failed-vendor'), 'runtime_rollback_vendor_stage', 'preserved candidate vendor', [], 'Inspect vendor paths.');
                }
                if ($vendorMoved) {
                    runtimeVerify($journal.'/original-vendor', $plan['vendor'], true);
                    requireGate(rename($journal.'/original-vendor', $app.'/vendor'), 'runtime_rollback_vendor', 'matched baseline vendor', [], 'Keep maintenance.');
                }
                foreach ($plan['assets'] as $path => $pin) {
                    $file = $app.'/public/build/'.$path;
                    if ($pin['before'] !== null && is_file($journal.'/rollback-assets/'.$path)) {
                        runtimeWrite($file, file_get_contents($journal.'/rollback-assets/'.$path));
                    } elseif ($pin['before'] === null && is_file($file)) {
                        requireGate(hash_equals($pin['after'], hash_file('sha256', $file)) && ! is_link($file), 'runtime_rollback_asset_ownership', 'operation-owned addition only', [], 'Preserve unexpected files.');
                        unlink($file);
                    }
                }
                foreach (glob($app.'/bootstrap/cache/*.php') as $file) {
                    if (! isset($plan['cache'][basename($file)])) {
                        // Only known framework-generated caches may be removed.
                        requireGate(in_array(basename($file), ['packages.php', 'services.php', 'routes-v7.php', 'events.php'], true) && ! is_link($file), 'runtime_rollback_cache_ownership', 'known generated cache only', [], 'Preserve unexpected cache files.');
                        unlink($file);
                    }
                }
                foreach ($plan['cache'] as $path => $hash) {
                    runtimeWrite($app.'/bootstrap/cache/'.$path, file_get_contents($journal.'/rollback-cache/'.$path), $plan['cacheModes'][$path]);
                }
                foreach (runtimeInventory($app.'/storage/framework/views') as $path => $hash) {
                    if (! isset($plan['views'][$path])) {
                        $generated = json_decode(privateRead($journal.'/candidate-views.json'), true, 512, JSON_THROW_ON_ERROR);
                        requireGate(runtimeCompiledViewPath($path) && isset($generated[$path]) && hash_equals($generated[$path], $hash), 'runtime_rollback_view_ownership', 'unchanged recorded compiled Blade view only', [], 'Preserve unexpected view files.');
                        unlink($app.'/storage/framework/views/'.$path);
                    }
                }
                foreach ($plan['views'] as $path => $hash) {
                    runtimeWrite($app.'/storage/framework/views/'.$path, file_get_contents($journal.'/rollback-views/'.$path));
                }
                runtimeVerify($app.'/vendor', $plan['vendor'], true);
                runtimeVerify($app, $m['protectedFiles']);
                requireGate(trim(runtimeGit($app, ['diff', '--name-only', $m['baseline'], '--', '.', ':(exclude)public/.htaccess'])) === '', 'runtime_rollback_source', 'clean matched baseline source', [], 'Keep maintenance and reconcile source changes.');
                $hooks['web']();
                $hooks['baselineReady']();
                $hooks['up']();
                requireGate(! is_file($app.'/storage/framework/down'), 'runtime_rollback_up', 'restored baseline online', [], 'Inspect maintenance.');
                $maintenance = false;
                $restored = true;
                writeNew($journal.'/rollback-complete.json', ['baseline' => $m['baseline'], 'databaseRestored' => false, 'sourceDetached' => $switched]);
            } catch (\Throwable $rollbackError) {
                if (! is_file($journal.'/rollback-stop.json')) {
                    writeNew($journal.'/rollback-stop.json', ['check' => $rollbackError instanceof GateFailure ? $rollbackError->check : 'operation_failure', 'retrySafe' => false]);
                }
                // Preserve every stage and evidence. An interrupted journal is never replayed.
            }
        }
        if ($created && ! is_file($journal.'/stop.json')) {
            writeNew($journal.'/stop.json', ['result' => 'RUNTIME_RELEASE_STOP', 'phase' => $phase, 'check' => $error instanceof GateFailure ? $error->check : 'operation_failure', 'retrySafe' => false, 'maintenanceActive' => $maintenance, 'baselineRestored' => $restored]);
        }
        throw $error;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function runtimeCompiledViewPath(string $path): bool
{
    return preg_match('/\A[a-f0-9]{32}\.php\z/D', $path) === 1;
}
