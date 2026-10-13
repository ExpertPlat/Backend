<?php

return [
    'contract_version' => '1.0.0',
    'schema_version' => 1,
    'rules_version' => 'deterministic-1',
    'languages' => ['ar', 'en'],
    'triage_url' => env('CASE_TRIAGE_URL'),
    'disk' => env('CASE_DOCUMENTS_DISK', 'case-documents'),
    'scanner_binary' => env('CASE_DOCUMENT_SCANNER_BINARY'),
    // Local/testing only: signature check instead of antivirus when no scanner binary is installed.
    'fake_scan' => (bool) env('CASE_DOCUMENT_SCAN_FAKE', false),
    'max_file_kib' => 10240,
    'max_documents' => 20,
    'max_revisions' => 20,
    'orphan_grace_hours' => 24,
    'limits' => ['read' => 120, 'create' => 10, 'autosave' => 60, 'write' => 30, 'upload' => 10, 'submit' => 5, 'assessment' => 10, 'download' => 20],
];
