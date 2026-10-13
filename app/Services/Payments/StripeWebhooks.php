<?php

namespace App\Services\Payments;

use App\Exceptions\PrivacyException;
use App\Models\FundingAttempt;
use App\Models\StripeWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stripe webhook processing (DR-090). The signature is verified over the raw body; the event id is stored for idempotency (the payload is not).
 * An event is only a trigger: the authoritative object is refetched, so replays and out-of-order delivery cannot corrupt state.
 */
class StripeWebhooks
{
    private const CHECKOUT = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'];

    private const PAYMENT_INTENT = ['payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.processing', 'payment_intent.requires_action', 'payment_intent.canceled'];

    public function __construct(private StripeGateway $stripe, private ProviderSync $sync, private Refunds $refunds, private Disputes $disputes, private ConnectAccounts $accounts) {}

    /** @return string `processed` | `ignored` | `duplicate` */
    public function handle(string $payload, ?string $signature): string
    {
        $event = $this->stripe->constructEvent($payload, $signature); // throws PrivacyException(400) when invalid
        $id = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        $object = (array) ($event['data']['object'] ?? []);
        $livemode = (bool) ($event['livemode'] ?? false);
        if ($id === '' || $type === '') {
            throw new PrivacyException('WEBHOOK_SIGNATURE_INVALID', 'The webhook signature is invalid.', 400);
        }

        try {
            return DB::transaction(function () use ($id, $type, $object, $livemode): string {
                $row = StripeWebhookEvent::where('stripe_event_id', $id)->lockForUpdate()->first();
                if ($row === null) {
                    try {
                        $row = new StripeWebhookEvent;
                        $row->forceFill(['stripe_event_id' => $id, 'event_type' => substr($type, 0, 80), 'livemode' => $livemode, 'object_id' => isset($object['id']) ? substr((string) $object['id'], 0, 100) : null,
                            'status' => StripeWebhookEvent::RECEIVED, 'attempts' => 0])->save();
                    } catch (UniqueConstraintViolationException) {
                        // a concurrent delivery inserted it first
                    }
                    $row = StripeWebhookEvent::where('stripe_event_id', $id)->lockForUpdate()->firstOrFail();
                }
                if (in_array($row->status, [StripeWebhookEvent::PROCESSED, StripeWebhookEvent::IGNORED], true)) {
                    return 'duplicate';
                }
                $handled = $livemode === (StripeGateway::mode() === 'live') && $this->route($type, $object);
                $row->forceFill(['status' => $handled ? StripeWebhookEvent::PROCESSED : StripeWebhookEvent::IGNORED, 'attempts' => $row->attempts + 1, 'processed_at' => now(), 'error_code' => null])->save();

                return $handled ? 'processed' : 'ignored';
            });
        } catch (Throwable $e) {
            // The transaction rolled back; keep a minimal failure marker (no payload) so operations can see it, and let Stripe retry.
            $code = $e instanceof PrivacyException ? $e->errorCode : 'WEBHOOK_PROCESSING_FAILED';
            report($e);
            $failure = ['status' => StripeWebhookEvent::FAILED, 'error_code' => substr($code, 0, 60), 'updated_at' => now()];
            if (DB::table('stripe_webhook_events')->where('stripe_event_id', $id)->exists()) {
                DB::table('stripe_webhook_events')->where('stripe_event_id', $id)->update($failure + ['attempts' => DB::raw('attempts + 1')]);
            } else {
                DB::table('stripe_webhook_events')->insert($failure + ['stripe_event_id' => $id, 'event_type' => substr($type, 0, 80), 'livemode' => $livemode, 'attempts' => 1, 'created_at' => now()]);
            }
            throw new PrivacyException('WEBHOOK_PROCESSING_FAILED', 'The webhook could not be processed.', 500);
        }
    }

    /** @param array<string, mixed> $obj */
    private function route(string $type, array $obj): bool
    {
        if (in_array($type, self::CHECKOUT, true)) {
            $attemptId = FundingAttempt::where('stripe_checkout_session_id', (string) ($obj['id'] ?? ''))->value('id');
            if ($attemptId === null) {
                return false;
            }
            $this->sync->syncAttempt((int) $attemptId);

            return true;
        }
        if (in_array($type, self::PAYMENT_INTENT, true)) {
            $attemptId = $this->sync->attemptForPaymentIntent($obj);
            if ($attemptId === null) {
                return false;
            }
            $this->sync->syncAttempt($attemptId);

            return true;
        }

        return match ($type) {
            'refund.created', 'refund.updated', 'refund.failed', 'charge.refund.updated' => $this->ack(fn () => $this->refunds->syncByStripeId((string) ($obj['id'] ?? ''))),
            'charge.refunded' => $this->ack(fn () => $this->refunds->syncForCharge($obj)),
            'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed' => $this->ack(fn () => $this->disputes->syncByStripeId((string) ($obj['id'] ?? ''))),
            'account.updated' => $this->accounts->syncByStripeId((string) ($obj['id'] ?? '')),
            default => false,
        };
    }

    private function ack(callable $work): bool
    {
        $work();

        return true;
    }
}
