<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_identity_keys', function (Blueprint $table): void {
            $table->string('phone_digest', 64)->primary();
        });
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->text('name');
            $table->text('contact_phone');
            $table->string('name_digest', 64)->index();
            $table->string('phone_digest', 64)->index();
            $table->string('identity_digest', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->foreignId('merged_into_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('consultations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('submission_digest', 64)->unique();
            $table->text('customer_name');
            $table->text('contact_phone');
            $table->string('phone_digest', 64)->index();
            $table->string('topic', 24)->index();
            $table->text('device_info')->nullable();
            $table->text('question');
            $table->string('status', 24)->default('new')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        foreach (['repair_requests', 'repair_cases'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            });
        }
        Schema::create('customer_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 24);
            $table->text('body');
            $table->timestamp('created_at');
        });
        Schema::create('consultation_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('channel', 24)->nullable();
            $table->string('outcome', 24)->nullable();
            $table->text('body');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_entries');
        Schema::dropIfExists('customer_notes');
        foreach (['repair_cases', 'repair_requests'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('customer_id'));
        }
        Schema::dropIfExists('consultations');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('customer_identity_keys');
    }
};
