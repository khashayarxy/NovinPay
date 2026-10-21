<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ArticleMigrationPreflightTest extends TestCase
{
    public static function restrictedGrants(): array
    {
        return [
            'DDL without trigger' => [true, ['GRANT SELECT, INSERT, CREATE, ALTER, REFERENCES ON `novinpay`.* TO `staff`@`%`'], 'direct TRIGGER'],
            'trusted policy is not a trigger grant' => [true, ['GRANT USAGE ON *.* TO `staff`@`%`'], 'direct TRIGGER'],
            'other schema' => [true, ['GRANT TRIGGER ON `other`.* TO `staff`@`%`'], 'direct TRIGGER'],
            'role only' => [true, ['GRANT `content_role`@`%` TO `staff`@`%`'], 'direct TRIGGER'],
            'binary logging restricted' => [false, ['GRANT ALL PRIVILEGES ON `novinpay`.* TO `staff`@`%`'], 'logging policy'],
            'SUPER in identifier is not privilege' => [false, ['GRANT TRIGGER ON `novinpay`.* TO `SUPER_staff`@`%`'], 'logging policy'],
        ];
    }

    #[DataProvider('restrictedGrants')]
    public function test_restricted_accounts_fail_before_any_content_ddl(bool $trusted, array $grants, string $message): void
    {
        Schema::shouldReceive('create')->never();
        DB::shouldReceive('getDriverName')->andReturn('mysql');
        DB::shouldReceive('selectOne')->with('SELECT VERSION() AS version')->andReturn((object) ['version' => '8.0.46']);
        DB::shouldReceive('selectOne')->with('SELECT @@global.log_bin AS logging, @@global.log_bin_trust_function_creators AS trusted')->andReturn((object) ['logging' => 1, 'trusted' => (int) $trusted]);
        DB::shouldReceive('select')->with('SHOW GRANTS FOR CURRENT_USER')->andReturn(array_map(fn ($grant) => (object) ['grant' => $grant], $grants));
        DB::shouldReceive('connection->getDatabaseName')->andReturn('novinpay');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        (require base_path('database/migrations/2026_10_21_000004_create_article_tables.php'))->up();
    }
}
