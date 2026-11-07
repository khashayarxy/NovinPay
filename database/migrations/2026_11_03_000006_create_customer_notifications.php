<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_account_id')->constrained()->restrictOnDelete();
            $table->string('event', 40);
            $table->string('event_key', 100)->unique();
            $table->string('path', 200)->default('/account');
            $table->string('state', 16)->default('pending');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('customer_notifications')->exists()) {
            throw new RuntimeException('Notification history must be preserved during rollback.');
        }
        Schema::drop('customer_notifications');
    }
};
