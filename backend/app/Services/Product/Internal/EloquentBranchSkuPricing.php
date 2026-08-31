<?php

declare(strict_types=1);

namespace App\Services\Product\Internal;

use App\Models\MenuProductSku;
use App\Models\ProductSku;
use App\Services\Product\Contracts\BranchSkuPricing;
use App\Services\Product\Contracts\SellableSkuPrice;
use Illuminate\Support\Facades\DB;

/**
 * #1597 — hiện thực {@see BranchSkuPricing}.
 *
 * Ba truy vấn chép NGUYÊN từ `WorkstationOrderPricingService`, kể cả phạm vi:
 *
 * 1. `ProductSku::find()` — kèm `with('product')` vì `isSellable()` đọc
 *    `$this->product?->status`, và docblock của nó nói thẳng *"Relies on the
 *    `product` relation being loaded by the caller; a null product is treated as
 *    NOT sellable"*. Bản cũ gọi `ProductSku::find()` trần rồi `isSellable()`,
 *    tức dựa vào lazy-load. Eager-load ở đây giữ NGUYÊN kết quả và bỏ một truy
 *    vấn ngầm — không đổi hành vi, chỉ bỏ một cái bẫy.
 * 2. giá menu — `is_active` VÀ `menuProduct.menu.branch_id = $branchId`. Phạm vi
 *    theo chi nhánh là load-bearing: docblock bản cũ ghi rõ tra cứu `is_active`
 *    toàn cục là **không xác định** với một SKU nằm trong nhiều menu.
 * 3. `product_category` — pivot thô, giữ nguyên `DB::table`.
 */
final class EloquentBranchSkuPricing implements BranchSkuPricing
{
    public function forBranch(string $branchId, string $productSkuId): ?SellableSkuPrice
    {
        $sku = ProductSku::query()->with('product')->find($productSkuId);

        if ($sku === null) {
            return null;
        }

        $menuPrice = MenuProductSku::query()
            ->where('product_sku_id', $sku->id)
            ->where('is_active', true)
            ->whereHas('menuProduct.menu', fn ($q) => $q->where('branch_id', $branchId))
            ->value('selling_price');

        return new SellableSkuPrice(
            skuId: (string) $sku->id,
            productId: $sku->product_id === null ? null : (string) $sku->product_id,
            isSellable: $sku->isSellable(),
            baseSellingPrice: (float) $sku->selling_price,
            branchMenuPrice: $menuPrice === null ? null : (float) $menuPrice,
            categoryIds: $sku->product_id === null ? [] : array_map(
                static fn ($id): string => (string) $id,
                DB::table('product_category')
                    ->where('product_id', $sku->product_id)
                    ->pluck('category_id')
                    ->all(),
            ),
        );
    }
}
