<?php

return [
    'algorithm_version' => 'deterministic-1',
    // Stable tie-break secret. APP_KEY is deliberately never used for matching order.
    'tie_break_key' => env('MATCHING_TIE_BREAK_KEY'),
    'invitation_ttl_hours' => (int) env('MATCHING_INVITATION_TTL_HOURS', 72),
    'proposal_window_days' => (int) env('MATCHING_PROPOSAL_WINDOW_DAYS', 7),
    'expiring_notice_hours' => (int) env('MATCHING_EXPIRING_NOTICE_HOURS', 12),
    'max_open_invitations_per_scope' => (int) env('MATCHING_MAX_OPEN_INVITATIONS_PER_SCOPE', 5),
    'max_universe' => 500,
    // Metadata-only release until real scanning and private object delivery are verified.
    'document_downloads_enabled' => (bool) env('CASE_EXPERT_DOCUMENT_DOWNLOADS_ENABLED', false),
    'limits' => ['write' => 20, 'read' => 120, 'download' => 10],
];
