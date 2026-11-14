<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('payment_attempts')->whereNotNull('resolved_by')->orWhereNotNull('resolved_at')->orWhereNotNull('resolution_note')->exists()) {
            throw new RuntimeException('Preserve payment resolution history; forward recovery required.');
        }
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['resolved_at', 'resolution_note']);
        });
    }
};
