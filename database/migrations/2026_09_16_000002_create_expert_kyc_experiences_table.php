<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_kyc_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('expert_kyc_applications')->cascadeOnDelete();
            $table->string('job_title');
            $table->string('organization');
            $table->string('from_month', 7)->nullable();
            $table->string('to_month', 7)->nullable();
            $table->boolean('is_current')->default(false);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_kyc_experiences');
    }
};
