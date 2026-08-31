<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * #2071 — MỘT dòng `discount` của sổ `order_conditions`, như tầng in nhìn thấy.
 *
 * Bản đối ứng của `service.OrderDiscountLine` bên Go. Hai trường, và chỉ hai:
 *
 *  - `rate`   — nhóm mức thuế phần giảm này rơi vào (`meta.rate_group` của sổ,
 *               #2031). `null` = dòng dự phòng khi đơn không có dòng chịu thuế.
 *  - `amount` — số tiền NGUYÊN VĂN từ sổ, ÂM cho một khoản trừ, đã làm tròn về
 *               đơn vị nhỏ nhất. Emitter in dấu đúng như sổ nói, không kẹp,
 *               không đảo.
 *
 * `label` của dòng sổ CỐ Ý không có mặt: đó là dữ liệu đóng băng lúc bán
 * ("Discount" / mã coupon) — không theo locale và không chắc mã hoá được
 * Shift_JIS. Chữ trên giấy là từ catalog ({@see PrintLabels::$discount}), cùng
 * quyết định với bản Go (xem docblock `OrderDiscountLine`).
 */
final class PrintRenderDiscount
{
    public function __construct(
        public readonly ?float $rate = null,
        public readonly int $amount = 0,
    ) {}
}
