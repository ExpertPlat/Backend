<?php

return [
    'version' => 1,
    // Product templates for private, non-regulated user-provided context.
    // Each field stores value, source and effectiveDate; no file upload here.
    'domains' => [
        'technology' => ['goal', 'background', 'tools', 'constraints'],
        'business' => ['goal', 'background', 'business_stage', 'constraints'],
        'design' => ['goal', 'background', 'audience', 'constraints'],
        'marketing' => ['goal', 'background', 'channels', 'audience'],
        'career' => ['goal', 'background', 'skills', 'constraints'],
        'education' => ['goal', 'background', 'subject', 'constraints'],
        'translation' => ['goal', 'background', 'source_language', 'target_language'],
    ],
];
