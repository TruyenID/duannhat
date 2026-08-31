<?php

use App\Models\Branch;
use App\Models\Device;
use App\Omnify\Enums\DeviceStatusEnum;
use Database\Seeders\ShopFloorSeeder;

it('seeds a pending-activation KDS device for each active branch', function () {
    // ShopFloorSeeder resolves its org via Organization::first(), which is the
    // baseline org-001 created in tests/Pest.php beforeEach.
    $branch = Branch::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
        'is_active' => true,
    ]);

    $this->seed(ShopFloorSeeder::class);

    $kds = Device::where('branch_id', $branch->id)
        ->where('type', 'kds')
        ->first();

    expect($kds)->not->toBeNull()
        ->and($kds->status)->toBe(DeviceStatusEnum::PendingActivation)
        ->and($kds->pairing_code)->not->toBeNull()
        ->and($kds->pairing_expires_at)->not->toBeNull();
});
