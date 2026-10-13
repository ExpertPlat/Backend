<?php

namespace App\Notifications\Commerce;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Proposal / agreement / booking workflow notice. Ids and an event type only: never amounts, Case text, notes,
 * messages or any content that could leak through email or a notification feed. It never says anything about payment.
 */
class CommerceNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPES = [
        'proposal.submitted', 'proposal.revised', 'proposal.change_requested', 'proposal.accepted', 'proposal.rejected', 'proposal.withdrawn', 'proposal.expired',
        'agreement.cancelled', 'booking.confirmed', 'booking.cancelled', 'booking.rescheduled', 'booking.hold_expired',
    ];

    public int $tries = 3;

    /** @param array<string, int> $refs */
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
            ->subject('SolveIt: update on your service')
            ->line('There is an update on a service in your SolveIt account.')
            ->line('Sign in to view the details. For your privacy, details are never included in email.');
    }
}
