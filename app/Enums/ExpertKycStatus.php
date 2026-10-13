<?php

namespace App\Enums;

enum ExpertKycStatus: string
{
    case NotSubmitted = 'not_submitted';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
