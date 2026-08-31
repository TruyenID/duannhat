<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\WarehouseMember;
use App\Services\Inventory\Contracts\WarehouseMemberDirectory;

/**
 * #962 — hiện thực Eloquent của {@see WarehouseMemberDirectory}. Cùng
 * chỗ đặt với `EloquentOrderLineStockDeduction` (#1595).
 */
final class EloquentWarehouseMemberDirectory implements WarehouseMemberDirectory
{
    public function userIdsWithRoleInWarehouse(string $warehouseId, string $memberRole): array
    {
        return WarehouseMember::query()
            ->where('warehouse_id', $warehouseId)
            ->where('role', $memberRole)
            ->pluck('user_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }
}
