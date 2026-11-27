<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->string('documents_status', 24)->default('pending_submission');
            $table->string('documents_revision_reason', 500)->nullable();
            $table->timestamp('documents_submitted_at')->nullable();
            $table->timestamp('documents_reviewed_at')->nullable();
            $table->foreignId('documents_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('documents_purged_at')->nullable();
            $table->string('telegram_chat_id', 64)->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('documents_staging_dir', 191)->nullable();

            $table->index(['documents_status', 'documents_purged_at'], 'sales_orders_docs_status_purged_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropIndex('sales_orders_docs_status_purged_idx');
            $table->dropConstrainedForeignId('documents_reviewed_by');
            $table->dropColumn([
                'documents_status',
                'documents_revision_reason',
                'documents_submitted_at',
                'documents_reviewed_at',
                'documents_purged_at',
                'telegram_chat_id',
                'telegram_message_id',
                'documents_staging_dir',
            ]);
        });
    }
};
