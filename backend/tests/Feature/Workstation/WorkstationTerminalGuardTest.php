<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\TaxType;
use App\Omnify\Enums\OrderItemStatusEnum;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * #900 — the workstation LAN sync-UP mutators must NOT mutate an order that is
 * already terminal (closed / voided / expired). A shared orderIsMutable() gate
 * turns such an op into an idempotent no-op (200 + current state, nothing
 * written, a warning logged) instead of skewing booked revenue or resurrecting
 * a cancelled order.
 *
 * The gate short-circuits BEFORE $request->validate() and before any price /
 * total recompute, so the terminal cases need no menu/SKU/tax fixture — a
 * random product_sku_id still yields 200 (which itself proves the gate precedes
 * validation).
 *
 * The intentional replay sinks (voidItem / deleteItem / refundPayment) stay
 * UNGUARDED — guarding them would re-open the #825 outbox stall — so they must
 * still succeed on a closed order (asserted below).
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    // Only the replay-sink tests (voidItem/deleteItem) reach refreshOrderTotals;
    // seed the settings + default tax so that path is robust.
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'currency_code' => 'JPY',
        'service_charge_rate' => 0,
        'prices_include_tax' => false,
        'service_charge_tax_rate' => 0,
    ]);
    TaxType::factory()->standard()->asDefault()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

function tgHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->wsToken];
}

function tgOrder(string $status = 'open', array $extra = []): CustomerOrder
{
    return CustomerOrder::create(array_merge([
        'order_code' => 'WS-'.Str::random(6),
        'order_type' => 'spot',
        'status' => $status,
        'subtotal' => 1000,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 1000,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ], $extra));
}

function tgLine(CustomerOrder $order): CustomerOrderItem
{
    return CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => ProductSku::factory()->create()->id,
        'quantity' => 1,
        'unit_price' => 500,
        'subtotal' => 500,
        'status' => 'pending',
    ]);
}

dataset('terminal statuses', ['closed', 'voided', 'expired']);

// ─── Terminal no-op per mutator ──────────────────────────────────────────────

it('update() is a no-op on a terminal order', function (string $status) {
    $order = tgOrder($status, ['note' => 'original', 'guest_count' => 2]);

    $this->withHeaders(tgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/update", [
            'note' => 'hacked',
            'guest_count' => 9,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', $status);

    $order->refresh();
    expect($order->note)->toBe('original');
    expect((int) $order->guest_count)->toBe(2);
})->with('terminal statuses');

it('addItems() is a no-op on a terminal order', function (string $status) {
    $order = tgOrder($status);

    // Random SKU id proves the gate runs before validate()/price resolution.
    $this->withHeaders(tgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [[
                'product_sku_id' => (string) Str::uuid(),
                'quantity' => 2,
            ]],
        ])
        ->assertOk();

    expect(CustomerOrderItem::where('customer_order_id', $order->id)->count())->toBe(0);
    expect((float) $order->fresh()->total_amount)->toBe(1000.0);
})->with('terminal statuses');

it('updateItem() is a no-op on a terminal order', function (string $status) {
    $order = tgOrder($status);
    $item = tgLine($order);

    $this->withHeaders(tgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items/{$item->id}", [
            'quantity' => 5,
        ])
        ->assertOk();

    $item->refresh();
    expect((int) $item->quantity)->toBe(1);
    expect((float) $item->subtotal)->toBe(500.0);
})->with('terminal statuses');

