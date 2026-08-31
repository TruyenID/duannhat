<?php

/**
 * plan-035 — coverage for the two test-gap findings that the existing
 * Plan035PaymentPolicyTest did NOT reach:
 *
 *   1. OrderPaidInvoiceMail — the "paid" invoice mail dispatched from
 *      OrderClosingService::close(). Existing tests only covered the
 *      OrderPlacedMail side; the paid-invoice branch (present-email gate +
 *      idempotency on a re-close) was untested.
 *   2. EffectiveOrderPolicyService cache invalidation + cross-tenant cache
 *      isolation. The saved-hook bust (shop + brand) and the guarantee that
 *      one tenant's invalidation never touches another tenant's cached policy
 *      had no assertions.
 *
 * Verify-first note: the "invalid phone rejected" finding was ALREADY covered
 * by Plan035PaymentPolicyTest (rejects invalid VN number / JP-on-VN mismatch /
 * isValidForCountry matrix), so it is intentionally not re-tested here.
 */

use App\Mail\OrderPaidInvoiceMail;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\OrderClosingService;
use App\Services\Shop\EffectiveOrderPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->organization = Organization::factory()->create([
        'console_organization_id' => '00000000-bbbb-4bbb-bbbb-000000000035',
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
        'locale' => 'vi-VN',
        'currency' => 'JPY',
    ]);
    $this->sku = ProductSku::factory()->create();
});

/**
 * Minimal, already-paid takeaway order that OrderClosingService::close() will
 * accept (takeaway skips the all-items-served precondition; a non-track_stock
 * SKU skips the stock-out branch; no table means no table release).
 */
function makeClosableTakeawayOrder(?string $email): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-INV-'.Str::random(5),
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Open->value,
        'opened_at' => now(),
        'subtotal' => 1500,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 1500,
        'paid_amount' => 1500, // isPaidEnough → true
        'total_tip' => 0,
        'created_by_id' => null,
        'customer_id' => null,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->organization->id,
        'customer_takeaway_email' => $email,
    ]);

    $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 1,
        'unit_price' => 1500,
        'original_unit_price' => 1500,
        'subtotal' => 1500,
        'status' => 'served',
        'served_at' => now(),
        'tax_rate' => 0,
    ]);

    return $order->load('items');
}

// =========================================================================
//  OrderPaidInvoiceMail
// =========================================================================

it('queues OrderPaidInvoiceMail to the customer email when closing a paid order', function () {
    Mail::fake();

    $order = makeClosableTakeawayOrder('invoice@example.com');

    app(OrderClosingService::class)->close($order);

    expect($order->fresh()->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);

    Mail::assertQueued(
        OrderPaidInvoiceMail::class,
        fn (OrderPaidInvoiceMail $mail) => $mail->hasTo('invoice@example.com')
            && $mail->order->order_code === $order->order_code,
    );
    Mail::assertQueuedCount(1);
});

it('does NOT queue OrderPaidInvoiceMail when the order has no customer email', function () {
    Mail::fake();

    $order = makeClosableTakeawayOrder(null);

    app(OrderClosingService::class)->close($order);

    expect($order->fresh()->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);
    Mail::assertNotQueued(OrderPaidInvoiceMail::class);
});

it('does not re-queue the invoice mail when close() runs again on an already-closed order (idempotent)', function () {
    Mail::fake();

    $order = makeClosableTakeawayOrder('invoice@example.com');

    // First close: one invoice mail.
    app(OrderClosingService::class)->close($order);
    Mail::assertQueuedCount(1);

    // Second close on the now-closed order returns early (idempotent guard)
    // → must NOT dispatch a duplicate invoice to the customer.
    app(OrderClosingService::class)->close($order->fresh());

    Mail::assertQueuedCount(1);
});

// =========================================================================
//  EffectiveOrderPolicyService — cache invalidation on save
// =========================================================================

it('busts the branch policy cache when a ShopOrderSetting is saved', function () {
    $service = app(EffectiveOrderPolicyService::class);

    // Prime the cache with the resolved default (no shop/brand setting → true).
    expect($service->resolve($this->branch->fresh())['prep_before_payment'])->toBeTrue();

    // Saving a shop override fires the model saved hook → forgetForBranch.
    ShopOrderSetting::create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->organization->id,
        'prep_before_payment' => false,
    ]);

    // Fresh resolve reflects the override — proves the stale `true` was evicted.
    expect($service->resolve($this->branch->fresh())['prep_before_payment'])->toBeFalse();
});

it('busts the branch policy cache when the BrandOrderPolicy is saved (brand fan-out)', function () {
    $service = app(EffectiveOrderPolicyService::class);

    expect($service->resolve($this->branch->fresh())['prep_before_payment'])->toBeTrue();

    // Brand-level save must reach every branch of the brand via forgetForBrand.
    BrandOrderPolicy::create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
        'default_prep_before_payment' => false,
    ]);

    expect($service->resolve($this->branch->fresh())['prep_before_payment'])->toBeFalse();
});

// =========================================================================
//  Cross-tenant cache isolation
// =========================================================================

it('keeps each tenant policy on its own cache key and never leaks across branches', function () {
    $service = app(EffectiveOrderPolicyService::class);

    // Second, fully independent tenant with a JP branch.
    $orgB = Organization::factory()->create([
        'console_organization_id' => '00000000-cccc-4ccc-cccc-000000000035',
    ]);
    $brandB = Brand::factory()->create([
        'console_organization_id' => $orgB->console_organization_id,
    ]);
    $branchB = Branch::factory()->create([
        'console_organization_id' => $orgB->console_organization_id,
        'console_brand_id' => $brandB->console_brand_id,
        'is_active' => true,
        'locale' => 'ja-JP',
        'currency' => 'JPY',
    ]);

    // Prime BOTH caches. Distinct locales prove the keys don't collide.
    $a = $service->resolve($this->branch->fresh());
    $b = $service->resolve($branchB->fresh());
    expect($a['phone_country'])->toBe('VN');
    expect($b['phone_country'])->toBe('JP');
    expect($a['prep_before_payment'])->toBeTrue();
    expect($b['prep_before_payment'])->toBeTrue();

    // Mutate branch B's brand policy directly via the query builder so NO model
    // event fires — the only thing that can evict B's cache in this test is an
    // explicit forget. This lets us prove tenant A's invalidation leaves B's
    // cached value untouched.
    DB::table('brand_order_policies')->insert([
        'id' => (string) Str::uuid(),
        'brand_id' => $brandB->id,
        'organization_id' => $orgB->id,
        'default_prep_before_payment' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Tenant A saves its brand policy → forgetForBrand(brandA) only.
    BrandOrderPolicy::create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
        'default_prep_before_payment' => false,
    ]);

    // A was evicted → recomputes to the new false.
    expect($service->resolve($this->branch->fresh())['prep_before_payment'])->toBeFalse();

    // B was NOT touched by A's invalidation → still serves its primed `true`
    // despite the raw DB row now saying false. Proves cross-tenant isolation.
    expect($service->resolve($branchB->fresh())['prep_before_payment'])->toBeTrue();

    // And forgetting B's brand finally surfaces the raw DB value.
    EffectiveOrderPolicyService::forgetForBrand($brandB->id);
    expect($service->resolve($branchB->fresh())['prep_before_payment'])->toBeFalse();
});
