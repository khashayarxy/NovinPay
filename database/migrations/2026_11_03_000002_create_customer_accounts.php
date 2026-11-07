<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('phone_digest', 64)->unique();
            $table->text('phone');
            $table->text('name')->nullable();
            $table->timestamp('verified_at');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('session_version')->default(1);
            $table->timestamps();
        });
        Schema::create('customer_login_challenges', function (Blueprint $table): void {
            $table->id();
            $table->string('phone_digest', 64)->unique();
            $table->text('phone');
            $table->uuid('challenge_id')->nullable()->unique();
            $table->string('code_hash')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('resend_at')->nullable();
        });
        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_account_id')->constrained()->restrictOnDelete();
            $table->text('recipient');
            $table->text('phone');
            $table->text('province');
            $table->text('city');
            $table->text('address');
            $table->text('postal_code');
            $table->timestamps();
        });
        foreach (['repair_requests', 'repair_cases'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('customer_account_id')->nullable()->constrained()->restrictOnDelete());
        }
    }

    public function down(): void
    {
        foreach (['customer_accounts', 'customer_addresses', 'customer_login_challenges'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Customer identity and address data must be preserved.');
            }
        }
        foreach (['repair_cases', 'repair_requests'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('customer_account_id'));
        }
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customer_login_challenges');
        Schema::dropIfExists('customer_accounts');
    }
};
