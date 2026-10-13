<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Http\Front\Front;
use App\Http\Front\FrontResponses;
use App\Http\Front\Presenters\CommercePresenter;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\ExpertReview;
use App\Models\FinancialDocument;
use App\Models\Funding;
use App\Models\Proposal;
use App\Models\User;
use App\Services\Commerce\Agreements;
use App\Services\Commerce\Bookings;
use App\Services\Commerce\Proposals;
use App\Services\Front\ReasonCodes;
use App\Services\Payments\Fundings;
use App\Services\Privacy\ActorIdempotency;
use App\Services\Reviews\ExpertReviews;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Proposals, agreements, bookings, funding and reviews for the User (v7 shapes, /api/v2/user/cases/{case}/…). */
class UserCommerceController extends Controller
{
    use FrontResponses;

    public function __construct(private ActorIdempotency $idempotency) {}

    private function user(Request $r): User
    {
        $u = $r->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }

    private function keyed(Request $r, array $payload, \Closure $op): JsonResponse
    {
        return $r->hasHeader('Idempotency-Key') ? $this->idempotency->run($r, $payload, $op) : $op();
    }

    private function agreementRow(Request $r, int $case, int $id): Agreement
    {
        $c = $this->user($r)->cases()->findOrFail($case);

        return Agreement::where('case_id', $c->id)->findOrFail($id);
    }

    /* ───────── proposals ───────── */

    public function proposals(Request $r, int $case): JsonResponse
    {
        $c = $this->user($r)->cases()->findOrFail($case);

        return $this->ok(Proposal::where('case_id', $c->id)->orderByDesc('id')->get()->map(fn ($p) => CommercePresenter::proposal($p, 'user'))->all());
    }

    public function proposal(Request $r, int $case, int $proposal): JsonResponse
    {
        $c = $this->user($r)->cases()->findOrFail($case);

        return $this->ok(CommercePresenter::proposal(Proposal::where('case_id', $c->id)->findOrFail($proposal), 'user'));
    }

