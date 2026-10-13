<?php

namespace App\Enums;

enum ExpertKycActorType: string
{
    case Expert = 'expert';
    case Admin = 'admin';
    case System = 'system';
}
