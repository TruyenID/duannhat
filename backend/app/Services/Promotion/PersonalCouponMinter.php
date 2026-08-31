<?php

declare(strict_types=1);

namespace App\Services\Promotion;

use App\Models\Coupon;
use App\Services\Promotion\Contracts\MintedCoupon;
use App\Services\Promotion\Contracts\PersonalCouponMinting;
use App\Services\Promotion\Contracts\PersonalCouponSpec;
use Illuminate\Support\Str;

/**
 * #962 — hiện thực của {@see PersonalCouponMinting}. Toàn bộ thân hàm
 * chuyển nguyên từ `CustomerPointService::mintCouponFor` + `uniqueCouponCode`,
 * kể cả các comment giải thích — chúng nói về `coupons`, tức chúng thuộc về đây.
 *
 * Lớp RIÊNG chứ không phải một method mới trên `CouponService`: bản gốc ghi rõ
 * tấm này KHÔNG đi đường HQ (không audit "coupon_created", không guard
 * locked-field, không pivot chi nhánh), nên gắn nó vào lớp đang giữ đúng những
 * thứ đó là mời người sau "thống nhất" hai đường lại làm một.
 *
 * KHÔNG mở transaction: người gọi đã ở trong một transaction có `lockForUpdate`
 * trên hàng khách, và đó mới là thứ ngăn tiêu quá số dư.
 */
final class PersonalCouponMinter implements PersonalCouponMinting
{
    public function mint(PersonalCouponSpec $spec): MintedCoupon
    {
        $now = now();

        $payload = [
            'code' => $this->uniqueCouponCode(),
            'discount_type' => $spec->discountType,
            'discount_value' => $spec->discountValue,
            'max_discount_cap' => $spec->maxDiscountCap,
            'min_order_subtotal' => $spec->minOrderSubtotal,
            // Một tấm, cho một người. `usage_limit_per_customer = 1` bắt
            // apply() phải có customer_id; `customer_id` bắt nó phải đúng
            // người (xem CouponService::apply).
            'usage_limit_total' => 1,
            'usage_limit_per_customer' => 1,
            'valid_from' => $now,
            // `valid_until` là một MỐC THỜI GIAN UTC (now + N ngày), không phải
            // một ngày làm việc — nên không cần BusinessClock: một khoảng cách
            // tính bằng ngày trên trục UTC không mơ hồ ở bất kỳ múi giờ nào.
            'valid_until' => $now->copy()->addDays(max(1, $spec->validDays)),
            'status' => 'draft',
            'brand_id' => $spec->brandId,
            'organization_id' => $spec->organizationId,
            'customer_id' => $spec->customerId,
            'point_reward_id' => $spec->pointRewardId,
        ];

        // Tên coupon soi gương tên phần thưởng ở cả 3 ngôn ngữ — khách đổi
        // bằng tiếng Nhật thì trong ví cũng phải thấy tiếng Nhật.
        foreach ($spec->namesByLocale as $locale => $name) {
            $payload["name:{$locale}"] = $name;
        }

        $coupon = Coupon::create($payload);

        return new MintedCoupon(
            id: (string) $coupon->id,
            code: (string) $coupon->code,
            name: $coupon->name === null ? null : (string) $coupon->name,
            discountType: $coupon->discount_type instanceof \BackedEnum
                ? (string) $coupon->discount_type->value
                : ($coupon->discount_type === null ? null : (string) $coupon->discount_type),
            discountValue: $coupon->discount_value === null ? null : (string) $coupon->discount_value,
            maxDiscountCap: $coupon->max_discount_cap === null ? null : (string) $coupon->max_discount_cap,
            minOrderSubtotal: $coupon->min_order_subtotal === null ? null : (string) $coupon->min_order_subtotal,
            validUntil: $coupon->valid_until?->toISOString(),
        );
    }

    /**
     * Mã coupon cá nhân: tiền tố `PT` + 8 ký tự Crockford-ish (bỏ chữ dễ đọc
     * nhầm). Va chạm là gần như không thể, nhưng unique index
     * (organization_id, brand_id, code) mới là thứ quyết định — nên thử lại
     * vài lần rồi mới chịu thua.
     */
    private function uniqueCouponCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = 'PT';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            if (! Coupon::withTrashed()->where('code', $code)->exists()) {
                return $code;
            }
        }

        return 'PT'.strtoupper(Str::random(12));
    }
}
