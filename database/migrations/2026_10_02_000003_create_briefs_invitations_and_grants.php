<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_briefs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $t->foreignId('scope_id')->constrained('case_service_scopes')->restrictOnDelete();
            $t->unsignedInteger('brief_version');
            $t->unsignedInteger('case_version');
            $t->foreignId('intake_version_id')->constrained('case_intake_versions')->restrictOnDelete();
            $t->foreignId('catalog_version_id')->constrained('catalog_versions')->restrictOnDelete();
            $t->char('content_hash', 64);
            $t->longText('payload');
            $t->json('context_snapshot_ids');
            $t->json('document_version_ids');
            $t->timestamp('approved_at');
            $t->timestamp('created_at');
            $t->unique(['scope_id', 'brief_version'], 'case_brief_scope_version');
        });
        Schema::create('case_invitations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $t->foreignId('scope_id')->constrained('case_service_scopes')->restrictOnDelete();
            $t->foreignId('expert_id')->constrained('experts')->restrictOnDelete();
            $t->foreignId('matching_run_id')->constrained('matching_runs')->restrictOnDelete();
            $t->foreignId('matching_candidate_id')->constrained('matching_run_candidates')->restrictOnDelete();
            $t->foreignId('brief_id')->constrained('case_briefs')->restrictOnDelete();
            $t->string('jurisdiction_code', 100);
            $t->string('status', 16)->default('pending');
            $t->string('expiry_reason', 24)->nullable();
            $t->unsignedInteger('version')->default(1);
            // Non-null only while pending/accepted: a portable (MySQL/SQLite) "one active invitation per scope+expert" guard.
            $t->string('active_key', 64)->nullable()->unique();
            $t->timestamp('expires_at');
            $t->timestamp('expiring_notified_at')->nullable();
            $t->timestamp('responded_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('proposal_due_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('decline_reason_code', 32)->nullable();
            $t->text('decline_note')->nullable();
            $t->timestamps();
            $t->index(['expert_id', 'status', 'id'], 'case_invitation_expert');
            $t->index(['case_id', 'scope_id', 'status'], 'case_invitation_case_scope');
            $t->index(['status', 'expires_at'], 'case_invitation_expiry');
            $t->index(['status', 'proposal_due_at'], 'case_invitation_proposal_due');
        });
        Schema::create('case_access_grants', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('invitation_id')->constrained('case_invitations')->restrictOnDelete();
            $t->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $t->foreignId('expert_id')->constrained('experts')->restrictOnDelete();
            $t->foreignId('document_id')->constrained('case_documents')->restrictOnDelete();
            $t->foreignId('document_version_id')->constrained('case_document_versions')->restrictOnDelete();
            $t->foreignId('brief_id')->constrained('case_briefs')->restrictOnDelete();
            $t->foreignId('granted_by_user_id')->constrained('users')->restrictOnDelete();
            $t->string('status', 16)->default('active');
            $t->string('active_key', 64)->nullable()->unique();
            $t->timestamp('granted_at');
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoke_reason', 32)->nullable();
            $t->timestamps();
            $t->index(['invitation_id', 'status'], 'case_grant_invitation');
            $t->index(['expert_id', 'status'], 'case_grant_expert');
        });
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->string('type');
                $t->morphs('notifiable');
                $t->text('data');
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('case_access_grants');
        Schema::dropIfExists('case_invitations');
        Schema::dropIfExists('case_briefs');
    }
};
