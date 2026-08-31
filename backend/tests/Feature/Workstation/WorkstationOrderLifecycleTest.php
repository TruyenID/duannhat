<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Table;
use App\Models\Zone;
use App\Omnify\Enums\TableStatusEnum;
use Illuminate\Support\Str;

/**
 * Workstation-flow order lifecycle endpoints — LAN-offline sync UP path.
 *
 * Covers the contract the workstation sync_queue worker relies on:
 *   - Idempotent updates (a retry doesn't double-apply)
 *   - Coupon apply / release with discount math
 *   - Void / checkout / delete state transitions
 *
 * Auth: device.auth:workstation. Cashier-shift tests already exercise the
 * device token pattern; we reuse the same factory.
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

    $this->wsToken = Str::random(64);
    $this->wsDevice = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

function mkWsOrder(int $subtotal = 1000, string $status = 'open'): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'WS-'.Str::random(4),
        'order_type' => 'spot',
        'status' => $status,
        'subtotal' => $subtotal,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => $subtotal,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

function wsHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->wsToken];
}

it('updates header fields idempotently', function () {
    $order = mkWsOrder();

    $first = $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/update", [
            'guest_count' => 4,
            'note' => 'Birthday party',
        ])
        ->assertOk();

    expect($first->json('data.guest_count'))->toBe(4);
    expect($first->json('data.note'))->toBe('Birthday party');

    // Retry — same payload, expect identical state.
    $second = $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/update", [
            'guest_count' => 4,
            'note' => 'Birthday party',
        ])
        ->assertOk();

    expect($second->json('data.guest_count'))->toBe(4);
    expect($second->json('data.note'))->toBe('Birthday party');
    expect(CustomerOrder::where('id', $order->id)->count())->toBe(1); // no dupes
});

it('voids the order idempotently', function () {
    $order = mkWsOrder();

    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/void", [
            'void_reason' => 'wrong table',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided')
        ->assertJsonPath('data.void_reason', 'wrong table');

    // Second call with no reason should NOT overwrite the first reason.
    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/void", [])
        ->assertOk()
        ->assertJsonPath('data.void_reason', 'wrong table');
});

it('flips to checkout state', function () {
    $order = mkWsOrder();

    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/checkout", [
            'discount_amount' => 100,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'checkout')
        ->assertJsonPath('data.discount_amount', '100.00');

    expect($order->conditions()->where('type', 'discount')->value('amount'))->toEqual(-100);
});

// -------------------------------------------------------------------------
//  Confirm (accept) replay — pending|confirmed → open. Idempotent by
//  status: any other status is a 200 no-op so a queue retry arriving after
//  later transitions drains instead of dead-lettering.
// -------------------------------------------------------------------------

it('confirm flips a confirmed order to open', function () {
    $order = mkWsOrder(status: 'confirmed');

    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/confirm", [])
        ->assertOk()
        ->assertJsonPath('data.status', 'open');
});

it('confirm flips a pending order to open', function () {
    $order = mkWsOrder(status: 'pending');

    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/confirm", [])
        ->assertOk()
        ->assertJsonPath('data.status', 'open');
});

it('confirm is a 200 no-op on an already-open order (replay-safe)', function () {
    $order = mkWsOrder(); // open

    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/confirm", [])
        ->assertOk()
        ->assertJsonPath('data.status', 'open');
});

it('confirm is a 200 no-op on a voided order (never dead-letters the queue)', function () {
    $order = mkWsOrder(status: 'voided');

    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/confirm", [])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided');
});

it('rejects when order belongs to another branch', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $foreign = CustomerOrder::create([
        'order_code' => 'OTHER-1',
        'order_type' => 'spot',
        'status' => 'open',
        'subtotal' => 100,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 100,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $otherBranch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$foreign->id}/update", [
            'note' => 'should fail',
        ])
        ->assertNotFound();
});

it('applies a flat coupon and computes discount', function () {
    $order = mkWsOrder(1000);

    Coupon::factory()->create([
        'code' => 'TEST10',
        'discount_type' => 'fixed',
        'discount_value' => 200,
        'min_order_subtotal' => 0,
        'status' => 'draft',
        'times_used' => 0,
        'usage_limit_total' => null,
        'organization_id' => $this->orgId,
    ]);

    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/apply-coupon", [
            'code' => 'TEST10',
        ])
        ->assertOk()
        ->assertJsonPath('data.discount_amount', '200.00')
        ->assertJsonPath('data.coupon_code_snapshot', 'TEST10');

    expect($order->conditions()->where('type', 'discount')->value('amount'))->toEqual(-200);
    expect(Coupon::where('code', 'TEST10')->first()->times_used)->toBe(1);
});

it('rejects coupon when cap reached', function () {
    $order = mkWsOrder();

    Coupon::factory()->create([
        'code' => 'CAP',
        'discount_type' => 'fixed',
        'discount_value' => 100,
        'min_order_subtotal' => 0,
        'status' => 'draft',
        'times_used' => 5,
        'usage_limit_total' => 5,
        'organization_id' => $this->orgId,
    ]);

    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/apply-coupon", [
            'code' => 'CAP',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'COUPON_USAGE_EXCEEDED');
});

it('applying the same coupon twice is a no-op', function () {
    $order = mkWsOrder();
    Coupon::factory()->create([
        'code' => 'IDEMP',
        'discount_type' => 'fixed',
        'discount_value' => 50,
        'min_order_subtotal' => 0,
        'status' => 'draft',
        'times_used' => 0,
        'usage_limit_total' => null,
        'organization_id' => $this->orgId,
    ]);

    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/apply-coupon", ['code' => 'IDEMP'])
        ->assertOk();
    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/apply-coupon", ['code' => 'IDEMP'])
        ->assertOk();

    // times_used should be 1, NOT 2.
    expect(Coupon::where('code', 'IDEMP')->first()->times_used)->toBe(1);
});

it('releases an applied coupon', function () {
    $order = mkWsOrder();
    Coupon::factory()->create([
        'code' => 'RELEASE',
        'discount_type' => 'fixed',
        'discount_value' => 100,
        'min_order_subtotal' => 0,
        'status' => 'draft',
        'times_used' => 0,
        'usage_limit_total' => null,
        'organization_id' => $this->orgId,
    ]);

    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/apply-coupon", ['code' => 'RELEASE'])
        ->assertOk();

    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/release-coupon")
        ->assertOk()
        ->assertJsonPath('data.coupon_id', null)
        ->assertJsonPath('data.discount_amount', '0.00');
});

/**
 * #1285 — the LAN void must free the table, like the shop void does.
 *
 * `tables.current_order_id` is nulled in exactly four places — delete(),
 * continueTableOrder(), releaseOrderTables() and unmergeTable() — none of which
 * ran on this path. The workstation's own table sync-UP does not help either:
 * TableStatusService writes `status` and leaves the pointer alone. So the table
 * kept pointing at a voided order forever, and a later merge onto it aborted
 * with 409 "Table is already occupied by another order" — about an order that
 * had been cancelled.
 */
