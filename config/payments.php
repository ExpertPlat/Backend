<?php

// Sprint 5 (docs/sprint5/CONTRACT.md). Test Mode only until the legal entity country is selected (DR-083).
// Secrets come from the environment only; nothing here is a real value and none may be committed.
return [
    // `stripe` (default) or `fake`: a local/testing-only stand-in that never moves money (see FakeStripeGateway).
    'driver' => env('PAYMENTS_DRIVER', 'stripe'),
    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'publishable' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Explicit legal-entity country (ISO 3166-1 alpha-2). Intentionally has NO default: DR-083.
        'platform_country' => env('STRIPE_PLATFORM_COUNTRY'),
        'webhook_tolerance_seconds' => (int) env('PAYMENTS_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],
    'urls' => [
        'connect_return' => env('STRIPE_CONNECT_RETURN_URL'),
        'connect_refresh' => env('STRIPE_CONNECT_REFRESH_URL'),
        'checkout_success' => env('STRIPE_CHECKOUT_SUCCESS_URL'),
        'checkout_cancel' => env('STRIPE_CHECKOUT_CANCEL_URL'),
    ],
    // DR-088: 10% = 1000 basis points. The single source of truth for the platform commission.
    'commission_bps' => (int) env('PAYMENTS_COMMISSION_BPS', 1000),
    // Stripe Checkout Sessions must live between 30 minutes and 24 hours.
    'checkout_min_minutes' => 30,
    'checkout_max_hours' => 24,
    // A processing/approved record older than this is picked up by reconciliation.
    'reconcile_stale_minutes' => (int) env('PAYMENTS_RECONCILE_STALE_MINUTES', 20),
    'limits' => ['read' => 120, 'write' => 20, 'sync' => 12, 'webhook' => 300],
];
