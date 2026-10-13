<?php

namespace App\Services\Payments;

use App\Exceptions\PrivacyException;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;

/**
 * The single Stripe boundary (official SDK, plain-array results). Services never touch SDK types, and tests replace this class with a
 * fake. Provider errors are mapped to stable application codes; provider messages, payloads and keys are never returned or logged.
 */
class StripeGateway
{
    private ?StripeClient $client = null;

    public static function mode(): string
    {
        $key = (string) config('payments.stripe.secret');

        return match (true) {
            $key === '' => 'unconfigured',
            str_starts_with($key, 'sk_live_'), str_starts_with($key, 'rk_live_') => 'live',
            str_starts_with($key, 'sk_test_'), str_starts_with($key, 'rk_test_') => 'test',
            default => 'unconfigured',
        };
    }

    public function configured(): bool
    {
        return self::mode() !== 'unconfigured';
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->call(fn () => $this->client()->checkout->sessions->create($params, ['idempotency_key' => $idempotencyKey])->toArray());
    }

    /** @return array<string, mixed> */
    public function retrieveCheckoutSession(string $id): array
    {
        return $this->call(fn () => $this->client()->checkout->sessions->retrieve($id, ['expand' => ['payment_intent']])->toArray());
    }

    /** @return array<string, mixed> */
    public function expireCheckoutSession(string $id): array
    {
        return $this->call(fn () => $this->client()->checkout->sessions->expire($id)->toArray());
    }

    /** @return array<string, mixed> */
    public function retrievePaymentIntent(string $id): array
    {
        return $this->call(fn () => $this->client()->paymentIntents->retrieve($id, ['expand' => ['latest_charge.balance_transaction']])->toArray());
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function createRefund(array $params, string $idempotencyKey): array
    {
        return $this->call(fn () => $this->client()->refunds->create($params, ['idempotency_key' => $idempotencyKey])->toArray());
    }

    /** @return array<string, mixed> */
    public function retrieveRefund(string $id): array
    {
        return $this->call(fn () => $this->client()->refunds->retrieve($id)->toArray());
    }

    /** @return array<string, mixed> */
    public function retrieveDispute(string $id): array
    {
        return $this->call(fn () => $this->client()->disputes->retrieve($id)->toArray());
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function createAccount(array $params, string $idempotencyKey): array
    {
        return $this->call(fn () => $this->client()->accounts->create($params, ['idempotency_key' => $idempotencyKey])->toArray());
    }

    /** @return array<string, mixed> */
    public function retrieveAccount(string $id): array
    {
        return $this->call(fn () => $this->client()->accounts->retrieve($id)->toArray());
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function createAccountLink(array $params): array
    {
        return $this->call(fn () => $this->client()->accountLinks->create($params)->toArray());
    }

    /**
     * Verifies the Stripe-Signature over the raw payload and returns the event as an array. Throws PrivacyException(400) on any
     * signature/payload problem; nothing about the failure is exposed.
     *
     * @return array<string, mixed>
     */
    public function constructEvent(string $payload, ?string $signatureHeader): array
    {
        $secret = (string) config('payments.stripe.webhook_secret');
        if ($secret === '' || $signatureHeader === null || $signatureHeader === '') {
            throw new PrivacyException('WEBHOOK_SIGNATURE_INVALID', 'The webhook signature is invalid.', 400);
        }
        try {
            return Webhook::constructEvent($payload, $signatureHeader, $secret, (int) config('payments.stripe.webhook_tolerance_seconds'))->toArray();
        } catch (SignatureVerificationException|UnexpectedValueException) {
            throw new PrivacyException('WEBHOOK_SIGNATURE_INVALID', 'The webhook signature is invalid.', 400);
        }
    }

    protected function client(): StripeClient
    {
        if (! $this->configured()) {
            throw new PrivacyException('PAYMENT_PROVIDER_UNAVAILABLE', 'The payment provider is not configured.', 503);
        }
        if (self::mode() === 'live' && (string) config('payments.stripe.platform_country') === '') {
            throw new PrivacyException('PRODUCTION_COUNTRY_NOT_CONFIGURED', 'Live payments are blocked until the platform legal-entity country is configured.', 503);
        }

        return $this->client ??= new StripeClient((string) config('payments.stripe.secret'));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    protected function call(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (PrivacyException $e) {
            throw $e;
        } catch (InvalidRequestException $e) {
            Log::warning('stripe.rejected', ['type' => $e::class, 'status' => $e->getHttpStatus(), 'stripe_code' => $e->getStripeCode()]);
            throw new PrivacyException('PAYMENT_PROVIDER_REJECTED', 'The payment provider rejected the request.', 422);
        } catch (ApiConnectionException|RateLimitException|AuthenticationException|ApiErrorException $e) {
            Log::warning('stripe.unavailable', ['type' => $e::class, 'status' => $e->getHttpStatus()]);
            throw new PrivacyException('PAYMENT_PROVIDER_UNAVAILABLE', 'The payment provider is temporarily unavailable.', 503);
        } catch (Throwable $e) {
            Log::error('stripe.unexpected', ['type' => $e::class]);
            throw new PrivacyException('PAYMENT_PROVIDER_UNAVAILABLE', 'The payment provider is temporarily unavailable.', 503);
        }
    }
}