it('#1285 frees the table when the LAN void cancels the order', function () {
    $order = mkWsOrder();

    $zone = Zone::factory()->create([
        'branch_id' => test()->branch->id,
        'organization_id' => test()->orgId,
    ]);
    $table = Table::factory()->create([
        'branch_id' => test()->branch->id,
        'organization_id' => test()->orgId,
        'zone_id' => $zone->id,
        'is_active' => true,
        'status' => TableStatusEnum::Occupied->value,
        'current_order_id' => $order->id,
    ]);

    test()->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/void", ['void_reason' => 'wrong table'])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided');

    $fresh = $table->fresh();

    expect($fresh->current_order_id)->toBeNull()
        ->and($fresh->status instanceof BackedEnum ? $fresh->status->value : $fresh->status)
        ->toBe(TableStatusEnum::Free->value);
});

/**
 * #1286 — the LAN soft-delete must clean up like the shop delete() does.
 *
 * It used to be a bare `$order->delete()`, so the table kept pointing at a
 * deleted order (the #1285 trap: a later merge 409s "Table is already occupied
 * by another order") and the guest's coupon stayed consumed for an order that
 * no longer exists (the #1276 trap).
 */
it('#1286 frees the table when the LAN soft-delete removes the order', function () {
    $order = mkWsOrder();

    $zone = Zone::factory()->create([
        'branch_id' => test()->branch->id,
        'organization_id' => test()->orgId,
    ]);
    $table = Table::factory()->create([
        'branch_id' => test()->branch->id,
        'organization_id' => test()->orgId,
        'zone_id' => $zone->id,
        'is_active' => true,
        'status' => TableStatusEnum::Occupied->value,
        'current_order_id' => $order->id,
    ]);

    test()->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/delete")
        ->assertNoContent();

    expect($order->fresh()?->trashed() ?? CustomerOrder::withTrashed()->find($order->id)->trashed())
        ->toBeTrue();

    $fresh = $table->fresh();
    expect($fresh->current_order_id)->toBeNull()
        ->and($fresh->status instanceof BackedEnum ? $fresh->status->value : $fresh->status)
        ->toBe(TableStatusEnum::Free->value);
});

/**
 * #1287 — a discount synced at checkout must move the total.
 *
 * This was the only workstation op that wrote money without recomputing the
 * totals it invalidates: `discount_amount` landed, `total_amount` kept its
 * pre-discount value. settleOrderIfPaid measures `total_amount - paid_amount`,
 * so the guest who paid the discounted price still read as owing the
 * difference, and Cloud's revenue was overstated by exactly the discount.
 *
 * The Go side really does send it — sync_service.go `handleOrderCheckout`
 * posts `{"discount_amount": ...}`.
 */
