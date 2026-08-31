<?php

declare(strict_types=1);

namespace App\Services\Device\Internal;

use App\Models\Device;
use App\Services\Device\Contracts\NotifiableDeviceDirectory;
use Illuminate\Support\Collection;

/**
 * #962 — hiện thực Eloquent của {@see NotifiableDeviceDirectory}.
 *
 * Ba điều kiện WHERE chép nguyên từ `DeviceResolver`, kể cả `! empty()`: một
 * `branch_id` rỗng hay một mảng `device_types` rỗng nghĩa là "không lọc theo
 * chiều đó", không phải "không khớp gì". Đổi thành `!== null` ở bước dời ranh
 * giới là đổi tập người nhận của một thông báo.
 */
final class EloquentNotifiableDeviceDirectory implements NotifiableDeviceDirectory
{
    public function matching(array $filter): Collection
    {
        $query = Device::query();

        if (! empty($filter['branch_id'])) {
            $query->where('branch_id', $filter['branch_id']);
        }

        if (! empty($filter['device_types'])) {
            $query->whereIn('type', (array) $filter['device_types']);
        }

        if (! empty($filter['device_ids'])) {
            $query->whereIn('id', (array) $filter['device_ids']);
        }

        return $query->get()->values();
    }
}
