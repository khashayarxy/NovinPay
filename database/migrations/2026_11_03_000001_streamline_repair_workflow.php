<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_cases', function (Blueprint $table): void {
            $table->string('inbound_method', 24)->default('in_person');
            $table->unsignedInteger('inbound_shipping_cost')->default(0);
        });
        Schema::table('repair_estimates', function (Blueprint $table): void {
            $table->unsignedInteger('diagnosis_fee')->default(0);
        });
        Schema::create('repair_contact_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repair_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('repair_estimate_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('note');
            $table->timestamp('created_at');
            $table->index(['repair_case_id', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('repair_contact_attempts')->exists()
            || DB::table('repair_estimates')->where('diagnosis_fee', '>', 0)->exists()
            || DB::table('repair_cases')->where('inbound_method', '<>', 'in_person')->orWhere('inbound_shipping_cost', '>', 0)->exists()) {
            throw new RuntimeException('Repair pricing or shipping history must be preserved.');
        }
        Schema::dropIfExists('repair_contact_attempts');
        Schema::table('repair_estimates', fn (Blueprint $table) => $table->dropColumn('diagnosis_fee'));
        Schema::table('repair_cases', fn (Blueprint $table) => $table->dropColumn(['inbound_method', 'inbound_shipping_cost']));
    }
};
