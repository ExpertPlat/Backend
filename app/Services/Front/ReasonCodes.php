<?php

namespace App\Services\Front;

/**
 * The v7 frontend sends short lowercase reason ids (services/reasonCodes.js); the API stores its own enums.
 * Both spellings are accepted; anything unknown becomes OTHER where the enum allows it.
 */
final class ReasonCodes
{
    private const DECLINE = ['unavailable' => 'NOT_AVAILABLE', 'out_of_scope' => 'OUTSIDE_EXPERTISE', 'conflict' => 'CONFLICT_OF_INTEREST', 'workload' => 'WORKLOAD', 'other' => 'OTHER'];

    private const WITHDRAW = ['unavailable' => 'NOT_AVAILABLE', 'price_changed' => 'OTHER', 'scope' => 'SCOPE_NOT_FEASIBLE', 'other' => 'OTHER'];

    private const REJECT = ['price' => 'TERMS_NOT_ACCEPTABLE', 'scope' => 'TERMS_NOT_ACCEPTABLE', 'other' => 'FOUND_ALTERNATIVE', 'not_needed' => 'NO_LONGER_NEEDED'];

    private const CANCEL_USER = ['schedule_conflict' => 'SCHEDULING_CONFLICT', 'unavailable' => 'CHANGE_OF_PLANS', 'not_needed' => 'NO_LONGER_NEEDED', 'other' => 'OTHER'];

    private const CANCEL_EXPERT = ['schedule_conflict' => 'SCHEDULING_CONFLICT', 'unavailable' => 'EXPERT_UNAVAILABLE', 'other' => 'OTHER'];

    private const CATEGORIES = ['time' => 'timeline', 'timing' => 'timeline'];

    private static function map(array $table, array $allowed, ?string $code, ?string $fallback = 'OTHER'): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        if (in_array($code, $allowed, true)) {
            return $code;
        }
        $mapped = $table[strtolower($code)] ?? (in_array(strtoupper($code), $allowed, true) ? strtoupper($code) : $fallback);

        return in_array($mapped, $allowed, true) ? $mapped : $fallback;
    }

    public static function decline(?string $c): ?string
    {
        return self::map(self::DECLINE, \App\Services\Matching\Invitations::DECLINE_REASONS, $c);
    }

    public static function withdraw(?string $c): ?string
    {
        return self::map(self::WITHDRAW, \App\Services\Commerce\Proposals::WITHDRAW_REASONS, $c);
    }

    public static function reject(?string $c): ?string
    {
        return self::map(self::REJECT, \App\Services\Commerce\Proposals::REJECT_REASONS, $c);
    }

    public static function cancel(?string $c, bool $expert): ?string
    {
        return self::map($expert ? self::CANCEL_EXPERT : self::CANCEL_USER, \App\Services\Commerce\Agreements::CANCEL_REASONS, $c ?? 'other');
    }

    /** @param list<string> $categories */
    public static function categories(array $categories): array
    {
        $allowed = \App\Services\Commerce\Proposals::CHANGE_CATEGORIES;

        return array_values(array_unique(array_map(fn ($c) => in_array($c, $allowed, true) ? $c : (self::CATEGORIES[strtolower((string) $c)] ?? 'other'), $categories)));
    }
}
