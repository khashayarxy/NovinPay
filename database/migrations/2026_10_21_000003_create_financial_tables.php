<?php

use App\Support\DatabaseTriggerAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Financial migration supports SQLite, MySQL 8 and verified MariaDB 10.6.28+.');
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $version = DB::selectOne('SELECT VERSION() AS version')->version;
            $maria = str_contains($version, 'MariaDB');
            preg_match('/(?:5\.5\.5-)?(\d+\.\d+\.\d+)/', $version, $match);
            $number = $match[1] ?? '0.0.0';
            if ($maria && (version_compare($number, '10.6.28', '<') || version_compare($number, '10.7.0', '>='))) {
                throw new RuntimeException('Only verified MariaDB 10.6.28+ in the 10.6 series is supported.');
            }
            if ($maria) {
                $settings = DB::selectOne('SELECT @@session.explicit_defaults_for_timestamp AS explicit_dates, @@session.check_constraint_checks AS checks_enabled, @@session.foreign_key_checks AS foreign_keys, @@default_storage_engine AS engine');
                if (! $settings->explicit_dates || ! $settings->checks_enabled || ! $settings->foreign_keys || strtoupper($settings->engine) !== 'INNODB') {
                    throw new RuntimeException('MariaDB requires explicit timestamps, CHECK/FK enforcement and InnoDB. Use the mariadb connection; never disable invariants.');
                }
            }
            if (! $maria && version_compare($number, '8.0.16', '<')) {
                throw new RuntimeException('Financial migration requires MySQL 8.0.16+ with enforced CHECK constraints.');
            }
            DatabaseTriggerAccess::verify(['financial_entries', 'financial_documents']);
        }
        Schema::create('financial_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->string('payload_digest', 64);
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('financial_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('repair_case_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('source_version');
            $table->unsignedBigInteger('source_amount_minor');
            $table->bigInteger('charged_minor');
            $table->bigInteger('paid_minor')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['customer_id', 'id']);
            $table->index(['created_at', 'id']);
        });
        Schema::create('financial_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('financial_operation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('financial_document_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reversal_of')->nullable()->unique()->constrained('financial_entries')->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('category', 20);
            $table->string('method', 12)->nullable();
            $table->string('reference_digest', 64)->nullable()->unique();
            $table->text('reference')->nullable();
            $table->text('note');
            $table->bigInteger('charge_delta')->default(0);
            $table->bigInteger('receipt_delta')->default(0);
            $table->bigInteger('expense_delta')->default(0);
            $table->timestamp('created_at');
            $table->index(['financial_document_id', 'id']);
            $table->index(['created_at', 'category', 'id'], 'finance_posted_category_index');
            $table->index(['category', 'created_at', 'id'], 'finance_category_posted_index');
            $table->index(['kind', 'created_at']);
        });

        $checks = [
            'financial_documents' => 'charged_minor >= 0 AND charged_minor <= 999999999999999 AND paid_minor >= 0 AND paid_minor <= charged_minor AND source_amount_minor > 0 AND source_amount_minor <= 999999999999999 AND version > 0 AND ((sales_order_id IS NOT NULL AND repair_case_id IS NULL) OR (sales_order_id IS NULL AND repair_case_id IS NOT NULL))',
            'financial_entries' => "ABS(charge_delta) <= 999999999999999 AND ABS(receipt_delta) <= 999999999999999 AND ABS(expense_delta) <= 999999999999999 AND ((kind IN ('charge','debit') AND financial_document_id IS NOT NULL AND charge_delta > 0 AND receipt_delta = 0 AND expense_delta = 0 AND reversal_of IS NULL) OR (kind = 'credit' AND financial_document_id IS NOT NULL AND charge_delta < 0 AND receipt_delta = 0 AND expense_delta = 0 AND reversal_of IS NULL) OR (kind = 'receipt' AND financial_document_id IS NOT NULL AND charge_delta = 0 AND receipt_delta > 0 AND expense_delta = 0 AND reference_digest IS NOT NULL AND reversal_of IS NULL) OR (kind = 'expense' AND charge_delta = 0 AND receipt_delta = 0 AND expense_delta > 0 AND reference_digest IS NOT NULL AND reversal_of IS NULL) OR (kind = 'reversal' AND reversal_of IS NOT NULL AND (charge_delta <> 0 OR receipt_delta <> 0 OR expense_delta <> 0)))",
        ];
        foreach ($checks as $table => $check) {
            if (DB::getDriverName() === 'sqlite') {
                $newCheck = preg_replace('/\b(charged_minor|paid_minor|source_amount_minor|version|sales_order_id|repair_case_id|charge_delta|receipt_delta|expense_delta|kind|financial_document_id|reversal_of|reference_digest)\b/', 'NEW.$1', $check);
                foreach (['INSERT', 'UPDATE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_check_{$event} BEFORE {$event} ON {$table} WHEN NOT ({$newCheck}) BEGIN SELECT RAISE(ABORT, 'Invalid financial invariant'); END");
                }
            } else {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_invariant CHECK ({$check})");
            }
        }
        foreach (['UPDATE', 'DELETE'] as $event) {
            $body = DB::getDriverName() === 'sqlite' ? "BEGIN SELECT RAISE(ABORT, 'Financial history is immutable'); END" : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financial history is immutable'";
            DB::unprepared("CREATE TRIGGER financial_entries_no_{$event} BEFORE {$event} ON financial_entries FOR EACH ROW {$body}");
        }
        $immutable = ['public_id', 'customer_id', 'sales_order_id', 'repair_case_id', 'source_version', 'source_amount_minor', 'created_by', 'created_at'];
        $changed = implode(' OR ', array_map(fn ($field) => DB::getDriverName() === 'sqlite' ? "NEW.{$field} IS NOT OLD.{$field}" : "NOT (NEW.{$field} <=> OLD.{$field})", $immutable));
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER financial_document_identity BEFORE UPDATE ON financial_documents WHEN {$changed} BEGIN SELECT RAISE(ABORT, 'Financial identity is immutable'); END");
        } else {
            DB::unprepared("CREATE TRIGGER financial_document_identity BEFORE UPDATE ON financial_documents FOR EACH ROW BEGIN IF {$changed} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financial identity is immutable'; END IF; END");
        }
    }

    public function down(): void
    {
        // DDL rollback irreversibly removes this phase's financial data only.
        foreach (['financial_entries', 'financial_documents', 'financial_operations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
