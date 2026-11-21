<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function NovinPay\Release\expectedTriggerContracts;
use function NovinPay\Release\migrationInventory;
use function NovinPay\Release\verifyMigrationInventory;

require_once __DIR__.'/../../scripts/release/schema-contract.php';

class ReleaseSchemaInventoryTest extends TestCase
{
    public function test_inventory_tracks_new_migration_names_and_rejects_same_count_drift(): void
    {
        $dir = sys_get_temp_dir().'/np-schema-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        try {
            file_put_contents($dir.'/2026_01_01_000001_initial.php', '<?php');
            $before = migrationInventory($dir);
            file_put_contents($dir.'/2026_01_02_000001_additive.php', '<?php');
            $after = migrationInventory($dir);
            $this->assertCount(count($before) + 1, $after);
            verifyMigrationInventory(array_reverse($after), $after);
            $this->expectExceptionMessage('migration_inventory_mismatch');
            verifyMigrationInventory([$after[0], 'unexpected_name'], $after);
        } finally {
            foreach (glob($dir.'/*.php') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_trigger_inventory_is_selected_by_actual_migration_names(): void
    {
        $baseline = expectedTriggerContracts(['2026_10_21_000003_create_financial_tables', '2026_10_21_000004_create_article_tables']);
        $current = expectedTriggerContracts(migrationInventory(__DIR__.'/../../database/migrations'));
        $this->assertArrayHasKey('financial_document_identity', $baseline);
        $this->assertArrayNotHasKey('repair_revisions_no_update', $baseline);
        $this->assertArrayHasKey('repair_revisions_no_update', $current);
        $this->assertSame($baseline['financial_document_identity'], $current['financial_document_identity']);
    }

    public function test_duplicate_migration_records_are_not_accepted(): void
    {
        $this->expectExceptionMessage('migration_inventory_mismatch');
        verifyMigrationInventory(['a', 'a'], ['a', 'b']);
    }
}
