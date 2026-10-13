<?php

namespace App\Enums;

enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case UserManager = 'user_manager';
    case SupportAdmin = 'support_admin';
    case KycReviewer = 'kyc_reviewer';
}
