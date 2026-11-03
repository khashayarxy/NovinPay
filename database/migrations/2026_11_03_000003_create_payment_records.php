<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $preservedTriggers = DB::getDriverName() === 'sqlite' ? DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name IN ('financial_entries','financial_operations')") : [];
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('financial_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_account_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('amount_rial');
            $table->unsignedInteger('document_version');
            $table->string('merchant_digest', 64);
            $table->string('track_id', 20)->nullable();
            $table->string('state', 24)->default('requesting');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['merchant_digest', 'track_id']);
            $table->index(['state', 'retry_at']);
        });
        Schema::create('payment_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('financial_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->text('path');
            $table->string('file_hash', 64)->unique();
            $table->text('bank_reference')->nullable();
            $table->string('state', 16)->default('pending');
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('financial_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_receipt_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->string('method', 16);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('allocated_minor');
            $table->text('provider_paid_at')->nullable();
            $table->string('reference_digest', 64)->unique();
            $table->text('reference');
            $table->timestamp('created_at');
        });
        Schema::create('payment_credit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('financial_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_delta');
            $table->string('reference_digest', 64)->nullable()->unique();
            $table->text('reason');
            $table->text('reference')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_attempt_id')->constrained()->restrictOnDelete();
            $table->string('event', 40);
            $table->timestamp('created_at');
        });
        // System-origin entries are explicit. Existing staff receipts retain their actor.
        Schema::table('financial_operations', fn (Blueprint $table) => $table->foreignId('actor_id')->nullable()->change());
        Schema::table('financial_entries', function (Blueprint $table): void {
            $table->foreignId('actor_id')->nullable()->change();
            $table->foreignId('payment_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        foreach ($preservedTriggers as $trigger) {
            if (! DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger->name)->exists()) {
                DB::unprepared($trigger->sql);
            }
        }
        $checks = [
            'payment_attempts' => "amount_minor > 0 AND amount_minor <= 999999999999999 AND amount_rial > 0 AND amount_minor = amount_rial * 10 AND state IN ('requesting','pending','processing','verified','settled','failed','uncertain','review')",
            'payment_transactions' => "amount_minor > 0 AND amount_minor <= 999999999999999 AND allocated_minor >= 0 AND allocated_minor <= amount_minor AND ((method = 'gateway' AND payment_attempt_id IS NOT NULL AND actor_id IS NULL AND payment_receipt_id IS NULL) OR (method = 'card_transfer' AND payment_receipt_id IS NOT NULL AND actor_id IS NOT NULL AND payment_attempt_id IS NULL) OR (method = 'in_store' AND actor_id IS NOT NULL AND payment_attempt_id IS NULL AND payment_receipt_id IS NULL))",
            'payment_credit_events' => 'amount_delta <> 0 AND ABS(amount_delta) <= 999999999999999 AND (amount_delta > 0 OR actor_id IS NOT NULL)',
            'financial_entries' => '(actor_id IS NOT NULL OR payment_transaction_id IS NOT NULL)',
            'financial_operations' => "(actor_id IS NOT NULL OR action = 'gateway.receipt')",
        ];
        foreach ($checks as $name => $check) {
            if (DB::getDriverName() === 'sqlite') {
                $new = preg_replace('/\b(amount_minor|amount_rial|allocated_minor|state|method|payment_attempt_id|payment_receipt_id|actor_id|payment_transaction_id|amount_delta|action)\b/', 'NEW.$1', $check);
                foreach (['insert', 'update'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$name}_payment_check_{$event} BEFORE {$event} ON {$name} WHEN NOT ({$new}) BEGIN SELECT RAISE(ABORT, 'Invalid payment invariant'); END");
                }
            } else {
                DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_payment_invariant CHECK ({$check})");
            }
        }
        foreach (['payment_transactions', 'payment_credit_events', 'payment_events'] as $name) {
            foreach (['update', 'delete'] as $event) {
                $body = DB::getDriverName() === 'sqlite' ? "BEGIN SELECT RAISE(ABORT, 'Payment history is immutable'); END" : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment history is immutable'";
                DB::unprepared("CREATE TRIGGER {$name}_no_{$event} BEFORE {$event} ON {$name} FOR EACH ROW {$body}");
            }
        }
    }

    public function down(): void
    {
        foreach (['payment_events', 'payment_credit_events', 'payment_transactions', 'payment_receipts', 'payment_attempts'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Payment history exists; use a separately reviewed data-preserving recovery plan.');
            }
        }
        if (DB::table('financial_entries')->whereNull('actor_id')->exists() || DB::table('financial_operations')->whereNull('actor_id')->exists()) {
            throw new RuntimeException('System-origin financial history must be preserved.');
        }
        foreach (['financial_entries', 'financial_operations'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['insert', 'update'] as $event) {
                    DB::unprepared("DROP TRIGGER IF EXISTS {$table}_payment_check_{$event}");
                }
            } else {
                $drop = DB::getDriverName() === 'mariadb' ? 'DROP CONSTRAINT' : 'DROP CHECK';
                DB::statement("ALTER TABLE {$table} {$drop} {$table}_payment_invariant");
            }
        }
        $triggers = DB::getDriverName() === 'sqlite' ? DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name IN ('financial_entries','financial_operations')") : [];
        Schema::table('financial_entries', function (Blueprint $table): void {
            $table->dropUnique(['payment_transaction_id']);
            $table->dropConstrainedForeignId('payment_transaction_id');
            $table->foreignId('actor_id')->nullable(false)->change();
        });
        Schema::table('financial_operations', fn (Blueprint $table) => $table->foreignId('actor_id')->nullable(false)->change());
        foreach ($triggers as $trigger) {
            if (! DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger->name)->exists()) {
                DB::unprepared($trigger->sql);
            }
        }
        foreach (['payment_events', 'payment_credit_events', 'payment_transactions', 'payment_receipts', 'payment_attempts'] as $table) {
            Schema::drop($table);
        }
    }
};
