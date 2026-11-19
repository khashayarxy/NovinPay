<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_carts', function (Blueprint $table): void {
            $table->string('scope', 64)->primary();
            $table->json('state');
        });

        Schema::create('legacy_cart_holds', function (Blueprint $table): void {
            $table->uuid('reference')->primary();
            $table->text('payload');
            $table->string('session_digest', 64);
            $table->string('device_scope', 64)->nullable();
            $table->timestamp('migrated_at')->nullable();
            $table->timestamp('captured_at');
        });

        Schema::create('cart_purchase_snapshots', function (Blueprint $table): void {
            $table->foreignId('sales_order_id')->primary()->constrained()->cascadeOnDelete();
            $table->json('sources');
            $table->text('delivery')->nullable();
            $table->timestamp('consumed_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('shopping_carts')->exists() || DB::table('cart_purchase_snapshots')->exists() || DB::table('legacy_cart_holds')->exists()) {
            throw new LogicException('Keep persistent carts and purchase snapshots when rolling back application code.');
        }

        Schema::drop('cart_purchase_snapshots');
        Schema::drop('legacy_cart_holds');
        Schema::drop('shopping_carts');
    }
};
