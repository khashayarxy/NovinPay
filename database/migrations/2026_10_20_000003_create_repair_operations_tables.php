<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_cases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('repair_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('tracking_reference', 35)->unique();
            $table->string('tracking_digest', 64)->unique();
            $table->string('intake_digest', 64)->unique();
            $table->string('phone_digest', 64)->index();
            $table->string('serial_digest', 64)->nullable()->index();
            $table->string('active_serial_digest', 64)->nullable()->unique();
            $table->string('device_brand', 16)->index();
            foreach (['customer_name', 'contact_phone', 'device_model', 'device_serial', 'issue', 'physical_condition', 'accessories'] as $field) {
                $table->text($field)->nullable($field === 'device_serial');
            }
            $table->string('status', 32)->default('received')->index();
            $table->timestamp('received_at')->index();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('repair_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repair_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            foreach (['findings', 'diagnosis', 'proposed_actions', 'internal_notes'] as $field) {
                $table->text($field)->nullable($field === 'internal_notes');
            }
            $table->timestamp('created_at');
        });
        Schema::create('repair_estimates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repair_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('repair_inspection_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('customer_summary');
            $table->unsignedBigInteger('service_cost');
            $table->unsignedBigInteger('parts_cost');
            $table->timestamp('created_at');
            $table->unique(['repair_case_id', 'revision']);
        });
        Schema::create('repair_estimate_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repair_estimate_id')->unique()->constrained()->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('channel', 24);
            $table->text('evidence');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('repair_work_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repair_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('repair_estimate_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            foreach (['work_performed', 'parts_replaced', 'internal_notes', 'completion_checks', 'final_result'] as $field) {
                $table->text($field)->nullable(! in_array($field, ['work_performed', 'parts_replaced'], true));
            }
            $table->boolean('is_completion')->default(false);
            $table->timestamp('created_at');
        });
        Schema::create('repair_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repair_case_id')->unique()->constrained()->restrictOnDelete();
            $table->string('outcome', 32);
            $table->text('outcome_notes');
            $table->text('recipient_name');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('delivered_at');
            $table->unsignedBigInteger('service_cost')->nullable();
            $table->unsignedBigInteger('parts_cost')->nullable();
            $table->string('confirmation_method', 24)->nullable();
            $table->text('confirmation_reference')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('repair_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repair_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('subject_type', 24)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('created_at');
            $table->index(['repair_case_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['repair_events', 'repair_deliveries', 'repair_work_entries', 'repair_estimate_decisions', 'repair_estimates', 'repair_inspections', 'repair_cases'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
