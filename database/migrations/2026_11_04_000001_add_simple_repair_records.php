<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_tracking_codes', function (Blueprint $table): void {
            $table->string('code', 8)->primary();
        });
        Schema::create('repair_serial_locks', function (Blueprint $table): void {
            $table->string('serial_digest', 64)->primary();
        });
        Schema::create('repair_record_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repair_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('payload');
            $table->timestamp('created_at')->nullable();
            $table->unique(['repair_case_id', 'version']);
        });
        foreach (['update', 'delete'] as $event) {
            $body = DB::getDriverName() === 'sqlite'
                ? "BEGIN SELECT RAISE(ABORT, 'Repair revisions are immutable'); END"
                : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Repair revisions are immutable'";
            DB::unprepared("CREATE TRIGGER repair_revisions_no_{$event} BEFORE {$event} ON repair_record_revisions FOR EACH ROW {$body}");
        }
        Schema::table('repair_cases', function (Blueprint $table): void {
            $table->string('device_brand', 16)->nullable()->change();
            $table->boolean('simple_workflow')->default(false);
            $table->unsignedBigInteger('repair_price_toman')->nullable();
            $table->text('repair_notes')->nullable();
            $table->string('delivery_method', 16)->nullable();
            $table->string('delivery_carrier', 16)->nullable();
            $table->string('settlement_method', 16)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('repair_record_revisions')->exists() || DB::table('repair_tracking_codes')->exists() || DB::table('repair_cases')->where('simple_workflow', true)->exists() || DB::table('repair_cases')->whereNull('device_brand')->exists()) {
            throw new RuntimeException('Repair records and tracking references must be preserved; use a reviewed forward-compatible rollback.');
        }
        Schema::table('repair_cases', function (Blueprint $table): void {
            $table->dropColumn(['simple_workflow', 'repair_price_toman', 'repair_notes', 'delivery_method', 'delivery_carrier', 'settlement_method']);
            $table->string('device_brand', 16)->nullable(false)->change();
        });
        Schema::dropIfExists('repair_tracking_codes');
        Schema::dropIfExists('repair_serial_locks');
        Schema::dropIfExists('repair_record_revisions');
    }
};
