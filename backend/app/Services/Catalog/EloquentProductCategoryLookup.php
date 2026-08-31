<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Services\Catalog\Contracts\ProductCategoryLookup;
use Illuminate\Support\Facades\DB;

/**
 * #2371 — bản cài của {@see ProductCategoryLookup}, đọc thẳng pivot của Catalog.
 *
 * Đọc thô ở đây là HỢP LỆ vì class này thuộc chính module sở hữu bảng; cùng
 * truy vấn đó đứng ở Ordering thì là nợ xuyên module. Xem contract để biết vì
 * sao nó được dời.
 *
 * Tên bảng là `product_category` — KHÔNG phải `category_product` như quy ước
 * `joiningTable()` của Laravel (snake_case, số ít, sắp alphabet) sẽ suy ra từ
 * `pivotFor: [Category, Product]`. Đó là tên omnify sinh ra, và tên bảng là thứ
 * **generator sở hữu**: đừng sửa tay ở đây, lượt regen sau sẽ ghi đè. Việc đưa
 * tên về đúng quy ước Laravel đang nằm ở upstream omnify-go#158.
 */
final class EloquentProductCategoryLookup implements ProductCategoryLookup
{
    /** @return list<string> */
    public function categoryIdsFor(string $productId): array
    {
        return DB::table('product_category')
            ->where('product_id', $productId)
            ->pluck('category_id')
            ->all();
    }
}
