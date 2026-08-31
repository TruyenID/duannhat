<?php

declare(strict_types=1);

namespace App\Services\Promotion\Contracts;

use DateTimeInterface;

/**
 * #962 — cổng Pricing công bố: **coupon THUỘC VỀ một khách**, cho ví coupon của
 * customer-web.
 *
 * Trước cổng này `CustomerCouponWalletService` (CustomerEngagement) tự truy vấn
 * `App\Models\Coupon` và `App\Models\CouponRedemption` — hai model của Pricing.
 * Chiều gọi vốn đúng (khách hỏi "tôi có mã nào"), nhưng cái đi qua ranh giới là
 * MODEL, nên mọi cột khác của coupon đều nằm trong tầm với của phía khách.
 *
 * ## Vì sao "dùng được / hết hạn" được phân loại Ở ĐÂY
 *
 * Ba điều kiện — chưa tạm dừng · còn trong hạn · chưa tiêu hết lượt — lặp lại
 * luật của `CouponService::validateForApply`, và `CouponService` là Pricing.
 * Trả bốn cột thô (`status`, `valid_until`, `times_used`, `usage_limit_total`)
 * ra ngoài chỉ để phía khách tự so là giữ nguyên bản sao thứ hai của luật, chỉ
 * đổi chỗ nó ngồi. Nếu ví sáng đèn một mã mà quầy từ chối thì đó là kiểu lỗi
 * khó chịu nhất — nên hai bên phải đọc CÙNG một chỗ.
 *
 * `$at` là tham số chứ không phải `now()` bên trong: chỗ gọi đã có đồng hồ của
 * nó, và một cổng tự lấy giờ là một cổng không test được cạnh biên.
 *
 * ## Vì sao trả mảng chứ không trả DTO
 *
 * Các trường tiền (`discount_value`, `max_discount_cap`, `min_order_subtotal`)
 * là cast `decimal:2` — tức CHUỖI — và chúng đi thẳng ra JSON của customer-web.
 * Ép chúng vào `float` của một DTO là đổi hình dạng payload đang chạy
 * ("1500.00" → 1500). Mảng ở đây giữ nguyên giá trị model trả về.
 */
interface CustomerOwnedCoupons
{
    /**
     * Coupon CÁ NHÂN của khách (`coupons.customer_id`), mới nhất trước, đã phân
     * loại thành còn dùng được / hết hiệu lực.
     *
     * KHÔNG bao gồm mã khuyến mãi công khai mà khách "đủ điều kiện dùng" — xem
     * `CustomerCouponWalletService` cho lý do (một endpoint như thế là một
     * endpoint phát tán mã).
     *
     * @return array{available: list<array<string, mixed>>, expired: list<array<string, mixed>>}
     */
    public function ownedFor(string $customerId, DateTimeInterface $at): array;

    /**
     * Sổ lượt đã dùng của khách (`coupon_redemptions` chưa bị nhả), mới nhất trước.
     *
     * `coupon_snapshot` trả về NGUYÊN dạng đã đóng băng lúc apply: coupon có thể
     * đã bị sửa hoặc xoá mềm sau lượt dùng, và lịch sử phải hiện đúng cái khách
     * đã dùng lúc đó. Việc chọn tên theo locale là chuyện hiển thị, thuộc phía
     * gọi.
     *
     * @return list<array{id: string, coupon_snapshot: array<string, mixed>|null, discount_applied_amount: mixed, redeemed_at: string|null, order_code: string|null}>
     */
    public function redemptionsFor(string $customerId, int $limit): array;
}
