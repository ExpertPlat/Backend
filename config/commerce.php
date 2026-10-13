<?php

// Sprint 4 (docs/sprint4/CONTRACT.md). Sprint 4.1 applied the Product Owner decisions DR-071..DR-080 (CLAUDE.md §16A).
// Values marked PLANNED are approved policy captured as configuration only; they are enforced when funding exists (Sprint 5).
return [
    // Allowed ISO 4217 currencies (DR-080: USD, EUR, JOD, SAR; AED added for the v7 frontend, which prices in AED). Centralized and extensible.
    'currencies' => array_values(array_filter(array_map('trim', explode(',', (string) env('COMMERCE_CURRENCIES', 'AED,USD,EUR,JOD,SAR'))))),
    'max_amount_minor' => 100_000_000_000,
    'proposal_validity_days' => (int) env('COMMERCE_PROPOSAL_VALIDITY_DAYS', 3),
    'max_proposal_versions' => 10,
    'review_period_hours_default' => (int) env('COMMERCE_REVIEW_PERIOD_HOURS', 72),
    'slot_granularity_minutes' => (int) env('COMMERCE_SLOT_GRANULARITY_MINUTES', 15),
    'hold_ttl_minutes' => (int) env('COMMERCE_HOLD_TTL_MINUTES', 30),
    'min_lead_minutes' => (int) env('COMMERCE_MIN_LEAD_MINUTES', 60),
    'max_horizon_days' => (int) env('COMMERCE_MAX_HORIZON_DAYS', 60),
    'reschedule_min_notice_hours' => (int) env('COMMERCE_RESCHEDULE_MIN_NOTICE_HOURS', 24),
    'max_reschedules' => (int) env('COMMERCE_MAX_RESCHEDULES', 3),
    // DR-073/DR-086 (enforced since Sprint 5 by App\Services\Payments\FundingDeadline).
    'funding_window_hours' => (int) env('COMMERCE_FUNDING_WINDOW_HOURS', 12),
    'funding_min_lead_minutes' => (int) env('COMMERCE_FUNDING_MIN_LEAD_MINUTES', 120),
    // Immediate flow (consultation closer than the minimum lead): payment must complete within this window (default = hold TTL).
    'funding_immediate_window_minutes' => (int) env('COMMERCE_FUNDING_IMMEDIATE_WINDOW_MINUTES', 30),
    // DR-076/DR-089 (enforced since Sprint 5 by RefundPolicy): MVP cancellation/reschedule policy. No penalty percentages; user no-show penalty is deferred.
    'cancellation' => [
        'user_full_refund_or_free_reschedule_notice_hours' => (int) env('COMMERCE_CANCEL_FREE_NOTICE_HOURS', 24),
        'late_free_reschedules' => (int) env('COMMERCE_LATE_FREE_RESCHEDULES', 1),
        'technical_recovery_minutes' => (int) env('COMMERCE_TECHNICAL_RECOVERY_MINUTES', 15),
    ],
    'availability_max_days' => 14,
    'availability_max_slots' => 500,
    'limits' => ['write' => 20, 'read' => 120],
];
