<?php

namespace App\Notifications\User;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends VerifyEmail
{
    /**
     * Build the signed regular-user verification URL.
     */
    protected function verificationUrl($notifiable): string
    {
        $signedUrl = URL::temporarySignedRoute(
            'auth.email.verify',
            Carbon::now()->addMinutes((int) config('user.email_verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );

        $frontendUrl = config('user.frontend.verify_email_url');

        if (! is_string($frontendUrl) || trim($frontendUrl) === '') {
            return $signedUrl;
        }

        $separator = str_contains($frontendUrl, '?') ? '&' : '?';

        return rtrim($frontendUrl).$separator.http_build_query(
            ['verification_url' => $signedUrl],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }
}