it('#1287 recomputes the total when checkout carries a discount', function () {
    $order = mkWsOrder(1000);
    // applyPricing recomputes the subtotal from the order's LINES, so the order
    // needs a real one — an item-less fixture would recompute to zero and prove
    // nothing about the discount.
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 1000,
        'subtotal' => 1000,
        'status' => 'pending',
    ]);

    test()->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/checkout", ['discount_amount' => 200])
        ->assertOk();

    $fresh = $order->fresh();

    expect((float) $fresh->discount_amount)->toBe(200.0)
        ->and((float) $fresh->total_amount)->toBe(800.0);
});

it('#1287 a checkout with no discount leaves the total alone', function () {
    $order = mkWsOrder(1000);

    test()->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/checkout")
        ->assertOk();

    $fresh = $order->fresh();

    expect((float) $fresh->total_amount)->toBe(1000.0)
        ->and($fresh->status instanceof BackedEnum ? $fresh->status->value : $fresh->status)
        ->toBe('checkout');
});

/**
 * #1288 — releasing a coupon from the workstation must give the use back.
 *
 * This path used to null the order's coupon columns by hand — a copy of
 * clearCouponFromOrder, the innermost step — skipping the half that concerns
 * the coupon: decrement `times_used` and stamp `released_at` on the redemption.
 * The order forgot the coupon while the coupon did not forget the order, so the
 * guest lost a use permanently and no sweep could find it.
 *
 * The shop twin (DELETE /pos/orders/{order}/coupon) always went through
 * CouponService::release, which does all three.
 */
it('#1288 returns the coupon use when the workstation releases it', function () {
    $order = mkWsOrder(1000);

    Coupon::factory()->create([
        'code' => 'REL10',
        'discount_type' => 'fixed',
        'discount_value' => 200,
        'min_order_subtotal' => 0,
        'status' => 'draft',
        'times_used' => 0,
        'usage_limit_total' => null,
        'organization_id' => $this->orgId,
    ]);

    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/apply-coupon", ['code' => 'REL10'])
        ->assertOk();

    expect(Coupon::where('code', 'REL10')->first()->times_used)->toBe(1);

    $this->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/release-coupon")
        ->assertOk();

    expect(Coupon::where('code', 'REL10')->first()->times_used)->toBe(0)
        ->and($order->fresh()->coupon_id)->toBeNull();

    // released_at is what makes a stranded redemption findable later — the
    // binding being cleared is not enough on its own.
    $redemption = CouponRedemption::where('customer_order_id', $order->id)->first();
    expect($redemption?->released_at)->not->toBeNull();
});

/**
 * #1294 — a late replay may void the line, but it must not restate a POSTED
 * total.
 *
 * The op still has to land: #825 pins that as a regression, because a blocked
 * replay leaves the workstation's sync queue retrying forever. What changes is
 * the money. A closed sale used to end up reading paid 1000 against a total of
 * 0, with no refund and no 適格返還請求書 to account for the gap.
 *
 * Both rules that govern this agree. 電子帳簿保存法 forbids altering a recorded
 * transaction without a retained 訂正・削除の履歴; the 適格請求書 regime corrects a
 * billed amount by issuing a return invoice, not by editing the original down.
 * So: void the line, leave the figure, write the history.
 */
it('#1294 voids the line on a settled order but leaves the posted total alone', function () {
    $order = mkWsOrder(1000);
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 1000,
        'subtotal' => 1000,
        'status' => 'pending',
    ]);
    $order->forceFill([
        'status' => 'closed',
        'closed_at' => now(),
        'paid_amount' => 1000,
        'total_amount' => 1000,
    ])->save();

    test()->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'late replay',
        ])
        ->assertOk();

    $fresh = $order->fresh();
    $itemStatus = $item->fresh()->status;

    // #825 — the replay lands, so the queue drains.
    expect($itemStatus instanceof BackedEnum ? $itemStatus->value : $itemStatus)->toBe('voided')
        // …and the posted figures are untouched.
        ->and((float) $fresh->total_amount)->toBe(1000.0)
        ->and((float) $fresh->paid_amount)->toBe(1000.0);
});

it('#1294 still restates the total when the order is NOT settled', function () {
    $order = mkWsOrder(1000);
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 1000,
        'subtotal' => 1000,
        'status' => 'pending',
    ]);

    test()->withHeaders(wsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'cashier removed it',
        ])
        ->assertOk();

    // An open order is not a posted record — voiding a line must still reprice.
    expect((float) $order->fresh()->total_amount)->toBe(0.0);
});
