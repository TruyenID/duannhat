<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Services\Device\Contracts\DeviceDirectory;
use Illuminate\Support\Str;

/**
 * #1666 (#962) — cổng `DeviceDirectory`: PlatformIntegration công bố "thiết bị
 * này là ai" cho Payments.
 *
 * Trước bản vá, hai class của Payments cầm thẳng `App\Models\Device`: một chỗ
 * chỉ để đọc `name` cho màn ngắt kết nối, một chỗ để so org/branch/status. Bài
 * này ghim đúng hai hành vi ĐÃ THAY ĐỔI HÌNH DẠNG khi đi qua cổng — id không
 * phân giải được, và thiết bị đã xoá mềm.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $brand->console_brand_id,
    ]);

    $this->directory = app(DeviceDirectory::class);

    $this->device = function (array $overrides = []): Device {
        return Device::factory()->create(array_merge([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ], $overrides));
    };
});

it('cổng có binding thật, không phải interface rỗng', function () {
    expect($this->directory)->toBeInstanceOf(DeviceDirectory::class);
});

it('trả id + tên theo ĐÚNG thứ tự được hỏi', function () {
    $a = ($this->device)(['name' => 'Quầy 1']);
    $b = ($this->device)(['name' => 'Quầy 2']);

    expect($this->directory->identitiesByIds([(string) $b->id, (string) $a->id]))
        ->toBe([
            ['id' => (string) $b->id, 'name' => 'Quầy 2'],
            ['id' => (string) $a->id, 'name' => 'Quầy 1'],
        ]);
});

it('id lạ và thiết bị đã xoá mềm bị BỎ KHỎI kết quả, không thành phần tử rỗng', function () {
    $alive = ($this->device)(['name' => 'Còn sống']);
    $gone = ($this->device)(['name' => 'Đã gỡ']);
    $gone->delete();

    // Chỗ gọi dùng `count()` của mảng này làm `device_count` trên màn ngắt kết
    // nối. Một phần tử rỗng cho id không phân giải được sẽ đếm sai lên.
    expect($this->directory->identitiesByIds([
        (string) $alive->id,
        (string) $gone->id,
        (string) Str::uuid(),
    ]))->toBe([['id' => (string) $alive->id, 'name' => 'Còn sống']]);
});

it('mảng rỗng vào thì mảng rỗng ra, không đụng DB', function () {
    expect($this->directory->identitiesByIds([]))->toBe([]);
});

it('isActiveInBranch: đúng org + đúng branch + active mới là true', function () {
    $device = ($this->device)();

    expect($this->directory->isActiveInBranch((string) $device->id, $this->orgId, (string) $this->branch->id))
        ->toBeTrue();
});

it('isActiveInBranch fail-closed: id lạ, sai org, sai branch, hoặc không active đều false', function () {
    $active = ($this->device)();
    $inactive = ($this->device)(['status' => 'inactive']);
    $otherBranch = Branch::factory()->create(['console_organization_id' => $this->orgId]);

    expect($this->directory->isActiveInBranch((string) Str::uuid(), $this->orgId, (string) $this->branch->id))->toBeFalse()
        ->and($this->directory->isActiveInBranch((string) $active->id, (string) Str::uuid(), (string) $this->branch->id))->toBeFalse()
        ->and($this->directory->isActiveInBranch((string) $active->id, $this->orgId, (string) $otherBranch->id))->toBeFalse()
        ->and($this->directory->isActiveInBranch((string) $inactive->id, $this->orgId, (string) $this->branch->id))->toBeFalse();
});
