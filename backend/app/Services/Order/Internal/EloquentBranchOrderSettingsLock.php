<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\ShopOrderSetting;
use App\Services\Order\Contracts\BranchOrderSettingsLock;
use App\Services\Order\Contracts\LockedBranchOrderSettings;

/**
 * #962 — hiện thực {@see BranchOrderSettingsLock}.
 *
 * Chép NGUYÊN câu truy vấn vừa rời `TillSessionService::open()`, kể cả thứ tự
 * `->lockForUpdate()` trước `->first([...])` và đúng hai cột được select. Đây là
 * PR ranh giới: không nhân tiện "dọn" gì cả.
 *
 * Dùng `ShopOrderSetting::query()` chứ không `DB::table()` — ngược với
 * {@see EloquentOrderRowLock}, nơi `DB::table` là cố ý để khoá được cả dòng đã
 * xoá mềm. `shop_order_settings` KHÔNG có `deleted_at`, nên hai lối sinh ra cùng
 * một câu SQL; giữ model builder vì bản cũ dùng model builder.
 */
final class EloquentBranchOrderSettingsLock implements BranchOrderSettingsLock
{
    public function lockAndReadForBranch(string $branchId): ?LockedBranchOrderSettings
    {
        $row = ShopOrderSetting::query()
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first(['currency_code', 'prices_include_tax']);

        if ($row === null) {
            return null;
        }

        return new LockedBranchOrderSettings(
            currencyCode: $row->currency_code === null ? null : (string) $row->currency_code,
            // Bản cũ: `(bool) ($shopSetting?->prices_include_tax ?? false)`. Cột
            // nullable ⇒ NULL phải thành `false`, không phải để `null` rò ra cổng.
            pricesIncludeTax: (bool) ($row->prices_include_tax ?? false),
        );
    }
}
