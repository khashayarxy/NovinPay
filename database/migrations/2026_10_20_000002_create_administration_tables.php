<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('role', 24)->default('staff');
            // The one protected owner occupies slot 1; staff slots are NULL.
            $table->unsignedTinyInteger('owner_slot')->nullable()->unique();
            $table->boolean('is_active')->default(false);
            $table->json('permissions')->nullable();
            $table->unsignedInteger('session_version')->default(1);
            $table->boolean('must_change_password')->default(true);
            $table->timestamps();
        });

        Schema::create('admin_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('action', 64)->index();
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activities');
        Schema::dropIfExists('admin_accounts');
    }
};
