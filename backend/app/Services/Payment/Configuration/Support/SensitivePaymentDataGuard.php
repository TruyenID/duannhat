<?php

namespace App\Services\Payment\Configuration\Support;

use App\Services\Payment\Configuration\Exceptions\PaymentConfigurationException;

final class SensitivePaymentDataGuard
{
    private const PAN_PATTERN = '/\b(?:\d[ -]*?){13,19}\b/';

    /**
     * UUIDs are masked out before the PAN scan: a v4 id whose first three
     * groups happen to be all digits (e.g. 12345678-1234-4234-…) reads as a
     * 13+-digit dash-separated run and false-positived the guard — config
     * payloads are full of entity ids, so this fired randomly in real
     * traffic and flaked the suite (~0.05% per id, dozens of ids per call).
     */
    private const UUID_PATTERN = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    private const CVV_KEYS = ['cvv', 'cvc', 'card_cvv', 'card_cvc', 'security_code', 'pan', 'card_number', 'cardNumber'];

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function rejectIfPresent(array $payload, ?string $correlationId = null): void
    {
        if (self::containsSensitiveValue($payload)) {
            throw new PaymentConfigurationException(
                'Sensitive payment card data is not accepted.',
                'PAYMENT_SENSITIVE_DATA_REJECTED',
                422,
                false,
                'remove_sensitive_data',
                [],
                $correlationId,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function containsSensitiveValue(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::CVV_KEYS, true)) {
                return true;
            }

            if (is_string($value)
                && preg_match(self::PAN_PATTERN, (string) preg_replace(self::UUID_PATTERN, '', $value)) === 1) {
                return true;
            }

            if (is_array($value) && self::containsSensitiveValue($value)) {
                return true;
            }
        }

        return false;
    }
}
