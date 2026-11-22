<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_chat_notifications', function (Blueprint $table): void {
            $table->id();
            $table->char('conversation_hash', 64)->unique();
            $table->timestamp('first_sms_sent_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_chat_notifications');
    }
};
