<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MariaDbCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Native MariaDB integration job only.');
        }
    }

    public function test_explicit_dates_and_integrity_checks_are_enabled_on_new_connections(): void
    {
        config(['database.connections.mariadb_probe' => config('database.connections.mariadb')]);
        try {
            $settings = DB::connection('mariadb_probe')->selectOne('SELECT @@explicit_defaults_for_timestamp AS dates, @@check_constraint_checks AS checks_enabled, @@foreign_key_checks AS fk');
            $this->assertSame(1, (int) $settings->dates);
            $this->assertSame(1, (int) $settings->checks_enabled);
            $this->assertSame(1, (int) $settings->fk);
        } finally {
            DB::purge('mariadb_probe');
        }
    }

    public function test_created_at_is_not_silently_changed_by_a_non_timestamp_update(): void
    {
        $user = User::factory()->create();
        $id = DB::table('financial_operations')->insertGetId(['operation_key' => fake()->uuid(), 'actor_id' => $user->id, 'action' => 'receipt', 'payload_digest' => str_repeat('a', 64), 'created_at' => '2020-01-02 03:04:05']);
        DB::table('financial_operations')->where('id', $id)->update(['result_id' => 123]);
        $this->assertSame('2020-01-02 03:04:05', DB::table('financial_operations')->where('id', $id)->value('created_at'));
    }

    public function test_json_storage_rejects_invalid_json_in_raw_sql(): void
    {
        $product = Product::create(['name' => 'Synthetic JSON', 'slug' => 'synthetic-json', 'category' => 'pos_terminal', 'condition' => 'new', 'specifications' => ['Network' => '4G']]);
        $this->assertSame(['Network' => '4G'], $product->fresh()->specifications);
        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $product->id)->update(['specifications' => '{broken']);
    }
}
