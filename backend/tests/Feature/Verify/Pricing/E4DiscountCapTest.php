<?php

require_once __DIR__.'/vpr_helpers.php';

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\ShopOrderSetting;
use App\Models\User;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Order\Coupon\OrderCouponService;
use App\Services\Promotion\CouponService;
use Database\Seeders\IamSeeder;
use Tests\TestCase;

/*
 * E4 — manual `discount_amount` at checkout.
 *
 * FLOOR PINNED 2026-07-27 (#1124): the domain checkout writer now rejects a
 * discount above the live items subtotal with 422 (E4b/E4d flipped from
 * document-gap). A discount EQUAL to the subtotal (full comp) stays legal
 * (E4a). The remaining governance layer — percent cap, manager approval,
 * dedicated column split — stays open in #1124 pending product decisions.
 *
 * Driven through the REAL HTTP route:
 *   POST /api/v1/pos/orders/{customerOrder}/checkout   (SSO auth + X-Shop-Slug)
 *
 * NOT re-tested here (a previous pass REFUTED them): authorization, audit log.
 */

function vprPosActor(array $t): User
{
    $user = User::factory()->create(['console_organization_id' => $t['org_id']]);
    grantOrgAccess($user, $t['org_id']);

    return $user;
}

function vprCheckout(array $t, User $user, string $orderId, array $payload)
{
    /** @var TestCase $test */
    $test = test();

    return $test->actingAs($user)
        ->withHeader('X-Shop-Slug', $t['branch']->slug)
        ->postJson("/api/v1/pos/orders/{$orderId}/checkout", $payload);
}

it('E4a: discount_amount = the FULL subtotal → order checks out at total_amount = 0', function () {
    $t = vprTenant('USD');
    $user = vprPosActor($t);

    $order = vprOrder($t, 0.0);
    $order->update(['status' => CustomerOrderStatusEnum::Open->value]);
    vprItem($order, 100.00, 1, 10.0); // 100.00 @ 10% tax

    $res = vprCheckout($t, $user, $order->id, [
        'discount_amount' => 100.00,
        'discount_reason' => 'full comp — spilled drinks on the guest',
    ]);
    $res->assertOk();
    $order->refresh();

    dump([
        'HTTP status' => $res->status(),
        'items subtotal' => 100.00,
        'discount_amount SENT' => 100.00,
        '--- order.subtotal' => (float) $order->subtotal,
        '--- order.discount_amount PERSISTED' => (float) $order->discount_amount,
        '--- order.tax_amount' => (float) $order->tax_amount,
        '--- order.total_amount' => (float) $order->total_amount,
        '--- order.status' => $order->status instanceof BackedEnum ? $order->status->value : $order->status,
    ]);

    expect((float) $order->total_amount)->toBe(0.0);
    expect((float) $order->discount_amount)->toBe(100.00);
    // #1124 — the manual entry is recorded in its own columns.
    expect((float) $order->manual_discount_amount)->toBe(100.00);
    expect($order->manual_discount_reason)->toBe('full comp — spilled drinks on the guest');
})->group('e4');

it('E4b: discount_amount ABOVE the subtotal is rejected 422 and nothing persists', function () {
    $t = vprTenant('USD');
    $user = vprPosActor($t);

    $order = vprOrder($t, 0.0);
    $order->update(['status' => CustomerOrderStatusEnum::Open->value]);
    vprItem($order, 100.00, 1, 10.0);

    $res = vprCheckout($t, $user, $order->id, [
        'discount_amount' => 999999.99,
        'discount_reason' => 'trying to exceed the subtotal',
    ]);
    $order->refresh();

    $res->assertStatus(422);
    expect((float) $order->discount_amount)->toBe(0.0)
        ->and($order->status->value)->toBe(CustomerOrderStatusEnum::Open->value);

    // Just above the subtotal is rejected too — the cap is the subtotal itself.
    vprCheckout($t, $user, $order->id, [
        'discount_amount' => 100.01,
        'discount_reason' => 'one cent too far',
    ])->assertStatus(422);
})->group('e4');

