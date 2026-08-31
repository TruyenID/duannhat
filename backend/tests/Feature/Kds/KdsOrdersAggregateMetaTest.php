<?php

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\Organization;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\Kds\KdsAggregateMeta;

// ---------------------------------------------------------------------------
// 1 — compute returns all required meta keys
// ---------------------------------------------------------------------------

it('compute returns all required meta keys for empty collection', function () {
    $meta = KdsAggregateMeta::compute(collect());

    expect($meta)->toHaveKeys([
        'active_count',
        'late_count',
        'critical_count',
        'avg_age_minutes',
        'oldest_pending_item_age_minutes',
        'items_by_status',
        'fetched_at',
    ]);

    expect($meta['items_by_status'])->toHaveCount(4);
    expect($meta['items_by_status'])->toHaveKeys([
        OrderItemStatusEnum::Pending->value,
        OrderItemStatusEnum::Preparing->value,
        OrderItemStatusEnum::Ready->value,
        OrderItemStatusEnum::Served->value,
    ]);
});

// ---------------------------------------------------------------------------
// 2 — compute counts active orders correctly
// ---------------------------------------------------------------------------

it('compute counts active orders correctly', function () {
    $orders = CustomerOrder::factory()->count(3)->create([
        'opened_at' => now()->subMinutes(2),
    ]);

    // Load empty items relation for each
    $orders->each(fn ($o) => $o->setRelation('items', collect()));

    $meta = KdsAggregateMeta::compute($orders);

    expect($meta['active_count'])->toBe(3);
});

// ---------------------------------------------------------------------------
// 3 — compute counts late orders (aging > 10)
// ---------------------------------------------------------------------------

it('compute counts late orders where aging > 10 minutes', function () {
    $order1 = CustomerOrder::factory()->create(['opened_at' => now()->subMinutes(5)]);
    $order2 = CustomerOrder::factory()->create(['opened_at' => now()->subMinutes(12)]);
    $order3 = CustomerOrder::factory()->create(['opened_at' => now()->subMinutes(15)]);

    $orders = collect([$order1, $order2, $order3]);
    $orders->each(fn ($o) => $o->setRelation('items', collect()));

    $meta = KdsAggregateMeta::compute($orders);

    expect($meta['late_count'])->toBe(2);
});

// ---------------------------------------------------------------------------
// 4 — compute counts critical orders (aging >= 10)
// ---------------------------------------------------------------------------

it('compute counts critical orders where aging >= 10 minutes', function () {
    $order1 = CustomerOrder::factory()->create(['opened_at' => now()->subMinutes(5)]);
    $order2 = CustomerOrder::factory()->create(['opened_at' => now()->subMinutes(10)]);
    $order3 = CustomerOrder::factory()->create(['opened_at' => now()->subMinutes(12)]);
    $order4 = CustomerOrder::factory()->create(['opened_at' => now()->subMinutes(15)]);

    $orders = collect([$order1, $order2, $order3, $order4]);
    $orders->each(fn ($o) => $o->setRelation('items', collect()));

    $meta = KdsAggregateMeta::compute($orders);

    // Orders at 10, 12, 15 minutes => 3 critical
    expect($meta['critical_count'])->toBe(3);
    // Orders at 12, 15 minutes => 2 late (> 10)
    expect($meta['late_count'])->toBe(2);
});

// ---------------------------------------------------------------------------
// 5 — compute average age
// ---------------------------------------------------------------------------

it('compute calculates average aging minutes rounded to 1 decimal', function () {
    $order1 = CustomerOrder::factory()->create(['opened_at' => now()->subMinutes(2)]);
    $order2 = CustomerOrder::factory()->create(['opened_at' => now()->subMinutes(4)]);
    $order3 = CustomerOrder::factory()->create(['opened_at' => now()->subMinutes(6)]);

    $orders = collect([$order1, $order2, $order3]);
    $orders->each(fn ($o) => $o->setRelation('items', collect()));

    $meta = KdsAggregateMeta::compute($orders);

    // avg = (2+4+6)/3 = 4.0
    expect($meta['avg_age_minutes'])->toBe(4.0);
});

// ---------------------------------------------------------------------------
// 6 — compute oldest pending item across orders
// ---------------------------------------------------------------------------

