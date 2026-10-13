<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\PaymentRequest;
use App\Http\Resources\Payments\PaymentsPresenter;
use App\Models\Agreement;
use App\Models\FinancialDocument;
use App\Models\Funding;
use App\Models\FundingRefund;
use App\Services\Payments\FundingDeadline;
use App\Services\Payments\Fundings;
use App\Services\Payments\Refunds;
use App\Services\Privacy\ActorIdempotency;
use Illuminate\Http\JsonResponse;

/** User funding, Checkout, receipts and refund requests. Every object is resolved through the authenticated owner; foreign ids are 404. */
class FundingController extends Controller
{
    use MatchingResponses;

    public function agreementFunding(PaymentRequest $r, int $case, int $agreement, FundingDeadline $deadline): JsonResponse
    {
        $a = $this->agreement($r, $case, $agreement);
        $funding = Funding::where('agreement_id', $a->id)->whereIn('status', Funding::ACTIVE)->first() ?? Funding::where('agreement_id', $a->id)->orderByDesc('id')->first();
        $fundable = in_array($a->status, [Agreement::AWAITING_PAYMENT, Agreement::FUNDING_EXPIRED], true) && ($funding === null || ! in_array($funding->status, [Funding::FUNDED, Funding::CANCELLED], true));
        $preview = null;
        if ($funding === null && $fundable) {
            $due = $deadline->compute($a, $a->status === Agreement::FUNDING_EXPIRED ? now() : $a->accepted_at);
            $preview = ['deadlineAt' => $due['at']->format('Y-m-d\TH:i:s\Z'), 'deadlineRule' => $due['rule']];
        }

        return $this->item(['item' => $funding ? PaymentsPresenter::funding($funding) : null, 'agreementStatus' => $a->status, 'fundable' => $fundable,
            'blockReason' => $fundable ? null : 'AGREEMENT_NOT_FUNDABLE', 'preview' => $preview]);
    }

    public function open(PaymentRequest $r, int $case, int $agreement, Fundings $fundings, ActorIdempotency $idempotency): JsonResponse
    {
        $this->agreement($r, $case, $agreement);

        return $idempotency->run($r, $r->validated(), function () use ($r, $case, $agreement, $fundings): JsonResponse {
            $out = $fundings->open($r->user(), $case, $agreement);
            if ($out['expired']) {
                return $this->windowExpired();
            }

            return $this->item(PaymentsPresenter::funding($out['funding']), $out['created'] ? 'Funding request created.' : 'Funding request already exists.', $out['created'] ? 201 : 200);
        });
    }

    public function show(PaymentRequest $r, int $case, int $funding): JsonResponse
    {
        return $this->item(PaymentsPresenter::funding($this->funding($r, $case, $funding)));
    }

    public function checkout(PaymentRequest $r, int $case, int $funding, Fundings $fundings, ActorIdempotency $idempotency): JsonResponse
    {
        $this->funding($r, $case, $funding);

        return $idempotency->run($r, $r->validated(), function () use ($r, $case, $funding, $fundings): JsonResponse {
            $out = $fundings->checkout($r->user(), $case, $funding);
            if ($out['expired']) {
                return $this->windowExpired();
            }

            return $this->item(['funding' => PaymentsPresenter::funding($out['funding']->refresh()),
                'checkout' => ['attemptId' => $out['attempt']->id, 'url' => $out['attempt']->checkout_url, 'expiresAt' => $out['attempt']->expires_at->utc()->format('Y-m-d\TH:i:s\Z'), 'reused' => $out['reused']],
                'redirectIsNotProof' => true], 'Checkout ready.', $out['reused'] ? 200 : 201);
        });
    }

    public function sync(PaymentRequest $r, int $case, int $funding, Fundings $fundings, ActorIdempotency $idempotency): JsonResponse
    {
        $this->funding($r, $case, $funding);

        return $idempotency->run($r, $r->validated(), fn () => $this->item(PaymentsPresenter::funding($fundings->sync($r->user(), $case, $funding)), 'Funding status refreshed from the payment provider.'));
    }

    public function documents(PaymentRequest $r, int $case, int $funding): JsonResponse
    {
        $f = $this->funding($r, $case, $funding);

        return $this->items($f->documents()->get()->map(fn (FinancialDocument $d) => PaymentsPresenter::document($d))->all());
    }

    public function document(PaymentRequest $r, int $case, int $funding, int $financialDocument): JsonResponse
    {
        $f = $this->funding($r, $case, $funding);

        return $this->item(PaymentsPresenter::document(FinancialDocument::where(['funding_id' => $f->id, 'user_id' => $r->user()->id])->findOrFail($financialDocument)));
    }

    public function requestRefund(PaymentRequest $r, int $case, int $funding, Refunds $refunds, ActorIdempotency $idempotency): JsonResponse
    {
        $this->funding($r, $case, $funding);

        return $idempotency->run($r, $r->validated(), function () use ($r, $case, $funding, $refunds): JsonResponse {
            $out = $refunds->requestByUser($r->user(), $case, $funding, $r->validated());

            return $this->item(['outcome' => $out['outcome'], 'refund' => $out['refund'] ? PaymentsPresenter::refund($out['refund']) : null, 'bookingId' => $out['bookingId'],
                'funding' => PaymentsPresenter::funding(Funding::findOrFail($funding))], 'Request recorded.', $out['refund'] ? 201 : 200);
        });
    }

    public function refunds(PaymentRequest $r, int $case, int $funding): JsonResponse
    {
        $f = $this->funding($r, $case, $funding);

        return $this->items($f->refunds()->get()->map(fn (FundingRefund $x) => PaymentsPresenter::refund($x))->all());
    }

    private function windowExpired(): JsonResponse
    {
        return response()->json(['status' => false, 'code' => 'FUNDING_WINDOW_EXPIRED', 'message' => 'The funding window for this agreement has closed. Start a new funding request to continue.'], 409);
    }

    private function agreement(PaymentRequest $r, int $case, int $agreement): Agreement
    {
        $caseRow = $r->user()->cases()->findOrFail($case);

        return Agreement::where(['case_id' => $caseRow->id, 'user_id' => $r->user()->id])->findOrFail($agreement);
    }

    private function funding(PaymentRequest $r, int $case, int $funding): Funding
    {
        $caseRow = $r->user()->cases()->findOrFail($case);

        return Funding::where(['case_id' => $caseRow->id, 'user_id' => $r->user()->id])->findOrFail($funding);
    }
}
