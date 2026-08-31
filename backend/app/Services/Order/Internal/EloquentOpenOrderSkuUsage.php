<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Services\Order\Contracts\OpenOrderSkuUsage;
use App\Services\Order\Contracts\OrderStatusVocabulary;
use Illuminate\Support\Facades\DB;

/**
 * #1622 — hiện thực {@see OpenOrderSkuUsage}.
 *
 * Hai truy vấn chép NGUYÊN từ `ProductSkuService::skuInOpenOrder()`, kể cả thứ
 * tự: dòng món trước, topping sau, và **thoát sớm** khi dòng món đã khớp. Thứ
 * tự đó không ngẫu nhiên — truy vấn topping có thêm hai `join`, nên chạy nó chỉ
 * khi cần là rẻ hơn, và giữ nguyên nghĩa là giữ nguyên cả số truy vấn ở ca phổ
 * biến nhất (SKU đang được bán ⇒ khớp ngay ở truy vấn đầu).
 *
 * Vẫn dùng query builder thô thay vì Eloquent: bản cũ như vậy, và đổi sang model
 * là đổi số truy vấn trên đường XOÁ SKU (plan-042) — việc riêng, không gộp vào
 * một PR dời ranh giới.
 */
final class EloquentOpenOrderSkuUsage implements OpenOrderSkuUsage
{
    public function anyOpenOrderUsesSkus(array $skuIds): bool
    {
        if ($skuIds === []) {
            return false;
        }

        $asLineItem = DB::table('customer_order_items as coi')
            ->join('customer_orders as co', 'co.id', '=', 'coi.customer_order_id')
            ->whereIn('coi.product_sku_id', $skuIds)
            ->whereIn('co.status', OrderStatusVocabulary::OPEN)
            ->exists();

        if ($asLineItem) {
            return true;
        }

        return DB::table('order_item_toppings as oit')
            ->join('customer_order_items as coi', 'coi.id', '=', 'oit.customer_order_item_id')
            ->join('customer_orders as co', 'co.id', '=', 'coi.customer_order_id')
            ->whereIn('oit.product_sku_id', $skuIds)
            ->whereIn('co.status', OrderStatusVocabulary::OPEN)
            ->exists();
    }
}
