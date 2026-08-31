<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Converts ISO 4217 major-unit decimal strings without floating-point loss.
 */
final class CurrencyMinorUnit
{
    public const THREE_DECIMAL_CODES = [
        'BHD', 'IQD', 'JOD', 'KWD', 'OMR', 'TND',
    ];

    public static function exponent(string $currency): int
    {
        $currency = strtoupper($currency);

        if (ZeroDecimalCurrency::contains($currency)) {
            return 0;
        }

        return in_array($currency, self::THREE_DECIMAL_CODES, true) ? 3 : 2;
    }

    public static function fromMajor(string $amount, string $currency): ?int
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $matches) !== 1) {
            return null;
        }

        $scale = self::exponent($currency);
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
            return null;
        }

        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $minor = ltrim($matches[1].$fraction, '0');
        if ($minor === '') {
            return 0;
        }

        $maximum = (string) PHP_INT_MAX;
        if (strlen($minor) > strlen($maximum)
            || (strlen($minor) === strlen($maximum) && strcmp($minor, $maximum) > 0)) {
            return null;
        }

        return (int) $minor;
    }
}
