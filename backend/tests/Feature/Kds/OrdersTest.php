<?php

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use Illuminate\Support\Str;

it('returns active orders scoped to device branch', function () {
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branchA->id,
        'device_token' => 'tok_kds_o',
    ]);

    $activeA = CustomerOrder::factory()->create([
        'branch_id' => $branchA->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    CustomerOrderItem::factory()->create(['customer_order_id' => $activeA->id, 'status' => 'pending']);

    $closedA = CustomerOrder::factory()->create([
        'branch_id' => $branchA->id,
        'status' => CustomerOrderStatusEnum::Closed,
    ]);
    $otherBranch = CustomerOrder::factory()->create([
        'branch_id' => $branchB->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer tok_kds_o')
        ->getJson('/api/v1/kds/orders');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)
        ->toContain($activeA->id)
        ->not->toContain($closedA->id)
        ->not->toContain($otherBranch->id);
});

it('does not disclose orders of another org sharing the same branch_id (#845)', function () {
    // Simulates a device cross-paired to a victim branch: the device carries the
    // attacker org but the victim branch id. Orders keyed to that branch but a
    // different org must never be returned.
    $victimBranchId = Branch::factory()->create()->id;

    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $victimBranchId,
        'device_token' => 'tok_kds_xorg',
    ]);

    $victimOrder = CustomerOrder::factory()->create([
        'branch_id' => $victimBranchId,
        'organization_id' => (string) Str::uuid(), // different org than the device
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    CustomerOrderItem::factory()->create(['customer_order_id' => $victimOrder->id, 'status' => 'pending']);

    $response = $this->withHeader('Authorization', 'Bearer tok_kds_xorg')
        ->getJson('/api/v1/kds/orders')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($victimOrder->id);
});
