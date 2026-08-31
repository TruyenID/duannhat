<?php

declare(strict_types=1);

namespace App\Services\Promotion\Contracts;

use Carbon\CarbonImmutable;

/**
 * #1597 — cổng Pricing công bố: **giá của khu vực nổi bật (floating section)
 * đang có hiệu lực cho từng SKU**.
 *
 * Khác {@see MenuPromotionResolver}: cái kia phải dựng một value object vì bản
 * cũ trả **model**. Ở đây bản cũ đã trả **mảng thuần** — không rò model nào —
 * nên chỉ thiếu đúng một thứ: **một interface để Ordering import thay cho lớp
 * cụ thể của Pricing**. Không đổi một dòng logic nào.
 *
 * Ghi lại vì đây là ca rẻ nhất của mẫu "tiêu thụ kết quả" (#1609), và dễ bị bỏ
 * qua nhất: nhìn vào code thì *"đã trả mảng rồi, sạch rồi"*, nhưng phép đo vẫn
 * đếm cạnh vì `use App\Services\Promotion\FloatingSectionPriceResolver`.
 *
 * ## Hình dạng trả về giữ NGUYÊN
 *
 * `resolveForSkus()` trả map `product_sku_id => {price, floating_section_id,
 * floating_section_product_id, name, priority}`. Ordering đọc đúng hai khoá
 * (`price`, `floating_section_product_id`), nhưng **không thu hẹp** ở PR này:
 * cùng một mảng đang phục vụ `MenuController` và `CustomerMenuService` cho màn
 * hình menu, và cắt bớt khoá là đổi payload của họ.
 */
interface FloatingSectionPricing
{
    /**
     * @param  array<int, string>  $productSkuIds
     * @return array<string, array{price: float, floating_section_id: string, floating_section_product_id: string, name: string, priority: int}>
     */
    public function resolveForSkus(string $branchId, array $productSkuIds, ?CarbonImmutable $at = null): array;

    /** Tiện ích một-SKU; `null` khi không có khu vực nổi bật nào đang áp. */
    public function resolvePrice(string $branchId, string $productSkuId): ?float;
}
