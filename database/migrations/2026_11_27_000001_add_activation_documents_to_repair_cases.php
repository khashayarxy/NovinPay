<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_cases', function (Blueprint $table): void {
            $table->string('activation_docs_status', 32)->nullable()->index();
            $table->timestamp('activation_docs_requested_at')->nullable();
            $table->timestamp('activation_docs_submitted_at')->nullable();
            $table->string('activation_docs_revision_reason', 500)->nullable();
            $table->string('activation_docs_telegram_chat_id', 64)->nullable();
            $table->unsignedBigInteger('activation_docs_telegram_message_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repair_cases', function (Blueprint $table): void {
            $table->dropIndex(['activation_docs_status']);
            $table->dropColumn([
                'activation_docs_status',
                'activation_docs_requested_at',
                'activation_docs_submitted_at',
                'activation_docs_revision_reason',
                'activation_docs_telegram_chat_id',
                'activation_docs_telegram_message_id',
            ]);
        });
    }
};
