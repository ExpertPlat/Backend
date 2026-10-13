<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_kyc_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('expert_kyc_applications')->cascadeOnDelete();
            $table->string('degree');
            $table->string('field')->nullable();
            $table->string('institution');
            $table->unsignedSmallInteger('graduation_year')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_kyc_qualifications');
    }
};
