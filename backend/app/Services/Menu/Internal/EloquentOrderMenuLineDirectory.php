<?php

declare(strict_types=1);

namespace App\Services\Menu\Internal;

use App\Models\MenuProduct;
use App\Services\Order\Contracts\OrderMenuLineDirectory;
use App\Services\Order\Contracts\OrderMenuLineTaxContext;

/**
 * #962 · 7a-7 — Catalog hiện thực cổng tra dòng menu mà Ordering khai.
 *
 * Hai truy vấn dưới đây được **chép nguyên** từ
 * `WritesCustomerOrders::resolveMenuLineForProduct()` và `::menuContextFor()`, cố ý
 * không "dọn" gì: đây là PR ranh giới, đổi chỗ code chứ không đổi dòng menu nào
 * được chọn. Đổi một điều kiện ở đây là đổi **thuế đóng lên đơn**.
 *
 * ## Từng mảnh của `taxContextForBranchProduct` đều load-bearing
 *
 * - `is_active = true` — dòng menu đã tắt không được đóng thuế cho đơn nữa.
 * - `whereHas('menu', branch_id)` — phạm vi CHI NHÁNH. Không có nó thì menu của chi
 *   nhánh khác lọt vào; cùng một SKU nằm trên 16+ menu ở staging (#514).
 * - `orderBy('id')` — **quyết định**, không phải thẩm mỹ. Không có nó thì DB trả
 *   dòng nào tuỳ hứng, và cùng một đơn re-resolve hai lần ra hai tỉ lệ khác nhau.
 * - `with('taxType')` — chỉ để tránh N+1 ở lối cũ; nay cổng trả `tax_type_id` nên
 *   giữ lại là thừa. VẪN GIỮ: bỏ đi là đổi số truy vấn, mà repo này có test trần
 *   đếm truy vấn. Dọn ở PR khác nếu muốn.
 */
final class EloquentOrderMenuLineDirectory implements OrderMenuLineDirectory
{
    public function taxContextForMenuProduct(?string $menuProductId): OrderMenuLineTaxContext
    {
        if ($menuProductId === null) {
            return OrderMenuLineTaxContext::none();
        }

        $line = MenuProduct::query()
            ->whereKey($menuProductId)
            ->with('taxType')
            ->first(['id', 'menu_id', 'menu_section_id', 'tax_type_id']);

        return new OrderMenuLineTaxContext(
            menuId: $line?->menu_id,
            menuSectionId: $line?->menu_section_id,
            taxTypeId: $line?->taxType?->id,
        );
    }

    public function taxContextForBranchProduct(string $branchId, string $productId): OrderMenuLineTaxContext
    {
        $line = MenuProduct::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->whereHas('menu', fn ($q) => $q->where('branch_id', $branchId))
            ->with('taxType')
            ->orderBy('id')
            ->first();

        if ($line === null) {
            return OrderMenuLineTaxContext::none();
        }

        return new OrderMenuLineTaxContext(
            menuId: $line->menu_id,
            menuSectionId: $line->menu_section_id,
            taxTypeId: $line->taxType?->id,
        );
    }
}