it('E4c: apply a coupon, then checkout with discount_amount = 0 — is the coupon wiped?', function () {
    $t = vprTenant('USD');
    $user = vprPosActor($t);

    $order = vprOrder($t, 100.00, subtotal: 100.00);
    $order->update(['status' => CustomerOrderStatusEnum::Open->value]);
    vprItem($order, 100.00, 1, 10.0);

    $coupon = Coupon::factory()->create([
        'organization_id' => $t['org_id'],
        'brand_id' => $t['brand']->id,
        'code' => 'VPR20',
        'discount_type' => 'percent',
        'discount_value' => 20.0,
        'max_discount_cap' => null,
        'min_order_subtotal' => 0,
        'status' => 'draft',
        'times_used' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 100,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDay(),
    ]);

    // per-customer limit is NOT NULL in the schema → apply() demands a customer.
    $customer = Customer::factory()->create(['organization_id' => $t['org_id']]);

    // Apply the coupon through the REAL CouponService.
    app(OrderCouponService::class)->apply($order, 'VPR20', $customer->id, 'pos', $user);
    $order->refresh();
    $coupon->refresh();

    $afterApply = [
        'order.discount_amount' => (float) $order->discount_amount,
        'order.coupon_id set' => $order->coupon_id !== null,
        'coupon.times_used' => (int) $coupon->times_used,
        'active redemptions' => CouponRedemption::where('coupon_id', $coupon->id)->whereNull('released_at')->count(),
    ];

    // Now the cashier checks out sending discount_amount = 0.
    $res = vprCheckout($t, $user, $order->id, ['discount_amount' => 0]);
    $res->assertOk();
    $order->refresh();
    $coupon->refresh();

    $after = [
        'order.subtotal' => (float) $order->subtotal,
        '>>> order.discount_amount' => (float) $order->discount_amount,
        '>>> order.total_amount' => (float) $order->total_amount,
        'order.coupon_id still set' => $order->coupon_id !== null,
        'coupon.times_used' => (int) $coupon->times_used,
        'active redemptions' => CouponRedemption::where('coupon_id', $coupon->id)->whereNull('released_at')->count(),
    ];

    dump([
        'AFTER coupon apply' => $afterApply,
        'AFTER checkout with discount_amount = 0' => $after,
        'VERDICT' => (float) $order->discount_amount > 0
            ? 'coupon SURVIVED — applyPricing re-derives it from the coupon (#550)'
            : 'coupon WIPED — customer pays full price, redemption still burned',
    ]);

    // MEASURED: the coupon SURVIVES. CustomerOrderService::applyPricing()
    // (CustomerOrderService.php:2308) calls
    //   CouponService::recomputeDiscountForOrder($order, $liveSubtotal)  (#550)
    // which returns non-null for any coupon-carrying order and OVERWRITES the
    // manual discount_amount the checkout request set. Sub-claim (b) REFUTED.
    expect((float) $order->discount_amount)->toBe(20.00);
    expect((float) $order->total_amount)->toBe(88.00); // 100 - 20 + 8 tax
    expect($order->coupon_id)->not->toBeNull();
})->group('e4');

it('E4d: on a COUPON order a huge manual discount is now rejected 422 outright (#1124 floor)', function () {
    $t = vprTenant('USD');
    $user = vprPosActor($t);

    $order = vprOrder($t, 100.00, subtotal: 100.00);
    $order->update(['status' => CustomerOrderStatusEnum::Open->value]);
    vprItem($order, 100.00, 1, 10.0);

    $coupon = Coupon::factory()->create([
        'organization_id' => $t['org_id'],
        'brand_id' => $t['brand']->id,
        'code' => 'VPR20D',
        'discount_type' => 'percent',
        'discount_value' => 20.0,
        'max_discount_cap' => null,
        'min_order_subtotal' => 0,
        'status' => 'draft',
        'times_used' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 100,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDay(),
    ]);
    $customer = Customer::factory()->create(['organization_id' => $t['org_id']]);
    app(OrderCouponService::class)->apply($order, 'VPR20D', $customer->id, 'pos', $user);

    // The floor fires before any coupon logic — an over-subtotal manual
    // discount is a bad request no matter what the coupon would recompute.
    vprCheckout($t, $user, $order->id, [
        'discount_amount' => 999999.99,
        'discount_reason' => 'floor fires before coupon logic',
    ])->assertStatus(422);

    // A sane checkout still lets the coupon recompute win over the manual
    // value (the E4c behaviour, unchanged).
    vprCheckout($t, $user, $order->id, ['discount_amount' => 0])->assertOk();
    $order->refresh();
    expect((float) $order->discount_amount)->toBe(20.00);
})->group('e4');

