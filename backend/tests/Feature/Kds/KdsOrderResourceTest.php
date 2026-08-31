<?php

use App\Http\Resources\Kds\KdsOrderResource;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Table;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use Illuminate\Http\Request;

// ---------------------------------------------------------------------------
// 1 — Hidden internal fields
// ---------------------------------------------------------------------------

it('KdsOrderResource hides internal columns', function () {
    $order = CustomerOrder::factory()->create();
    $order->load(['items', 'tables']);

    $resource = (new KdsOrderResource($order))->toArray(Request::create('/'));

    expect($resource)
        ->not->toHaveKey('organization_id')
        ->not->toHaveKey('brand_id')
        ->not->toHaveKey('console_organization_id')
        ->not->toHaveKey('console_brand_id')
        ->not->toHaveKey('created_by_id')
        ->not->toHaveKey('customer_id')
        ->not->toHaveKey('subtotal_cents')
        ->not->toHaveKey('tax_cents')
        ->not->toHaveKey('total_cents')
        ->not->toHaveKey('discount_cents')
        ->not->toHaveKey('payment_status')
        ->not->toHaveKey('created_at')
        ->not->toHaveKey('updated_at')
        ->not->toHaveKey('deleted_at')
        ->not->toHaveKey('branch_id')
        ->not->toHaveKey('cancelled_at');
});

// ---------------------------------------------------------------------------
// 2 — Exposed semantic fields
// ---------------------------------------------------------------------------

it('KdsOrderResource exposes semantic fields', function () {
    $order = CustomerOrder::factory()->create([
        'opened_at' => now()->subMinutes(3),
        'note' => 'Không bỏ rau thơm',
    ]);
    $order->load(['items', 'tables']);

    $resource = (new KdsOrderResource($order))->toArray(Request::create('/'));

    expect($resource)->toHaveKeys([
        'id',
        'order_code',
        'order_type',
        'guest_count',
        'note',
        'opened_at',
        'aging_minutes',
        'is_late',
        'priority',
        'pending_items_count',
        'preparing_items_count',
        'ready_items_count',
        'oldest_pending_age_minutes',
        'can_bump_all',
        'table',
        'items',
    ]);
});

// ---------------------------------------------------------------------------
// 3 — priority normal when aging < 5
// ---------------------------------------------------------------------------

it('priority is normal when aging_minutes < 5', function () {
    $order = CustomerOrder::factory()->create([
        'opened_at' => now()->subMinutes(2),
    ]);
    $order->load(['items', 'tables']);

    $resource = (new KdsOrderResource($order))->toArray(Request::create('/'));

    expect($resource['priority'])->toBe('normal');
    expect($resource['is_late'])->toBeFalse();
    expect($resource['aging_minutes'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(4);
});

// ---------------------------------------------------------------------------
// 4 — priority warning when 5 <= aging < 10
// ---------------------------------------------------------------------------

it('priority is warning when 5 <= aging_minutes < 10', function () {
    $order = CustomerOrder::factory()->create([
        'opened_at' => now()->subMinutes(7),
    ]);
    $order->load(['items', 'tables']);

    $resource = (new KdsOrderResource($order))->toArray(Request::create('/'));

    expect($resource['priority'])->toBe('warning');
    expect($resource['is_late'])->toBeFalse();
    expect($resource['aging_minutes'])->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(8);
});

// ---------------------------------------------------------------------------
// 5 — priority critical when aging >= 10
// ---------------------------------------------------------------------------

it('priority is critical when aging_minutes >= 10', function () {
    $order = CustomerOrder::factory()->create([
        'opened_at' => now()->subMinutes(13),
    ]);
    $order->load(['items', 'tables']);

    $resource = (new KdsOrderResource($order))->toArray(Request::create('/'));

    expect($resource['priority'])->toBe('critical');
    expect($resource['is_late'])->toBeTrue();
    expect($resource['aging_minutes'])->toBeGreaterThanOrEqual(12)->toBeLessThanOrEqual(14);
});

// ---------------------------------------------------------------------------
// 6 — item counts exclude voided
// ---------------------------------------------------------------------------

it('item counts exclude voided items', function () {
    $order = CustomerOrder::factory()->create();

    CustomerOrderItem::factory()->count(2)->create([
        'customer_order_id' => $order->id,
        'status' => OrderItemStatusEnum::Pending->value,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => OrderItemStatusEnum::Preparing->value,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => OrderItemStatusEnum::Ready->value,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => OrderItemStatusEnum::Voided->value,
    ]);

    $order->load(['items', 'tables']);

    $resource = (new KdsOrderResource($order))->toArray(Request::create('/'));

    expect($resource['pending_items_count'])->toBe(2);
    expect($resource['preparing_items_count'])->toBe(1);
    expect($resource['ready_items_count'])->toBe(1);
});

// ---------------------------------------------------------------------------
// 7 — oldest_pending_age_minutes from oldest pending item
// ---------------------------------------------------------------------------

it('oldest_pending_age_minutes returns age of the oldest pending item', function () {
    $order = CustomerOrder::factory()->create();

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => OrderItemStatusEnum::Pending->value,
        'created_at' => now()->subMinutes(5),
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => OrderItemStatusEnum::Pending->value,
        'created_at' => now()->subMinutes(12),
    ]);

    $order->load(['items', 'tables']);

    $resource = (new KdsOrderResource($order))->toArray(Request::create('/'));

    expect($resource['oldest_pending_age_minutes'])
        ->toBeGreaterThanOrEqual(11)
        ->toBeLessThanOrEqual(13);
});

