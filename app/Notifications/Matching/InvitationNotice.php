<?php

namespace App\Notifications\Matching;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Workflow notice for the invitation lifecycle. Carries ids and an event type only: never Case text, titles,
 * document names, expert reasons or any other content that could leak through email or a notification feed.
 */
class InvitationNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPES = ['invitation.created', 'invitation.expiring', 'invitation.accepted', 'invitation.declined', 'invitation.expired', 'invitation.revoked'];

    public int $tries = 3;

    public function __construct(public readonly string $type, public readonly int $invitationId)
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
        return ['type' => $this->type, 'invitationId' => $this->invitationId, 'occurredAt' => now()->toISOString()];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('SolveIt: update on an invitation')
            ->line('There is an update on an invitation in your SolveIt account.')
            ->line('Sign in to view the details. For your privacy, case details are never included in email.');
    }
}
