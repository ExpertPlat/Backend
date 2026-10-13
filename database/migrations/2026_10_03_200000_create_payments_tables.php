<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sprint 5 (docs/sprint5/CONTRACT.md). Additive. Financial rows are never hard-deleted. Agreement.status simply gains the
// values `funded` and `funding_expired` (string column: no schema change).
return new class extends Migration
{
    public function up(): void
    {
        // One free reschedule waiver per booking (DR-089): lets the User reschedule once inside the notice window.
        Schema::table('bookings', function (Blueprint $t): void {
            $t->timestamp('free_reschedule_granted_at')->nullable();
        });

        Schema::create('stripe_accounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('expert_id')->unique()->constrained('experts')->restrictOnDelete();
            $t->string('stripe_account_id', 64)->unique();
            $t->boolean('details_submitted')->default(false);
            $t->boolean('charges_enabled')->default(false);
            $t->boolean('payouts_enabled')->default(false);
            $t->json('requirements_due')->nullable();
            $t->string('disabled_reason', 80)->nullable();
            $t->timestamp('onboarding_started_at')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
        });

        Schema::create('fundings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('agreement_id')->constrained('agreements')->restrictOnDelete();
            $t->unsignedBigInteger('milestone_id')->nullable(); // reserved for a future stage-level funding; unused in Sprint 5
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('expert_id')->constrained('experts')->restrictOnDelete();
            $t->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $t->string('status', 20)->default('awaiting_payment');
            $t->string('refund_status', 20)->default('none');
            $t->string('dispute_status', 10)->default('none');
            $t->unsignedBigInteger('gross_amount_minor');
            $t->char('currency', 3);
            $t->unsignedBigInteger('original_amount_minor');
            $t->unsignedBigInteger('discount_amount_minor')->default(0);
            $t->string('discount_reason', 120)->nullable();
            $t->string('discount_policy_ref', 80)->nullable();
            $t->unsignedSmallInteger('commission_bps');
            $t->unsignedBigInteger('platform_commission_minor');
            $t->unsignedBigInteger('expert_provisional_minor');
            $t->unsignedBigInteger('retained_commission_minor');
            $t->unsignedBigInteger('expert_remaining_minor');
            $t->unsignedBigInteger('refunded_amount_minor')->default(0);
            $t->unsignedBigInteger('refund_reserved_minor')->default(0);
            $t->timestamp('deadline_at');
            $t->string('deadline_rule', 20);
            $t->timestamp('deadline_basis_at');
            $t->timestamp('funded_at')->nullable();
            $t->timestamp('expired_at')->nullable();
            $t->timestamp('release_frozen_at')->nullable();
            $t->string('stripe_payment_intent_id', 80)->nullable()->unique();
            $t->unsignedBigInteger('provider_fee_minor')->nullable();
            $t->string('active_key', 24)->nullable()->unique();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['agreement_id', 'status'], 'funding_agreement_status');
            $t->index(['status', 'deadline_at'], 'funding_status_deadline');
            $t->index(['user_id', 'id'], 'funding_user');
        });

        Schema::create('funding_attempts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('funding_id')->constrained('fundings')->restrictOnDelete();
            $t->unsignedSmallInteger('attempt_no');
            $t->string('status', 12)->default('open');
            $t->string('stripe_checkout_session_id', 100)->unique();
            $t->string('stripe_payment_intent_id', 80)->nullable()->index();
            $t->text('checkout_url')->nullable();
            $t->timestamp('expires_at');
            $t->string('failure_code', 60)->nullable();
            $t->timestamp('provider_paid_at')->nullable();
            $t->string('active_key', 24)->nullable()->unique();
            $t->timestamps();
            $t->unique(['funding_id', 'attempt_no'], 'funding_attempt_no');
        });

        Schema::create('financial_ledger_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('funding_id')->constrained('fundings')->restrictOnDelete();
            $t->unsignedBigInteger('agreement_id');
            $t->unsignedBigInteger('case_id');
            $t->string('event_type', 40);
            $t->string('actor_type', 10)->default('system');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->unsignedBigInteger('gross_amount_minor');
            $t->unsignedBigInteger('amount_minor')->nullable();
            $t->char('currency', 3);
            $t->unsignedBigInteger('platform_commission_minor');
            $t->unsignedBigInteger('expert_provisional_minor');
            $t->string('provider_ref', 100)->nullable();
            $t->string('correlation_ref', 100)->nullable();
            $t->string('dedupe_key', 160)->unique();
            $t->timestamp('occurred_at');
            $t->timestamp('created_at');
            $t->index(['funding_id', 'id'], 'ledger_funding');
        });

        Schema::create('stripe_webhook_events', function (Blueprint $t): void {
            $t->id();
            $t->string('stripe_event_id', 100)->unique();
            $t->string('event_type', 80);
            $t->boolean('livemode')->default(false);
            $t->string('object_id', 100)->nullable();
            $t->string('status', 12)->default('received');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('error_code', 60)->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'id'], 'webhook_status');
        });

        Schema::create('funding_refunds', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('funding_id')->constrained('fundings')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('initiated_by_type', 8);
            $t->unsignedBigInteger('initiated_by_id')->nullable();
            $t->string('reason_code', 32);
            $t->string('kind', 10)->default('standard');
            $t->string('payment_intent_id', 80)->nullable();
            $t->unsignedBigInteger('amount_minor')->nullable();
            $t->char('currency', 3);
            $t->string('status', 14);
            $t->unsignedBigInteger('retained_commission_after_minor')->nullable();
            $t->unsignedBigInteger('expert_remaining_after_minor')->nullable();
            $t->string('stripe_refund_id', 80)->nullable()->unique();
            $t->string('failure_code', 60)->nullable();
            $t->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $t->string('decision', 20)->nullable();
            $t->text('decision_reason')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('active_key', 40)->nullable()->unique();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['status', 'id'], 'refund_status');
        });

        Schema::create('funding_disputes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('funding_id')->constrained('fundings')->restrictOnDelete();
            $t->string('stripe_dispute_id', 80)->unique();
            $t->string('status', 30);
            $t->string('reason_code', 60)->nullable();
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->timestamp('evidence_due_by')->nullable();
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('financial_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('funding_id')->constrained('fundings')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('document_type', 10);
            $t->string('number', 30)->unique();
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('status', 10);
            $t->timestamp('issued_at');
            $t->text('provider_receipt_url')->nullable();
            $t->timestamps();
            $t->unique(['funding_id', 'document_type'], 'financial_document_once');
        });

        Schema::create('reconciliation_issues', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('funding_id')->nullable()->constrained('fundings')->restrictOnDelete();
            $t->string('kind', 40);
            $t->string('status', 10)->default('open');
            $t->string('open_key', 80)->nullable()->unique();
            $t->json('detail')->nullable();
            $t->string('resolution', 40)->nullable();
            $t->timestamp('detected_at');
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'id'], 'reconciliation_status');
        });
    }

    public function down(): void
    {
        foreach (['reconciliation_issues', 'financial_documents', 'funding_disputes', 'funding_refunds', 'stripe_webhook_events', 'financial_ledger_events', 'funding_attempts', 'fundings', 'stripe_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('bookings', function (Blueprint $t): void {
            $t->dropColumn('free_reschedule_granted_at');
        });
    }
};
