<?php

return [
    'frontend' => [
        'verify_email_url' => env('USER_FRONTEND_VERIFY_EMAIL_URL'),
        'reset_password_url' => env('USER_FRONTEND_RESET_PASSWORD_URL'),
    ],

    'email_verification' => [
        'expire' => 60,
    ],
];
