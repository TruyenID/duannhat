<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

use Carbon\CarbonImmutable;

/**
 * The coupon fields the ORDER half reads — no Eloquent row attached (#962).
 *
 * The `Coupon` model belongs to Pricing. `OrderCouponService` (Ordering) never
 * needed the row itself: it reads eight scalars to decide whether the coupon may
 * be applied and to write the audit line. Those eight travel here instead, which
 * is what lets {@see OrderCouponLedger} be a PUBLISHED contract — a published
 * contract may not depend on any module, so it may not carry another module's
 * model in its signature.
 *
 * Deliberately NOT here: `name` translations and the redemption snapshot shape.
 * Both are Pricing's business and are written by the ledger itself, so nothing
 * on the order side can drift from them.
 */
final class CouponTerms
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        /** Computed effective status (`active` · `paused` · …), not the raw column. */
        public readonly string $status,
        public readonly float $minOrderSubtotal,
        public readonly ?int $usageLimitTotal,
        public readonly int $timesUsed,
        public readonly CarbonImmutable $validFrom,
        public readonly CarbonImmutable $validUntil,
    ) {}
}
