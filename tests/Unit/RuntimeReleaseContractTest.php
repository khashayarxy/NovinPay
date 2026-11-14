<?php

namespace Tests\Unit;

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

    public function test_exact_schema_neutral_contract_preserves_trigger_definers_and_rejects_migrations(): void
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
}
