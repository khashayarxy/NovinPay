<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('review_owner_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('status', 10)->default('pending');
            $table->text('body');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('verified_buyer')->default(false);
            $table->text('staff_reply')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'review_owner_id']);
            $table->index(['product_id', 'kind', 'status', 'id'], 'product_feedback_public');
        });
        Schema::create('product_feedback_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_feedback_id')->constrained('product_feedback')->restrictOnDelete();
            $table->foreignId('customer_account_id')->constrained()->restrictOnDelete();
            $table->string('reason', 30);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['product_feedback_id', 'customer_account_id'], 'product_feedback_report_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_feedback_reports');
        Schema::dropIfExists('product_feedback');
    }
};
