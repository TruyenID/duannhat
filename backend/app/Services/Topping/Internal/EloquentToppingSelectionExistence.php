<?php

declare(strict_types=1);

namespace App\Services\Topping\Internal;

use App\Models\ProductSku;
use App\Models\ToppingGroupItem;
use App\Services\Order\Contracts\ToppingSelectionExistence;

/**
 * #962 · 7a-7 — Catalog hiện thực cổng "cặp (topping, SKU) có thật không".
 *
 * Chép nguyên hai phép kiểm từ
 * `WritesCustomerOrders::transportWorkstationPersistToppings()`. `whereKey(...)
 * ->exists()` chứ không `find()`: chỉ cần biết có hay không, và `exists()` không
 * hydrate model — đường này chạy trong vòng lặp replay của máy trạm.
 *
 * Cả hai model đều soft-delete, nên hàng đã xoá mềm cho `false`. Đó là hành vi
 * ĐÚNG ở đây: topping đã gỡ khỏi catalog thì không được ghi thành dòng đơn mới.
 */
final class EloquentToppingSelectionExistence implements ToppingSelectionExistence
{
    public function selectionExists(string $toppingGroupItemId, string $productSkuId): bool
    {
        return ToppingGroupItem::whereKey($toppingGroupItemId)->exists()
            && ProductSku::whereKey($productSkuId)->exists();
    }
}
