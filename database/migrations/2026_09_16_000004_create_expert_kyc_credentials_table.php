<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_kyc_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('expert_kyc_applications')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('name');
            $table->string('issuer');
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_kyc_credentials');
    }
};
