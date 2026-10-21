<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('payload_digest', 64);
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('name', 150)->index();
            $table->string('condition', 8);
            $table->boolean('serialized');
            $table->unsignedInteger('on_hand')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedInteger('dispatched')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(0);
            $table->boolean('is_low_stock')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['condition', 'id']);
            $table->index(['is_low_stock', 'id']);
        });
        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('status', 12)->default('draft');
            $table->string('currency', 5)->default('TOMAN');
            $table->decimal('total', 15, 2)->default('0.00');
            $table->unsignedInteger('version')->default(1);
            $table->text('fulfillment_info')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index(['customer_id', 'id']);
        });
        Schema::create('sales_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->string('sku', 64);
            $table->string('description', 150);
            $table->string('condition', 8);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('revision');
            $table->boolean('is_current')->default(true);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->unique(['sales_order_id', 'inventory_item_id', 'revision'], 'order_item_revision_unique');
            $table->index(['sales_order_id', 'is_current', 'id'], 'order_current_lines_index');
        });
        Schema::create('inventory_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->string('serial', 100)->unique();
            $table->string('state', 12)->default('on_hand');
            $table->timestamp('created_at');
            $table->index(['inventory_item_id', 'state', 'id']);
        });
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 12);
            $table->foreignId('commerce_operation_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->index(['sales_order_line_id', 'status']);
            $table->unique(['commerce_operation_id', 'inventory_unit_id'], 'reservation_operation_unit_unique');
        });
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('commerce_operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 16);
            $table->integer('on_hand_delta');
            $table->integer('reserved_delta');
            $table->integer('dispatched_delta');
            $table->unsignedInteger('on_hand_after');
            $table->unsignedInteger('reserved_after');
            $table->unsignedInteger('dispatched_after');
            $table->json('unit_ids');
            $table->text('reason');
            $table->timestamp('created_at');
            $table->unique(['commerce_operation_id', 'inventory_item_id']);
            $table->index(['inventory_item_id', 'id']);
            $table->index(['sales_order_id', 'id']);
            $table->index(['kind', 'id']);
        });
        Schema::create('sales_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('commerce_operation_id')->unique()->constrained()->restrictOnDelete();
            $table->string('action', 16);
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12);
            $table->text('reason');
            $table->json('line_snapshot');
            $table->timestamp('created_at');
            $table->index(['sales_order_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['sales_order_events', 'stock_movements', 'stock_reservations', 'inventory_units', 'sales_order_lines', 'sales_orders', 'inventory_items', 'commerce_operations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
