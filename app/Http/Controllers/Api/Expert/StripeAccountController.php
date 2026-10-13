<?php

namespace App\Http\Controllers\Api\Expert;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\ExpertPaymentRequest;
use App\Http\Resources\Payments\PaymentsPresenter;
use App\Models\Agreement;
use App\Models\Funding;
use App\Services\Payments\ConnectAccounts;
use App\Services\Privacy\ActorIdempotency;
use Illuminate\Http\JsonResponse;

/** Expert Stripe Connect onboarding and funding visibility. Never returns the Stripe account id; the Account Link is returned only here, in the response. */
class StripeAccountController extends Controller
{
    use MatchingResponses;

    public function show(ExpertPaymentRequest $r, ConnectAccounts $accounts): JsonResponse
    {
        return $this->item(PaymentsPresenter::account($accounts->find($r->user())));
    }

    public function onboardingLink(ExpertPaymentRequest $r, ConnectAccounts $accounts, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, [], function () use ($r, $accounts): JsonResponse {
            $out = $accounts->onboardingLink($r->user());

            return $this->item(['account' => PaymentsPresenter::account($out['account']), 'onboarding' => ['url' => $out['url'], 'expiresAt' => $out['expiresAt']]], 'Onboarding link created.', 201);
        });
    }

    /** The return URL is not proof of completion: the account is refetched from Stripe. */
    public function onboardingReturn(ExpertPaymentRequest $r, ConnectAccounts $accounts, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, [], fn () => $this->item(PaymentsPresenter::account($accounts->sync($r->user())), 'Account status refreshed from Stripe.'));
    }

    public function sync(ExpertPaymentRequest $r, ConnectAccounts $accounts, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, [], fn () => $this->item(PaymentsPresenter::account($accounts->sync($r->user())), 'Account status refreshed from Stripe.'));
    }

    public function funding(ExpertPaymentRequest $r, int $agreement): JsonResponse
    {
        $a = Agreement::where('expert_id', $r->user()->id)->findOrFail($agreement);
        $funding = Funding::where('agreement_id', $a->id)->orderByDesc('id')->first();

        return $this->item(['agreementStatus' => $a->status, 'funding' => $funding ? PaymentsPresenter::fundingForExpert($funding) : null]);
    }
}
