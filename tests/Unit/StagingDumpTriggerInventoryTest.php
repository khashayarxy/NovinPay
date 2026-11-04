<?php

namespace Tests\Unit;

use App\Support\StagingDumpTriggerInventory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StagingDumpTriggerInventoryTest extends TestCase
{
    private function fixture(bool $quoted = false): array
    {
        $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'History is immutable'";
        $name = $quoted ? '`history_no_update`' : 'history_no_update';
        $sql = "/*!50003 CREATE*/ /*!50017 DEFINER=`staging_migrator`@`localhost`*/ /*!50003 TRIGGER {$name} BEFORE update ON history FOR EACH ROW {$body} \n*/;;\n";
        $expected = ['history_no_update' => ['table' => 'history', 'event' => 'UPDATE', 'timing' => 'BEFORE', 'bodySha256' => hash('sha256', $body), 'definer' => 'staging_migrator@localhost']];

        return [$sql, $expected];
    }

    public function test_mariadb_unquoted_and_quoted_names_and_crlf_are_supported(): void
    {
        foreach ([false, true] as $quoted) {
            [$sql, $expected] = $this->fixture($quoted);
            $this->assertSame($expected, StagingDumpTriggerInventory::verify($sql, $expected));
            $this->assertSame($expected, StagingDumpTriggerInventory::verify(str_replace("\n", "\r\n", $sql), $expected));
        }
    }

    public function test_old_substring_check_rejects_valid_unquoted_dump(): void
    {
        [$sql, $expected] = $this->fixture();
        $this->assertStringNotContainsString('TRIGGER `history_no_update`', $sql);
        $this->assertCount(1, StagingDumpTriggerInventory::verify($sql, $expected));
    }

    public function test_missing_duplicate_truncated_and_altered_objects_are_rejected(): void
    {
        [$sql, $expected] = $this->fixture();
        $bad = ['', $sql.$sql, substr($sql, 0, -5), str_replace('history_no_update', 'unexpected', $sql),
            str_replace('BEFORE', 'AFTER', $sql), str_replace('update ON', 'delete ON', $sql),
            str_replace('ON history', 'ON other', $sql), str_replace('staging_migrator', 'runtime_user', $sql),
            str_replace('History is immutable', 'History is mutable', $sql),
            "INSERT INTO notes VALUES ('TRIGGER `history_no_update`');\n"];
        foreach ($bad as $fixture) {
            try {
                StagingDumpTriggerInventory::verify($fixture, $expected);
                $this->fail('Invalid inventory accepted');
            } catch (RuntimeException $e) {
                $this->assertStringStartsWith('dump_trigger_', $e->getMessage());
            }
        }
    }
}
