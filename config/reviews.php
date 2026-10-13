<?php

// Sprint 4.1 (docs/sprint4/reviews-contract.md, DR-081/DR-082).
return [
    // Review creation stays OFF until a completed-service lifecycle exists (Sprint 11). Never enable it to fake completions.
    'write_enabled' => (bool) env('REVIEWS_WRITE_ENABLED', false),
    'public_per_page' => (int) env('REVIEWS_PUBLIC_PER_PAGE', 10),
    'max_body_length' => 2000,
];
