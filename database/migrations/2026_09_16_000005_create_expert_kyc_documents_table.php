<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('expert_kyc_applications')->cascadeOnDelete();
            $table->string('document_type', 32);
            $table->foreignId('qualification_id')->nullable()->constrained('expert_kyc_qualifications')->cascadeOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained('expert_kyc_credentials')->cascadeOnDelete();
            $table->string('disk', 64);
            $table->string('path', 512);
            $table->string('original_name');
            $table->string('extension', 16);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size');
            $table->char('checksum', 64);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['application_id', 'document_type']);
            $table->index(['disk', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_kyc_documents');
    }
};
