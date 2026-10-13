<?php

namespace App\Enums;

enum CaseSuitability: string
{
    case NotAssessed = 'not_assessed';
    case Suitable = 'suitable';
    case NeedsClarification = 'needs_clarification';
    case Unsupported = 'unsupported';
    case UrgentStop = 'urgent_stop';
}
