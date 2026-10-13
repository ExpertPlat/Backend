<?php

namespace App\Enums;

enum CatalogStatus: string
{
    case Draft = 'draft';
    case IntakeOnly = 'intake_only';
    case Pilot = 'pilot';
    case Enabled = 'enabled';
    case Paused = 'paused';
    case Retired = 'retired';
    case Blocked = 'blocked';
}
