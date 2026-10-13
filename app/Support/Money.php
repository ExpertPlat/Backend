<?php

namespace App\Support;

use InvalidArgumentException;

/** Central money rules: integer minor units plus an explicit ISO 4217 currency. Floats never touch money. */
final class Money
{
    /** ISO 4217 minor-unit exponents for the currencies this platform knows. Data, extendable. */
    private const EXPONENTS = [
        'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'SAR' => 2, 'AED' => 2, 'QAR' => 2, 'EGP' => 2,
        'JOD' => 3, 'KWD' => 3, 'BHD' => 3, 'OMR' => 3,
        'JPY' => 0,
    ];

    /** @return list<string> */
    public static function allowed(): array
    {
        return array_values(array_filter(array_map('strtoupper', (array) config('commerce.currencies')), fn (string $c): bool => isset(self::EXPONENTS[$c])));
    }

    public static function isAllowed(string $currency): bool
    {
        return in_array($currency, self::allowed(), true);
    }

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[$currency] ?? throw new InvalidArgumentException('Unknown currency.');
    }

    /**
     * Smallest amount increment Stripe accepts, in minor units. Stripe handles three-decimal currencies (e.g. JOD) in 3 decimals but
     * requires the last digit to be 0, i.e. multiples of 10. Two-decimal currencies accept every integer.
     */
    public static function providerStep(string $currency): int
    {
        return self::exponent($currency) === 3 ? 10 : 1;
    }

    public static function isProviderRepresentable(int $amountMinor, string $currency): bool
    {
        return $amountMinor > 0 && $amountMinor % self::providerStep($currency) === 0;
    }

    public static function isValidAmount(mixed $amount): bool
    {
        return is_int($amount) && $amount > 0 && $amount <= (int) config('commerce.max_amount_minor');
    }

    /** @param list<int> $amounts */
    public static function sum(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $a) {
            $total += $a;
        }

        return $total;
    }
}