it('applyCoupon() is a no-op on a terminal order — coupon not consumed', function (string $status) {
    $order = tgOrder($status);
    Coupon::factory()->create([
        'code' => 'TERM10',
        'discount_type' => 'fixed',
        'discount_value' => 100,
        'min_order_subtotal' => 0,
        'status' => 'draft',
        'times_used' => 0,
        'usage_limit_total' => null,
        'organization_id' => $this->orgId,
    ]);

    $this->withHeaders(tgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/apply-coupon", ['code' => 'TERM10'])
        ->assertOk()
        ->assertJsonPath('data.coupon_id', null);

    expect(Coupon::where('code', 'TERM10')->first()->times_used)->toBe(0);
})->with('terminal statuses');

it('releaseCoupon() is a no-op on a terminal order — keeps the coupon', function () {
    $coupon = Coupon::factory()->create([
        'code' => 'KEEP',
        'discount_type' => 'fixed',
        'discount_value' => 100,
        'min_order_subtotal' => 0,
        'status' => 'draft',
        'times_used' => 1,
        'usage_limit_total' => null,
        'organization_id' => $this->orgId,
    ]);
    $order = tgOrder('closed', [
        'coupon_id' => $coupon->id,
        'coupon_code_snapshot' => 'KEEP',
        'discount_amount' => 100,
    ]);

    $this->withHeaders(tgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/release-coupon")
        ->assertOk()
        ->assertJsonPath('data.coupon_id', $coupon->id);

    expect($order->fresh()->coupon_id)->toBe($coupon->id);
});

it('mergeTable() is a no-op on a terminal order', function () {
    $order = tgOrder('closed');
    $table = Table::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'status' => 'free',
    ]);

    $this->withHeaders(tgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/merge-table", ['table_id' => $table->id])
        ->assertOk();

    expect($order->fresh()->tables()->count())->toBe(0);
    expect($table->fresh()->current_order_id)->toBeNull();
});

it('unmergeTable() is a no-op on a terminal order — keeps the table bound', function () {
    $order = tgOrder('closed');
    $table = Table::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'status' => 'occupied',
        'current_order_id' => $order->id,
    ]);

    $this->withHeaders(tgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/unmerge-table", ['table_id' => $table->id])
        ->assertOk();

    expect($table->fresh()->current_order_id)->toBe($order->id);
});

it('init() is a no-op on a terminal order', function () {
    $order = tgOrder('closed', ['guest_count' => 3]);

    $this->withHeaders(tgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/init", ['guest_count' => 8])
        ->assertOk();

    expect((int) $order->fresh()->guest_count)->toBe(3);
});

it('checkout() does not resurrect a voided or expired order', function (string $status) {
    $order = tgOrder($status);

    $this->withHeaders(tgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/checkout", [])
        ->assertOk()
        ->assertJsonPath('data.status', $status);

    expect($order->fresh()->status->value)->toBe($status);
})->with(['voided', 'expired']);

// ─── Warning is emitted ──────────────────────────────────────────────────────

it('logs a warning when a mutation is skipped on a terminal order', function () {
    Log::spy();
    $order = tgOrder('closed', ['note' => 'original']);

    $this->withHeaders(tgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/update", ['note' => 'x'])
        ->assertOk();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => str_contains($msg, 'skipped mutation on terminal order'))
        ->once();
});

// ─── Regression: mutable states still work (no over-blocking, no false warn) ──

it('still applies update() on a mutable order and does not warn', function (string $status) {
    Log::spy();
    $order = tgOrder($status, ['note' => 'original']);

    $this->withHeaders(tgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/update", ['note' => 'updated'])
        ->assertOk()
        ->assertJsonPath('data.note', 'updated');

    expect($order->fresh()->note)->toBe('updated');
    Log::shouldNotHaveReceived('warning');
})->with(['open', 'dining', 'checkout', 'paying']);

it('checkout() on a closed order stays a silent no-op', function () {
    Log::spy();
    $order = tgOrder('closed');

    $this->withHeaders(tgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/checkout", [])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');

    Log::shouldNotHaveReceived('warning');
});

// ─── Regression: replay sinks must NOT be blocked on a closed order (#825) ────

it('voidItem() still voids a line on a closed order (replay sink not blocked)', function () {
    $order = tgOrder('closed');
    $item = tgLine($order);

    $this->withHeaders(tgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items/{$item->id}/void", [])
        ->assertOk();

    expect($item->fresh()->status->value)->toBe(OrderItemStatusEnum::Voided->value);
});

it('deleteItem() still voids a line on a closed order (replay sink not blocked)', function () {
    $order = tgOrder('closed');
    $item = tgLine($order);

    $this->withHeaders(tgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items/{$item->id}/delete", [])
        ->assertOk();

    expect($item->fresh()->status->value)->toBe(OrderItemStatusEnum::Voided->value);
});
