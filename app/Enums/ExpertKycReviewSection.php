<?php

namespace App\Enums;

enum ExpertKycReviewSection: string
{
    case IdentityScope = 'identity_scope';
    case Experience = 'experience';
    case Qualifications = 'qualifications';
    case Credentials = 'credentials';
    case WorkSamples = 'work_samples';
    case Other = 'other';
}
