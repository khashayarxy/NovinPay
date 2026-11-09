<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FinancialDocument;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\FinancialOperations;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerPaymentMigrationTest extends TestCase
{
    // DDL is not transaction-wrapped: MySQL implicitly commits it and SQLite's
    // table-rebuild foreign-key toggles require the real migrator context.
    use DatabaseMigrations;

    public function test_upgrade_from_existing_schema_preserves_populated_financial_history(): void
    {
        $paths = $this->extensionMigrations();
        foreach (array_reverse($paths) as $path) {
            (require $path)->down();
        }
        $owner = User::factory()->superAdmin()->create();
        $customer = new Customer;
        foreach (['public_id' => (string) Str::uuid(), 'name' => 'SYNTHETIC UPGRADE', 'contact_phone' => '09000000000', 'phone_digest' => hash('sha256', 'upgrade-phone'), 'name_digest' => hash('sha256', 'upgrade-name'), 'identity_digest' => hash('sha256', 'upgrade-identity'), 'is_active' => true] as $key => $value) {
            $customer->$key = $value;
        }
        $customer->save();
        $order = new SalesOrder;
        foreach (['public_id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'created_by' => $owner->id, 'status' => 'fulfilled', 'total' => '1000.00', 'currency' => 'TOMAN', 'version' => 1] as $key => $value) {
            $order->$key = $value;
        }
        $order->save();
        app(FinancialOperations::class)->execute($owner, 'issue', ['operation_key' => (string) Str::uuid(), 'password' => 'password', 'reason' => 'Synthetic retained ledger', 'source_type' => 'order', 'source' => $order->public_id, 'source_version' => 1]);
        $tables = ['users', 'customers', 'financial_operations', 'financial_entries', 'financial_documents'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }
        foreach ($paths as $path) {
            (require $path)->up();
        }
        foreach ($tables as $table) {
            $columns = array_keys((array) $before[$table][0]);
            $this->assertEquals($before[$table], DB::table($table)->select($columns)->orderBy('id')->get()->toArray(), $table);
        }
        $document = FinancialDocument::firstOrFail();
        $this->assertSame(100000, app(FinancialOperations::class)->reconcile($document)['charged']);
        $this->assertSame($owner->id, (int) DB::table('financial_entries')->value('actor_id'));
    }

    public function test_empty_extension_rollback_and_reapply_preserve_original_financial_triggers(): void
    {
        $triggers = fn () => DB::table('sqlite_master')->where('type', 'trigger')->where(fn ($q) => $q->where('name', 'like', 'financial_entries_no_%')->orWhere('name', 'like', 'financial_document%'))->orderBy('name')->pluck('sql', 'name')->all();
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite table-rebuild preservation test; MySQL/MariaDB require their migration harness.');
        }
        $before = $triggers();
        $paths = $this->extensionMigrations();
        foreach (array_reverse($paths) as $path) {
            (require $path)->down();
        }
        $this->assertSame($before, $triggers());
        foreach ($paths as $path) {
            (require $path)->up();
        }
        $this->assertSame($before, $triggers());
        $this->assertTrue(Schema::hasColumn('financial_entries', 'payment_transaction_id'));
        $this->assertTrue(Schema::hasColumn('customer_accounts', 'password'));
    }

    private function extensionMigrations(): array
    {
        return array_merge(
            glob(database_path('migrations/2026_11_03_*.php')),
            glob(database_path('migrations/2026_11_04_*.php')),
            [database_path('migrations/2026_11_09_000001_add_customer_password.php')],
        );
    }
}
