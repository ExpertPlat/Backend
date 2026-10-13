<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expert_verified_scopes', function (Blueprint $table): void {
            $table->string('evidence_type', 32)->nullable();
            $table->unsignedBigInteger('evidence_id')->nullable();
            $table->foreignId('evidence_document_id')->nullable()
                ->constrained('expert_kyc_documents')->nullOnDelete();
            $table->string('verified_country', 16)->nullable();
            $table->string('regulator')->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->string('verification_source', 2048)->nullable();
            $table->string('status_checked', 32)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->date('next_review_at')->nullable();

            $table->index(['status', 'next_review_at']);
        });
    }

    public function down(): void
    {
        Schema::table('expert_verified_scopes', function (Blueprint $table): void {
            $table->dropIndex(['status', 'next_review_at']);
            $table->dropConstrainedForeignId('evidence_document_id');
            $table->dropColumn([
                'evidence_type', 'evidence_id', 'verified_country', 'regulator',
                'registration_number', 'verification_source', 'status_checked', 'checked_at', 'next_review_at',
            ]);
        });
    }
};
