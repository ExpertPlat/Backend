<?php

namespace App\Enums;

enum CaseDocumentScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
