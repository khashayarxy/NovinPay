<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('currency', 5)->default('TOMAN');
            $table->boolean('online_purchasing_enabled')->default(true);
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('color', 100)->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->json('product_details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn([
            'currency', 'online_purchasing_enabled', 'brand', 'model', 'color', 'warranty_months', 'product_details',
        ]));
    }
};
