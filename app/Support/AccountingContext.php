<?php

namespace App\Support;

use InvalidArgumentException;

final class AccountingContext
{
    public const KOPERASI = 'KOPERASI';

    public const SHM = 'SHM';

    public const ALL = [
        self::KOPERASI,
        self::SHM,
    ];

    /**
     * Check if a context string is valid.
     */
    public static function isValid(?string $context): bool
    {
        if ($context === null) {
            return false;
        }

        return in_array(strtoupper(trim($context)), self::ALL, true);
    }

    /**
     * Normalize context string to uppercase valid value or fail.
     */
    public static function normalize(?string $context): string
    {
        if ($context === null) {
            return self::KOPERASI;
        }

        $normalized = strtoupper(trim($context));

        if (! in_array($normalized, self::ALL, true)) {
            throw new InvalidArgumentException("Invalid accounting context [{$context}]. Allowed contexts: ".implode(', ', self::ALL));
        }

        return $normalized;
    }
}
