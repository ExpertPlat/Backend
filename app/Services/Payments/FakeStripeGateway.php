<?php

namespace App\Services\Payments;

use App\Exceptions\PrivacyException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Local/testing stand-in for Stripe (PAYMENTS_DRIVER=fake). It returns the same plain-array shapes the services read,
 * keeps its objects in the cache, and never moves money. The v7 frontend shows its own "test checkout" for `mock://`
 * URLs and completes it through POST /api/__mock/fundings/{funding}/complete, which marks the session paid here and
 * then runs the real ProviderSync path (ledger, receipt, agreement → funded). Refused outside local/testing.
 */
class FakeStripeGateway extends StripeGateway
{
    private const TTL = 86400 * 30;

    public static function enabled(): bool
    {
        return config('payments.driver') === 'fake' && app()->environment(['local', 'testing']);
    }

    private function put(string $id, array $object): array
    {
        Cache::put('fake-stripe:'.$id, $object, self::TTL);

        return $object;
    }

    private function get(string $id): array
    {
        return Cache::get('fake-stripe:'.$id) ?? throw new PrivacyException('PAYMENT_PROVIDER_REJECTED', 'Unknown test payment object.', 502);
    }

    public function configured(): bool
    {
        return self::enabled();
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        if ($existing = Cache::get('fake-stripe-idem:'.$idempotencyKey)) {
            return $this->get($existing);
        }
        $id = 'cs_test_fake_'.Str::lower(Str::random(20));
        $funding = $params['metadata']['funding_id'] ?? '0';
        Cache::put('fake-stripe-idem:'.$idempotencyKey, $id, self::TTL);

        return $this->put($id, ['id' => $id, 'object' => 'checkout.session', 'url' => "mock://checkout/{$funding}", 'status' => 'open', 'payment_status' => 'unpaid',
            'expires_at' => $params['expires_at'] ?? now()->addHour()->getTimestamp(), 'amount_total' => $params['line_items'][0]['price_data']['unit_amount'] ?? null,
            'currency' => $params['line_items'][0]['price_data']['currency'] ?? null, 'metadata' => $params['metadata'] ?? [], 'payment_intent' => null]);
    }

    /** Test-only: the payer "completed" the hosted checkout. */
    public function completeCheckout(string $sessionId): void
    {
        $s = $this->get($sessionId);
        $pi = 'pi_test_fake_'.Str::lower(Str::random(20));
        $this->put($pi, ['id' => $pi, 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => $s['amount_total'], 'currency' => $s['currency'], 'created' => now()->getTimestamp(),
            'metadata' => $s['metadata'], 'latest_charge' => ['id' => 'ch_test_fake_'.Str::lower(Str::random(12)), 'created' => now()->getTimestamp(), 'receipt_url' => null, 'amount_refunded' => 0,
                'balance_transaction' => ['fee' => 0, 'currency' => $s['currency']]]]);
        $this->put($sessionId, array_merge($s, ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => $pi]));
    }

    public function retrieveCheckoutSession(string $id): array
    {
        $s = $this->get($id);
        if ($s['payment_intent']) {
            $s['payment_intent'] = $this->get($s['payment_intent']);
        }

        return $s;
    }

    public function expireCheckoutSession(string $id): array
    {
        return $this->put($id, array_merge($this->get($id), ['status' => 'expired']));
    }

    public function retrievePaymentIntent(string $id): array
    {
        return $this->get($id);
    }

    public function createRefund(array $params, string $idempotencyKey): array
    {
        $id = 're_test_fake_'.Str::lower(Str::random(16));

        return $this->put($id, ['id' => $id, 'object' => 'refund', 'status' => 'succeeded', 'amount' => $params['amount'] ?? null, 'payment_intent' => $params['payment_intent'] ?? null, 'metadata' => $params['metadata'] ?? []]);
    }

    public function retrieveRefund(string $id): array
    {
        return $this->get($id);
    }

    public function retrieveDispute(string $id): array
    {
        return $this->get($id);
    }

    public function createAccount(array $params, string $idempotencyKey): array
    {
        $id = 'acct_test_fake_'.Str::lower(Str::random(12));

        return $this->put($id, ['id' => $id, 'object' => 'account', 'details_submitted' => false, 'charges_enabled' => false, 'payouts_enabled' => false, 'requirements' => ['currently_due' => ['external_account']]]);
    }

    public function retrieveAccount(string $id): array
    {
        return $this->get($id);
    }

    /** The test onboarding link completes the account immediately (there is no hosted form locally). */
    public function createAccountLink(array $params): array
    {
        $this->put($params['account'], array_merge($this->get($params['account']), ['details_submitted' => true, 'charges_enabled' => true, 'payouts_enabled' => true, 'requirements' => ['currently_due' => []]]));

        return ['url' => 'mock://stripe-onboarding', 'expires_at' => now()->addMinutes(10)->getTimestamp()];
    }

    public function constructEvent(string $payload, ?string $signatureHeader): array
    {
        throw new PrivacyException('WEBHOOK_SIGNATURE_INVALID', 'Webhooks are not used by the test payment driver.', 400);
    }
}
