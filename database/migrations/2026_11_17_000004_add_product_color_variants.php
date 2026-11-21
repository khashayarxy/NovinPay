<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('variant_family', 64)->nullable();
            $table->string('color_swatch', 7)->nullable();
            $table->unique(['variant_family', 'color'], 'products_family_color_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_family_color_unique');
            $table->dropColumn(['variant_family', 'color_swatch']);
        });
    }
};
