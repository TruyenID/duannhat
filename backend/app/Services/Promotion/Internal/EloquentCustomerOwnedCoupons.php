<?php

declare(strict_types=1);

namespace App\Services\Promotion\Internal;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Omnify\Enums\CouponStatusEnum;
use App\Services\Promotion\Contracts\CustomerOwnedCoupons;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * #962 — hiện thực {@see CustomerOwnedCoupons}.
 *
 * Hai truy vấn chép NGUYÊN từ `CustomerCouponWalletService`, kể cả các chi tiết
 * dễ tưởng là vụn:
 *
 * - **`with('translations')`** trên `coupons`: `name`/`description` là thuộc
 *   tính Astrotomic, đọc chúng mà không nạp sẵn là N+1 trên mỗi mã trong ví.
 * - **`whereNull('released_at')`** trên `coupon_redemptions`: lượt đã nhả không
 *   phải lượt đã dùng.
 * - **`with(['customerOrder:id,order_code,created_at'])`** — vẫn chỉ ba cột như
 *   bản cũ; nới ra là kéo cả dòng đơn qua ranh giới mà không ai xin.
 * - **`limit(50)`** do phía gọi truyền vào, không cứng ở đây.
 */
final class EloquentCustomerOwnedCoupons implements CustomerOwnedCoupons
{
    public function ownedFor(string $customerId, DateTimeInterface $at): array
    {
        $now = Carbon::instance($at);

        $personal = Coupon::query()
            ->with('translations')
            ->where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->get();

        [$available, $expired] = $personal->partition(
            fn (Coupon $coupon) => $this->isUsable($coupon, $now)
        );

        return [
            'available' => $available->map(fn (Coupon $c) => $this->present($c))->values()->all(),
            'expired' => $expired->map(fn (Coupon $c) => $this->present($c))->values()->all(),
        ];
    }

    public function redemptionsFor(string $customerId, int $limit): array
    {
        return CouponRedemption::query()
            ->where('customer_id', $customerId)
            ->whereNull('released_at')
            ->with(['customerOrder:id,order_code,created_at'])
            ->orderByDesc('redeemed_at')
            ->limit($limit)
            ->get()
            ->map(fn (CouponRedemption $redemption): array => [
                'id' => (string) $redemption->id,
                'coupon_snapshot' => $redemption->coupon_snapshot,
                'discount_applied_amount' => $redemption->discount_applied_amount,
                'redeemed_at' => $redemption->redeemed_at?->toISOString(),
                'order_code' => $redemption->customerOrder?->order_code,
            ])
            ->values()
            ->all();
    }

    /**
     * Một coupon cá nhân là "còn dùng được" khi còn trong hạn, chưa bị tạm dừng,
     * và chưa tiêu hết lượt — cùng ba điều kiện `CouponService::validateForApply`
     * áp lúc quầy nhận mã.
     */
    private function isUsable(Coupon $coupon, Carbon $now): bool
    {
        $status = $coupon->status instanceof \BackedEnum
            ? $coupon->status->value
            : (string) $coupon->status;

        if ($status === CouponStatusEnum::Paused->value) {
            return false;
        }
        if ($coupon->valid_until !== null && $now->gt($coupon->valid_until)) {
            return false;
        }
        if ($coupon->usage_limit_total !== null && $coupon->times_used >= $coupon->usage_limit_total) {
            return false;
        }

        return true;
    }

    /**
     * Các trường tiền giữ NGUYÊN giá trị model trả về (cast `decimal:2` ⇒ chuỗi):
     * chúng đi thẳng ra JSON của customer-web, ép kiểu ở đây là đổi payload.
     *
     * @return array<string, mixed>
     */
    private function present(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'description' => $coupon->description,
            'discount_type' => $coupon->discount_type instanceof \BackedEnum
                ? $coupon->discount_type->value
                : $coupon->discount_type,
            'discount_value' => $coupon->discount_value,
            'max_discount_cap' => $coupon->max_discount_cap,
            'min_order_subtotal' => $coupon->min_order_subtotal,
            'valid_from' => $coupon->valid_from?->toISOString(),
            'valid_until' => $coupon->valid_until?->toISOString(),
            // Coupon cá nhân đổi từ điểm — FE gắn nhãn khác coupon HQ phát.
            'from_point_reward' => $coupon->point_reward_id !== null,
        ];
    }
}
