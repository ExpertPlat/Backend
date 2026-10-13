<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->text('phone')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('language', 16)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
        Schema::create('user_profile_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->longText('snapshot');
            $table->json('changed_fields');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'version']);
        });
        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->json('contact_channels');
            $table->boolean('ai_assistance_enabled')->default(false);
            $table->boolean('recording_preference')->default(false);
            $table->string('context_visibility', 16)->default('private');
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
        Schema::create('specialized_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('domain', 50);
            $table->char('country', 2);
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('current_version')->default(1);
            $table->boolean('allow_case_reuse')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'updated_at', 'id'], 'contexts_owner_status_updated');
            $table->index(['user_id', 'domain', 'status'], 'contexts_owner_domain_status');
        });
        Schema::create('specialized_context_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('context_id')->constrained('specialized_contexts')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->longText('payload');
            $table->string('conflict_status', 16)->default('none');
            $table->unsignedInteger('supersedes_version')->nullable();
            $table->text('clarification')->nullable();
            $table->timestamp('created_at');
            $table->unique(['context_id', 'version']);
        });
        Schema::create('policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('policy_key', 64);
            $table->string('purpose', 32);
            $table->string('version', 32);
            $table->string('locale', 16);
            $table->longText('content');
            $table->char('content_hash', 64);
            $table->timestamp('effective_at');
            $table->boolean('requires_reconsent')->default(true);
            $table->boolean('is_published')->default(false);
            $table->timestamp('created_at');
            $table->unique(['policy_key', 'version', 'locale']);
            $table->index(['purpose', 'locale', 'is_published', 'effective_at'], 'policies_effective');
        });
        Schema::create('user_consent_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 32);
            $table->foreignId('policy_version_id')->constrained()->restrictOnDelete();
            $table->string('decision', 16);
            $table->foreignId('previous_record_id')->nullable()->constrained('user_consent_records')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->string('source', 16)->default('api');
            $table->uuid('request_id');
            $table->index(['user_id', 'purpose', 'decided_at', 'id'], 'consents_owner_purpose_time');
            $table->index(['user_id', 'purpose', 'id'], 'consents_latest_purpose');
        });
        Schema::create('data_rights_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->string('scope', 32)->default('account');
            $table->string('status', 16)->default('requested');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('identity_confirmed_at');
            $table->timestamp('requested_at');
            $table->timestamp('due_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->json('outcome')->nullable();
            $table->string('artifact_disk', 64)->nullable();
            $table->string('artifact_path', 512)->nullable();
            $table->char('artifact_checksum', 64)->nullable();
            $table->unsignedBigInteger('artifact_size')->nullable();
            $table->timestamp('artifact_expires_at')->nullable();
            $table->uuid('processing_token')->nullable();
            $table->timestamp('processing_lease_until')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'type', 'status'], 'rights_owner_type_status');
            $table->index(['user_id', 'requested_at', 'id'], 'rights_owner_requested');
            $table->index(['status', 'due_at', 'id'], 'rights_status_due');
            $table->index(['status', 'processing_lease_until'], 'rights_processing_lease');
            $table->index('artifact_expires_at');
        });
        Schema::create('data_rights_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('data_rights_requests')->restrictOnDelete();
            $table->string('record_class', 64);
            $table->string('scope_reference', 64)->default('account');
            $table->string('status', 24)->default('pending');
            $table->string('reason_code', 64)->nullable();
            $table->timestamp('retain_until')->nullable();
            $table->timestamp('review_at')->nullable();
            $table->unique(['request_id', 'record_class']);
            $table->index(['request_id', 'status']);
        });
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('actor_token_id')->nullable();
            $table->string('action', 80);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('previous_state', 64)->nullable();
            $table->string('new_state', 64)->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->uuid('request_id');
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->index(['subject_type', 'subject_id', 'occurred_at'], 'audit_subject_time');
            $table->index(['actor_type', 'actor_id', 'occurred_at'], 'audit_actor_time');
        });
        Schema::create('idempotency_records', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id');
            $table->string('operation', 150);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->longText('response_body');
            $table->unsignedSmallInteger('response_status');
            $table->timestamp('expires_at')->index();
            $table->unique(['actor_type', 'actor_id', 'operation', 'key_hash'], 'idempotency_actor_operation_key');
        });
    }

    public function down(): void
    {
        foreach (['idempotency_records', 'audit_events', 'data_rights_request_items', 'data_rights_requests', 'user_consent_records', 'policy_versions', 'specialized_context_versions', 'specialized_contexts', 'user_preferences', 'user_profile_versions', 'user_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
