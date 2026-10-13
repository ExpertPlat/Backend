<?php

namespace App\Enums;

enum ConsentDecision: string
{
    case Granted = 'granted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
}
