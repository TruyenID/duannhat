<?php

use App\Http\Resources\Kds\KdsItemResource;
use App\Models\CustomerOrderItem;
use App\Models\OrderItemTopping;
use Illuminate\Http\Request;

// ---------------------------------------------------------------------------
// 1 — Hidden internal fields
// ---------------------------------------------------------------------------

it('KdsItemResource hides internal fields', function () {
    $item = CustomerOrderItem::factory()->create();

    $resource = (new KdsItemResource($item))->toArray(Request::create('/'));

    expect($resource)
        ->not->toHaveKey('customer_order_id')
        ->not->toHaveKey('product_sku_id')
        ->not->toHaveKey('voided_at')
        ->not->toHaveKey('voided_by')
        ->not->toHaveKey('void_reason')
        ->not->toHaveKey('price_cents')
        ->not->toHaveKey('tax_cents')
        ->not->toHaveKey('subtotal_cents')
        ->not->toHaveKey('idempotency_key')
        ->not->toHaveKey('created_at')
        ->not->toHaveKey('updated_at')
        ->not->toHaveKey('deleted_at');
});

// ---------------------------------------------------------------------------
// 2 — Semantic fields all present
// ---------------------------------------------------------------------------

it('KdsItemResource exposes semantic fields', function () {
    $item = CustomerOrderItem::factory()->create([
        'status' => 'pending',
        'note' => 'Ít hành',
    ]);

    $resource = (new KdsItemResource($item))->toArray(Request::create('/'));

    expect($resource)->toHaveKeys([
        'id',
        'menu_item_name',
        'quantity',
        'status',
        'note',
        'aging_minutes',
        'time_in_current_status_seconds',
        'is_blocked_by_toppings',
        'allowed_transitions',
        'started_preparing_at',
        'ready_at',
        'served_at',
        'toppings',
    ]);
});

// ---------------------------------------------------------------------------
// 3 — aging_minutes computed since created_at
// ---------------------------------------------------------------------------

it('aging_minutes computes since created_at', function () {
    $item = CustomerOrderItem::factory()->create([
        'created_at' => now()->subMinutes(8),
    ]);

    $resource = (new KdsItemResource($item))->toArray(Request::create('/'));

    expect($resource['aging_minutes'])
        ->toBeGreaterThanOrEqual(7)
        ->toBeLessThanOrEqual(9);
});

// ---------------------------------------------------------------------------
// 4 — time_in_current_status_seconds reflects current status transition
// ---------------------------------------------------------------------------

it('time_in_current_status_seconds uses created_at for pending status', function () {
    $item = CustomerOrderItem::factory()->create([
        'status' => 'pending',
        'created_at' => now()->subSeconds(90),
    ]);

    $resource = (new KdsItemResource($item))->toArray(Request::create('/'));

    expect($resource['time_in_current_status_seconds'])
        ->toBeGreaterThanOrEqual(85)
        ->toBeLessThanOrEqual(95);
});

it('time_in_current_status_seconds uses started_preparing_at for preparing status', function () {
    $item = CustomerOrderItem::factory()->create([
        'status' => 'preparing',
        'started_preparing_at' => now()->subSeconds(120),
        'created_at' => now()->subSeconds(300),
    ]);

    $resource = (new KdsItemResource($item))->toArray(Request::create('/'));

    expect($resource['time_in_current_status_seconds'])
        ->toBeGreaterThanOrEqual(115)
        ->toBeLessThanOrEqual(125);
});

it('time_in_current_status_seconds uses ready_at for ready status', function () {
    $item = CustomerOrderItem::factory()->create([
        'status' => 'ready',
        'ready_at' => now()->subSeconds(60),
        'started_preparing_at' => now()->subSeconds(200),
        'created_at' => now()->subSeconds(300),
    ]);

    $resource = (new KdsItemResource($item))->toArray(Request::create('/'));

    expect($resource['time_in_current_status_seconds'])
        ->toBeGreaterThanOrEqual(55)
        ->toBeLessThanOrEqual(65);
});

