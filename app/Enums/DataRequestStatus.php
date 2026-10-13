<?php

namespace App\Enums;

enum DataRequestStatus: string
{
    case Requested = 'requested';
    case Processing = 'processing';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Deferred = 'deferred';
    case Failed = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Requested => [self::Processing, self::Cancelled],
            self::Processing => [self::Completed, self::Rejected, self::Deferred, self::Failed],
            self::Deferred, self::Failed => [self::Processing, self::Cancelled],
            default => [],
        }, true);
    }

    public static function openValues(): array
    {
        return ['requested', 'processing', 'deferred', 'failed'];
    }
}
