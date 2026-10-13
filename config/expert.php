<?php

return [
    'frontend' => [
        'verify_email_url' => env('EXPERT_FRONTEND_VERIFY_EMAIL_URL'),
        'reset_password_url' => env('EXPERT_FRONTEND_RESET_PASSWORD_URL'),
        'kyc_url' => env('EXPERT_FRONTEND_KYC_URL'),
    ],

    'email_verification' => [
        'expire' => 60,
    ],
];
