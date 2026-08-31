<?php

require_once __DIR__.'/vpr_helpers.php';

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\OrderPricingCalculator;

/*
 * M14 — Coupon apply must re-price through OrderMutationFacade::refreshPricing so
 * tax_amount reflects the post-discount taxable base (plan-047 T4.11 boundary).
 */

it('M14: HTTP apply-coupon persists engine totals on a 1000 @10% order + fixed-200 coupon', function () {
    $t = vprTenant('USD');
    $user = vprActor($t);
    ShopOrderSetting::where('branch_id', $t['branch']->id)->first();

    $order = vprOrder($t, 0.0);
    $order->update(['status' => CustomerOrderStatusEnum::Open->value]);
    vprItem($order, 1000.00, 1, 10.0);

    test()->actingAs($user)
        ->withHeader('X-Shop-Slug', $t['branch']->slug)
        ->postJson("/api/v1/pos/orders/{$order->id}/checkout", [])
        ->assertOk();
    $order->refresh();

    expect((float) $order->subtotal)->toBe(1000.00);
    expect((float) $order->tax_amount)->toBe(100.00);
    expect((float) $order->total_amount)->toBe(1100.00);

    $coupon = Coupon::factory()->create([
        'organization_id' => $t['org_id'],
        'brand_id' => $t['brand']->id,
        'code' => 'VPRFIX200',
        'discount_type' => 'fixed',
        'discount_value' => 200.0,
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

    $res = test()->actingAs($user)
        ->withHeader('X-Shop-Slug', $t['branch']->slug)
        ->postJson("/api/v1/pos/orders/{$order->id}/apply-coupon", [
            'code' => 'VPRFIX200',
            'customer_id' => $customer->id,
        ]);

    $res->assertOk();
    $order->refresh()->load('items');

    $engine = app(OrderPricingCalculator::class)->priceGroups(
        ['10' => 1000.0], 200.0, 0.0, 0.0, false, 0.01
    );

    expect($engine->taxAmount)->toBe(80.0);
    expect($engine->totalAmount)->toBe(880.0);
    expect((float) $order->tax_amount)->toBe(80.00);
    expect((float) $order->total_amount)->toBe(880.00);
    expect((float) $res->json('data.total_amount'))->toBe(880.00);
})->group('m14');

it('M14b: POS payment closes the bill at the re-priced total after coupon apply', function () {
    $t = vprTenant('USD');
    $user = vprActor($t);
    vprOpenShift($t);

    $order = vprOrder($t, 0.0);
    $order->update(['status' => CustomerOrderStatusEnum::Open->value]);
    vprItem($order, 1000.00, 1, 10.0);

    test()->actingAs($user)->withHeader('X-Shop-Slug', $t['branch']->slug)
        ->postJson("/api/v1/pos/orders/{$order->id}/checkout", [])->assertOk();

    $coupon = Coupon::factory()->create([
        'organization_id' => $t['org_id'],
        'brand_id' => $t['brand']->id,
        'code' => 'VPRFIX200B',
        'discount_type' => 'fixed',
        'discount_value' => 200.0,
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

    test()->actingAs($user)->withHeader('X-Shop-Slug', $t['branch']->slug)
        ->postJson("/api/v1/pos/orders/{$order->id}/apply-coupon", ['code' => 'VPRFIX200B', 'customer_id' => $customer->id])
        ->assertOk();
    $afterCoupon = (float) $order->refresh()->total_amount;

    $card = PaymentMethod::factory()->cardTerminal()->create(['organization_id' => $t['org_id']]);
    $pay = test()->actingAs($user)->withHeader('X-Shop-Slug', $t['branch']->slug)
        ->postJson("/api/v1/pos/orders/{$order->id}/payments", [
            'payment_method_id' => $card->id,
            'amount' => 880.00,
        ]);
    $pay->assertStatus(201);
    $order->refresh();

    expect($afterCoupon)->toBe(880.00);
    expect($pay->status())->toBe(201);
    expect((float) $order->paid_amount)->toBe(880.00);
    expect($order->status instanceof BackedEnum ? $order->status->value : $order->status)->toBe('closed');
})->group('m14');
