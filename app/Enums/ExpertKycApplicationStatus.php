<?php

namespace App\Enums;

enum ExpertKycApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case NeedsInformation = 'needs_information';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function isVisibleToAdmin(): bool
    {
        return $this !== self::Draft;
    }

    public function canStartNewAttempt(): bool
    {
        return in_array($this, [self::NeedsInformation, self::Rejected], true);
    }

    /**
     * @return list<string>
     */
    public static function adminVisibleValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isVisibleToAdmin()),
        ));
    }
}
