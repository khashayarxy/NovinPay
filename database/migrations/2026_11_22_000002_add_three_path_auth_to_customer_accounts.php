<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->string('phone_digest', 64)->nullable()->change();
            $table->text('phone')->nullable()->change();
            $table->char('email_digest', 64)->nullable()->unique()->after('phone');
            $table->text('email')->nullable()->after('email_digest');
            $table->timestamp('email_verified_at')->nullable()->after('verified_at');
            $table->char('google_id_digest', 64)->nullable()->unique()->after('email');
        });

        Schema::create('customer_email_challenges', function (Blueprint $table): void {
            $table->id();
            $table->char('email_digest', 64)->unique();
            $table->text('email');
            $table->uuid('challenge_id')->nullable()->unique();
            $table->string('code_hash')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('resend_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_email_challenges');

        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->dropUnique(['email_digest']);
            $table->dropUnique(['google_id_digest']);
            $table->dropColumn(['email_digest', 'email', 'email_verified_at', 'google_id_digest']);
        });
    }
};
