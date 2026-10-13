<?php

namespace App\Services\Cases;

use App\Enums\CaseDocumentScanStatus;
use Symfony\Component\Process\Process;

class ClamAvDocumentScanner implements DocumentScanner
{
    public function scan(string $privatePath): CaseDocumentScanStatus
    {
        $binary = config('case_intake.scanner_binary');
        if (! is_string($binary) || $binary === '') {
            return CaseDocumentScanStatus::Failed;
        }
        try {
            $process = new Process([$binary, '--no-summary', '--', $privatePath]);
            $process->setTimeout(30);
            $code = $process->run();
        } catch (\Throwable) {
            return CaseDocumentScanStatus::Failed;
        }

        return match ($code) {
            0 => CaseDocumentScanStatus::Clean,1 => CaseDocumentScanStatus::Rejected,default => CaseDocumentScanStatus::Failed
        };
    }
}
