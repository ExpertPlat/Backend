<?php

namespace App\Notifications\Expert;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

class ResetPasswordNotification extends ResetPassword
{
    /**
     * Build the expert frontend reset URL.
     */
    protected function resetUrl($notifiable): string
    {
        $frontendUrl = config('expert.frontend.reset_password_url');

        if (! is_string($frontendUrl) || trim($frontendUrl) === '') {
            $frontendUrl = rtrim((string) config('app.url'), '/').'/expert/reset-password';
        }

        $separator = str_contains($frontendUrl, '?') ? '&' : '?';

        return rtrim($frontendUrl).$separator.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Build the mail message using the expert broker's expiration period.
     */
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject(Lang::get('Reset your expert account password'))
            ->line(Lang::get('You are receiving this email because we received a password reset request for your expert account.'))
            ->action(Lang::get('Reset Password'), $url)
            ->line(Lang::get('This password reset link will expire in :count minutes.', [
                'count' => config('auth.passwords.experts.expire'),
            ]))
            ->line(Lang::get('If you did not request a password reset, no further action is required.'));
    }
}
