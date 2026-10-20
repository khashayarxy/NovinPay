<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('tracking_reference', 35)->unique();
            $table->string('submission_digest', 64)->unique();
            $table->text('customer_name');
            $table->text('contact_phone');
            $table->string('phone_digest', 64)->index();
            $table->string('device_brand', 16);
            $table->text('device_model');
            $table->text('issue');
            $table->string('status', 32)->default('online_submitted');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_requests');
    }
};
