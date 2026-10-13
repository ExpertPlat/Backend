<?php

namespace App\Enums;

enum ExpertScopeStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
