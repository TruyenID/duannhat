<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Services\Promotion\Contracts\CustomerOwnedCoupons;
use Illuminate\Support\Carbon;

/**
 * #1441 — "Coupon của tôi" cho customer-web.
 *
 * PHẠM VI CÓ CHỦ Ý: ví này chỉ chứa coupon THUỘC VỀ khách —
 *
 *   1. coupon cá nhân khách đổi bằng điểm (`coupons.customer_id = khách`), và
 *   2. coupon khách ĐÃ TỪNG dùng (đọc từ sổ `coupon_redemptions`).
 *
 * Nó KHÔNG liệt kê các mã khuyến mãi công khai mà khách "đủ điều kiện dùng".
 * Nghe thì tiện, nhưng một endpoint trả về mọi mã còn hiệu lực chính là một
 * endpoint phát tán mã: `WELCOME10` giới hạn 100 lượt toàn hệ thống sẽ cháy
 * trong một buổi chiều nếu mọi khách đăng nhập đều đọc được nó, và các mã
 * chiến dịch nhắm riêng (gửi email cho nhóm khách cụ thể) thì mất hẳn ý nghĩa
 * "nhắm riêng". Mã công khai được PHÁT ra qua kênh marketing, không phải được
 * TRA ra qua API.
 *
 * Khách vẫn gõ mã tay như trước (`POST /customer/coupons/preview`).
 *
 * #962 — coupon là dữ liệu của **Pricing**, nên hai truy vấn (và luật "còn dùng
 * được") sống sau {@see CustomerOwnedCoupons}. Cái còn lại ở đây đúng là việc
 * của CustomerEngagement: dựng ví cho MỘT khách và chọn tên hiển thị theo locale
 * của người đang xem.
 */
class CustomerCouponWalletService
{
    /** Lịch sử dùng mã hiện chỉ tới đây — bản cũ cũng `limit(50)`. */
    private const REDEMPTION_HISTORY_LIMIT = 50;

    public function __construct(
        private readonly CustomerOwnedCoupons $ownedCoupons,
    ) {}

    /**
     * @return array{
     *     available: list<array<string, mixed>>,
     *     used: list<array<string, mixed>>,
     *     expired: list<array<string, mixed>>,
     * }
     */
    public function wallet(Customer $customer): array
    {
        $owned = $this->ownedCoupons->ownedFor((string) $customer->id, Carbon::now());

        $used = array_map(
            fn (array $redemption): array => [
                'id' => $redemption['id'],
                // Đọc từ snapshot chứ không join `coupons`: coupon có thể đã bị
                // sửa hoặc xoá mềm sau lượt dùng, và lịch sử phải hiện đúng cái
                // khách đã dùng lúc đó.
                'code' => $redemption['coupon_snapshot']['code'] ?? null,
                'name' => $this->snapshotName($redemption['coupon_snapshot'] ?? null),
                'discount_applied_amount' => $redemption['discount_applied_amount'],
                'redeemed_at' => $redemption['redeemed_at'],
                'order_code' => $redemption['order_code'],
            ],
            $this->ownedCoupons->redemptionsFor((string) $customer->id, self::REDEMPTION_HISTORY_LIMIT),
        );

        return [
            'available' => $owned['available'],
            'used' => $used,
            'expired' => $owned['expired'],
        ];
    }

    /**
     * `coupon_snapshot.name` là payload đa ngôn ngữ được đóng băng lúc apply.
     * Nó có thể là chuỗi (dữ liệu cũ) hoặc map locale → tên.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    private function snapshotName(?array $snapshot): ?string
    {
        $name = $snapshot['name'] ?? null;

        if (is_array($name)) {
            return $name[app()->getLocale()] ?? $name['ja'] ?? $name['en'] ?? reset($name) ?: null;
        }

        return is_string($name) ? $name : null;
    }
}
