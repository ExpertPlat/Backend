<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('current_intake_version_id')->nullable();
            $table->unsignedBigInteger('confirmed_assessment_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('primary_domain', 50)->nullable();
            $table->char('jurisdiction', 2)->nullable();
            $table->string('language', 16)->nullable();
            $table->string('urgency', 16)->nullable();
            $table->string('suitability', 32)->default('not_assessed');
            $table->char('title_fingerprint', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at', 'id'], 'cases_owner_updated');
            $table->index(['user_id', 'status', 'updated_at', 'id'], 'cases_owner_status_updated');
            $table->index(['status', 'submitted_at', 'id'], 'cases_status_submitted');
            $table->index(['user_id', 'title_fingerprint'], 'cases_owner_title');
        });
        Schema::create('case_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $table->string('domain', 50);
            $table->unique(['case_id', 'domain']);
            $table->index(['domain', 'case_id']);
        });
        Schema::create('case_intake_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedSmallInteger('schema_version');
            $table->longText('payload');
            $table->timestamp('created_at');
            $table->unique(['case_id', 'version']);
        });
        Schema::create('case_context_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $table->foreignId('source_context_version_id')->constrained('specialized_context_versions')->restrictOnDelete();
            $table->foreignId('policy_version_id')->constrained('policy_versions')->restrictOnDelete();
            $table->longText('payload');
            $table->json('selected_fact_keys');
            $table->timestamp('authorized_at');
            $table->timestamp('detached_at')->nullable();
            $table->index(['case_id', 'detached_at', 'id'], 'case_snapshots_active');
        });
        Schema::create('case_intake_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $table->foreignId('intake_version_id')->constrained('case_intake_versions')->restrictOnDelete();
            $table->char('input_fingerprint', 64);
            $table->string('rules_version', 64);
            $table->string('suitability', 32);
            $table->longText('result');
            $table->timestamp('created_at');
            $table->index(['case_id', 'intake_version_id', 'id'], 'case_assessments_input');
        });
        Schema::create('case_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['case_id', 'deleted_at', 'id'], 'case_documents_active');
        });
        Schema::create('case_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('case_documents')->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->text('title');
            $table->string('category', 32);
            $table->string('disk', 64);
            $table->string('path', 512)->unique();
            $table->string('mime_type', 127);
            $table->string('extension', 8);
            $table->unsignedBigInteger('size');
            $table->char('checksum', 64);
            $table->string('scan_status', 16)->default('pending');
            $table->string('scan_reason', 64)->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'version']);
            $table->index(['scan_status', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['case_document_versions', 'case_documents', 'case_intake_assessments', 'case_context_snapshots', 'case_intake_versions', 'case_domains', 'cases'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
