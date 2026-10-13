<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Http\Front\FrontResponses;
use App\Http\Front\Presenters\CommercePresenter;
use App\Models\Funding;
use App\Models\FundingAttempt;
use App\Models\User;
use App\Services\Payments\FakeStripeGateway;
use App\Services\Payments\ProviderSync;
use App\Services\Payments\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Local test checkout (PAYMENTS_DRIVER=fake, local/testing only): the v7 "test payment" dialog calls
 * POST /api/__mock/fundings/{funding}/complete. The payment is then applied by the real ProviderSync path.
 */
class DevPaymentController extends Controller
{
    use FrontResponses;

    public function complete(Request $r, int $funding, ProviderSync $sync): JsonResponse
    {
        abort_unless(FakeStripeGateway::enabled(), 404);
        $u = $r->user();
        abort_unless($u instanceof User, 403);
        $f = Funding::where('user_id', $u->id)->findOrFail($funding);
        $attempt = FundingAttempt::where('funding_id', $f->id)->whereIn('status', [FundingAttempt::OPEN, FundingAttempt::PROCESSING])->orderByDesc('id')->first();
        abort_if($attempt === null, 409, 'Open the checkout first.');
        $gateway = app(StripeGateway::class);
        abort_unless($gateway instanceof FakeStripeGateway, 404);
        $gateway->completeCheckout($attempt->stripe_checkout_session_id);
        $sync->syncFunding($f->id);

        return $this->ok(CommercePresenter::funding($f->fresh()), 'Test payment completed.');
    }
}
