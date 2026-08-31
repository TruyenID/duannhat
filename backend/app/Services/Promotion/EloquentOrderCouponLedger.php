<?php

declare(strict_types=1);

namespace App\Services\Promotion;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Services\Order\Contracts\CouponTerms;
use App\Services\Order\Contracts\OrderCouponLedger;
use App\Services\Promotion\Contracts\CouponPricing;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Pricing side of {@see OrderCouponLedger} (#962).
 *
 * Every statement in here was lifted verbatim out of
 * `App\Services\Order\Coupon\OrderCouponService` — same locks, same order, same
 * guards. The point of the move is WHERE it runs, not WHAT it does: `coupons`
 * and `coupon_redemptions` are Pricing's tables, and the order half was writing
 * to them directly.
 *
 * Nothing here opens a transaction. The caller's transaction is what makes the
 * `lockForUpdate` calls meaningful, and wrapping again here would create a
 * savepoint whose rollback semantics differ from what `apply()` relies on.
 */
final class EloquentOrderCouponLedger implements OrderCouponLedger
{
    public function __construct(private readonly CouponPricing $pricing) {}

    public function lockByCode(string $brandId, string $code): ?CouponTerms
    {
        $coupon = Coupon::query()
            ->where('brand_id', $brandId)
            ->whereRaw('UPPER(code) = ?', [strtoupper($code)])
            ->lockForUpdate()
            ->first();

        return $coupon === null ? null : $this->toTerms($coupon);
    }

    public function lockByIdOrFail(string $couponId): CouponTerms
    {
        /** @var Coupon $coupon */
        $coupon = Coupon::query()->where('id', $couponId)->lockForUpdate()->firstOrFail();

        return $this->toTerms($coupon);
    }

    public function find(string $couponId): ?CouponTerms
    {
        $coupon = Coupon::query()->find($couponId);

        return $coupon === null ? null : $this->toTerms($coupon);
    }

    public function hasRedemptionForOrder(string $orderId): bool
    {
        return CouponRedemption::query()->where('customer_order_id', $orderId)->exists();
    }

    public function purgeReleasedRedemptions(string $orderId): void
    {
        CouponRedemption::query()
            ->where('customer_order_id', $orderId)
            ->whereNotNull('released_at')
            ->forceDelete();
    }

    public function claimUsage(CouponTerms $coupon): bool
    {
        // Defensive bump — the caller's lockForUpdate already serializes; the
        // WHERE clause is the backup race-guard.
        $bumped = Coupon::query()
            ->where('id', $coupon->id)
            ->where(function ($q) use ($coupon) {
                if ($coupon->usageLimitTotal !== null) {
                    $q->whereColumn('times_used', '<', 'usage_limit_total');
                }
            })
            ->increment('times_used');

        return $bumped > 0;
    }

    public function recordRedemption(
        string $couponId,
        string $orderId,
        ?string $customerId,
        float $discount,
        string $via,
        ?string $redeemedByUserId = null,
        ?CarbonImmutable $redeemedAt = null,
    ): bool {
        /** @var Coupon $coupon */
        $coupon = Coupon::query()->findOrFail($couponId);

        try {
            CouponRedemption::create([
                'coupon_id' => $coupon->id,
                'customer_order_id' => $orderId,
                'customer_id' => $customerId,
                'discount_applied_amount' => $discount,
                'coupon_snapshot' => $this->pricing->snapshotCoupon($coupon),
                'redeemed_at' => $redeemedAt ?? now(),
                'redeemed_by_user_id' => $redeemedByUserId,
                'redeemed_via' => $via,
            ]);
        } catch (UniqueConstraintViolationException) {
            // `customer_order_id` is uniquely indexed — a concurrent writer for
            // the same order already recorded it.
            return false;
        }

        return true;
    }

    /**
     * #2154 — sổ đổi phải mang khoản giảm ĐANG áp.
     *
     * `recordRedemption()` ghi cột này đúng MỘT lần lúc áp coupon, còn
     * khoản giảm trong `order_conditions` được tính lại mỗi lần giỏ đổi. Hai
     * sổ phân kỳ ngay lần void/refund đầu tiên, và Z-report `SUM()` lên cột
     * đứng yên: đơn 200k + coupon 20%, void một dòng 100k ⇒ đơn giảm 20.000
     * nhưng báo cáo khai 40.000.
     *
     * Chỉ chạm hàng CÒN SỐNG (`released_at IS NULL`) — hàng đã nhả không còn
     * là khoản giảm của đơn nữa và mọi chỗ đọc đều đã lọc nó ra.
     *
     * `update()` trên query builder chứ không nạp model rồi save: không có
     * observer nào trên `CouponRedemption`, và một lệnh UPDATE giữ cho đường
     * này không tự gọi lại chính nó qua vòng observer của `CustomerOrder`.
     */
    public function syncRedemptionAmountForOrder(string $orderId, float $amount): void
    {
        CouponRedemption::query()
            ->where('customer_order_id', $orderId)
            ->whereNull('released_at')
            ->update(['discount_applied_amount' => $amount]);
    }

    public function releaseRedemptionForOrder(string $orderId, bool $hardDelete = false): void
    {
        $redemption = CouponRedemption::query()
            ->where('customer_order_id', $orderId)
            ->whereNull('released_at')
            ->lockForUpdate()
            ->first();

        if ($redemption === null) {
            return;
        }

        Coupon::query()
            ->where('id', $redemption->coupon_id)
            ->where('times_used', '>', 0)
            ->lockForUpdate()
            ->decrement('times_used');

        if ($hardDelete) {
            $redemption->forceDelete();
        } else {
            $redemption->update(['released_at' => now()]);
        }
    }

    public function computeDiscount(string $couponId, float $subtotal, ?string $currencyCode = null): float
    {
        /** @var Coupon $coupon */
        $coupon = Coupon::query()->findOrFail($couponId);

        return $this->pricing->computeDiscount($coupon, $subtotal, $currencyCode);
    }

    public function resolveCurrencyForBranch(?string $branchId): ?string
    {
        return $this->pricing->resolveCurrencyForBranch($branchId);
    }

    public function assertBranchEligible(string $couponId, string $branchId): void
    {
        /** @var Coupon $coupon */
        $coupon = Coupon::query()->findOrFail($couponId);

        $this->pricing->assertBranchEligible($coupon, $branchId);
    }

    public function assertCustomerEligible(string $couponId, ?string $customerId): void
    {
        /** @var Coupon $coupon */
        $coupon = Coupon::query()->findOrFail($couponId);

        $this->pricing->assertCustomerEligible($coupon, $customerId);
    }

    private function toTerms(Coupon $coupon): CouponTerms
    {
        return new CouponTerms(
            id: (string) $coupon->id,
            code: (string) $coupon->code,
            status: $this->pricing->statusValue($coupon),
            minOrderSubtotal: (float) $coupon->min_order_subtotal,
            usageLimitTotal: $coupon->usage_limit_total === null ? null : (int) $coupon->usage_limit_total,
            timesUsed: (int) $coupon->times_used,
            validFrom: CarbonImmutable::parse($coupon->valid_from),
            validUntil: CarbonImmutable::parse($coupon->valid_until),
        );
    }
}
