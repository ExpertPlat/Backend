<?php

return [
    // Must be set to an approved operational SLA before accepting requests.
    // This value is not a statement of a jurisdiction's legal deadline.
    'due_days' => env('DATA_REQUEST_DUE_DAYS') !== null ? (int) env('DATA_REQUEST_DUE_DAYS') : null,
    'artifact_hours' => 24,
    'disk' => env('DATA_EXPORTS_DISK', 'data-exports'),
    'lease_minutes' => 10,
    'sections' => ['account', 'profile', 'preferences', 'contexts', 'consents', 'data_requests'],
];
