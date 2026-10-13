<?php

namespace App\Http\Front\Presenters;

use App\Enums\CaseStatus;
use App\Enums\ExpertScopeStatus;
use App\Http\Front\Front;
use App\Http\Resources\Expert\Profile\VerifiedScopeResource;
use App\Models\Admin;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\CaseDocument;
use App\Models\CaseInvitation;
use App\Models\CaseRecord;
use App\Models\CatalogEntry;
use App\Models\CatalogNode;
use App\Models\CatalogVersion;
use App\Models\DataRightsRequest;
use App\Models\Expert;
use App\Models\ExpertCatalogGrant;
use App\Models\ExpertKycApplication;
use App\Models\ExpertReview;
use App\Models\ExpertScopeRenewal;
use App\Models\FinancialDocument;
use App\Models\Funding;
use App\Models\FundingAttempt;
use App\Models\FundingDispute;
use App\Models\FundingRefund;
use App\Models\LedgerEvent;
use App\Models\Proposal;
use App\Models\ReconciliationIssue;
use App\Models\StripeAccount;
use App\Models\User;
use App\Services\Front\CaseAnalyzer;
use App\Services\Reviews\ExpertReviews;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Admin resources in the v7 dashboard shapes (services/mock/routesAdmin.js). Privacy rule kept from the API design:
 * the administration sees Case metadata only — no story, title, summary, answers or documents' content.
 */
final class AdminPresenter
{
    public static function ref(?object $m): ?array
    {
        return $m ? ['id' => $m->id, 'name' => $m->name] : null;
    }

    private static function lastLogin(string $type, int $id): ?string
    {
        return Front::iso(DB::table('personal_access_tokens')->where('tokenable_type', $type)->where('tokenable_id', $id)->max('last_used_at')
            ?? DB::table('personal_access_tokens')->where('tokenable_type', $type)->where('tokenable_id', $id)->max('created_at'));
    }

    /* ───────── users ───────── */

