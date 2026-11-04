<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_customer_owners', function (Blueprint $table): void {
            $table->string('owner_id', 64)->primary();
            $table->foreignId('customer_account_id')->constrained()->restrictOnDelete();
            $table->timestamp('bound_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('chat_customer_owners')->exists()) {
            throw new RuntimeException('Chat ownership records must be preserved.');
        }
        Schema::dropIfExists('chat_customer_owners');
    }
};
