<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_availability_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expert_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone', 64)->default('UTC');
            $table->json('service_modes')->nullable();
            $table->json('weekly_schedule')->nullable();
            $table->json('blackout_dates')->nullable();
            $table->unsignedSmallInteger('max_active_requests')->default(3);
            $table->unsignedSmallInteger('response_time_hours')->default(48);
            $table->boolean('accepting_new_requests')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_availability_settings');
    }
};
