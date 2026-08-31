<?php

declare(strict_types=1);

namespace App\Services\Order\ValueObjects;

use BackedEnum;

/**
 * The three coupon fields a discount computation needs, detached from
 * the `Coupon` model (epic #962).
 *
 * `Coupon` belongs to Pricing; `WorkstationOrderPricingService` belongs to
 * Ordering. The service never needed the row — only its discount terms — so
 * the caller (a controller, i.e. Composition, which may read any module's
 * model) hands those over and the Ordering service stays model-free.
 *
 * Normalisation lives in {@see self::of} rather than at each call site: a
 * `Coupon` loaded through Eloquent returns `discount_type` as a backed enum,
 * while one built in-memory for a test returns the raw string, and the two must
 * not resolve differently.
 */
final class CouponDiscountTerms
{
    public function __construct(
        public readonly string $discountType,
        public readonly float $discountValue,
        public readonly ?float $maxDiscountCap = null,
    ) {}

    public static function of(mixed $discountType, mixed $discountValue, mixed $maxDiscountCap = null): self
    {
        return new self(
            $discountType instanceof BackedEnum ? (string) $discountType->value : (string) $discountType,
            (float) $discountValue,
            $maxDiscountCap === null ? null : (float) $maxDiscountCap,
        );
    }
}
