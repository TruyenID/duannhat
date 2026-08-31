<?php

namespace App\Services\Order\Internal;

use App\Models\ShopOrderSetting;
use App\Services\Order\Contracts\BranchCurrency;

/**
 * #1589 — hiện thực {@see BranchCurrency}.
 *
 * Một dòng, và cố ý là một dòng: `value('currency_code')` chép nguyên từ sáu chỗ
 * gọi vừa rời đi. Không cache, không `?? 'JPY'`, không `strtoupper()` — thêm bất
 * kỳ thứ nào trong đó là đổi hành vi ở ít nhất một trong sáu chỗ.
 */
final class EloquentBranchCurrency implements BranchCurrency
{
    public function codeFor(string $branchId): ?string
    {
        $code = ShopOrderSetting::query()
            ->where('branch_id', $branchId)
            ->value('currency_code');

        return $code === null ? null : (string) $code;
    }
}
