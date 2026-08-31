<?php

declare(strict_types=1);

namespace App\Services\PeripheralDevice\Internal;

use App\Models\PeripheralDevice;
use App\Services\PeripheralDevice\Contracts\BranchCashDevices;

/**
 * Hiện thực {@see BranchCashDevices} — sống trong PlatformIntegration vì đây là
 * module SỞ HỮU `peripheral_devices`.
 */
final class EloquentBranchCashDevices implements BranchCashDevices
{
    public function activeCashDeviceIds(string $branchId): array
    {
        return PeripheralDevice::query()
            ->where('branch_id', $branchId)
            ->where('type', 'coin_changer')
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }
}
