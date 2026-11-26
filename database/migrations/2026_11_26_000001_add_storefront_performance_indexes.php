<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasIndex('products', 'products_published_latest_idx')) {
                $table->index(['is_published', 'created_at', 'id'], 'products_published_latest_idx');
            }
            if (! Schema::hasIndex('products', 'products_published_category_latest_idx')) {
                $table->index(['is_published', 'category', 'created_at', 'id'], 'products_published_category_latest_idx');
            }
            if (! Schema::hasIndex('products', 'products_published_price_idx')) {
                $table->index(['is_published', 'is_sample', 'price'], 'products_published_price_idx');
            }
        });

        Schema::table('inventory_items', function (Blueprint $table): void {
            if (! Schema::hasIndex('inventory_items', 'inventory_items_condition_product_idx')) {
                $table->index(['condition', 'product_id'], 'inventory_items_condition_product_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            if (Schema::hasIndex('inventory_items', 'inventory_items_condition_product_idx')) {
                $table->dropIndex('inventory_items_condition_product_idx');
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            foreach ([
                'products_published_price_idx',
                'products_published_category_latest_idx',
                'products_published_latest_idx',
            ] as $indexName) {
                if (Schema::hasIndex('products', $indexName)) {
                    $table->dropIndex($indexName);
                }
            }
        });
    }
};