it('time_in_current_status_seconds uses served_at for served status', function () {
    $item = CustomerOrderItem::factory()->create([
        'status' => 'served',
        'served_at' => now()->subSeconds(45),
        'ready_at' => now()->subSeconds(200),
        'started_preparing_at' => now()->subSeconds(400),
        'created_at' => now()->subSeconds(600),
    ]);

    $resource = (new KdsItemResource($item))->toArray(Request::create('/'));

    expect($resource['time_in_current_status_seconds'])
        ->toBeGreaterThanOrEqual(40)
        ->toBeLessThanOrEqual(50);
});

// ---------------------------------------------------------------------------
// 5 — allowed_transitions per status (parametric)
// ---------------------------------------------------------------------------

dataset('allowed_transitions', [
    'pending' => ['pending', ['mark-preparing']],
    'preparing' => ['preparing', ['mark-ready', 'revert']],
    'ready' => ['ready', ['mark-served', 'revert']],
    'served' => ['served', ['revert']],
    'voided' => ['voided', []],
]);

it('allowed_transitions matches status', function (string $status, array $expected) {
    $item = CustomerOrderItem::factory()->create(['status' => $status]);

    $resource = (new KdsItemResource($item))->toArray(Request::create('/'));

    expect($resource['allowed_transitions'])->toEqual($expected);
})->with('allowed_transitions');

// ---------------------------------------------------------------------------
// 6 — is_blocked_by_toppings
// ---------------------------------------------------------------------------

it('is_blocked_by_toppings is true when preparing and a topping is not ready', function () {
    $item = CustomerOrderItem::factory()->create([
        'status' => 'preparing',
        'started_preparing_at' => now()->subSeconds(30),
    ]);

    OrderItemTopping::factory()->create([
        'customer_order_item_id' => $item->id,
        'status' => 'pending',
    ]);

    $item->load('orderItemToppings');
    $resource = (new KdsItemResource($item))->toArray(Request::create('/'));

    expect($resource['is_blocked_by_toppings'])->toBeTrue();
});

it('is_blocked_by_toppings is false when all toppings are ready', function () {
    $item = CustomerOrderItem::factory()->create([
        'status' => 'preparing',
        'started_preparing_at' => now()->subSeconds(30),
    ]);

    OrderItemTopping::factory()->create([
        'customer_order_item_id' => $item->id,
        'status' => 'ready',
    ]);

    $item->load('orderItemToppings');
    $resource = (new KdsItemResource($item))->toArray(Request::create('/'));

    expect($resource['is_blocked_by_toppings'])->toBeFalse();
});

it('is_blocked_by_toppings is false when item is not preparing', function () {
    $item = CustomerOrderItem::factory()->create(['status' => 'pending']);

    OrderItemTopping::factory()->create([
        'customer_order_item_id' => $item->id,
        'status' => 'pending',
    ]);

    $item->load('orderItemToppings');
    $resource = (new KdsItemResource($item))->toArray(Request::create('/'));

    expect($resource['is_blocked_by_toppings'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// 7 — toppings embed minimal shape {name, quantity, status}
// ---------------------------------------------------------------------------

it('toppings embed has exactly name/quantity/status keys per topping', function () {
    $item = CustomerOrderItem::factory()->create(['status' => 'preparing']);

    OrderItemTopping::factory()->create([
        'customer_order_item_id' => $item->id,
        'status' => 'pending',
        'quantity' => 2,
    ]);

    $item->load('orderItemToppings');
    $resource = (new KdsItemResource($item))->toArray(Request::create('/'));

    expect($resource['toppings'])->toBeArray()->toHaveCount(1);

    $topping = $resource['toppings'][0];
    expect($topping)->toHaveKeys(['name', 'quantity', 'status']);
});
