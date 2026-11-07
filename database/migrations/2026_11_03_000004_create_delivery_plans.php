<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('financial_document_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_account_id')->constrained()->restrictOnDelete();
            $table->string('method', 16)->default('pickup');
            $table->string('requested_method', 16)->nullable();
            $table->text('address_snapshot')->nullable();
            $table->text('requested_address')->nullable();
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->string('state', 16)->default('planned');
            $table->unsignedInteger('version')->default(1);
            $table->text('tracking')->nullable();
            $table->timestamps();
        });
        Schema::create('delivery_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 32);
            $table->unsignedInteger('version');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('delivery_events')->exists() || DB::table('delivery_plans')->exists()) {
            throw new RuntimeException('Delivery history requires a reviewed data-preserving rollback.');
        }
        Schema::drop('delivery_events');
        Schema::drop('delivery_plans');
    }
};
