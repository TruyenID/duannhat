<?php

namespace App\Services\Order\Internal;

use App\Models\ShopOrderSetting;
use App\Services\Order\Contracts\BranchDefaultTaxType;

/**
 * #962 — hiện thực Eloquent của {@see BranchDefaultTaxType}.
 *
 * Đọc thẳng cột `default_tax_type_id` thay vì nạp quan hệ `defaultTaxType`: bên
 * gọi (Pricing) cần đúng cái id để tự nạp `TaxType` bằng luật của mình, và nạp
 * quan hệ ở đây sẽ kéo model của Pricing vào Ordering — đúng cạnh vừa gỡ.
 */
final class EloquentBranchDefaultTaxType implements BranchDefaultTaxType
{
    public function defaultTaxTypeIdForBranch(string $branchId): ?string
    {
        $id = ShopOrderSetting::query()
            ->where('branch_id', $branchId)
            ->value('default_tax_type_id');

        return $id === null ? null : (string) $id;
    }
}
