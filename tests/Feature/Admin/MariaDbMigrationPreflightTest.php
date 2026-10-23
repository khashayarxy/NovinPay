<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class MariaDbMigrationPreflightTest extends TestCase
{
    public static function unsafeSettings(): array
    {
        $cases = [];
        foreach (['000003_create_financial_tables', '000004_create_article_tables'] as $migration) {
            foreach (['explicit_dates' => 0, 'checks_enabled' => 0, 'foreign_keys' => 0, 'engine' => 'MyISAM'] as $key => $value) {
                $cases[$migration.' '.$key] = [$migration, '10.6.28-MariaDB', $key, $value];
            }
            foreach (['10.6.27-MariaDB', '10.11.0-MariaDB'] as $version) {
                $cases[$migration.' '.$version] = [$migration, $version, null, null];
            }
        }

        return $cases;
    }

    #[DataProvider('unsafeSettings')]
    public function test_invariants_or_unverified_versions_cannot_be_bypassed(string $migration, string $version, ?string $key, mixed $value): void
    {
        Schema::shouldReceive('create')->never();
        DB::shouldReceive('getDriverName')->andReturn('mariadb');
        DB::shouldReceive('selectOne')->with('SELECT VERSION() AS version')->andReturn((object) ['version' => $version]);
        if ($key) {
            $settings = ['explicit_dates' => 1, 'checks_enabled' => 1, 'foreign_keys' => 1, 'engine' => 'InnoDB'];
            $settings[$key] = $value;
            DB::shouldReceive('selectOne')->with('SELECT @@session.explicit_defaults_for_timestamp AS explicit_dates, @@session.check_constraint_checks AS checks_enabled, @@session.foreign_key_checks AS foreign_keys, @@default_storage_engine AS engine')->andReturn((object) $settings);
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($key ? 'never disable invariants' : 'Only verified MariaDB');
        (require base_path('database/migrations/2026_10_21_'.$migration.'.php'))->up();
    }
}
