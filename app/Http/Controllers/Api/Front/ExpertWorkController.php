<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Http\Front\Front;
use App\Http\Front\FrontResponses;
use App\Http\Front\Presenters\CommercePresenter;
use App\Http\Front\Presenters\MatchingPresenter;
use App\Http\Requests\Commerce\ProposalContent;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\CaseInvitation;
use App\Models\CaseRecord;
use App\Models\CatalogNode;
use App\Models\Expert;
use App\Models\ExpertOffering;
use App\Models\Funding;
use App\Models\Proposal;
use App\Services\Commerce\Agreements;
use App\Services\Commerce\Bookings;
use App\Services\Commerce\Proposals;
use App\Services\Front\CaseAnalyzer;
use App\Services\Front\ReasonCodes;
use App\Services\Matching\ExpertOfferings;
use App\Services\Matching\Invitations;
use App\Services\Payments\ConnectAccounts;
use App\Services\Privacy\ActorIdempotency;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** The Expert's work: invitations, proposals, agreements, sessions, offerings and payouts account (v7 shapes). */
class ExpertWorkController extends Controller
{
    use FrontResponses;

    public function __construct(private ActorIdempotency $idempotency) {}

    private function expert(Request $r): Expert
    {
        $e = $r->user();
        abort_unless($e instanceof Expert, 403);

        return $e;
    }

    private function keyed(Request $r, array $payload, \Closure $op): JsonResponse
    {
        return $r->hasHeader('Idempotency-Key') ? $this->idempotency->run($r, $payload, $op) : $op();
    }

    public function me(Request $r): JsonResponse
    {
        $e = $this->expert($r)->load('profile');

        return $this->ok(['id' => $e->id, 'slug' => $e->profile?->slug, 'name' => $e->name, 'email' => $e->email, 'country' => $e->country, 'language' => $e->language,
            'emailVerified' => $e->hasVerifiedEmail(), 'avatarUrl' => \App\Http\Front\Presenters\ExpertPresenter::avatar($e), 'kycStatus' => $e->kyc_status?->value ?? 'not_submitted',
            'domain' => $e->domain, 'isActive' => (bool) $e->is_active, 'profile' => ['slug' => $e->profile?->slug, 'isPublished' => (bool) $e->profile?->is_published]]);
    }

    /* ───────── invitations ───────── */

    public function invitations(Request $r): JsonResponse
    {
        return $this->ok($this->expert($r)->invitations()->orderByDesc('created_at')->get()->map(fn ($i) => MatchingPresenter::forExpert($i))->all());
    }

    public function invitation(Request $r, int $invitation): JsonResponse
    {
        return $this->ok(MatchingPresenter::forExpert($this->expert($r)->invitations()->findOrFail($invitation)));
    }

    public function accept(Request $r, int $invitation, Invitations $invitations): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1']]);
        $e = $this->expert($r);

