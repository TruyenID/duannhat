<?php

declare(strict_types=1);

namespace App\Services\Shop\Internal;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Services\Shop\Contracts\BranchCashVarianceTolerance;

/**
 * Hiện thực {@see BranchCashVarianceTolerance} — sống trong Organization vì đây
 * là module SỞ HỮU `branches`, `brands` và `brand_order_policies`.
 */
final class EloquentBranchCashVarianceTolerance implements BranchCashVarianceTolerance
{
    public function toleranceMinorForBranch(string $branchId): ?int
    {
        // ⚠️ `branches` KHÔNG có `brand_id` — chỉ có `console_brand_id`. Đọc
        // thẳng `$branch->brand_id` sẽ ra NULL và MỌI brand rơi về mặc định
        // trong im lặng.
        $consoleBrandId = Branch::query()->whereKey($branchId)->value('console_brand_id');

        if ($consoleBrandId === null) {
            return null;
        }

        $brandId = Brand::query()->where('console_brand_id', $consoleBrandId)->value('id');

        if ($brandId === null) {
            return null;
        }

        $value = BrandOrderPolicy::query()
            ->where('brand_id', $brandId)
            ->value('cash_variance_tolerance_minor');

        return $value === null ? null : (int) $value;
    }
}