it('E4e: a manual discount without a reason is rejected 422 (#1124 decision 2)', function () {
    $t = vprTenant('USD');
    $user = vprPosActor($t);

    $order = vprOrder($t, 0.0);
    $order->update(['status' => CustomerOrderStatusEnum::Open->value]);
    vprItem($order, 100.00, 1, 10.0);

    vprCheckout($t, $user, $order->id, ['discount_amount' => 10.00])->assertStatus(422);
    // Reason present → passes (org-admin actor = manager tier).
    vprCheckout($t, $user, $order->id, [
        'discount_amount' => 10.00,
        'discount_reason' => 'regular customer',
    ])->assertOk();
})->group('e4');

it('E4f: a cashier is capped at manual_discount_max_percent of subtotal; a manager is not (#1124 decision 1)', function () {
    $t = vprTenant('USD');
    if (! Permission::query()->exists()) {
        (new IamSeeder)->run();
    }

    // Cashier: branch-scoped shop-staff (clears shop middleware, not a manager).
    $cashier = User::factory()->create(['console_organization_id' => $t['org_id']]);
    $cashier->assignRole('shop-staff', $t['org_id'], $t['branch']->id);

    $order = vprOrder($t, 0.0);
    $order->update(['status' => CustomerOrderStatusEnum::Open->value]);
    vprItem($order, 100.00, 1, 10.0);

    // Default cap 20% of $100 = $20: 20.00 passes, 20.01 is refused.
    vprCheckout($t, $cashier, $order->id, [
        'discount_amount' => 20.01,
        'discount_reason' => 'too generous for a cashier',
    ])->assertStatus(422);
    vprCheckout($t, $cashier, $order->id, [
        'discount_amount' => 20.00,
        'discount_reason' => 'at the cap exactly',
    ])->assertOk();

    // A branch-scoped shop-manager may comp beyond the cashier cap.
    $manager = User::factory()->create(['console_organization_id' => $t['org_id']]);
    $manager->assignRole('shop-manager', $t['org_id'], $t['branch']->id);
    $order2 = vprOrder($t, 0.0);
    $order2->update(['status' => CustomerOrderStatusEnum::Open->value]);
    vprItem($order2, 100.00, 1, 10.0);
    vprCheckout($t, $manager, $order2->id, [
        'discount_amount' => 75.00,
        'discount_reason' => 'manager comp',
    ])->assertOk();
})->group('e4');

it('E4g: the cashier cap honours the per-shop manual_discount_max_percent setting', function () {
    $t = vprTenant('USD');
    if (! Permission::query()->exists()) {
        (new IamSeeder)->run();
    }
    ShopOrderSetting::query()
        ->where('branch_id', $t['branch']->id)
        ->update(['manual_discount_max_percent' => 50]);

    $cashier = User::factory()->create(['console_organization_id' => $t['org_id']]);
    $cashier->assignRole('shop-staff', $t['org_id'], $t['branch']->id);

    $order = vprOrder($t, 0.0);
    $order->update(['status' => CustomerOrderStatusEnum::Open->value]);
    vprItem($order, 100.00, 1, 10.0);

    vprCheckout($t, $cashier, $order->id, [
        'discount_amount' => 45.00,
        'discount_reason' => 'half-off promo the shop allows',
    ])->assertOk();
})->group('e4');

it('E4h: pos-web-style ECHO of the current discount is a no-op — no reason required (#1124)', function () {
    $t = vprTenant('USD');
    $user = vprPosActor($t);

    $order = vprOrder($t, 100.00, subtotal: 100.00);
    $order->update(['status' => CustomerOrderStatusEnum::Open->value]);
    vprItem($order, 100.00, 1, 10.0);

    $coupon = Coupon::factory()->create([
        'organization_id' => $t['org_id'],
        'brand_id' => $t['brand']->id,
        'code' => 'VPRECHO',
        'discount_type' => 'percent',
        'discount_value' => 20.0,
        'max_discount_cap' => null,
        'min_order_subtotal' => 0,
        'status' => 'draft',
        'times_used' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 100,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDay(),
    ]);
    $customer = Customer::factory()->create(['organization_id' => $t['org_id']]);
    app(OrderCouponService::class)->apply($order, 'VPRECHO', $customer->id, 'pos', $user);
    $order->refresh();

    // pos-web forwards the server-computed order.discount_amount verbatim
    // (plan-019 draft) — a coupon order checkout must not demand a "reason".
    vprCheckout($t, $user, $order->id, ['discount_amount' => (float) $order->discount_amount])
        ->assertOk();
    $order->refresh();
    expect((float) $order->discount_amount)->toBe(20.00)
        ->and((float) $order->manual_discount_amount)->toBe(0.0);
})->group('e4');
