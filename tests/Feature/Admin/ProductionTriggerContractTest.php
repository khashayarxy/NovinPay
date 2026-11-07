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
        foreach (['2026_10_21_000003_create_financial_tables', '2026_10_21_000004_create_article_tables',
            '2026_11_03_000003_create_payment_records', '2026_11_04_000001_add_simple_repair_records'] as $name) {
            (require database_path('migrations/'.$name.'.php'))->up();
        }
        $this->assertCount(13, pr13VerifyTriggerContracts($rows, true));
    }
}
