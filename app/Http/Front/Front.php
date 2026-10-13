<?php

namespace App\Http\Front;

/**
 * Helpers for the v7 frontend contract: request language (Accept-Language: ar | en) and resolution of bilingual
 * system text. System-authored text is stored as {ar, en}; user-authored text is a plain string and passes through.
 */
final class Front
{
    public static function lang(): string
    {
        $header = strtolower((string) request()?->header('Accept-Language', 'ar'));

        return str_starts_with($header, 'en') ? 'en' : 'ar';
    }

    public static function isPair(mixed $value): bool
    {
        return is_array($value) && count($value) === 2 && array_key_exists('ar', $value) && array_key_exists('en', $value)
            && (is_string($value['ar']) || $value['ar'] === null) && (is_string($value['en']) || $value['en'] === null);
    }

    public static function t(mixed $value): mixed
    {
        if (self::isPair($value)) {
            return $value[self::lang()] ?? $value['ar'] ?? $value['en'];
        }

        return $value;
    }

    /** Resolves every {ar, en} pair inside a payload. */
    public static function deep(mixed $value): mixed
    {
        if (self::isPair($value)) {
            return self::t($value);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::deep($v);
            }
        }

        return $value;
    }

    public static function iso(mixed $date): ?string
    {
        return $date ? \Illuminate\Support\Carbon::parse($date)->utc()->format('Y-m-d\TH:i:s.v\Z') : null;
    }
}