// ---------------------------------------------------------------------------
// 8 — oldest_pending_age_minutes is 0 when no pending items
// ---------------------------------------------------------------------------

it('oldest_pending_age_minutes is 0 when no pending items', function () {
    $order = CustomerOrder::factory()->create();

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => OrderItemStatusEnum::Ready->value,
    ]);

    $order->load(['items', 'tables']);

    $resource = (new KdsOrderResource($order))->toArray(Request::create('/'));

    expect($resource['oldest_pending_age_minutes'])->toBe(0);
});

// ---------------------------------------------------------------------------
// 9 — can_bump_all false when order voided
// ---------------------------------------------------------------------------

it('can_bump_all is false when order is voided', function () {
    $order = CustomerOrder::factory()->create([
        'status' => CustomerOrderStatusEnum::Voided->value,
    ]);

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => OrderItemStatusEnum::Pending->value,
    ]);

    $order->load(['items', 'tables']);

    $resource = (new KdsOrderResource($order))->toArray(Request::create('/'));

    expect($resource['can_bump_all'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// 10 — can_bump_all false when no pending/preparing items
// ---------------------------------------------------------------------------

it('can_bump_all is false when no pending or preparing items', function () {
    $order = CustomerOrder::factory()->create([
        'status' => CustomerOrderStatusEnum::Open->value,
    ]);

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => OrderItemStatusEnum::Ready->value,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => OrderItemStatusEnum::Served->value,
    ]);

    $order->load(['items', 'tables']);

    $resource = (new KdsOrderResource($order))->toArray(Request::create('/'));

    expect($resource['can_bump_all'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// 11 — can_bump_all true when active items present
// ---------------------------------------------------------------------------

it('can_bump_all is true when order is active and has pending items', function () {
    $order = CustomerOrder::factory()->create([
        'status' => CustomerOrderStatusEnum::Open->value,
    ]);

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => OrderItemStatusEnum::Pending->value,
    ]);

    $order->load(['items', 'tables']);

    $resource = (new KdsOrderResource($order))->toArray(Request::create('/'));

    expect($resource['can_bump_all'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// 12 — table embed has only id/code/zone
// ---------------------------------------------------------------------------

it('table embed has exactly id, code, and zone keys', function () {
    $order = CustomerOrder::factory()->create();

    // Create a table pointing to this order
    $table = Table::factory()->create([
        'current_order_id' => $order->id,
    ]);

    $order->load(['items', 'tables.zone']);

    $resource = (new KdsOrderResource($order))->toArray(Request::create('/'));

    expect($resource['table'])->not->toBeNull();
    expect($resource['table'])->toHaveCount(3);
    expect($resource['table'])->toHaveKeys(['id', 'code', 'zone']);
});

// ---------------------------------------------------------------------------
// 13 — items collection filters voided
// ---------------------------------------------------------------------------

it('items collection filters out voided items', function () {
    $order = CustomerOrder::factory()->create();

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => OrderItemStatusEnum::Pending->value,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => OrderItemStatusEnum::Voided->value,
    ]);

    $order->load(['items', 'tables']);

    $resource = (new KdsOrderResource($order))->toArray(Request::create('/'));

    expect($resource['items'])->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// 14 — can_bump_all false when items relation not loaded
// ---------------------------------------------------------------------------

test('can_bump_all is false when items relation not loaded', function () {
    $order = CustomerOrder::factory()->create([
        'status' => CustomerOrderStatusEnum::Open->value,
        // Do NOT load items
    ]);
    $resource = (new KdsOrderResource($order))->toArray(Request::create('/'));
    expect($resource['can_bump_all'])->toBeFalse();
});
