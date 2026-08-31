<?php

namespace App\Services\Promotion;

use App\Services\Product\Contracts\FloatingSectionAvailability;
use App\Services\Product\Contracts\FloatingSectionSkuPrice;
use App\Services\Promotion\Contracts\FloatingSectionPricing;
use Carbon\CarbonImmutable;

/**
 * Resolve the active branch-clone Floating Section price for each SKU.
 *
 * #1622 — class này giữ **luật giá**, không còn giữ luật "đang phát sóng".
 * Câu hỏi *"section nào đang chạy lúc này"* thuộc về Catalog và giờ đi qua
 * {@see FloatingSectionAvailability}; ở đây chỉ còn câu hỏi của Pricing:
 * **nhiều section cùng chào một món thì lấy giá nào.**
 */
class FloatingSectionPriceResolver implements FloatingSectionPricing
{
    public function __construct(
        private readonly FloatingSectionAvailability $availability,
    ) {}

    /**
     * @param  array<int, string>  $productSkuIds
     * @return array<string, array{price: float, floating_section_id: string, name: string, priority: int}>
     */
    public function resolveForSkus(string $branchId, array $productSkuIds, ?CarbonImmutable $at = null): array
    {
        $candidates = $this->availability->livePricesForSkus($branchId, array_values($productSkuIds), $at);

        if ($candidates === []) {
            return [];
        }

        // LUẬT GIÁ: rẻ nhất thắng; hoà thì section có `priority` nhỏ hơn, rồi id
        // (chỉ để kết quả tất định). Trước #1622 thứ tự này sống trong `ORDER BY`
        // của một câu SQL đọc thẳng bảng của Catalog — cùng một luật, nhưng nằm ở
        // phía không chịu trách nhiệm về giá, và không đọc ra được từ chỗ này.
        usort($candidates, fn (FloatingSectionSkuPrice $a, FloatingSectionSkuPrice $b): int => [$a->price, $a->priority, $a->floatingSectionId]
            <=> [$b->price, $b->priority, $b->floatingSectionId]);

        $resolved = [];

        foreach ($candidates as $candidate) {
            $resolved[$candidate->skuId] ??= [
                'price' => $candidate->price,
                'floating_section_id' => $candidate->floatingSectionId,
                // Owner of the winning row — the same ordering (price, then
                // priority, then id) that picks the price picks this, so the
                // topping tier and the unit price always come from ONE row (#1180).
                'floating_section_product_id' => $candidate->floatingSectionProductId,
                'name' => $candidate->sectionName,
                // Display order of the floating-section spotlight (lower = higher,
                // same convention as menu). Used to sort the merged view's promo
                // section when several floating sections are active at once.
                'priority' => $candidate->priority,
            ];
        }

        return $resolved;
    }

    public function resolvePrice(string $branchId, string $productSkuId): ?float
    {
        return $this->resolveForSkus($branchId, [$productSkuId])[$productSkuId]['price'] ?? null;
    }
}
