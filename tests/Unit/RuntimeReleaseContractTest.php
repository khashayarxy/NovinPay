<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use NovinPay\Release\GateFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function NovinPay\Release\runtimeAssets;
use function NovinPay\Release\runtimeExtract;
use function NovinPay\Release\runtimeGit;
use function NovinPay\Release\runtimeInodes;
use function NovinPay\Release\runtimeInventory;
use function NovinPay\Release\runtimePackages;
use function NovinPay\Release\runtimePath;
use function NovinPay\Release\runtimePublicAccess;
use function NovinPay\Release\runtimePublicCreation;
use function NovinPay\Release\runtimeReleaseContract;
use function NovinPay\Release\runtimeVerify;
use function NovinPay\Release\runtimeWrite;
use function NovinPay\Release\sourceReleaseManifest;

require_once __DIR__.'/../../scripts/release/runtime-release.php';

class RuntimeReleaseContractTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/np-runtime-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        // Exact test-owned directory; never operate on the application or owner DB.
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            if (PHP_OS_FAMILY === 'Windows' && $file->isFile() && ! $file->isLink()) {
                // Git makes disposable object files read-only on Windows.
                chmod($file->getPathname(), 0600);
            }
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public static function unsafePaths(): array
    {
        return array_map(fn ($p) => [$p], ['../outside', '/absolute', 'C:/outside', 'vendor/../../.env', 'vendor\\file', 'vendor//file', './file', '.git/config', "vendor/a\0b"]);
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_paths_are_rejected(string $path): void
    {
        $this->expectException(GateFailure::class);
        runtimePath($path);
    }

    public function test_source_deletions_preserve_unexpected_recreated_files(): void
    {
        runtimeGit($this->dir, ['init']);
        runtimeGit($this->dir, ['config', 'user.email', 'fixture@example.invalid']);
        runtimeGit($this->dir, ['config', 'user.name', 'Isolated fixture']);
        runtimeWrite($this->dir.'/old.php', '<?php // old');
        runtimeGit($this->dir, ['add', 'old.php']);
        runtimeGit($this->dir, ['commit', '-m', 'baseline']);
        $base = trim(runtimeGit($this->dir, ['rev-parse', 'HEAD']));
        runtimeGit($this->dir, ['rm', 'old.php']);
        runtimeWrite($this->dir.'/new.php', '<?php // new');
        runtimeGit($this->dir, ['add', 'new.php']);
        runtimeGit($this->dir, ['commit', '-m', 'candidate']);
        $m = ['profile' => 'application-neutral-6eb8e00', 'baseline' => $base, 'target' => trim(runtimeGit($this->dir, ['rev-parse', 'HEAD']))];
        $this->assertSame(['new.php' => 'A', 'old.php' => 'D'], \NovinPay\Release\runtimeSourceDelta($this->dir, $m));
        \NovinPay\Release\runtimeDeletedSource($this->dir, $m);
        runtimeWrite($this->dir.'/old.php', 'unrelated work');
        try {
            \NovinPay\Release\runtimeDeletedSource($this->dir, $m);
            $this->fail('Recreated deleted file accepted');
        } catch (GateFailure $e) {
            $this->assertSame('runtime_deleted_source_present', $e->check);
            $this->assertSame('unrelated work', file_get_contents($this->dir.'/old.php'));
        }
    }

    public function test_recovery_provenance_is_explicit_and_schema_and_credentials_must_match(): void
    {
        $repo = dirname(__DIR__, 2);
        $probe = \NovinPay\Release\command(['git', 'cat-file', '-e', 'ecf672110214a24a9171d942a2730d9bfad5f65c'], $repo, 'fixture_history');
        if ($probe['exit'] !== 0) {
            $this->markTestSkipped('Full Git history is exercised by the existing SQLite release rehearsal job.');
        }
        $m = $this->neutral();
        $proof = ['sourceCommit' => $m['recoverySourceCommit'], 'protectedFileHashes' => ['.env' => $m['protectedFiles']['.env']],
            'allProtectedFileHashesMatch' => true, 'schemaAndDefinersMatch' => true, 'databaseDumpRowDigestMatches' => true];
        \NovinPay\Release\runtimeRecoveryCompatibility($repo, $m, $proof);
        foreach (['sourceCommit' => $m['baseline'], 'protectedFileHashes' => ['.env' => str_repeat('f', 64)], 'schemaAndDefinersMatch' => false] as $key => $value) {
            $bad = $proof;
            $bad[$key] = $value;
            try {
                \NovinPay\Release\runtimeRecoveryCompatibility($repo, $m, $bad);
                $this->fail('Unverified recovery accepted');
            } catch (GateFailure $e) {
                $this->assertContains($e->check, ['runtime_recovery_provenance', 'runtime_recovery_configuration', 'runtime_recovery_compatibility_proof']);
            }
        }
    }

    private function archive(array $entries): array
    {
        $path = $this->dir.'/test.zip';
        $z = new \ZipArchive;
        $this->assertTrue($z->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL));
        $files = [];
        foreach ($entries as $name => $bytes) {
            $z->addFromString($name, $bytes);
            $files[$name] = hash('sha256', $bytes);
        }
        $z->close();

        return [$path, ['sha256' => hash_file('sha256', $path), 'files' => $files]];
    }

    public function test_real_zip_extracts_and_installed_tampering_is_detected(): void
    {
        [$path, $c] = $this->archive(['vendor/a.php' => '<?php return 1;', 'vendor/nested/b' => 'bytes']);
        runtimeExtract($path, $c, $this->dir.'/stage');
        $this->assertSame($c['files'], runtimeInventory($this->dir.'/stage'));
        file_put_contents($this->dir.'/stage/vendor/a.php', 'altered');
        $this->expectException(GateFailure::class);
        runtimeVerify($this->dir.'/stage', $c['files'], true);
    }

    public function test_corrupt_archive_fails_before_creating_destination(): void
    {
        [$path, $c] = $this->archive(['vendor/a' => 'bytes']);
        file_put_contents($path, 'corrupt', FILE_APPEND);
        try {
            runtimeExtract($path, $c, $this->dir.'/stage');
            $this->fail('Corrupt package accepted');
        } catch (GateFailure $e) {
            $this->assertSame('runtime_archive_hash', $e->check);
            $this->assertDirectoryDoesNotExist($this->dir.'/stage');
        }
    }

    public function test_uninventoried_secret_is_rejected_before_any_extraction(): void
    {
        [$path, $c] = $this->archive(['vendor/a' => 'bytes', '.env' => 'synthetic']);
        unset($c['files']['.env']);
        $this->expectException(GateFailure::class);
        runtimeExtract($path, $c, $this->dir.'/stage');
    }

    public function test_missing_member_is_rejected(): void
    {
        [$path, $c] = $this->archive(['vendor/a' => 'bytes']);
        $c['files']['vendor/missing'] = hash('sha256', 'missing');
        $this->expectException(GateFailure::class);
        runtimeExtract($path, $c, $this->dir.'/stage');
    }

    public function test_zip_symlink_is_rejected(): void
    {
        [$path, $c] = $this->archive(['vendor/a' => '/outside']);
        $z = new \ZipArchive;
        $z->open($path);
        $z->setExternalAttributesName('vendor/a', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $z->close();
        $c['sha256'] = hash_file('sha256', $path);
        $this->expectException(GateFailure::class);
        runtimeExtract($path, $c, $this->dir.'/stage');
    }

    public function test_zip_traversal_is_rejected_before_any_destination_is_created(): void
    {
        [$path, $c] = $this->archive(['../escaped' => 'must not publish']);
        try {
            runtimeExtract($path, $c, $this->dir.'/stage');
            $this->fail('Traversal ZIP accepted');
        } catch (GateFailure $e) {
            $this->assertSame('runtime_unsafe_path', $e->check);
            $this->assertFileDoesNotExist($this->dir.'/escaped');
            $this->assertDirectoryDoesNotExist($this->dir.'/stage');
        }
    }

    public function test_reference_versions_are_validated_not_only_package_names(): void
    {
        $p = ['name' => 'vendor/package', 'version' => '1.0.0', 'source' => ['reference' => 'ref-a'], 'dist' => ['reference' => 'ref-a']];
        $lock = json_encode(['packages' => [$p]]);
        $installed = ['dev' => false, 'dev-package-names' => [], 'packages' => [$p]];
        $this->assertCount(1, runtimePackages($lock, json_encode($installed)));
        $installed['packages'][0]['source']['reference'] = 'ref-b';
        $this->expectException(GateFailure::class);
        runtimePackages($lock, json_encode($installed));
    }

    public function test_zero_remaining_is_not_mistaken_for_unlimited_inodes(): void
    {
        $this->expectException(GateFailure::class);
        runtimeInodes(['underInodeQuota' => true, 'inodeLimit' => 0, 'inodesRemaining' => 0], 8000);
    }

    public function test_explicit_unlimited_or_sufficient_measured_inodes_pass(): void
    {
        runtimeInodes(['underInodeQuota' => true, 'inodeLimit' => 0, 'inodesRemaining' => 0, 'inodeUnlimited' => true], 8000);
        runtimeInodes(['underInodeQuota' => true, 'inodeLimit' => 100000, 'inodesRemaining' => 13000], 8000);
        $this->addToAssertionCount(2);
    }

    public function test_insufficient_inodes_fail_closed(): void
    {
        $this->expectException(GateFailure::class);
        runtimeInodes(['underInodeQuota' => true, 'inodeLimit' => 100000, 'inodesRemaining' => 10000], 8000);
    }

    public function test_missing_dynamic_vite_chunk_is_rejected(): void
    {
        runtimeWrite($this->dir.'/manifest.json', json_encode(['app' => ['file' => 'assets/app.js', 'dynamicImports' => ['admin']], 'admin' => ['file' => 'assets/admin.js']]));
        $this->expectException(GateFailure::class);
        runtimeAssets($this->dir, ['assets/app.js' => hash('sha256', 'app')]);
    }

    public function test_new_mode_cannot_weaken_or_enter_legacy_contract(): void
    {
        $this->expectException(GateFailure::class);
        sourceReleaseManifest(['format' => 'novinpay-runtime-release-v1']);
    }

    public function test_failed_native_command_cannot_be_treated_as_success(): void
    {
        $this->expectException(GateFailure::class);
        runtimeGit($this->dir, ['rev-parse', 'HEAD']);
    }

    private function manifest(): array
    {
        $zero = str_repeat('0', 64);
        $names = \NovinPay\Release\migrationInventory(__DIR__.'/../../database/migrations');
        $m = ['format' => 'novinpay-runtime-release-v1', 'baseline' => 'ecf672110214a24a9171d942a2730d9bfad5f65c',
            'target' => str_repeat('a', 40), 'applicationCommit' => '9f2e6a48481c8527d538de9e4ba24a2ca211ee8f',
            'releaseName' => 'unit-runtime', 'sourceBranch' => 'synthetic', 'targetBranch' => 'codex/synthetic',
            'stateDirectory' => '/home/novinpay/novinpay-direct-pr13', 'publicLink' => '/home/novinpay/novinpay/public',
            'backup' => ['directory' => '/home/novinpay/novinpay-private-backups/recovery-synthetic', 'restoreDatabase' => 'novinpay_restoresynthetic', 'serverKeySha256' => $zero],
            'credentialsSha256' => $zero, 'maintenanceHashes' => ['state.json' => $zero, 'audit.jsonl' => $zero], 'retainedFiles' => [],
            'migrations' => $names, 'triggers' => \NovinPay\Release\expectedTriggerContracts($names), 'expectedTriggers' => 13, 'migrationsApplied' => 0,
            'productionPackages' => array_fill(0, 76, []), 'dependencyChanges' => ['laravel/framework' => ['13.35.0', '13.40.0']],
            'baselineFiles' => array_fill_keys(['one', 'two', 'three'], $zero),
            'protectedFiles' => array_fill_keys(['.env', 'public/.htaccess', 'bootstrap/cache/config.php', 'storage/app/private/support-chat/installation.env', 'storage/framework/site-maintenance/state.json', 'storage/framework/site-maintenance/audit.jsonl'], $zero),
            'httpChecks' => ['/' => ['status' => 200], '/login' => ['status' => 200]]];
        foreach (['source' => 'README.md', 'vendor' => 'vendor/autoload.php', 'assets' => 'manifest.json'] as $kind => $path) {
            $m['archives'][$kind] = ['file' => $kind.'.zip', 'sha256' => $zero, 'files' => [$path => $zero]];
        }

        return $m;
    }

    public function test_exact_schema_neutral_contract_preserves_trigger_definers_and_rejects_migrations(): void
    {
        $m = $this->manifest();
        runtimeReleaseContract($m);
        $this->addToAssertionCount(1);
        $changed = $m;
        $changed['triggers'][array_key_first($m['triggers'])]['definer'] = 'other@localhost';
        try {
            runtimeReleaseContract($changed);
            $this->fail('Definer drift accepted');
        } catch (GateFailure $e) {
            $this->assertSame('runtime_trigger_contract', $e->check);
        }
        $m['migrationsApplied'] = 1;
        $this->expectException(GateFailure::class);
        runtimeReleaseContract($m);
    }

    private function neutral(): array
    {
        return array_replace($this->manifest(), ['profile' => 'application-neutral-6eb8e00',
            'baseline' => 'c9c46dc39f79aad59601045cb44e342168536e9c', 'target' => '6eb8e00c6e46b342df5eea15cebea90e85cc86f2',
            'applicationCommit' => '6eb8e00c6e46b342df5eea15cebea90e85cc86f2', 'reuseVendor' => true, 'refreshConfiguration' => true,
            'recoverySourceCommit' => 'ecf672110214a24a9171d942a2730d9bfad5f65c', 'dependencyChanges' => []]);
    }

    public function test_neutral_profile_accepts_only_reviewed_pair_without_weakening_legacy_contract(): void
    {
        $m = $this->neutral();
        runtimeReleaseContract($m);
        $this->assertSame('13.40.0', \NovinPay\Release\runtimeFramework($m, true));
        foreach (['baseline' => str_repeat('f', 40), 'target' => str_repeat('a', 40), 'applicationCommit' => str_repeat('b', 40),
            'reuseVendor' => false, 'refreshConfiguration' => false, 'migrationsApplied' => 1, 'profile' => 'unknown'] as $key => $value) {
            try {
                runtimeReleaseContract(array_replace($m, [$key => $value]));
                $this->fail('Unreviewed release accepted: '.$key);
            } catch (GateFailure) {
                $this->addToAssertionCount(1);
            }
        }
        $m['dependencyChanges'] = ['laravel/framework' => ['13.35.0', '13.40.0']];
        $this->expectException(GateFailure::class);
        runtimeReleaseContract($m);
    }

    public function test_config_refresh_preserves_active_secrets_and_every_unrelated_setting(): void
    {
        $before = ['customer' => ['ippanel' => ['api_key' => 'synthetic', 'sender' => '+983000505', 'enabled' => true], 'test_reset_on_logout' => true, 'sms_enabled' => false],
            'operations' => ['scheduled_payments' => false, 'scheduled_notifications' => false], 'database' => ['default' => 'mariadb'], 'app' => ['key' => 'synthetic-key']];
        $after = $before;
        unset($after['customer']['sms_enabled'], $after['operations']['scheduled_notifications']);
        $digest = \NovinPay\Release\runtimeConfigurationDigest($before);
        $this->assertSame($digest, \NovinPay\Release\runtimeConfigurationDigest($after));
        foreach ([['customer', ['ippanel' => ['api_key' => 'changed']]], ['operations', ['scheduled_payments' => true]], ['database', ['default' => 'sqlite']], ['app', ['key' => 'changed']]] as [$key, $value]) {
            $changed = $after;
            $changed[$key] = $value;
            $this->assertNotSame($digest, \NovinPay\Release\runtimeConfigurationDigest($changed));
        }
        runtimeWrite($this->dir.'/before.php', '<?php return '.var_export($before, true).';', 0600);
        runtimeWrite($this->dir.'/after.php', '<?php return '.var_export($after, true).';', 0600);
        \NovinPay\Release\runtimeConfigurationMatches($this->dir.'/before.php', $this->dir.'/after.php', hash_file('sha256', $this->dir.'/before.php'));
        file_put_contents($this->dir.'/before.php', '<?php return [];');
        $this->expectException(GateFailure::class);
        \NovinPay\Release\runtimeConfigurationMatches($this->dir.'/before.php', $this->dir.'/after.php', str_repeat('0', 64));
    }

    public function test_retained_framework_cache_admits_only_missing_inactive_mercure_false_default(): void
    {
        $before = ['broadcasting' => ['default' => null, 'connections' => ['mercure' => ['driver' => 'mercure']]],
            'customer' => ['ippanel' => ['api_key' => 'synthetic-unchanged']]];
        $after = $before;
        $after['broadcasting']['connections']['mercure']['async'] = false;
        $cases = [[$before, $before, true], [$before, $after, true]];
        $changed = $after;
        $changed['broadcasting']['connections']['mercure']['async'] = true;
        $cases[] = [$before, $changed, false];
        $activeBefore = $before;
        $activeBefore['broadcasting']['default'] = 'mercure';
        $activeAfter = $after;
        $activeAfter['broadcasting']['default'] = 'mercure';
        $cases[] = [$activeBefore, $activeAfter, false];
        $cases[] = [$after, $changed, false]; // Existing false must stay false.
        $cases[] = [$changed, $after, false]; // Existing true cannot be normalized.
        $cases[] = [$after, $before, false]; // Existing setting cannot disappear.
        $changed = $after;
        $changed['customer']['ippanel']['api_key'] = 'changed';
        $cases[] = [$before, $changed, false];
        $changed = $after;
        $changed['queue']['connections']['background']['driver'] = 'background';
        $cases[] = [$before, $changed, false]; // No general framework-default exemption.
        $changed = $before;
        unset($changed['broadcasting']['connections']['mercure']);
        $cases[] = [$changed, $after, false];
        foreach ($cases as $index => [$original, $candidate, $allowed]) {
            $a = $this->dir.'/original-'.$index.'.php';
            $b = $this->dir.'/candidate-'.$index.'.php';
            runtimeWrite($a, '<?php return '.var_export($original, true).';', 0600);
            runtimeWrite($b, '<?php return '.var_export($candidate, true).';', 0600);
            try {
                \NovinPay\Release\runtimeConfigurationMatches($a, $b, hash_file('sha256', $a));
                $this->assertTrue($allowed, 'Unsafe configuration accepted: '.$index);
            } catch (GateFailure $error) {
                $this->assertFalse($allowed, 'Compatible inactive default rejected: '.$index);
                $this->assertSame('runtime_configuration_changed', $error->check);
            }
        }
    }

    public function test_rollback_recognizes_actual_laravel_compiled_view_names(): void
    {
        $compiler = new BladeCompiler(new Filesystem, $this->dir);
        $actual = basename($compiler->getCompiledPath('/synthetic/example.blade.php'));
        $this->assertTrue(\NovinPay\Release\runtimeCompiledViewPath($actual));
        $this->assertFalse(\NovinPay\Release\runtimeCompiledViewPath('customer-upload.php'));
    }

    public function test_private_cache_copy_does_not_broaden_permissions(): void
    {
        runtimeWrite($this->dir.'/private-cache.php', '<?php return [];', 0600);
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($this->dir.'/private-cache.php') & 0777);
        }
        $this->assertSame('<?php return [];', file_get_contents($this->dir.'/private-cache.php'));
    }

    public function test_public_creation_restores_private_mask_even_after_failure(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Windows PHP does not implement Unix umask semantics.');
        }
        $previous = umask(0077);
        try {
            try {
                runtimePublicCreation(function () {
                    $this->assertSame(0022, umask());
                    throw new \RuntimeException('synthetic creation failure');
                });
                $this->fail('Creation failure was swallowed');
            } catch (\RuntimeException $e) {
                $this->assertSame('synthetic creation failure', $e->getMessage());
            }
            $this->assertSame(0077, umask());
        } finally {
            umask($previous);
        }
    }

    public function test_nested_assets_are_public_under_private_umask_without_exposing_private_files(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix permission bits require the existing Linux CI jobs.');
        }
        $previous = umask(0077);
        try {
            mkdir($this->dir.'/public', 0755);
            chmod($this->dir.'/public', 0755);
            runtimeWrite($this->dir.'/private/credentials', 'synthetic private data', 0600);
            runtimeWrite($this->dir.'/public/build/support-chat/version/operator/app.js', '/* public asset */', 0644, 0755);
            $this->assertSame(0077, umask());
            foreach (['build', 'build/support-chat', 'build/support-chat/version', 'build/support-chat/version/operator'] as $dir) {
                $this->assertSame(0755, fileperms($this->dir.'/public/'.$dir) & 0777);
            }
            $this->assertSame(0644, fileperms($this->dir.'/public/build/support-chat/version/operator/app.js') & 0777);
            $this->assertSame(0700, fileperms($this->dir.'/private') & 0777);
            $this->assertSame(0600, fileperms($this->dir.'/private/credentials') & 0777);
            runtimePublicAccess($this->dir.'/public', ['build/support-chat/version/operator/app.js' => 'unused-by-access-check']);
        } finally {
            umask($previous);
        }
    }

    public function test_real_git_worktree_creation_is_public_and_does_not_change_private_configuration(): void
    {
        $previous = umask(0077);
        try {
            runtimeGit($this->dir, ['init', '-b', 'test-baseline']);
            runtimeGit($this->dir, ['config', 'user.name', 'Synthetic release test']);
            runtimeGit($this->dir, ['config', 'user.email', 'synthetic@example.invalid']);
            runtimeWrite($this->dir.'/baseline', 'baseline');
            runtimeWrite($this->dir.'/public/robots.txt', 'baseline robots', 0644, 0755);
            runtimeGit($this->dir, ['add', 'baseline', 'public']);
            runtimeGit($this->dir, ['commit', '-m', 'synthetic baseline']);
            $baseline = trim(runtimeGit($this->dir, ['rev-parse', 'HEAD']));
            runtimeGit($this->dir, ['switch', '-c', 'test-candidate']);
            runtimeWrite($this->dir.'/public/images/hero.avif', 'synthetic public image');
            runtimeWrite($this->dir.'/public/robots.txt', 'candidate robots');
            runtimeGit($this->dir, ['add', 'public']);
            runtimeGit($this->dir, ['commit', '-m', 'synthetic public asset']);
            runtimeGit($this->dir, ['switch', '--detach', $baseline]);
            runtimeWrite($this->dir.'/.env', 'synthetic secret', 0600);
            runtimePublicCreation(fn () => runtimeGit($this->dir, ['merge', '--ff-only', 'test-candidate']));
            $this->assertSame('synthetic public image', file_get_contents($this->dir.'/public/images/hero.avif'));
            $this->assertSame('synthetic secret', file_get_contents($this->dir.'/.env'));
            file_put_contents($this->dir.'/private-after', 'synthetic private data');
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->assertSame(0077, umask());
                $this->assertSame(0644, fileperms($this->dir.'/public/images/hero.avif') & 0777);
                $this->assertSame(0755, fileperms($this->dir.'/public/images') & 0777);
                $this->assertSame(0600, fileperms($this->dir.'/.env') & 0777);
                $this->assertSame(0600, fileperms($this->dir.'/private-after') & 0777);
                runtimePublicAccess($this->dir.'/public', ['images/hero.avif' => 'unused']);
            }
            runtimePublicCreation(fn () => runtimeGit($this->dir, ['switch', '--detach', $baseline]));
            $this->assertSame('baseline robots', file_get_contents($this->dir.'/public/robots.txt'));
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->assertSame(0644, fileperms($this->dir.'/public/robots.txt') & 0777);
                $this->assertSame(0600, fileperms($this->dir.'/.env') & 0777);
            }
        } finally {
            umask($previous);
        }
    }

    #[DataProvider('unreadablePublicPaths')]
    public function test_public_access_rejects_same_uid_readable_but_web_inaccessible_assets(bool $blockedDirectory): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix permission bits require the existing Linux CI jobs.');
        }
        runtimeWrite($this->dir.'/public/nested/app.js', 'synthetic public asset');
        chmod($this->dir.'/public/nested', $blockedDirectory ? 0700 : 0755);
        chmod($this->dir.'/public/nested/app.js', $blockedDirectory ? 0644 : 0600);
        $this->assertSame('synthetic public asset', file_get_contents($this->dir.'/public/nested/app.js'));
        try {
            runtimePublicAccess($this->dir.'/public', ['nested/app.js' => 'unused']);
            $this->fail('Same-UID readability accepted as web readability');
        } catch (GateFailure $e) {
            $this->assertSame($blockedDirectory ? 'runtime_public_directory_traverse' : 'runtime_public_file_read', $e->check);
        }
    }

    public static function unreadablePublicPaths(): array
    {
        return [[true], [false]];
    }
}
