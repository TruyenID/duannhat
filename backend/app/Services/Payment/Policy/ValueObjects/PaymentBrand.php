<?php

namespace App\Services\Payment\Policy\ValueObjects;

use InvalidArgumentException;

final class PaymentBrand
{
    public const ACCOUNT_CONFIGURED = 'account_configured';

    public static function normalize(?string $value, string $name = 'paymentBrand'): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        if ($value === '' || preg_match('/^[a-z0-9][a-z0-9._-]{0,49}$/', $value) !== 1) {
            throw new InvalidArgumentException("{$name} must be a normalized payment brand/network.");
        }

        return $value;
    }
}
