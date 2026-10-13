<?php

namespace App\Notifications\Admin;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class AdminInvitationNotification extends Notification
{
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $token,
        public readonly Carbon $expiresAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been invited to the SolveIt administration area')
            ->greeting('Hello '.$notifiable->name)
            ->line('An authorized administrator created a restricted administration account for you.')
            ->line('Use the secure link below to choose your password and activate your account.')
            ->action('Accept administrator invitation', $this->invitationUrl($notifiable->email))
            ->line('This invitation expires at '.$this->expiresAt->toDayDateTimeString().'.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }

    private function invitationUrl(string $email): string
    {
        $frontendUrl = rtrim((string) config('admin.invitation.frontend_url'), '/');
        $separator = str_contains($frontendUrl, '?') ? '&' : '?';

        return $frontendUrl.$separator.http_build_query([
            'email' => $email,
            'token' => $this->token,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
