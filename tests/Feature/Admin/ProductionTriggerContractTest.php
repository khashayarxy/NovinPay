<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductionTriggerContractTest extends TestCase
{
    public function test_release_trigger_pins_match_the_sql_emitted_by_the_actual_reviewed_migrations(): void
    {
        require_once base_path('scripts/release/pr13-schema-contract.php');
        Schema::shouldReceive('create')->andReturnNull();
        Schema::shouldReceive('table')->andReturnNull();
        DB::shouldReceive('getDriverName')->andReturn('mariadb');
        DB::shouldReceive('selectOne')->with('SELECT VERSION() AS version')->andReturn((object) ['version' => '10.6.28-MariaDB']);
        DB::shouldReceive('selectOne')->with('SELECT @@session.explicit_defaults_for_timestamp AS explicit_dates, @@session.check_constraint_checks AS checks_enabled, @@session.foreign_key_checks AS foreign_keys, @@default_storage_engine AS engine')
            ->andReturn((object) ['explicit_dates' => 1, 'checks_enabled' => 1, 'foreign_keys' => 1, 'engine' => 'InnoDB']);
        DB::shouldReceive('selectOne')->with('SELECT @@global.log_bin AS logging, @@global.log_bin_trust_function_creators AS trusted')->andReturn((object) ['logging' => 0, 'trusted' => 1]);
        DB::shouldReceive('select')->with('SHOW GRANTS FOR CURRENT_USER')->andReturn([(object) ['grant' => 'GRANT TRIGGER ON `novinpay_live1`.* TO `novinpay_mig1`@`localhost`']]);
        DB::shouldReceive('connection->getDatabaseName')->andReturn('novinpay_live1');
        DB::shouldReceive('statement')->andReturnTrue();
        $rows = [];
        DB::shouldReceive('unprepared')->andReturnUsing(function (string $sql) use (&$rows): bool {
            $this->assertSame(1, preg_match('/\ACREATE TRIGGER (\w+) BEFORE (\w+) ON (\w+) FOR EACH ROW (.*)\z/s', $sql, $m));
            $rows[] = ['name' => $m[1], 'eventName' => strtoupper($m[2]), 'tableName' => $m[3], 'timing' => 'BEFORE', 'bodySha256' => hash('sha256', $m[4]), 'definer' => 'novinpay_mig1@localhost'];

            return true;
        });
        $contracts = json_decode(file_get_contents(base_path('scripts/release/trigger-contracts.json')), true, 32, JSON_THROW_ON_ERROR);
        foreach (array_unique(array_column($contracts, 'migration')) as $name) {
            (require database_path('migrations/'.$name.'.php'))->up();
        }
        $expected = \NovinPay\Release\expectedTriggerContracts(\NovinPay\Release\migrationInventory(database_path('migrations')));
        $this->assertCount(count($expected), pr13VerifyTriggerContracts($rows, true));
    }
}
