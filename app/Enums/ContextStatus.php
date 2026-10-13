<?php

namespace App\Enums;

enum ContextStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
    case Deleted = 'deleted';
}
