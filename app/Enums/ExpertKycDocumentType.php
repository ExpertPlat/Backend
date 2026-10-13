<?php

namespace App\Enums;

enum ExpertKycDocumentType: string
{
    case Identity = 'identity';
    case Cv = 'cv';
    case Qualification = 'qualification';
    case Credential = 'credential';
    case WorkSample = 'work_sample';
}
