<?php

namespace App\Services\Notification\AudienceResolvers;

use App\Models\Brand;
use App\Services\Device\Contracts\NotifiableDeviceDirectory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Resolves `{type: 'device', device_types: [...], branch_id: '<uuid>'}`.
 *
 * Emits Device models (polymorphic — resolver output is not always Users).
 * Trace: `device:branch_id:{branch_id}`.
 *
 * #962 — `devices` thuộc PlatformIntegration; lớp này hỏi qua
 * {@see NotifiableDeviceDirectory} thay vì tự dựng truy vấn. Cùng khuôn với
 * `RoleResolver`, vốn đã đi qua `RoleAssignmentDirectory` từ #1622.
 */
class DeviceResolver implements AudienceResolver
{
    public function type(): string
    {
        return 'device';
    }

    public function resolve(array $rule, Brand $brand): Collection
    {
        $branchId = $rule['branch_id'] ?? 'any';

        return app(NotifiableDeviceDirectory::class)
            ->matching([
                'branch_id' => $rule['branch_id'] ?? null,
                'device_types' => (array) ($rule['device_types'] ?? []),
                'device_ids' => (array) ($rule['device_ids'] ?? []),
            ])
            ->map(fn (Model $device): array => [
                'notifiable' => $device,
                'key' => $device->getMorphClass().':'.$device->getKey(),
                'trace' => "device:branch_id:{$branchId}",
            ])
            ->values();
    }
}