        return $this->keyed($r, $d, fn () => $this->ok(MatchingPresenter::forExpert($invitations->auditFailures($e, 'case_invitation', $invitation,
            fn () => \Illuminate\Support\Facades\DB::transaction(fn () => $invitations->accept($e, $invitation, (int) $d['expectedVersion'])))->fresh()), 'Invitation accepted.'));
    }

    public function decline(Request $r, int $invitation, Invitations $invitations): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'reasonCode' => ['sometimes', 'nullable', 'string', 'max:40'], 'note' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $d['reasonCode'] = ReasonCodes::decline($d['reasonCode'] ?? null) ?? 'OTHER';
        $e = $this->expert($r);

        return $this->keyed($r, $d, fn () => $this->ok(MatchingPresenter::forExpert(\Illuminate\Support\Facades\DB::transaction(fn () => $invitations->decline($e, $invitation, $d))->fresh()), 'Invitation declined.'));
    }

    /* ───────── proposals ───────── */

    private function content(Request $r, bool $revise): array
    {
        $rules = ProposalContent::rules() + ($revise ? ['expectedVersion' => ['required', 'integer', 'min:1']] : []);
        $v = Validator::make($r->all(), $rules);
        $v->after(fn ($validator) => ProposalContent::check($validator, $r->all()));

        return $v->validate();
    }

    public function submitProposal(Request $r, int $invitation, Proposals $proposals): JsonResponse
    {
        $d = $this->content($r, false);

        return $this->keyed($r, $d, fn () => $this->ok(CommercePresenter::proposal($proposals->submit($this->expert($r), $invitation, $d), 'expert'), 'Proposal sent.', 201));
    }

    public function proposals(Request $r): JsonResponse
    {
        return $this->ok(Proposal::where('expert_id', $this->expert($r)->id)->orderByDesc('updated_at')->get()->map(fn ($p) => CommercePresenter::proposal($p, 'expert'))->all());
    }

    public function proposal(Request $r, int $proposal): JsonResponse
    {
        return $this->ok(CommercePresenter::proposal(Proposal::where('expert_id', $this->expert($r)->id)->findOrFail($proposal), 'expert'));
    }

    public function revise(Request $r, int $proposal, Proposals $proposals): JsonResponse
    {
        $d = $this->content($r, true);

        return $this->keyed($r, $d, fn () => $this->ok(CommercePresenter::proposal($proposals->revise($this->expert($r), $proposal, $d), 'expert'), 'Revised proposal sent.'));
    }

    public function withdraw(Request $r, int $proposal, Proposals $proposals): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'reasonCode' => ['sometimes', 'nullable', 'string', 'max:40']]);
        $d['reasonCode'] = ReasonCodes::withdraw($d['reasonCode'] ?? null) ?? 'OTHER';

        return $this->keyed($r, $d, fn () => $this->ok(CommercePresenter::proposal($proposals->withdraw($this->expert($r), $proposal, $d), 'expert'), 'Proposal withdrawn.'));
    }

    /* ───────── agreements & sessions ───────── */

    public function agreements(Request $r): JsonResponse
    {
        app(\App\Services\Front\SessionCompletion::class)->run(50);
        return $this->ok(Agreement::where('expert_id', $this->expert($r)->id)->orderByDesc('id')->get()->map(fn ($a) => CommercePresenter::agreement($a, 'expert'))->all());
    }

    public function agreement(Request $r, int $agreement): JsonResponse
    {
        app(\App\Services\Front\SessionCompletion::class)->run(50);
        return $this->ok(CommercePresenter::agreement(Agreement::where('expert_id', $this->expert($r)->id)->findOrFail($agreement), 'expert'));
    }

    public function cancelAgreement(Request $r, int $agreement, Agreements $agreements): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'reasonCode' => ['sometimes', 'nullable', 'string', 'max:40']]);
        $d['reasonCode'] = ReasonCodes::cancel($d['reasonCode'] ?? null, true);

        return $this->ok(CommercePresenter::agreement($agreements->cancelByExpert($this->expert($r), $agreement, $d), 'expert'), 'Agreement cancelled.');
    }

    public function agreementFunding(Request $r, int $agreement): JsonResponse
    {
        $a = Agreement::where('expert_id', $this->expert($r)->id)->findOrFail($agreement);
        $f = Funding::where('agreement_id', $a->id)->orderByDesc('id')->first();

        return $this->ok(['agreementId' => $a->id, 'status' => $f ? CommercePresenter::fundingStatus($f) : 'requires_payment', 'fundedAt' => Front::iso($f?->funded_at), 'split' => CommercePresenter::split($a)]);
    }

    private function bookingRow(Booking $b): array
    {
        $case = CaseRecord::with(['currentIntake', 'owner'])->find($b->case_id);

        return CommercePresenter::booking($b) + ['caseTitle' => $case?->currentIntake?->payload['title'] ?? null, 'client' => ['firstName' => MatchingPresenter::firstName($case?->owner?->name)]];
    }

    public function bookings(Request $r): JsonResponse
    {
        app(\App\Services\Front\SessionCompletion::class)->run(50);
        return $this->ok(Booking::where('expert_id', $this->expert($r)->id)->orderBy('start_at')->get()->map(fn ($b) => $this->bookingRow($b))->all());
    }

    public function booking(Request $r, int $booking): JsonResponse
    {
        app(\App\Services\Front\SessionCompletion::class)->run(50);
        return $this->ok($this->bookingRow(Booking::where('expert_id', $this->expert($r)->id)->findOrFail($booking)));
    }

    public function cancelBooking(Request $r, int $booking, Bookings $bookings): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'reasonCode' => ['sometimes', 'nullable', 'string', 'max:40']]);
        $d['reasonCode'] = ReasonCodes::cancel($d['reasonCode'] ?? null, true);

        return $this->ok($this->bookingRow($bookings->cancelByExpert($this->expert($r), $booking, $d)), 'Session cancelled.');
    }

    public function reschedule(Request $r, int $booking, Bookings $bookings): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'startAt' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?Z$/']]);
        $d['startAt'] = CarbonImmutable::parse($d['startAt'])->utc()->format('Y-m-d\TH:i:s\Z');

        return $this->ok($this->bookingRow($bookings->rescheduleByExpert($this->expert($r), $booking, $d)), 'Session moved.');
    }

    /* ───────── offerings ───────── */

    private static function service(\App\Models\CatalogEntry $entry, ?array $allowed = null): array
    {
        $v = $entry->versions()->where('id', $entry->current_version_id)->first() ?? $entry->versions()->orderByDesc('version')->first();
        $p = $v?->policy ?? [];
        $countries = CatalogNode::where('kind', 'jurisdiction')->whereIn('code', $allowed['jurisdictionCodes'] ?? $p['jurisdictionCodes'] ?? [])->get()
            ->map(fn ($j) => CatalogNode::whereKey($j->parent_id)->value('code'))->filter()->unique()->values()->all();
        $service = CatalogNode::where('kind', 'service')->where('code', $p['serviceType'] ?? '')->value('labels');

        return ['id' => $entry->id, 'code' => $entry->code, 'domain' => $p['domain'] ?? null, 'label' => $v ? CaseAnalyzer::label($v) : $entry->code,
            'summary' => $service ?? null, 'deliveryModes' => $allowed['deliveryModes'] ?? $p['deliveryModes'] ?? [], 'jurisdictionCodes' => $countries ?: (($p['jurisdictionMode'] ?? '') === 'GLOBAL' ? ['GLOBAL'] : []),
            'languages' => $allowed['languages'] ?? ['ar', 'en']];
    }

    private function offering(ExpertOffering $o, ExpertOfferings $offerings, ?array $services = null): array
    {
        $o->loadMissing('entry');
        $countries = $o->jurisdiction_codes === null ? [] : CatalogNode::where('kind', 'jurisdiction')->whereIn('code', $o->jurisdiction_codes)->get()
            ->map(fn ($j) => CatalogNode::whereKey($j->parent_id)->value('code'))->filter()->unique()->values()->all();

        return ['id' => $o->id, 'catalogEntryId' => $o->catalog_entry_id, 'status' => $o->status, 'version' => $o->version, 'deliveryModes' => $o->delivery_modes, 'languages' => $o->languages,
            'jurisdictionCodes' => $countries, 'summary' => $o->summary ?? '', 'priceFromMinor' => $o->price_from_minor, 'currency' => $o->currency ?? 'AED',
            'publishedAt' => Front::iso($o->published_at), 'updatedAt' => Front::iso($o->updated_at), 'service' => self::service($o->entry),
            'eligibility' => $offerings->eligibility($this->expertOf($o), $o, $services)];
    }

    private function expertOf(ExpertOffering $o): Expert
    {
        return $o->relationLoaded('expert') ? $o->expert : $o->expert()->firstOrFail();
    }

    /** v7 sends country codes (AE) or nothing; the catalog stores jurisdiction codes (AE-FEDERAL). */
    private function jurisdictions(?array $codes, ?array $allowed): ?array
    {
        if (! $codes) {
            return null;
        }
        $out = [];
        foreach ($codes as $code) {
            if (strlen($code) === 2) {
                $country = CatalogNode::where('kind', 'country')->where('code', strtoupper($code))->value('id');
                $out = array_merge($out, CatalogNode::where('kind', 'jurisdiction')->where('parent_id', $country)->pluck('code')->all());
            } else {
                $out[] = $code;
            }
        }
        if ($allowed !== null) {
            $out = array_values(array_intersect($out, $allowed));
        }

        return $out ? array_values(array_unique($out)) : null;
    }

    private function offeringInput(Request $r, bool $create): array
    {
        return $r->validate(($create ? ['catalogEntryId' => ['required', 'integer', 'min:1']] : ['expectedVersion' => ['required', 'integer', 'min:1']]) + [
            'deliveryModes' => ['required', 'array', 'min:1', 'max:5'], 'deliveryModes.*' => ['string', 'max:100'], 'languages' => ['required', 'array', 'min:1', 'max:4'], 'languages.*' => ['string', 'max:10'],
            'jurisdictionCodes' => ['sometimes', 'nullable', 'array', 'max:20'], 'jurisdictionCodes.*' => ['string', 'max:100'], 'summary' => ['sometimes', 'nullable', 'string', 'max:500'],
            'priceFromMinor' => ['sometimes', 'nullable', 'integer', 'min:1']]);
    }

    public function eligibleServices(Request $r, ExpertOfferings $offerings): JsonResponse
    {
        return $this->ok(array_values(array_map(fn ($s) => self::service($s['entry'], $s), $offerings->eligibleServices($this->expert($r)))));
    }

    public function offerings(Request $r, ExpertOfferings $offerings): JsonResponse
    {
        $e = $this->expert($r);
        $services = $offerings->eligibleServices($e);

        return $this->ok($e->offerings()->orderByDesc('updated_at')->get()->map(fn ($o) => $this->offering($o->setRelation('expert', $e), $offerings, $services))->all());
    }

    public function showOffering(Request $r, int $offering, ExpertOfferings $offerings): JsonResponse
    {
        $e = $this->expert($r);

        return $this->ok($this->offering($e->offerings()->findOrFail($offering)->setRelation('expert', $e), $offerings));
    }

    public function createOffering(Request $r, ExpertOfferings $offerings): JsonResponse
    {
        $e = $this->expert($r);
        $d = $this->offeringInput($r, true);
        $allowed = $offerings->eligibleServices($e)[(int) $d['catalogEntryId']] ?? null;
        $d['jurisdictionCodes'] = $this->jurisdictions($d['jurisdictionCodes'] ?? null, $allowed['jurisdictionCodes'] ?? null);

        return $this->keyed($r, $d, fn () => $this->ok($this->offering($offerings->create($e, $d)->setRelation('expert', $e), $offerings), 'Offering created.', 201));
    }

    public function updateOffering(Request $r, int $offering, ExpertOfferings $offerings): JsonResponse
    {
        $e = $this->expert($r);
        $d = $this->offeringInput($r, false);
        $row = $e->offerings()->findOrFail($offering);
        $allowed = $offerings->eligibleServices($e)[$row->catalog_entry_id] ?? null;
        $d['jurisdictionCodes'] = $this->jurisdictions($d['jurisdictionCodes'] ?? null, $allowed['jurisdictionCodes'] ?? null);

        return $this->ok($this->offering($offerings->update($e, $offering, $d)->setRelation('expert', $e), $offerings), 'Offering saved.');
    }

    public function publishOffering(Request $r, int $offering, ExpertOfferings $offerings): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1']]);
        $e = $this->expert($r);

        return $this->ok($this->offering($offerings->publish($e, $offering, $d)->setRelation('expert', $e), $offerings), 'Offering published.');
    }

    public function pauseOffering(Request $r, int $offering, ExpertOfferings $offerings): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1']]);
        $e = $this->expert($r);

        return $this->ok($this->offering($offerings->pause($e, $offering, $d)->setRelation('expert', $e), $offerings), 'Offering paused.');
    }

    /* ───────── payouts account (Stripe Connect) ───────── */

    private function account(?\App\Models\StripeAccount $a): array
    {
        $shape = \App\Http\Resources\Payments\PaymentsPresenter::account($a);

        return ['status' => $shape['status'], 'onboardingCompleted' => $shape['onboardingCompleted'], 'chargesEnabled' => $shape['chargesEnabled'], 'payoutsEnabled' => $shape['payoutsEnabled'],
            'detailsSubmitted' => $shape['detailsSubmitted'], 'requirementsDue' => $shape['requirementsDue'], 'lastSyncedAt' => $shape['lastSyncedAt']];
    }

    public function stripeAccount(Request $r, ConnectAccounts $accounts): JsonResponse
    {
        return $this->ok($this->account($accounts->find($this->expert($r))));
    }

    public function stripeLink(Request $r, ConnectAccounts $accounts): JsonResponse
    {
        return $this->keyed($r, [], function () use ($r, $accounts) {
            $out = $accounts->onboardingLink($this->expert($r));

            return $this->ok(['url' => $out['url'], 'expiresAt' => $out['expiresAt']], 'Onboarding link ready.');
        });
    }

    public function stripeSync(Request $r, ConnectAccounts $accounts): JsonResponse
    {
        return $this->ok($this->account($accounts->sync($this->expert($r))));
    }
}
