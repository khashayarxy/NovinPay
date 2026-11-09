<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->string('password')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('verified_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('customer_accounts')->whereNull('verified_at')->orWhereNotNull('password')->exists()) {
            throw new RuntimeException('Customer credentials and verification status must be retained.');
        }

        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->dropColumn(['password', 'last_login_at']);
            $table->timestamp('verified_at')->nullable(false)->change();
        });
    }
};
