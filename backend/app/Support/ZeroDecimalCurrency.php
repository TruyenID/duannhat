<?php

namespace App\Support;

use App\Services\Customer\StripePaymentService;

/**
 * Single source of truth for zero-decimal currencies.
 *
 * Historically three hand-maintained tables disagreed about which currencies
 * have no minor unit:
 *   - {@see StripePaymentService} (Stripe minor-unit conversion)
 *   - {@see RoundingMode} (split-bill / tax rounding step)
 *   - customer-web `lib/split-rounding.ts::ZERO_FRACTION` (front-end display + charge)
 *
 * The divergence was a real money bug: StripePaymentService wrongly listed TWD
 * and IDR as zero-decimal (Stripe charges both with 2 minor digits), so a NT$1,000
 * charge was sent to Stripe as 1000 minor units = NT$10 — a 100× UNDER-charge — and
 * MGA (which Stripe DOES treat as zero-decimal) was missing, causing a 100× OVER-charge.
 *
 * {@see self::CODES} is Stripe's documented zero-decimal set — the authoritative
 * definition of "currency with no minor unit" for BOTH Stripe conversion and rounding.
 * https://stripe.com/docs/currencies#zero-decimal
 */
final class ZeroDecimalCurrency
{
    /**
     * Stripe's documented zero-decimal currencies (amount is the integer major
     * unit — no ×100). This is the canonical set every backend caller must use.
     *
     * @var list<string>
     */
    public const CODES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * True when the currency has no minor unit under Stripe's definition.
     */
    public static function contains(?string $currencyCode): bool
    {
        return in_array(strtoupper((string) $currencyCode), self::CODES, true);
    }
}