    public function requestChange(Request $r, int $case, int $proposal, Proposals $proposals): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'versionNo' => ['required', 'integer', 'min:1'], 'categories' => ['required', 'array', 'min:1', 'max:5'],
            'categories.*' => ['string', 'max:30'], 'message' => ['required', 'string', 'min:3', 'max:2000']]);
        $d['categories'] = ReasonCodes::categories($d['categories']);

        return $this->keyed($r, $d, fn () => $this->ok(CommercePresenter::proposal($proposals->requestChange($this->user($r), $case, $proposal, $d), 'user'), 'Change requested.'));
    }

    public function accept(Request $r, int $case, int $proposal, Proposals $proposals): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'versionNo' => ['required', 'integer', 'min:1'], 'totalAmountMinor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'], 'acknowledge' => ['required', 'accepted']]);

        return $this->keyed($r, $d, function () use ($r, $case, $proposal, $proposals, $d) {
            [, $agreement] = $proposals->accept($this->user($r), $case, $proposal, $d);

            return $this->ok(CommercePresenter::agreement($agreement, 'user'), 'Proposal accepted. The agreement is awaiting payment.', 201);
        });
    }

    public function reject(Request $r, int $case, int $proposal, Proposals $proposals): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'reasonCode' => ['sometimes', 'nullable', 'string', 'max:40']]);
        $d['reasonCode'] = ReasonCodes::reject($d['reasonCode'] ?? null);

        return $this->keyed($r, $d, fn () => $this->ok(CommercePresenter::proposal($proposals->reject($this->user($r), $case, $proposal, $d), 'user'), 'Proposal declined.'));
    }

    /* ───────── agreements ───────── */

    public function agreements(Request $r, int $case): JsonResponse
    {
        app(\App\Services\Front\SessionCompletion::class)->run(50);
        $c = $this->user($r)->cases()->findOrFail($case);

        return $this->ok(Agreement::where('case_id', $c->id)->orderByDesc('id')->get()->map(fn ($a) => CommercePresenter::agreement($a, 'user'))->all());
    }

    public function agreement(Request $r, int $case, int $agreement): JsonResponse
    {
        app(\App\Services\Front\SessionCompletion::class)->run(50);
        return $this->ok(CommercePresenter::agreement($this->agreementRow($r, $case, $agreement), 'user'));
    }

    public function cancelAgreement(Request $r, int $case, int $agreement, Agreements $agreements): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'reasonCode' => ['sometimes', 'nullable', 'string', 'max:40']]);
        $d['reasonCode'] = ReasonCodes::cancel($d['reasonCode'] ?? null, false);

        return $this->ok(CommercePresenter::agreement($agreements->cancelByUser($this->user($r), $case, $agreement, $d), 'user'), 'Agreement cancelled.');
    }

    public function availability(Request $r, int $case, int $agreement, Bookings $bookings): JsonResponse
    {
        $d = $r->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $a = $this->agreementRow($r, $case, $agreement);
        $tz = $a->expert?->availability?->timezone ?: 'UTC';
        if (! in_array($a->status, [Agreement::AWAITING_PAYMENT, Agreement::FUNDED], true) || ! $a->consultation_minutes) {
            return $this->ok(['timezone' => $tz, 'days' => []]);
        }
        $slots = $bookings->freeSlots($a, CarbonImmutable::parse($d['from'], 'UTC')->startOfDay(), CarbonImmutable::parse($d['to'], 'UTC')->endOfDay());
        $days = [];
        foreach ($slots as $s) {
            $date = $s->setTimezone($tz)->format('Y-m-d');
            $days[$date] ??= ['date' => $date, 'slots' => []];
            $days[$date]['slots'][] = ['startsAt' => Front::iso($s), 'endsAt' => Front::iso($s->addMinutes($a->consultation_minutes))];
        }

        return $this->ok(['timezone' => $tz, 'days' => array_values($days)]);
    }

    /* ───────── bookings ───────── */

    public function bookings(Request $r, int $case, int $agreement): JsonResponse
    {
        app(\App\Services\Front\SessionCompletion::class)->run(50);
        $a = $this->agreementRow($r, $case, $agreement);

        return $this->ok(Booking::where('agreement_id', $a->id)->orderBy('start_at')->get()->map(fn ($b) => CommercePresenter::booking($b))->all());
    }

    public function hold(Request $r, int $case, int $agreement, Bookings $bookings): JsonResponse
    {
        $d = $r->validate(['startAt' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?Z$/']]);
        $start = CarbonImmutable::parse($d['startAt'])->utc()->format('Y-m-d\TH:i:s\Z');

        return $this->keyed($r, $d, fn () => $this->ok(CommercePresenter::booking($bookings->hold($this->user($r), $case, $agreement, $start)), 'Slot held.', 201));
    }

    public function booking(Request $r, int $case, int $booking): JsonResponse
    {
        $c = $this->user($r)->cases()->findOrFail($case);

        return $this->ok(CommercePresenter::booking(Booking::where('case_id', $c->id)->findOrFail($booking)));
    }

    public function confirm(Request $r, int $case, int $booking, Bookings $bookings): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1']]);

        return $this->keyed($r, $d, fn () => $this->ok(CommercePresenter::booking($bookings->confirm($this->user($r), $case, $booking, (int) $d['expectedVersion'])), 'Session confirmed.'));
    }

    public function cancelBooking(Request $r, int $case, int $booking, Bookings $bookings): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'reasonCode' => ['sometimes', 'nullable', 'string', 'max:40']]);
        $d['reasonCode'] = ReasonCodes::cancel($d['reasonCode'] ?? null, false);

        return $this->ok(CommercePresenter::booking($bookings->cancelByUser($this->user($r), $case, $booking, $d)), 'Session cancelled.');
    }

    public function reschedule(Request $r, int $case, int $booking, Bookings $bookings): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'startAt' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?Z$/']]);
        $d['startAt'] = CarbonImmutable::parse($d['startAt'])->utc()->format('Y-m-d\TH:i:s\Z');

        return $this->ok(CommercePresenter::booking($bookings->rescheduleByUser($this->user($r), $case, $booking, $d)), 'Session moved.');
    }

    /* ───────── funding ───────── */

    public function agreementFunding(Request $r, int $case, int $agreement): JsonResponse
    {
        $a = $this->agreementRow($r, $case, $agreement);
        $f = Funding::where('agreement_id', $a->id)->whereIn('status', Funding::ACTIVE)->orderByDesc('id')->first() ?? Funding::where('agreement_id', $a->id)->orderByDesc('id')->first();

        return $this->ok(CommercePresenter::funding($f));
    }

    public function openFunding(Request $r, int $case, int $agreement, Fundings $fundings): JsonResponse
    {
        $this->agreementRow($r, $case, $agreement);

        return $this->keyed($r, [], function () use ($r, $case, $agreement, $fundings) {
            $out = $fundings->open($this->user($r), $case, $agreement);
            abort_if($out['expired'], 409, 'The funding window for this agreement has closed. Start again to reopen it.');

            return $this->ok(CommercePresenter::funding($out['funding']), $out['created'] ? 'Funding request created.' : 'Funding request already exists.', $out['created'] ? 201 : 200);
        });
    }

    private function fundingRow(Request $r, int $case, int $funding): Funding
    {
        $c = $this->user($r)->cases()->findOrFail($case);

        return Funding::where(['case_id' => $c->id, 'user_id' => $c->user_id])->findOrFail($funding);
    }

    public function funding(Request $r, int $case, int $funding): JsonResponse
    {
        return $this->ok(CommercePresenter::funding($this->fundingRow($r, $case, $funding)));
    }

    public function checkout(Request $r, int $case, int $funding, Fundings $fundings): JsonResponse
    {
        $this->fundingRow($r, $case, $funding);
        abort_if($r->all() !== [], 422, 'This request takes no fields.');

        return $this->keyed($r, [], function () use ($r, $case, $funding, $fundings) {
            $out = $fundings->checkout($this->user($r), $case, $funding);
            abort_if($out['expired'], 409, 'The funding window for this agreement has closed.');

            return $this->ok(['checkoutUrl' => $out['attempt']->checkout_url, 'expiresAt' => Front::iso($out['attempt']->expires_at), 'reused' => $out['reused'],
                'funding' => CommercePresenter::funding($out['funding']->refresh())], 'Checkout ready.', $out['reused'] ? 200 : 201);
        });
    }

    public function sync(Request $r, int $case, int $funding, Fundings $fundings): JsonResponse
    {
        $this->fundingRow($r, $case, $funding);

        return $this->ok(CommercePresenter::funding($fundings->sync($this->user($r), $case, $funding)), 'Funding status refreshed.');
    }

    public function documents(Request $r, int $case, int $funding): JsonResponse
    {
        $f = $this->fundingRow($r, $case, $funding);

        return $this->ok($f->documents()->get()->map(fn (FinancialDocument $d) => ['id' => $d->id, 'type' => $d->document_type, 'number' => $d->number, 'status' => $d->status,
            'issuedAt' => Front::iso($d->issued_at), 'amount' => CommercePresenter::money((int) $d->amount_minor, $d->currency), 'providerReceiptUrl' => $d->provider_receipt_url])->all());
    }

    /* ───────── reviews ───────── */

    public function reviewEligibility(Request $r, int $case, int $agreement, ExpertReviews $reviews): JsonResponse
    {
        app(\App\Services\Front\SessionCompletion::class)->run(50);
        $state = $reviews->eligibility($this->user($r), $this->agreementRow($r, $case, $agreement));

        return $this->ok(['eligible' => $state['eligible'], 'reason' => match ($state['reasonCode']) {
            'ALREADY_REVIEWED' => 'already_reviewed', 'SERVICE_NOT_COMPLETED' => 'session_not_completed', default => $state['reasonCode'] ? strtolower($state['reasonCode']) : null,
        }, 'reviewId' => $state['reviewId']]);
    }

    public function review(Request $r, int $case, int $agreement, ExpertReviews $reviews): JsonResponse
    {
        $d = $r->validate(['rating' => ['required', 'integer', 'min:1', 'max:5'], 'body' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        $a = $this->agreementRow($r, $case, $agreement);

        return $this->keyed($r, $d, fn () => $this->ok(self::reviewShape($reviews->create($this->user($r), $a, $d)), 'Thank you. Your review is awaiting moderation.', 201));
    }

    public static function reviewShape(ExpertReview $rv): array
    {
        return ['id' => $rv->id, 'agreementId' => $rv->agreement_id, 'caseId' => $rv->case_id, 'expertId' => $rv->expert_id, 'rating' => $rv->rating, 'body' => $rv->body ?? '',
            'version' => $rv->version, 'status' => $rv->moderation_status === ExpertReview::PENDING ? 'pending_moderation' : $rv->moderation_status, 'createdAt' => Front::iso($rv->created_at)];
    }
}
