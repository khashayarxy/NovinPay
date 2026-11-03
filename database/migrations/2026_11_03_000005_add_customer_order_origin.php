<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->change();
            $table->foreignId('customer_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('checkout_key')->nullable()->unique();
            $table->string('checkout_digest', 64)->nullable();
        });
        Schema::table('sales_order_events', function (Blueprint $table): void {
            $table->foreignId('actor_id')->nullable()->change();
            $table->foreignId('commerce_operation_id')->nullable()->change();
            $table->foreignId('customer_account_id')->nullable()->constrained()->restrictOnDelete();
        });
        $checks = ['sales_orders' => '(created_by IS NOT NULL OR (customer_account_id IS NOT NULL AND checkout_key IS NOT NULL AND checkout_digest IS NOT NULL))', 'sales_order_events' => "((actor_id IS NOT NULL AND commerce_operation_id IS NOT NULL) OR (actor_id IS NULL AND commerce_operation_id IS NULL AND customer_account_id IS NOT NULL AND action = 'save' AND to_status = 'draft'))"];
        foreach ($checks as $name => $check) {
            if (DB::getDriverName() === 'sqlite') {
                $new = preg_replace('/\b(created_by|customer_account_id|checkout_key|checkout_digest|actor_id|commerce_operation_id|action|to_status)\b/', 'NEW.$1', $check);
                foreach (['insert', 'update'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$name}_origin_{$event} BEFORE {$event} ON {$name} WHEN NOT ({$new}) BEGIN SELECT RAISE(ABORT, 'Invalid order origin'); END");
                }
            } else {
                DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_origin CHECK ({$check})");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('sales_orders')->whereNotNull('customer_account_id')->exists() || DB::table('sales_order_events')->whereNotNull('customer_account_id')->exists()) {
            throw new RuntimeException('Customer orders must be preserved during rollback.');
        }
        foreach (['sales_orders', 'sales_order_events'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['insert', 'update'] as $event) {
                    DB::unprepared("DROP TRIGGER IF EXISTS {$table}_origin_{$event}");
                }
            } else {
                $drop = DB::getDriverName() === 'mariadb' ? 'DROP CONSTRAINT' : 'DROP CHECK';
                DB::statement("ALTER TABLE {$table} {$drop} {$table}_origin");
            }
        }
        Schema::table('sales_order_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_account_id');
            $table->foreignId('actor_id')->nullable(false)->change();
            $table->foreignId('commerce_operation_id')->nullable(false)->change();
        });
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_account_id');
            $table->dropUnique(['checkout_key']);
            $table->dropColumn(['checkout_key', 'checkout_digest']);
            $table->foreignId('created_by')->nullable(false)->change();
        });
    }
};
