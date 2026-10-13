<?php

return [
    // Backend-only secrets/config. The key is never returned by an API, logged or committed.
    'api_key' => env('OPENAI_API_KEY'),
    // The exact model is a Product Owner / operations decision and is intentionally not defaulted.
    'model' => env('OPENAI_MODEL'),
    'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
    'timeout_seconds' => (int) env('OPENAI_TIMEOUT_SECONDS', 12),
    'connect_timeout_seconds' => 3,
    'retries' => 1,
    'retry_sleep_ms' => 300,
    'intake' => [
        'enabled' => (bool) env('OPENAI_INTAKE_ENABLED', true),
        'max_questions' => min(8, max(1, (int) env('OPENAI_INTAKE_MAX_QUESTIONS', 5))),
        'purpose' => 'intake_follow_up_questions',
        'rate_per_minute' => 5,
        'rate_per_hour' => 30,
    ],
];
