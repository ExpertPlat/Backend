<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\AdminFinanceRequest;
use App\Http\Resources\Payments\PaymentsPresenter;
use App\Models\Funding;
use App\Models\FundingDispute;
use App\Models\FundingRefund;
use App\Models\LedgerEvent;
use App\Models\ReconciliationIssue;
use App\Models\StripeAccount;
use App\Services\Payments\PaymentReconciler;
use App\Services\Payments\Refunds;
use App\Services\Privacy\ActorIdempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Admin finance operations. Each action needs its explicit permission. There is deliberately NO endpoint to edit ledger rows or amounts,
 * to mark a payment paid, or to confirm provider state: payment truth comes only from Stripe through the reconciliation path.
 */
class FinanceController extends Controller
{
    use MatchingResponses;

    public function fundings(AdminFinanceRequest $r): JsonResponse
    {
        Gate::authorize('finance.view');
        $d = $r->validated();
        $q = Funding::query()->orderByDesc('id')->when(isset($d['status']), fn ($x) => $x->where('status', $d['status']));

        return $this->page($q, $d, fn (Funding $f) => PaymentsPresenter::fundingForAdmin($f));
    }

    public function funding(AdminFinanceRequest $r, int $funding): JsonResponse
    {
        Gate::authorize('finance.view');

        return $this->item(PaymentsPresenter::fundingForAdmin(Funding::findOrFail($funding), true));
    }

    public function ledger(AdminFinanceRequest $r): JsonResponse
    {
        Gate::authorize('finance.view');
        $d = $r->validated();

        return $this->page(LedgerEvent::query()->orderByDesc('id')->when(isset($d['fundingId']), fn ($x) => $x->where('funding_id', $d['fundingId'])), $d, fn (LedgerEvent $e) => PaymentsPresenter::ledger($e));
    }

    public function refunds(AdminFinanceRequest $r): JsonResponse
    {
        Gate::authorize('refunds.view');
        $d = $r->validated();
        $q = FundingRefund::query()->orderByDesc('id')->when(isset($d['status']), fn ($x) => $x->where('status', $d['status']))->when(isset($d['fundingId']), fn ($x) => $x->where('funding_id', $d['fundingId']));

        return $this->page($q, $d, fn (FundingRefund $x) => PaymentsPresenter::refund($x, true));
    }

    public function decide(AdminFinanceRequest $r, int $refund, Refunds $refunds, ActorIdempotency $idempotency): JsonResponse
    {
        Gate::authorize('refunds.approve');

        return $idempotency->run($r, $r->validated(), fn () => $this->item(PaymentsPresenter::refund($refunds->decide($r->user(), $refund, $r->validated()), true), 'Refund decision recorded.'));
    }

    public function createRefund(AdminFinanceRequest $r, int $funding, Refunds $refunds, ActorIdempotency $idempotency): JsonResponse
    {
        Gate::authorize('refunds.approve');

        return $idempotency->run($r, $r->validated(), fn () => $this->item(PaymentsPresenter::refund($refunds->createByAdmin($r->user(), $funding, $r->validated()), true), 'Refund approved.', 201));
    }

    public function reconciliation(AdminFinanceRequest $r): JsonResponse
    {
        Gate::authorize('finance.view');
        $d = $r->validated();
        $q = ReconciliationIssue::query()->orderByDesc('id')->when(isset($d['status']), fn ($x) => $x->where('status', $d['status']))->when(isset($d['kind']), fn ($x) => $x->where('kind', $d['kind']))
            ->when(isset($d['fundingId']), fn ($x) => $x->where('funding_id', $d['fundingId']));

        return $this->page($q, $d, fn (ReconciliationIssue $i) => PaymentsPresenter::issue($i));
    }

    public function reconcile(AdminFinanceRequest $r, int $funding, PaymentReconciler $reconciler, ActorIdempotency $idempotency): JsonResponse
    {
        Gate::authorize('finance.reconcile');
        Funding::findOrFail($funding);

        return $idempotency->run($r, [], fn () => $this->item($reconciler->reconcileFunding($funding, true), 'Reconciliation completed against the payment provider.'));
    }

    public function disputes(AdminFinanceRequest $r): JsonResponse
    {
        Gate::authorize('disputes.view');
        $d = $r->validated();

        return $this->page(FundingDispute::query()->orderByDesc('id')->when(isset($d['status']), fn ($x) => $x->where('status', $d['status'])), $d, fn (FundingDispute $x) => PaymentsPresenter::dispute($x));
    }

    public function stripeAccounts(AdminFinanceRequest $r): JsonResponse
    {
        Gate::authorize('stripe_accounts.view');

        return $this->page(StripeAccount::query()->orderByDesc('id'), $r->validated(), fn (StripeAccount $a) => PaymentsPresenter::accountForAdmin($a));
    }
}
