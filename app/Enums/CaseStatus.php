<?php

namespace App\Enums;

enum CaseStatus: string
{
    case Draft = 'draft';
    case NeedsInformation = 'needs_information';
    case ReadyForMatching = 'ready_for_matching';
    case Cancelled = 'cancelled';

    public function editable(): bool
    {
        return in_array($this, [self::Draft, self::NeedsInformation], true);
    }

    public function permits(self $target): bool
    {
        return match ($target) {
            self::Cancelled => $this !== self::Cancelled,
            self::NeedsInformation, self::ReadyForMatching => $this->editable(),
            self::Draft => false,
        };
    }
}
