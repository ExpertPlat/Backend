<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Platform commission arithmetic (DR-088). Integer minor units and basis points only; no float ever touches money.
 * Rounding is ROUND_HALF_UP on non-negative integers: floor((amount * bps + 5000) / 10000).
 */
final class Commission
{
    public static function bps(): int
    {
        $bps = (int) config('payments.commission_bps');
        if ($bps < 0 || $bps > 10000) {
            throw new InvalidArgumentException('Commission basis points must be between 0 and 10000.');
        }

        return $bps;
    }

    public static function of(int $amountMinor, ?int $bps = null): int
    {
        if ($amountMinor < 0) {
            throw new InvalidArgumentException('Amount must not be negative.');
        }

        return intdiv($amountMinor * ($bps ?? self::bps()) + 5000, 10000);
    }

    /** @return array{commission: int, expert: int} */
    public static function split(int $grossMinor, ?int $bps = null): array
    {
        $commission = self::of($grossMinor, $bps);

        return ['commission' => $commission, 'expert' => $grossMinor - $commission];
    }

    /**
     * Accounting after a refund: the retained commission is recomputed from the remaining gross with the same rule.
     *
     * @return array{remaining: int, commission: int, expert: int}
     */
    public static function afterRefund(int $grossMinor, int $refundedTotalMinor, ?int $bps = null): array
    {
        $remaining = max(0, $grossMinor - $refundedTotalMinor);

        return ['remaining' => $remaining] + self::split($remaining, $bps);
    }
}