it('compute finds oldest pending item age across all orders', function () {
    $order1 = CustomerOrder::factory()->create(['opened_at' => now()->subMinutes(5)]);
    $order2 = CustomerOrder::factory()->create(['opened_at' => now()->subMinutes(8)]);

    // Order 1: pending item aged 6 min
    $item1 = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order1->id,
        'status' => OrderItemStatusEnum::Pending->value,
        'created_at' => now()->subMinutes(6),
    ]);
    // Order 2: pending item aged 14 min (oldest)
    $item2 = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order2->id,
        'status' => OrderItemStatusEnum::Pending->value,
        'created_at' => now()->subMinutes(14),
    ]);
    // Order 2: preparing item aged 20 min (should not count)
    $item3 = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order2->id,
        'status' => OrderItemStatusEnum::Preparing->value,
        'created_at' => now()->subMinutes(20),
    ]);

    $order1->setRelation('items', collect([$item1]));
    $order2->setRelation('items', collect([$item2, $item3]));

    $meta = KdsAggregateMeta::compute(collect([$order1, $order2]));

    // Oldest pending item is 14 min (with ±1 tolerance for timing)
    expect($meta['oldest_pending_item_age_minutes'])
        ->toBeGreaterThanOrEqual(13)
        ->toBeLessThanOrEqual(15);
});

// ---------------------------------------------------------------------------
// 7 — compute items_by_status excludes voided
// ---------------------------------------------------------------------------

it('compute items_by_status excludes voided items', function () {
    $order = CustomerOrder::factory()->create();

    $items = collect([
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'status' => OrderItemStatusEnum::Pending->value,
        ]),
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'status' => OrderItemStatusEnum::Pending->value,
        ]),
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'status' => OrderItemStatusEnum::Preparing->value,
        ]),
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'status' => OrderItemStatusEnum::Ready->value,
        ]),
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'status' => OrderItemStatusEnum::Served->value,
        ]),
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'status' => OrderItemStatusEnum::Voided->value,
        ]),
    ]);

    $order->setRelation('items', $items);

    $meta = KdsAggregateMeta::compute(collect([$order]));

    expect($meta['items_by_status'])->toBe([
        'pending' => 2,
        'preparing' => 1,
        'ready' => 1,
        'served' => 1,
    ]);
});

// ---------------------------------------------------------------------------
// 8 — compute empty orders returns zeros
// ---------------------------------------------------------------------------

it('compute empty orders returns all zeros', function () {
    $meta = KdsAggregateMeta::compute(collect());

    expect($meta['active_count'])->toBe(0);
    expect($meta['late_count'])->toBe(0);
    expect($meta['critical_count'])->toBe(0);
    expect($meta['avg_age_minutes'])->toBe(0);
    expect($meta['oldest_pending_item_age_minutes'])->toBe(0);
    expect($meta['items_by_status'])->toBe([
        'pending' => 0,
        'preparing' => 0,
        'ready' => 0,
        'served' => 0,
    ]);
    expect($meta['fetched_at'])->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 9 — endpoint integration: GET /kds/orders returns aggregate meta
// ---------------------------------------------------------------------------

it('GET /kds/orders response includes aggregate meta block', function () {
    $org = Organization::factory()->create();
    $branch = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'organization_id' => $org->id,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_meta',
    ]);

    // 2 active orders, 1 late (12 min old), 1 normal (3 min old)
    $lateOrder = CustomerOrder::factory()->create([
        'organization_id' => $org->id,
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Open,
        'opened_at' => now()->subMinutes(12),
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $lateOrder->id,
        'status' => OrderItemStatusEnum::Pending->value,
    ]);

    $normalOrder = CustomerOrder::factory()->create([
        'organization_id' => $org->id,
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Open,
        'opened_at' => now()->subMinutes(3),
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $normalOrder->id,
        'status' => OrderItemStatusEnum::Preparing->value,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer tok_kds_meta')
        ->getJson('/api/v1/kds/orders');

    $response->assertOk();

    $response->assertJsonStructure([
        'meta' => [
            'active_count',
            'late_count',
            'critical_count',
            'avg_age_minutes',
            'oldest_pending_item_age_minutes',
            'items_by_status' => [
                'pending',
                'preparing',
                'ready',
                'served',
            ],
            'fetched_at',
        ],
    ]);

    $meta = $response->json('meta');
    expect($meta['active_count'])->toBe(2);
    expect($meta['late_count'])->toBe(1); // only the 12min order is > 10
    expect($meta['critical_count'])->toBe(1); // 12min >= 10
    expect($meta['items_by_status']['pending'])->toBe(1);
    expect($meta['items_by_status']['preparing'])->toBe(1);
    expect($meta['items_by_status']['ready'])->toBe(0);
    expect($meta['items_by_status']['served'])->toBe(0);
});
