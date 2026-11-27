<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_accounts') && ! Schema::hasColumn('customer_accounts', 'email_notifications_enabled')) {
            Schema::table('customer_accounts', function (Blueprint $table): void {
                $table->boolean('email_notifications_enabled')->default(true)->after('verified_at');
            });
        }

        if (! Schema::hasTable('customer_devices')) {
            Schema::create('customer_devices', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
                $table->string('device_brand', 50);
                $table->string('device_model', 120);
                $table->text('device_serial')->nullable();
                $table->string('fingerprint', 64);
                $table->string('source_type', 30)->default('repair');
                $table->string('source_ref', 64)->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->unique(['customer_account_id', 'fingerprint'], 'customer_devices_account_fingerprint_unique');
                $table->index(['customer_account_id', 'last_used_at'], 'customer_devices_account_recent_idx');
            });
        }

        if (Schema::hasTable('product_feedback') && ! Schema::hasColumn('product_feedback', 'helpful_count')) {
            Schema::table('product_feedback', function (Blueprint $table): void {
                $table->unsignedInteger('helpful_count')->default(0)->after('staff_reply');
            });
        }

        if (! Schema::hasTable('product_feedback_helpful_votes')) {
            Schema::create('product_feedback_helpful_votes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_feedback_id')->constrained('product_feedback')->cascadeOnDelete();
                $table->foreignId('customer_account_id')->nullable()->constrained('customer_accounts')->nullOnDelete();
                $table->string('voter_digest', 64);
                $table->timestamps();

                $table->unique(['product_feedback_id', 'voter_digest'], 'pf_helpful_votes_feedback_voter_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_feedback_helpful_votes');

        if (Schema::hasTable('product_feedback') && Schema::hasColumn('product_feedback', 'helpful_count')) {
            Schema::table('product_feedback', function (Blueprint $table): void {
                $table->dropColumn('helpful_count');
            });
        }

        Schema::dropIfExists('customer_devices');

        if (Schema::hasTable('customer_accounts') && Schema::hasColumn('customer_accounts', 'email_notifications_enabled')) {
            Schema::table('customer_accounts', function (Blueprint $table): void {
                $table->dropColumn('email_notifications_enabled');
            });
        }
    }
};
