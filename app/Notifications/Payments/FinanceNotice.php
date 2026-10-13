<?php

namespace App\Notifications\Payments;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Funding / refund / dispute notice. Event type and ids only: never amounts, payment details, Case text or links to provider pages.
 * Nothing here claims that an Expert has been paid (no payout exists in Sprint 5).
 */
class FinanceNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const USER_TYPES = ['funding.requires_action', 'funding.confirmed', 'funding.failed', 'funding.expired', 'refund.requested', 'refund.completed', 'refund.failed'];

    public const EXPERT_TYPES = ['funding.confirmed', 'funding.expired'];

    public const ADMIN_TYPES = ['refund.review_required', 'reconciliation.failed', 'dispute.opened', 'stripe_account.issue'];

    public int $tries = 3;

    /** @param array<string, int|string> $refs */
    public function __construct(public readonly string $type, public readonly array $refs)
    {
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => $this->type] + $this->refs + ['occurredAt' => now()->toISOString()];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('SolveIt: update on a service payment')
            ->line('There is an update on a payment-related item in your SolveIt account.')
            ->line('Sign in to view the details. For your security, payment details are never included in email.');
    }
}
