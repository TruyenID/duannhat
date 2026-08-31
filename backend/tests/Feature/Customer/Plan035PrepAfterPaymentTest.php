<?php

/**
 * plan-035 / plan-037 — prep-after-payment mechanism (regression for #671).
 *
 * The plan-035 DESIGN originally reached for a realtime `OrderReadyForPrep`
 * event fired on `pending → open` at payment time. The shipped design instead
 * relies on:
 *   1. a `confirmed`/`pending` takeaway order being INVISIBLE to KDS
 *      (KdsController::orders only lists open/dining/checkout/paying), and
 *   2. `CustomerOrderService::confirmOrder()` flipping the acknowledged order
 *      to `open`, at which point the KDS poll surfaces it.
 *
 * These tests lock that mechanism so the "prep only after payment/confirm"
 * guarantee for counter-pay takeaway can't silently regress — whether or not
 * a realtime event ever gets added on top.
 */

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use App\Services\Customer\CustomerOrderService;

function kdsDeviceForBranch(Branch $branch, string $token): Device
{
    return Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => $token,
    ]);
}

it('hides a confirmed (unpaid) takeaway order from KDS', function () {
    $branch = Branch::factory()->create();
    kdsDeviceForBranch($branch, 'tok_kds_confirmed');

    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Confirmed,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer tok_kds_confirmed')
        ->getJson('/api/v1/kds/orders');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($order->id);
});

it('hides a pending takeaway order from KDS', function () {
    $branch = Branch::factory()->create();
    kdsDeviceForBranch($branch, 'tok_kds_pending');

    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Pending,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer tok_kds_pending')
        ->getJson('/api/v1/kds/orders');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($order->id);
});

it('surfaces the order to KDS once confirmOrder flips confirmed → open', function () {
    $branch = Branch::factory()->create();
    kdsDeviceForBranch($branch, 'tok_kds_flip');

    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Confirmed,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
    ]);

    // Not yet visible while awaiting the staff acknowledge / payment step.
    $before = $this->withHeader('Authorization', 'Bearer tok_kds_flip')
        ->getJson('/api/v1/kds/orders');
    expect(collect($before->json('data'))->pluck('id')->all())
        ->not->toContain($order->id);

    // Staff acknowledges (the shipped "prep after payment" trigger) →
    // CustomerOrderService::confirmOrder flips the order to `open`.
    app(CustomerOrderService::class)->confirmOrder($order->fresh());

    expect($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Open);

    // Now the KDS poll picks it up.
    $after = $this->withHeader('Authorization', 'Bearer tok_kds_flip')
        ->getJson('/api/v1/kds/orders');
    expect(collect($after->json('data'))->pluck('id')->all())
        ->toContain($order->id);
});

it('confirmOrder also promotes a legacy pending takeaway order to open for KDS', function () {
    $branch = Branch::factory()->create();
    kdsDeviceForBranch($branch, 'tok_kds_legacy');

    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Pending,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
    ]);

    app(CustomerOrderService::class)->confirmOrder($order->fresh());

    expect($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Open);

    $after = $this->withHeader('Authorization', 'Bearer tok_kds_legacy')
        ->getJson('/api/v1/kds/orders');
    expect(collect($after->json('data'))->pluck('id')->all())
        ->toContain($order->id);
});
