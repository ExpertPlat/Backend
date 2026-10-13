<?php

return [
    'initial' => [
        'name' => env('ADMIN_NAME'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'invitation' => [
        'frontend_url' => env(
            'ADMIN_FRONTEND_INVITATION_URL',
            'http://localhost:3000/admin/accept-invitation',
        ),
        'expire_hours' => (int) env('ADMIN_INVITATION_EXPIRE_HOURS', 72),
    ],
];
