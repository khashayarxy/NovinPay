<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('summary')->nullable();
            $table->text('description')->nullable();
            $table->string('category')->index();
            $table->string('condition')->index();
            $table->unsignedBigInteger('price')->nullable();
            $table->string('stock_status')->default('in_stock')->index();
            $table->string('image_path')->nullable();
            $table->boolean('is_sample')->default(false);
            $table->json('specifications')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
