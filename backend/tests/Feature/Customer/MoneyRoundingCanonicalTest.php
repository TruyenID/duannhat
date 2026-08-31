<?php

/**
 * #524 — Unified money/tax rounding.
 *
 * Before this fix the backend contradicted itself: OrderPricingCalculator
 * (checkout / kiosk read / split preview) rounded tax + service HALF-UP, while
 * CustomerOrderService::recalculateTotals — the path that actually persists on
 * every item add/void/update — rounded tax + service UP (ceil) AND then ceil'd
 * the total again. That double-ceil systematically over-collected tax and made
 * the printed receipt disagree with the persisted record for the same order.
 *
 * The canonical rule is now: round HALF-UP to the currency's minor unit
 * (RoundingMode::roundHalfUpToStep, driven by ShopOrderSetting.currency_code),
 * applied in the single OrderPricingCalculator. recalculateTotals delegates to
 * it, so checkout() and every live recompute produce identical figures.
 *
 * These tests pin the rule so a future edit can't silently reintroduce ceil.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\OrderPricingCalculator;
use App\Support\RoundingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// -----------------------------------------------------------------------------
//  RoundingMode::roundHalfUpToStep — the primitive
// -----------------------------------------------------------------------------

it('rounds half-up to an integer step (not ceil)', function () {
    // The issue's worked example: taxable 1230 @ 8% = 98.4.
    // Half-up → 98 ; the old ceil path produced 99.
    expect(RoundingMode::roundHalfUpToStep(98.4, 1.0))->toBe(98.0);
    expect(RoundingMode::roundHalfUpToStep(98.5, 1.0))->toBe(99.0);
    expect(RoundingMode::roundHalfUpToStep(98.6, 1.0))->toBe(99.0);
    expect(RoundingMode::roundHalfUpToStep(98.0, 1.0))->toBe(98.0);
});

it('rounds half-up to a two-decimal step', function () {
    expect(RoundingMode::roundHalfUpToStep(1.234, 0.01))->toEqualWithDelta(1.23, 1e-9);
    expect(RoundingMode::roundHalfUpToStep(1.236, 0.01))->toEqualWithDelta(1.24, 1e-9);
});

it('passes the value through unchanged when the step is 0 (mode none)', function () {
    expect(RoundingMode::roundHalfUpToStep(1.237, 0.0))->toBe(1.237);
});

// -----------------------------------------------------------------------------
//  OrderPricingCalculator — the single source of truth
// -----------------------------------------------------------------------------

it('prices tax half-up to the currency step for the issue example', function () {
    $calc = app(OrderPricingCalculator::class);

    // taxable 1230, 8% tax, no service, JPY (integer step).
    $pricing = $calc->price(1230.0, 0.0, 8.0, 0.0, 'JPY');

    expect($pricing['tax_amount'])->toBe(98.0);      // half-up, NOT ceil (99)
    expect($pricing['service_charge'])->toBe(0.0);
    expect($pricing['total_amount'])->toBe(1328.0);  // 1230 + 98
});

it('keeps JPY totals whole so an integer payment can never fall short (anti-lockup)', function () {
    $calc = app(OrderPricingCalculator::class);

    // A subtotal that produced a fractional 2-decimal total under the old
    // hardcoded rounding; the currency step now guarantees a whole unit.
    $pricing = $calc->price(1515.0, 0.0, 8.0, 5.0, 'JPY');

    expect(fmod($pricing['tax_amount'], 1.0))->toBe(0.0);
    expect(fmod($pricing['service_charge'], 1.0))->toBe(0.0);
    expect(fmod($pricing['total_amount'], 1.0))->toBe(0.0);
});

// -----------------------------------------------------------------------------
//  recalculateTotals ⇄ checkout parity — the core R1 / R1b fix
// -----------------------------------------------------------------------------

beforeEach(function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $orgId,
        'service_charge_rate' => 0,
        'currency_code' => 'JPY',
    ]);

    $this->orgId = $orgId;
    $this->orderService = app(CustomerOrderService::class);
});

/**
 * Open order whose non-voided items sum to $taxable (quantity 1 each).
 */
function makeRoundingOrder(float $taxable, string $orgId, string $brandId, string $branchId): CustomerOrder
{
    $order = CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'branch_id' => $branchId,
        'status' => 'open',
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'subtotal' => $taxable,
        'total_amount' => $taxable,
    ]);

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => $taxable,
        'subtotal' => $taxable,
        'topping_subtotal' => 0,
        // plan-043 — tax is now the per-line snapshot rate (the flat branch
        // tax_rate was dropped); stamp 8% so recalculateTotals groups + taxes it.
        'tax_rate' => 8,
        'status' => 'pending',
    ]);

    return $order->refresh();
}

it('recalculateTotals persists the half-up figure, not the old ceil (R1b)', function () {
    $order = makeRoundingOrder(1230.0, $this->orgId, $this->brand->id, $this->branch->id);

    // plan-047 (T2.12) moved the private recalculateTotals into the persistence
    // layer, exposed as the public refreshOrderTotals() — the same path that
    // fires on every item add/void/update in production.
    $this->orderService->refreshOrderTotals($order);

    $order->refresh();
    expect((float) $order->tax_amount)->toBe(98.0);        // was 99 under ceil
    expect((float) $order->total_amount)->toBe(1328.0);
});

it('recalculateTotals and checkout agree on tax + total for the same order (R1)', function () {
    $order = makeRoundingOrder(1230.0, $this->orgId, $this->brand->id, $this->branch->id);

    $this->orderService->refreshOrderTotals($order);
    $order->refresh();

    $recalcTax = (float) $order->tax_amount;
    $recalcTotal = (float) $order->total_amount;

    // checkout() prices through the same OrderPricingCalculator.
    $checkedOut = $this->orderService->checkout($order, []);

    expect((float) $checkedOut->tax_amount)->toBe($recalcTax);
    expect((float) $checkedOut->total_amount)->toBe($recalcTotal);
    expect((float) $checkedOut->tax_amount)->toBe(98.0);
});
