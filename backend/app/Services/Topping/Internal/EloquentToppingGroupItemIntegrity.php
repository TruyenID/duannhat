<?php

declare(strict_types=1);

namespace App\Services\Topping\Internal;

use App\Models\ToppingGroupItem;
use App\Services\Topping\Contracts\ToppingGroupItemIntegrity;

/**
 * #962 — hiện thực {@see ToppingGroupItemIntegrity}.
 *
 * Truy vấn chép NGUYÊN từ `MenuLocalizationIntegrityReporter`, kể cả chi tiết
 * quyết định kết quả: điều kiện là `whereDoesntHave('product')` **HOẶC**
 * `whereHas('product', status != 'active')`. Đổi thành một `whereHas` phủ định
 * duy nhất sẽ bỏ sót đúng một trong hai ca.
 */
final class EloquentToppingGroupItemIntegrity implements ToppingGroupItemIntegrity
{
    public function unusableItemCountForGroups(array $toppingGroupIds): int
    {
        if ($toppingGroupIds === []) {
            return 0;
        }

        return ToppingGroupItem::query()
            ->whereIn('topping_group_id', $toppingGroupIds)
            ->where(function ($query) {
                $query->whereDoesntHave('product')
                    ->orWhereHas('product', fn ($product) => $product->where('status', '!=', 'active'));
            })
            ->count();
    }
}
