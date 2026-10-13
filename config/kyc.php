<?php

return [
    'disk' => env('KYC_FILESYSTEM_DISK', 'kyc'),

    'documents' => [
        'max_size_kilobytes' => 10 * 1024,
        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
        'max_work_samples' => 5,
    ],
];
