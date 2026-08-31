<?php

namespace App\Services\Order\Internal;

use App\Models\ShopOrderSetting;
use App\Services\Order\Contracts\BranchSplitBillPolicy;
use App\Services\Order\ValueObjects\SplitBillSettings;

/**
 * #962 — hiện thực Eloquent của {@see BranchSplitBillPolicy}.
 *
 * Một truy vấn, ba cột, cùng bộ mặc định mà `OrderPaymentService` vốn viết
 * inline (`?? 'auto'`, `?? 'JPY'` theo #815, `?? 0`). Chép nguyên, không gộp và
 * không "chuẩn hoá" thêm — đổi mặc định ở bước dời chỗ là đổi TIỀN.
 */
final class EloquentBranchSplitBillPolicy implements BranchSplitBillPolicy
{
    public function forBranch(?string $branchId): SplitBillSettings
    {
        $setting = $branchId === null
            ? null
            : ShopOrderSetting::query()->where('branch_id', $branchId)->first();

        return new SplitBillSettings(
            roundingMode: (string) ($setting?->split_bill_rounding_mode ?? 'auto'),
            // #815 — mặc định JPY, khớp charge currency.
            currencyCode: (string) ($setting?->currency_code ?? 'JPY'),
            serviceChargeRate: (float) ($setting?->service_charge_rate ?? 0),
        );
    }
}
