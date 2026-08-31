<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\CustomerOrderItem;
use App\Services\Order\Contracts\PromotionRedemptionReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * #962 — hiện thực {@see PromotionRedemptionReads} trên `customer_order_items`.
 *
 * Cột nối là `applied_promotion_id` (xem quan hệ `MenuPromotion::customerOrderItems`),
 * KHÔNG phải `menu_promotion_id` — tên cột và tên quan hệ lệch nhau ở chỗ này.
 */
final class EloquentPromotionRedemptionReads implements PromotionRedemptionReads
{
    public function summaryForPromotion(string $promotionId): array
    {
        $count = $this->query($promotionId)->count();

        if ($count === 0) {
            return [
                'items_with_promotion_count' => 0,
                'total_discount_applied' => 0.0,
                'first_redeemed_at' => null,
                'last_redeemed_at' => null,
            ];
        }

        /*
         * `CASE WHEN` chứ không phải `GREATEST(...)`. Biểu thức cũ chỉ chạy trên
         * MySQL — SQLite không có hàm `GREATEST`, nên nhánh "khuyến mãi ĐÃ có
         * người dùng" của báo cáo này chưa từng chạy được trong test suite, và
         * lỗi chỉ hiện ra ở production. `CASE WHEN` là SQL chuẩn, cùng ngữ nghĩa:
         * kẹp phần chênh ở 0 để dòng có `original_unit_price` nhỏ hơn không trừ
         * ngược vào tổng.
         */
        $totalDiscount = (float) $this->query($promotionId)->sum(DB::raw(
            'quantity * CASE WHEN COALESCE(original_unit_price, 0) - unit_price > 0 '.
            'THEN COALESCE(original_unit_price, 0) - unit_price ELSE 0 END'
        ));

        return [
            'items_with_promotion_count' => $count,
            'total_discount_applied' => round($totalDiscount, 2),
            'first_redeemed_at' => $this->query($promotionId)->min('created_at'),
            'last_redeemed_at' => $this->query($promotionId)->max('created_at'),
        ];
    }

    public function recentForPromotion(string $promotionId, int $limit): array
    {
        return $this->query($promotionId)
            ->with(['customerOrder:id,order_code', 'productSku:id,name'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (CustomerOrderItem $item): array => [
                'id' => (string) $item->id,
                'customer_order_id' => (string) $item->customer_order_id,
                'order_code' => $item->customerOrder?->order_code,
                'product_name' => $item->productSku?->name,
                'original_unit_price' => $item->original_unit_price !== null
                    ? (float) $item->original_unit_price
                    : null,
                'unit_price' => (float) $item->unit_price,
                'applied_at' => $item->created_at?->toIso8601String() ?? '',
            ])
            ->values()
            ->all();
    }

    private function query(string $promotionId): Builder
    {
        return CustomerOrderItem::query()->where('applied_promotion_id', $promotionId);
    }
}
