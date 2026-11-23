<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_accounts', 'national_code')) {
            Schema::table('customer_accounts', function (Blueprint $table): void {
                $table->text('national_code')->nullable()->after('name');
            });
        }

        if (! Schema::hasColumn('customer_addresses', 'latitude')) {
            Schema::table('customer_addresses', function (Blueprint $table): void {
                $table->decimal('latitude', 10, 7)->nullable()->after('postal_code');
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_addresses', 'latitude')) {
            Schema::table('customer_addresses', function (Blueprint $table): void {
                $table->dropColumn(['latitude', 'longitude']);
            });
        }

        if (Schema::hasColumn('customer_accounts', 'national_code')) {
            Schema::table('customer_accounts', function (Blueprint $table): void {
                $table->dropColumn('national_code');
            });
        }
    }
};
