<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sprint 4 (docs/sprint4/CONTRACT.md): additive only. No existing table is altered. Money is integer minor units + ISO currency. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $t->foreignId('scope_id')->constrained('case_service_scopes')->restrictOnDelete();
            $t->foreignId('invitation_id')->unique()->constrained('case_invitations')->restrictOnDelete();
            $t->foreignId('expert_id')->constrained('experts')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('brief_id')->constrained('case_briefs')->restrictOnDelete();
            $t->string('status', 20)->default('submitted');
            $t->unsignedInteger('current_version_no')->default(1);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('valid_until');
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->index(['case_id', 'status'], 'proposal_case');
            $t->index(['expert_id', 'status', 'id'], 'proposal_expert');
            $t->index(['status', 'valid_until'], 'proposal_expiry');
        });
        Schema::create('proposal_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('proposal_id')->constrained('proposals')->restrictOnDelete();
            $t->unsignedInteger('version_no');
            $t->longText('content');
            $t->char('content_hash', 64);
            $t->char('currency', 3);
            $t->unsignedBigInteger('total_amount_minor');
            $t->unsignedSmallInteger('consultation_minutes')->nullable();
            $t->timestamp('valid_until');
            $t->timestamp('created_at');
            $t->unique(['proposal_id', 'version_no'], 'proposal_version_no');
        });
        Schema::create('proposal_milestones', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('proposal_version_id')->constrained('proposal_versions')->restrictOnDelete();
            $t->unsignedSmallInteger('sequence');
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->unsignedBigInteger('amount_minor');
            $t->timestamp('due_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['proposal_version_id', 'sequence'], 'proposal_milestone_seq');
        });
        Schema::create('proposal_change_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('proposal_id')->constrained('proposals')->restrictOnDelete();
            $t->unsignedInteger('version_no');
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->json('categories');
            $t->text('message');
            $t->string('status', 12)->default('open');
            $t->unsignedInteger('resolved_by_version')->nullable();
            $t->timestamps();
            $t->index(['proposal_id', 'status'], 'proposal_change_open');
        });
        Schema::create('proposal_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('proposal_id')->constrained('proposals')->restrictOnDelete();
            $t->unsignedInteger('version_no')->nullable();
            $t->string('type', 24);
            $t->string('actor_type', 8);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('reason_code', 32)->nullable();
            $t->timestamp('created_at');
            $t->index(['proposal_id', 'id'], 'proposal_event_order');
        });
        Schema::create('agreements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $t->foreignId('scope_id')->constrained('case_service_scopes')->restrictOnDelete();
            $t->foreignId('invitation_id')->constrained('case_invitations')->restrictOnDelete();
            $t->foreignId('proposal_id')->unique()->constrained('proposals')->restrictOnDelete();
            $t->foreignId('proposal_version_id')->constrained('proposal_versions')->restrictOnDelete();
            $t->unsignedInteger('proposal_version_no');
            $t->foreignId('brief_id')->constrained('case_briefs')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('expert_id')->constrained('experts')->restrictOnDelete();
            $t->string('status', 20)->default('awaiting_payment');
            $t->char('currency', 3);
            $t->unsignedBigInteger('total_amount_minor');
            $t->unsignedSmallInteger('consultation_minutes')->nullable();
            $t->longText('snapshot');
            $t->char('snapshot_hash', 64);
            $t->timestamp('accepted_at');
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason_code', 32)->nullable();
            $t->string('cancelled_by_type', 8)->nullable();
            $t->unsignedBigInteger('cancelled_by_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['case_id', 'status'], 'agreement_case');
            $t->index(['expert_id', 'status', 'id'], 'agreement_expert');
        });
        Schema::create('agreement_milestones', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('agreement_id')->constrained('agreements')->restrictOnDelete();
            $t->unsignedSmallInteger('sequence');
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->timestamp('due_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['agreement_id', 'sequence'], 'agreement_milestone_seq');
        });
        Schema::create('bookings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('agreement_id')->constrained('agreements')->restrictOnDelete();
            $t->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $t->foreignId('expert_id')->constrained('experts')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('status', 12)->default('held');
            $t->timestamp('start_at');
            $t->timestamp('end_at');
            $t->unsignedSmallInteger('duration_minutes');
            $t->timestamp('hold_expires_at')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason_code', 32)->nullable();
            $t->string('cancelled_by_type', 8)->nullable();
            $t->unsignedSmallInteger('reschedule_count')->default(0);
            $t->unsignedInteger('version')->default(1);
            // Non-null only while held/confirmed: portable "one active booking per agreement" guard.
            $t->string('active_key', 32)->nullable()->unique();
            $t->timestamps();
            $t->index(['expert_id', 'status', 'start_at'], 'booking_expert');
            $t->index(['agreement_id', 'status'], 'booking_agreement');
            $t->index(['status', 'hold_expires_at'], 'booking_hold_expiry');
        });
        Schema::create('booking_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $t->string('type', 16);
            $t->string('actor_type', 8);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->timestamp('from_start_at')->nullable();
            $t->timestamp('to_start_at')->nullable();
            $t->string('reason_code', 32)->nullable();
            $t->timestamp('created_at');
            $t->index(['booking_id', 'id'], 'booking_event_order');
        });
        // One row per granularity step of every held/confirmed booking. UNIQUE(expert_id, slot_start) is what makes a
        // double booking impossible on any engine. Rows are ephemeral lock markers; booking history lives in `bookings`.
        Schema::create('booking_slot_locks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('expert_id')->constrained('experts')->restrictOnDelete();
            $t->timestamp('slot_start');
            $t->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $t->unique(['expert_id', 'slot_start'], 'booking_slot_unique');
            $t->index('booking_id', 'booking_slot_booking');
        });
    }

    public function down(): void
    {
        foreach (['booking_slot_locks', 'booking_events', 'bookings', 'agreement_milestones', 'agreements', 'proposal_events', 'proposal_change_requests', 'proposal_milestones', 'proposal_versions', 'proposals'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
