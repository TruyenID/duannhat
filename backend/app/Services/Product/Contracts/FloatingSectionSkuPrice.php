<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

/**
 * #1622 — MỘT dòng giá do một floating section ĐANG PHÁT SÓNG chào cho một SKU.
 *
 * Đây là **ứng viên**, không phải kết quả. Catalog trả về mọi dòng đang hiệu lực
 * và **không xếp hạng** chúng: luật "rẻ nhất thắng, hoà thì theo `priority` rồi
 * `id`" là luật ĐỊNH GIÁ, thuộc về Pricing (`FloatingSectionPriceResolver`),
 * không phải luật của catalog. Nhét thứ tự đó vào `ORDER BY` của Catalog là
 * chuyển một quyết định giá sang phía không chịu trách nhiệm về giá — và giấu
 * nó trong SQL.
 *
 * ⚠️ Tên class ở trên cố ý viết trong dấu nháy chứ KHÔNG dùng `{@see}` với tên
 * đầy đủ: pint (`fully_qualified_strict_types`) sẽ nâng tham chiếu đó thành một
 * `use` thật, và một hợp đồng ĐƯỢC CÔNG BỐ chỉ được phụ thuộc hai kernel — một
 * import sang Pricing biến file này thành cạnh vi phạm. Đã xảy ra khi viết #1622.
 *
 * `floatingSectionProductId` có mặt vì #1180: override topping tầng 1 khoá theo
 * `floating_section_product`, nên động cơ đơn hàng cần CHỦ của dòng thắng cuộc,
 * không chỉ section chứa nó. Nó phải đi cùng giá trong CÙNG một dòng, nếu tách
 * ra hai lời gọi thì giá và tầng topping có thể đến từ hai dòng khác nhau.
 */
final readonly class FloatingSectionSkuPrice
{
    public function __construct(
        public string $skuId,
        public float $price,
        public string $floatingSectionId,
        public string $floatingSectionProductId,
        public string $sectionName,
        /** Thứ tự hiển thị của section (nhỏ hơn = cao hơn, cùng quy ước với menu). */
        public int $priority,
    ) {}
}
