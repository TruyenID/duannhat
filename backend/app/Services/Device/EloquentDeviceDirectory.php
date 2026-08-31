<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\Device;
use App\Services\Device\Contracts\DeviceDirectory;

/**
 * #1666 — hiện thực Eloquent của {@see DeviceDirectory}.
 *
 * `Device` dùng SoftDeletes, nên `Device::query()` đã loại thiết bị đã xoá mềm —
 * đúng cái `->pluck('device')->filter()` của chỗ gọi cũ làm được nhờ quan hệ trả
 * `null`. Không thêm `withTrashed()`: một thiết bị đã xoá không còn là "thiết bị
 * bị ảnh hưởng", và cũng không được xem cấu hình thanh toán nữa.
 */
final class EloquentDeviceDirectory implements DeviceDirectory
{
    public function identitiesByIds(array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }

        $rows = Device::query()
            ->whereIn('id', $deviceIds)
            ->get(['id', 'name'])
            ->keyBy(static fn (Device $device): string => (string) $device->id);

        $identities = [];

        // Đi theo thứ tự id được hỏi chứ không theo thứ tự DB trả: chỗ gọi dựng
        // danh sách cho người đọc, và một danh sách đổi thứ tự giữa hai lần mở
        // cùng màn hình là thứ không ai báo lỗi nhưng ai cũng thấy sai.
        foreach ($deviceIds as $deviceId) {
            $device = $rows->get($deviceId);

            if ($device === null) {
                continue;
            }

            $identities[] = [
                'id' => (string) $device->id,
                'name' => $device->name === null ? null : (string) $device->name,
            ];
        }

        return $identities;
    }

    public function isActiveInBranch(string $deviceId, string $organizationId, string $branchId): bool
    {
        return Device::query()
            ->whereKey($deviceId)
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->exists();
    }
}