    public static function user(User $u): array
    {
        $cases = CaseRecord::where('user_id', $u->id);
        $paid = (int) Funding::where('user_id', $u->id)->where('status', Funding::FUNDED)->sum(DB::raw('gross_amount_minor - refunded_amount_minor'));

        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->profile?->phone, 'country' => $u->profile?->country, 'emailVerifiedAt' => Front::iso($u->email_verified_at),
            'isActive' => true, 'createdAt' => Front::iso($u->created_at), 'lastLoginAt' => self::lastLogin(User::class, $u->id), 'casesCount' => (clone $cases)->count(),
            'openCasesCount' => (clone $cases)->where('status', '!=', CaseStatus::Cancelled)->where('readiness_status', '!=', 'draft')->count(), 'paidMinor' => $paid, 'currency' => 'AED'];
    }

    public static function userDetail(User $u): array
    {
        return self::user($u) + ['cases' => CaseRecord::where('user_id', $u->id)->orderByDesc('updated_at')->get()->map(fn ($c) => self::caseRow($c))->all(),
            'dataRequests' => DataRightsRequest::where('user_id', $u->id)->orderByDesc('id')->get()->map(fn ($r) => self::dataRequest($r, false))->all()];
    }

    /* ───────── experts ───────── */

    public static function expert(Expert $e): array
    {
        $e->loadMissing('profile');
        $scopes = $e->verifiedScopes()->get();
        $rep = app(ExpertReviews::class)->summary($e->id);

        return ['id' => $e->id, 'name' => $e->name, 'email' => $e->email, 'country' => $e->country, 'language' => $e->language, 'domain' => $e->domain, 'is_active' => (bool) $e->is_active,
            'email_verified_at' => Front::iso($e->email_verified_at), 'kycStatus' => $e->kyc_status?->value ?? 'not_submitted', 'scopesCount' => $scopes->count(),
            'activeScopesCount' => $scopes->filter(fn ($s) => $s->isEffective())->count(), 'rating' => $rep['averageRating'], 'reviewsCount' => $rep['reviewCount'],
            'createdAt' => Front::iso($e->created_at), 'lastLoginAt' => self::lastLogin(Expert::class, $e->id), 'published' => (bool) $e->profile?->is_published];
    }

    public static function expertDetail(Expert $e): array
    {
        $public = ExpertPresenter::public($e);
        $stripe = StripeAccount::where('expert_id', $e->id)->first();

        return self::expert($e) + [
            'scopes' => VerifiedScopeResource::collection($e->verifiedScopes()->orderBy('id')->get())->resolve(),
            'kycApplications' => ExpertKycApplication::where('expert_id', $e->id)->where('status', '!=', 'draft')->orderByDesc('id')->get()->map(fn ($a) => self::kycSummary($a))->all(),
            'renewals' => ExpertScopeRenewal::where('expert_id', $e->id)->whereNotNull('submitted_at')->orderByDesc('id')->get()->map(fn ($r) => self::renewal($r))->all(),
            'agreements' => Agreement::where('expert_id', $e->id)->orderByDesc('id')->get()->map(fn ($a) => ['id' => $a->id, 'caseId' => $a->case_id, 'caseTitle' => self::caseLabel($a->case_id),
                'status' => CommercePresenter::agreementStatus($a), 'amount' => CommercePresenter::money($a->total_amount_minor, $a->currency), 'createdAt' => Front::iso($a->created_at)])->all(),
            'reviews' => ExpertReview::where('expert_id', $e->id)->orderByDesc('id')->get()->map(fn ($r) => ['id' => $r->id, 'rating' => $r->rating, 'status' => self::reviewStatus($r), 'body' => $r->body, 'createdAt' => Front::iso($r->created_at)])->all(),
            'stripe' => $stripe ? self::stripe($stripe) : null,
            'profile' => $e->profile ? Front::deep(['slug' => $public['slug'], 'title' => $public['title'], 'location' => $public['location'], 'yearsExperience' => $public['yearsExperience'],
                'completedSessions' => $public['completedSessions'], 'languages' => $public['languages'], 'responseTime' => $public['responseTime'], 'avatarUrl' => $public['avatarUrl']]) : null,
        ];
    }

    public static function kycSummary(ExpertKycApplication $a): array
    {
        $a->loadMissing('expert');

        return ['id' => $a->id, 'reference' => $a->reference, 'attemptNumber' => $a->attempt_number, 'status' => $a->status->value, 'expert' => ['id' => $a->expert_id, 'name' => $a->expert?->name ?? $a->full_name, 'email' => $a->email_snapshot],
            'domain' => $a->domain, 'jurisdiction' => $a->jurisdiction, 'documentsCount' => $a->documents()->count(), 'submittedAt' => Front::iso($a->submitted_at), 'updatedAt' => Front::iso($a->updated_at)];
    }

    public static function stripe(StripeAccount $s): array
    {
        $shape = \App\Http\Resources\Payments\PaymentsPresenter::account($s);

        return ['expertId' => $s->expert_id, 'accountId' => $s->stripe_account_id, 'status' => $shape['status'], 'chargesEnabled' => $shape['chargesEnabled'], 'payoutsEnabled' => $shape['payoutsEnabled'],
            'requirementsDue' => $shape['requirementsDue'], 'updatedAt' => Front::iso($s->updated_at), 'expert' => self::ref(Expert::find($s->expert_id))];
    }

    /* ───────── renewals ───────── */

    public static function renewal(ExpertScopeRenewal $r, bool $detail = false): array
    {
        $r->loadMissing(['scope', 'submissions']);
        $scope = $r->scope ? (new VerifiedScopeResource($r->scope))->resolve() : null;
        $row = ['id' => $r->id, 'scopeId' => $r->scope_id, 'status' => $r->status->value ?? $r->status, 'version' => $r->version, 'expert' => self::ref(Expert::find($r->expert_id)), 'scope' => $scope,
            'submittedAt' => Front::iso($r->submitted_at), 'decidedAt' => Front::iso($r->decided_at), 'createdAt' => Front::iso($r->created_at), 'updatedAt' => Front::iso($r->updated_at)];
        if (! $detail) {
            return $row;
        }
        $feedback = $r->feedback;

        return $row + ['nextReviewAt' => $scope['nextReviewAt'] ?? null, 'feedback' => is_array($feedback) ? $feedback : ($feedback ? ['reason' => $feedback] : null),
            'history' => array_values(array_map(fn ($h) => ['at' => $h['at'] ?? $h['createdAt'] ?? null, 'event' => $h['to'] ?? $h['event'] ?? $h['action'] ?? null] + $h, $r->history ?? [])),
            'submissions' => $r->submissions->sortBy('sequence')->values()->map(fn ($s) => ['id' => $s->id, 'kind' => $s->evidence['type'] ?? 'credential', 'originalName' => 'evidence-'.$s->sequence.'.'.pathinfo($s->path, PATHINFO_EXTENSION),
                'size' => $s->size, 'submittedAt' => Front::iso($s->created_at), 'note' => $s->evidence['note'] ?? null, 'document' => null])->all()];
    }

    /* ───────── catalog ───────── */

    public static function version(CatalogVersion $v): array
    {
        return ['id' => $v->id, 'catalogEntryId' => $v->entry_id, 'catalogVersion' => $v->version, 'revision' => $v->revision, 'status' => $v->status, 'policy' => $v->policy, 'policyHash' => $v->policy_hash,
            'reviewedAt' => Front::iso($v->reviewed_at), 'publishedAt' => Front::iso($v->published_at), 'reviewReasonCode' => null, 'version' => $v->revision, 'createdAt' => Front::iso($v->created_at)];
    }

    public static function entry(CatalogEntry $e, bool $withVersions = true): array
    {
        $versions = $e->versions()->orderBy('version')->get();
        $latest = $versions->last();
        $p = $latest?->policy ?? [];
        $labels = $latest ? CaseAnalyzer::label($latest) : ['ar' => $e->code, 'en' => $e->code];
        $service = CatalogNode::where('kind', 'service')->where('code', $p['serviceType'] ?? '')->value('labels');
        $live = $versions->sortByDesc('version')->first(fn ($v) => in_array($v->status, ['intake_only', 'pilot', 'enabled', 'paused', 'retired', 'blocked'], true));

        return ['id' => $e->id, 'code' => $e->code, 'domain' => $p['domain'] ?? null, 'labels' => $labels, 'summary' => $service ?? ['ar' => '', 'en' => ''], 'status' => $live?->status ?? 'draft',
            'versionsCount' => $versions->count(), 'latestVersion' => $latest ? self::version($latest) : null]
            + ($withVersions ? ['versions' => $versions->map(fn ($v) => self::version($v))->all()] : []);
    }

    public static function grant(ExpertCatalogGrant $g): array
    {
        $g->loadMissing('scope');

        return ['id' => $g->id, 'scopeId' => $g->scope_id, 'expertId' => $g->scope?->expert_id, 'catalogVersionId' => $g->catalog_version_id, 'jurisdictionCode' => $g->jurisdiction_code,
            'evidenceId' => $g->evidence_id, 'evidenceType' => $g->evidence_type, 'status' => $g->revoked_at ? 'revoked' : 'active', 'reasonCode' => null, 'createdAt' => Front::iso($g->created_at),
            'revokedAt' => Front::iso($g->revoked_at), 'expert' => self::ref($g->scope ? Expert::find($g->scope->expert_id) : null)];
    }

    /* ───────── cases (metadata only) ───────── */

    public static function caseRow(CaseRecord $c): array
    {
        $agreement = Agreement::where('case_id', $c->id)->orderByDesc('id')->first();
        $funding = $agreement ? Funding::where('agreement_id', $agreement->id)->orderByDesc('id')->first() : null;
        $scopes = $c->serviceScopes()->whereNull('detached_at')->with('catalogVersion')->get();

        return ['id' => $c->id, 'userId' => $c->user_id, 'user' => self::ref(User::find($c->user_id)), 'title' => self::caseLabel($c->id), 'reference' => 'CASE-'.str_pad((string) $c->id, 5, '0', STR_PAD_LEFT),
            'status' => $c->status === CaseStatus::Cancelled ? 'cancelled' : $c->readiness_status, 'lifecycleStatus' => $c->status->value, 'version' => $c->version, 'scopeCount' => $scopes->count(),
            'domain' => $scopes->first()?->catalogVersion?->policy['domain'] ?? $c->primary_domain, 'caseCountry' => $c->jurisdiction, 'urgency' => $c->urgency,
            'createdAt' => Front::iso($c->created_at), 'updatedAt' => Front::iso($c->updated_at), 'submittedAt' => Front::iso($c->submitted_at),
            'agreementStatus' => $agreement ? CommercePresenter::agreementStatus($agreement) : null, 'fundingStatus' => $funding ? CommercePresenter::fundingStatus($funding) : null];
    }

    public static function caseDetail(CaseRecord $c): array
    {
        $expert = fn ($id) => self::ref(Expert::find($id));
        $invitations = CaseInvitation::where('case_id', $c->id)->orderBy('id')->get()->map(fn ($i) => ['id' => $i->id, 'status' => $i->status, 'expert' => $expert($i->expert_id),
            'sentAt' => Front::iso($i->created_at), 'respondedAt' => Front::iso($i->responded_at), 'expiresAt' => Front::iso($i->expires_at)])->all();
        $proposals = Proposal::where('case_id', $c->id)->with('versions')->orderBy('id')->get()->map(function ($p) use ($expert) {
            $v = $p->versions->firstWhere('version_no', $p->current_version_no);

            return ['id' => $p->id, 'status' => $p->status, 'expert' => $expert($p->expert_id), 'versionNo' => $p->current_version_no,
                'amount' => $v ? CommercePresenter::money($v->total_amount_minor, $v->currency) : null, 'createdAt' => Front::iso($p->created_at)];
        })->all();
        $agreements = Agreement::where('case_id', $c->id)->orderBy('id')->get()->map(fn ($a) => ['id' => $a->id, 'status' => CommercePresenter::agreementStatus($a), 'expert' => $expert($a->expert_id),
            'amount' => CommercePresenter::money($a->total_amount_minor, $a->currency), 'createdAt' => Front::iso($a->created_at)])->all();
        $fundings = Funding::where('case_id', $c->id)->orderBy('id')->get()->map(fn ($f) => ['id' => $f->id, 'status' => CommercePresenter::fundingStatus($f), 'refundStatus' => $f->refund_status,
            'amounts' => CommercePresenter::funding($f)['amounts'], 'createdAt' => Front::iso($f->created_at)])->all();
        $bookings = Booking::where('case_id', $c->id)->orderBy('id')->get()->map(fn ($b) => ['id' => $b->id, 'status' => $b->status, 'expert' => $expert($b->expert_id),
            'startsAt' => Front::iso($b->start_at), 'endsAt' => Front::iso($b->end_at)])->all();
        // Document metadata only (no titles: they are part of the private case content).
        $documents = CaseDocument::where('case_id', $c->id)->whereNull('deleted_at')->with('currentVersion')->get()->map(fn ($d) => ['id' => $d->id, 'title' => null,
            'category' => $d->currentVersion?->category, 'size' => $d->currentVersion?->size, 'scanStatus' => $d->currentVersion?->scan_status->value, 'createdAt' => Front::iso($d->created_at)])->all();
        $timeline = DB::table('audit_events')->where('subject_type', 'case')->where('subject_id', $c->id)->orderByDesc('occurred_at')->limit(60)->get()
            ->map(fn ($e) => ['at' => Front::iso($e->occurred_at), 'action' => $e->action, 'actorType' => $e->actor_type])->all();
        $scopes = $c->serviceScopes()->whereNull('detached_at')->with('catalogVersion')->get()->map(fn ($s) => ['id' => $s->id, 'code' => $s->catalogVersion->policy['domain'],
            'label' => CaseAnalyzer::label($s->catalogVersion), 'priority' => null, 'included' => (bool) $s->confirmed, 'status' => $s->status])->all();

        return self::caseRow($c) + ['summary' => null, 'language' => $c->language, 'residence' => null, 'scopes' => $scopes, 'invitations' => $invitations, 'proposals' => $proposals,
            'agreements' => $agreements, 'fundings' => $fundings, 'bookings' => $bookings, 'documents' => $documents, 'timeline' => $timeline];
    }

    /* ───────── privacy ───────── */

    public static function dataRequest(DataRightsRequest $r, bool $withUser = true): array
    {
        return ['id' => $r->id, 'reference' => $r->reference, 'userId' => $r->user_id, 'type' => $r->type?->value ?? $r->type, 'scope' => $r->scope, 'status' => $r->status?->value ?? $r->status,
            'requestedAt' => Front::iso($r->requested_at), 'dueAt' => Front::iso($r->due_at), 'completedAt' => Front::iso($r->completed_at), 'reasonCode' => $r->reason_code]
            + ($withUser ? ['user' => self::ref(User::find($r->user_id))] : []);
    }

    /* ───────── reviews ───────── */

    /** API moderation states ↔ v7: pending ↔ pending_moderation; a never-published hidden review reads as rejected. */
    public static function reviewStatus(ExpertReview $r): string
    {
        return match ($r->moderation_status) {
            ExpertReview::PENDING => 'pending_moderation',
            ExpertReview::HIDDEN => $r->published_at ? 'hidden' : 'rejected',
            default => $r->moderation_status,
        };
    }

    public static function review(ExpertReview $r): array
    {
        $status = self::reviewStatus($r);
        $transitions = ['pending_moderation' => ['published', 'rejected'], 'published' => ['hidden'], 'hidden' => ['published'], 'rejected' => ['published'], 'challenged' => ['published', 'hidden']];

        return ['id' => $r->id, 'expertId' => $r->expert_id, 'expert' => self::ref(Expert::find($r->expert_id)), 'caseId' => $r->case_id, 'caseTitle' => self::caseLabel($r->case_id), 'agreementId' => $r->agreement_id,
            'rating' => $r->rating, 'body' => $r->body, 'status' => $status, 'flagged' => $r->moderation_status === ExpertReview::CHALLENGED, 'flagReason' => null, 'moderationNote' => $r->moderation_note,
            'version' => $r->version, 'reviewer' => ['id' => $r->user_id, 'name' => ExpertPresenter::initials(User::whereKey($r->user_id)->value('name'))],
            'allowedTransitions' => $transitions[$status] ?? [], 'createdAt' => Front::iso($r->created_at), 'updatedAt' => Front::iso($r->updated_at)];
    }

    /* ───────── finance ───────── */

    public static function funding(Funding $f): array
    {
        $agreement = Agreement::find($f->agreement_id);
        $base = CommercePresenter::funding($f);
        $net = $f->gross_amount_minor - $f->refunded_amount_minor;

        return ['id' => $f->id, 'agreementId' => $f->agreement_id, 'caseId' => $f->case_id, 'caseTitle' => self::caseLabel($f->case_id), 'status' => $base['status'], 'refundStatus' => $f->refund_status, 'version' => $f->version,
            'user' => self::ref(User::find($f->user_id)), 'expert' => self::ref($agreement ? Expert::find($agreement->expert_id) : null),
            'amounts' => $base['amounts'] + ['platformFeeMinor' => $f->retained_commission_minor ?? intdiv($net * (int) $f->commission_bps, 10000), 'expertNetMinor' => $f->expert_remaining_minor ?? $net,
                'platformFeePercent' => ($f->commission_bps ?? config('payments.commission_bps')) / 100],
            'pendingRefunds' => FundingRefund::where('funding_id', $f->id)->where('status', FundingRefund::PENDING_REVIEW)->count(),
            'openReconciliation' => ReconciliationIssue::where('funding_id', $f->id)->where('status', ReconciliationIssue::OPEN)->count(), 'deadlineAt' => Front::iso($f->deadline_at),
            'fundedAt' => Front::iso($f->funded_at), 'disputeStatus' => $f->dispute_status, 'createdAt' => Front::iso($f->created_at)];
    }

    public static function fundingDetail(Funding $f): array
    {
        return self::funding($f) + [
            'attempts' => FundingAttempt::where('funding_id', $f->id)->orderBy('attempt_no')->get()->map(fn ($a) => ['id' => $a->id, 'attemptNo' => $a->attempt_no,
                'status' => $a->status === 'paid' ? 'succeeded' : $a->status, 'paymentIntent' => $a->stripe_payment_intent_id, 'amountMinor' => $f->gross_amount_minor, 'failureCode' => $a->failure_code,
                'createdAt' => Front::iso($a->created_at), 'paidAt' => Front::iso($a->provider_paid_at)])->all(),
            'refunds' => FundingRefund::where('funding_id', $f->id)->orderByDesc('id')->get()->map(fn ($r) => self::refund($r, false))->all(),
            'reconciliation' => ReconciliationIssue::where('funding_id', $f->id)->orderByDesc('id')->get()->map(fn ($i) => self::issue($i))->all(),
            'disputes' => FundingDispute::where('funding_id', $f->id)->orderByDesc('id')->get()->map(fn ($d) => self::dispute($d, false))->all(),
            'ledger' => self::ledger(LedgerEvent::where('funding_id', $f->id)->orderByDesc('id')->get()),
            'receipts' => FinancialDocument::where('funding_id', $f->id)->get(['id', 'document_type', 'number', 'issued_at'])->toArray(),
        ];
    }

    public static function refund(FundingRefund $r, bool $withRefs = true): array
    {
        $f = Funding::find($r->funding_id);
        $status = $r->status;
        $row = ['id' => $r->id, 'fundingId' => $r->funding_id, 'kind' => $r->initiated_by_type === 'user' ? 'user_request' : 'admin_initiated', 'status' => $status, 'reasonCode' => $r->reason_code,
            'choice' => null, 'note' => null, 'requestedAmountMinor' => $r->amount_minor, 'approvedAmountMinor' => in_array($status, [FundingRefund::APPROVED, FundingRefund::PROCESSING, FundingRefund::SUCCEEDED], true) ? $r->amount_minor : null,
            'requestedBy' => $r->initiated_by_type, 'decidedBy' => $r->reviewed_by_admin_id, 'decisionReason' => $r->decision_reason, 'attemptId' => null, 'version' => $r->version,
            'createdAt' => Front::iso($r->created_at), 'decidedAt' => Front::iso($r->decided_at), 'completedAt' => Front::iso($r->completed_at), 'currency' => $r->currency ?? $f?->currency ?? 'AED'];
        if ($withRefs && $f) {
            $agreement = Agreement::find($f->agreement_id);
            $row += ['funding' => ['id' => $f->id, 'caseId' => $f->case_id, 'caseTitle' => self::caseLabel($f->case_id), 'amounts' => CommercePresenter::funding($f)['amounts'], 'status' => CommercePresenter::fundingStatus($f)],
                'user' => self::ref(User::find($f->user_id)), 'expert' => self::ref($agreement ? Expert::find($agreement->expert_id) : null)];
        }

        return $row;
    }

    public static function issue(ReconciliationIssue $i): array
    {
        return ['id' => $i->id, 'fundingId' => $i->funding_id, 'kind' => strtolower((string) $i->kind), 'code' => $i->kind, 'status' => $i->status, 'detail' => $i->detail, 'resolution' => $i->resolution,
            'detectedAt' => Front::iso($i->detected_at), 'resolvedAt' => Front::iso($i->resolved_at), 'createdAt' => Front::iso($i->created_at)];
    }

    public static function dispute(FundingDispute $d, bool $withFunding = true): array
    {
        $f = Funding::find($d->funding_id);
        $row = ['id' => $d->id, 'fundingId' => $d->funding_id, 'status' => $d->status, 'reason' => $d->reason_code, 'reasonCode' => $d->reason_code, 'amountMinor' => $d->amount_minor,
            'currency' => $d->currency, 'evidenceDueBy' => Front::iso($d->evidence_due_by), 'openedAt' => Front::iso($d->opened_at), 'closedAt' => Front::iso($d->closed_at), 'createdAt' => Front::iso($d->created_at)];

        return $withFunding ? $row + ['funding' => $f ? CommercePresenter::funding($f) : null, 'caseTitle' => $f ? self::caseLabel($f->case_id) : null] : $row;
    }

    /** The event log as the v7 ledger rows (charge / platform_fee / expert_payable / refund). */
    public static function ledger($events): array
    {
        $out = [];
        foreach ($events as $e) {
            $at = Front::iso($e->occurred_at ?? $e->created_at);
            $base = ['fundingId' => $e->funding_id, 'currency' => $e->currency, 'createdAt' => $at, 'eventType' => $e->event_type];
            switch ($e->event_type) {
                case 'payment_confirmed':
                    $gross = (int) ($e->amount_minor ?? $e->gross_amount_minor);
                    $out[] = $base + ['id' => "L{$e->id}-c", 'type' => 'charge', 'direction' => 'credit', 'amountMinor' => $gross, 'memo' => 'Payment '.($e->provider_ref ?? ''), 'duplicate' => false];
                    $out[] = $base + ['id' => "L{$e->id}-f", 'type' => 'platform_fee', 'direction' => 'credit', 'amountMinor' => (int) $e->platform_commission_minor, 'memo' => 'Platform commission', 'duplicate' => false];
                    $out[] = $base + ['id' => "L{$e->id}-e", 'type' => 'expert_payable', 'direction' => 'credit', 'amountMinor' => (int) $e->expert_provisional_minor, 'memo' => 'Held for the expert', 'duplicate' => false];
                    break;
                case 'late_payment_received':
                case 'duplicate_payment_detected':
                    $out[] = $base + ['id' => "L{$e->id}-d", 'type' => 'charge', 'direction' => 'credit', 'amountMinor' => (int) ($e->amount_minor ?? 0), 'memo' => 'Extra payment '.($e->provider_ref ?? ''), 'duplicate' => true];
                    break;
                case 'refund_completed':
                    $out[] = $base + ['id' => "L{$e->id}-r", 'type' => 'refund', 'direction' => 'debit', 'amountMinor' => (int) ($e->amount_minor ?? 0), 'memo' => 'Refund', 'duplicate' => false];
                    break;
            }
        }

        return $out;
    }

    public static function money(int $minor, string $currency): array
    {
        return ['amountMinor' => $minor, 'currency' => $currency, 'minorUnitExponent' => Money::exponent($currency)];
    }

    /** D1: the admin never sees the user's case title; it gets a neutral reference label instead. */
    public static function caseLabel($caseId): ?string
    {
        return $caseId ? Front::t(['ar' => 'حالة', 'en' => 'Case']).' CASE-'.str_pad((string) $caseId, 5, '0', STR_PAD_LEFT) : null;
    }
}
