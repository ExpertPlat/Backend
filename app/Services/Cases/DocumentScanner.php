<?php

namespace App\Services\Cases;

use App\Enums\CaseDocumentScanStatus;

interface DocumentScanner
{
    public function scan(string $privatePath): CaseDocumentScanStatus;
}
