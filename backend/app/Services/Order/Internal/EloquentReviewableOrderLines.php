<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\CustomerOrderItem;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\Order\Contracts\ReviewableOrderLine;
use App\Services\Order\Contracts\ReviewableOrderLines;

/**
 * #962 — hiện thực {@see ReviewableOrderLines}.
 *
 * Truy vấn chép NGUYÊN từ `ProductReviewService`: cùng bộ lọc `status != voided`,
 * không sắp xếp thêm, không nạp sẵn quan hệ nào của Catalog.
 *
 * `$order->items()` của bản cũ là `hasMany` trơn, nên lọc theo `customer_order_id`
 * ở đây cho cùng tập dòng (kể cả scope toàn cục của model).
 */
final class EloquentReviewableOrderLines implements ReviewableOrderLines
{
    public function forOrder(string $orderId): array
    {
        return CustomerOrderItem::query()
            ->where('customer_order_id', $orderId)
            ->where('status', '!=', OrderItemStatusEnum::Voided)
            ->get()
            ->map(fn (CustomerOrderItem $item): ReviewableOrderLine => new ReviewableOrderLine(
                id: (string) $item->id,
                productSkuId: $item->product_sku_id === null ? null : (string) $item->product_sku_id,
                unitPrice: $item->unit_price,
            ))
            ->values()
            ->all();
    }
}
