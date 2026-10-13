<?php

namespace App\Services\Cases;

use App\Enums\CaseDocumentScanStatus;

/**
 * Local/testing stand-in when no antivirus binary is configured (CASE_DOCUMENT_SCAN_FAKE=true): checks only that the
 * bytes match the declared PDF/JPEG/PNG signature. Never bound outside local/testing.
 */
class LocalDocumentScanner implements DocumentScanner
{
    public function scan(string $privatePath): CaseDocumentScanStatus
    {
        $head = (string) file_get_contents($privatePath, false, null, 0, 8);

        return str_starts_with($head, '%PDF') || str_starts_with($head, "\xFF\xD8\xFF") || str_starts_with($head, "\x89PNG")
            ? CaseDocumentScanStatus::Clean : CaseDocumentScanStatus::Rejected;
    }
}
