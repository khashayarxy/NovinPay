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
        });

        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->char('email_digest', 64)->nullable()->after('phone');
            $table->text('email')->nullable()->after('email_digest');
            $table->timestamp('email_verified_at')->nullable()->after('verified_at');
            $table->char('google_id_digest', 64)->nullable()->after('email');
            $table->unique('email_digest', 'customer_accounts_email_digest_unique');
            $table->unique('google_id_digest', 'customer_accounts_google_id_digest_unique');
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

        if (! Schema::hasTable('customer_accounts')) {
            return;
        }

        $columns = array_values(array_filter(
            ['email_digest', 'email', 'email_verified_at', 'google_id_digest'],
            static fn (string $column): bool => Schema::hasColumn('customer_accounts', $column),
        ));

        Schema::table('customer_accounts', function (Blueprint $table) use ($columns): void {
            if (Schema::hasIndex('customer_accounts', 'customer_accounts_email_digest_unique')) {
                $table->dropUnique('customer_accounts_email_digest_unique');
            }
            if (Schema::hasIndex('customer_accounts', 'customer_accounts_google_id_digest_unique')) {
                $table->dropUnique('customer_accounts_google_id_digest_unique');
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
