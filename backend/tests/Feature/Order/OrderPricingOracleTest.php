<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;
use App\Support\CurrencyMinorUnit;
use Illuminate\Support\Str;

/**
 * T2.12 pricing ORACLE — issue #1090.
 *
 * `OrderService::create()` is meant to run
 * `OrderPricingResolutionPort::resolveOrder()` and hand the result to
 * `insertResolvedOrder()`. Both are still `LogicException` stubs, so the typed
 * path has no behaviour to compare against and the legacy engine in
 * `WritesCustomerOrders` is the de-facto specification.
 *
 * T2.12's contract is explicit: move the transports behind typed commands
 * "without changing behavior yet". That is only checkable against a written-down
 * baseline, so this file IS that baseline. Each case pins what the legacy engine
 * produces today; the typed resolver must reproduce the same numbers exactly.
 *
 * The minor-unit cases matter most. The legacy engine works in MAJOR-unit floats
 * (`decimal(15,2)` columns) while `TrustedOrderSnapshot` requires MINOR-unit
 * integer evidence that reconciles exactly:
 *
 *     total = subtotal - discount + serviceCharge + tax
 *
 * Every money bug this plan has shipped so far — markSettled inflating non-JPY
 * 100x, recordTender writing minor into major columns — lived precisely in that
 * conversion. These tests pin both sides of it.
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
});

/**
 * An order carrying explicit per-line tax snapshots, priced by the legacy
 * engine. Mirrors what `addItems` leaves behind, without depending on menu
 * fixtures — the oracle is about ARITHMETIC, not menu resolution.
 *
 * @param  list<array{qty: int|float, price: float, rate: float, topping?: float}>  $lines
 */
function oracleOrder(object $ctx, array $lines, array $settings = [], array $orderOverrides = []): CustomerOrder
{
    $order = CustomerOrder::factory()->create(array_merge([
        'organization_id' => $ctx->orgId,
        'brand_id' => $ctx->brand->id,
        'branch_id' => $ctx->branch->id,
        'status' => 'open',
        'is_tax_included' => false,
        'discount_amount' => 0,
        'coupon_id' => null,
        'tax_rounding_mode' => 'round',
        'tax_rounding_decimals' => 0,
    ], $orderOverrides));

    ShopOrderSetting::query()->updateOrCreate(
        ['branch_id' => $order->branch_id],
        array_merge([
            'organization_id' => $ctx->orgId,
            'service_charge_rate' => 0,
            'service_charge_tax_rate' => 0,
            'currency_code' => 'JPY',
            'prices_include_tax' => false,
        ], $settings),
    );

    foreach ($lines as $line) {
        $topping = $line['topping'] ?? 0;
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'quantity' => $line['qty'],
            'unit_price' => $line['price'],
            'topping_subtotal' => $topping,
            'subtotal' => $line['qty'] * $line['price'] + $topping,
            'tax_rate' => $line['rate'],
            'tax_amount' => 0,
            'status' => 'served',
        ]);
    }

    app(CustomerOrderService::class)->refreshOrderTotals($order->fresh('items'));

    return $order->fresh('items');
}

/** The exact conversion the typed layer must use. */
function toMinor(float $major, string $currency): int
{
    return (int) round($major * (10 ** CurrencyMinorUnit::exponent($currency)));
}

// ---------------------------------------------------------------------------
// Tax-exclusive (外税) — tax is added on top
// ---------------------------------------------------------------------------

it('prices a single exclusive-tax line', function () {
    $order = oracleOrder($this, [['qty' => 1, 'price' => 1000, 'rate' => 10]]);

    expect((float) $order->subtotal)->toBe(1000.0)
        ->and((float) $order->tax_amount)->toBe(100.0)
        ->and((float) $order->total_amount)->toBe(1100.0);
});

it('multiplies quantity before taxing', function () {
    $order = oracleOrder($this, [['qty' => 3, 'price' => 1000, 'rate' => 10]]);

    expect((float) $order->subtotal)->toBe(3000.0)
        ->and((float) $order->tax_amount)->toBe(300.0)
        ->and((float) $order->total_amount)->toBe(3300.0);
});

it('folds a topping subtotal into the taxable base', function () {
    $order = oracleOrder($this, [['qty' => 1, 'price' => 1000, 'rate' => 10, 'topping' => 200]]);

    expect((float) $order->subtotal)->toBe(1200.0)
        ->and((float) $order->tax_amount)->toBe(120.0)
        ->and((float) $order->total_amount)->toBe(1320.0);
});

// ---------------------------------------------------------------------------
// Tax-inclusive (内税) — tax already sits inside the price
// ---------------------------------------------------------------------------

it('does not add inclusive tax on top of the total', function () {
    $order = oracleOrder(
        $this,
        [['qty' => 1, 'price' => 1100, 'rate' => 10]],
        ['prices_include_tax' => true],
        ['is_tax_included' => true],
    );

    // ¥1100 tax-inclusive at 10% → ¥100 of it is tax, and the customer still
    // pays ¥1100. Adding it again would be the classic double-charge.
    expect((float) $order->subtotal)->toBe(1100.0)
        ->and((float) $order->tax_amount)->toBe(100.0)
        ->and((float) $order->total_amount)->toBe(1100.0);
});

// ---------------------------------------------------------------------------
// Per-rate grouping — 軽減税率. Rounding happens ONCE PER RATE GROUP.
// ---------------------------------------------------------------------------

it('rounds once per rate group rather than once per line', function () {
    // Two 8% lines that individually round to .5 boundaries: rounding per line
    // would drift from rounding the 8% group as a whole.
    $order = oracleOrder($this, [
        ['qty' => 1, 'price' => 1234, 'rate' => 8],
        ['qty' => 1, 'price' => 1234, 'rate' => 8],
    ]);

    // Group net 2468 @8% = 197.44 → 197 (round, 0 decimals).
    // Per-line would be round(98.72)=99 twice = 198. The group answer wins.
    expect((float) $order->subtotal)->toBe(2468.0)
        ->and((float) $order->tax_amount)->toBe(197.0);
});

it('keeps reduced and standard rate groups separate', function () {
    $order = oracleOrder($this, [
        ['qty' => 1, 'price' => 1000, 'rate' => 8],   // 軽減税率 takeaway food
        ['qty' => 1, 'price' => 1000, 'rate' => 10],  // standard
    ]);

    expect((float) $order->subtotal)->toBe(2000.0)
        ->and((float) $order->tax_amount)->toBe(180.0)   // 80 + 100
        ->and((float) $order->total_amount)->toBe(2180.0);
});

// ---------------------------------------------------------------------------
// Rounding mode is an immutable per-order snapshot
// ---------------------------------------------------------------------------

it('applies the order snapshot rounding mode at the .4 boundary', function (string $mode, float $expected) {
    // 1234 @ 10% = 123.4 — the value that separates the three modes.
    $order = oracleOrder($this, [['qty' => 1, 'price' => 1234, 'rate' => 10]], [], ['tax_rounding_mode' => $mode]);

    expect((float) $order->tax_amount)->toBe($expected);
})->with([
    'round' => ['round', 123.0],
    'ceil' => ['ceil', 124.0],
    'floor' => ['floor', 123.0],
]);

it('applies the order snapshot rounding mode at the .5 boundary', function (string $mode, float $expected) {
    // 1230 @ 10% = 123.0 is exact; use 1235 @ 10% = 123.5 for half-up proof.
    $order = oracleOrder($this, [['qty' => 1, 'price' => 1235, 'rate' => 10]], [], ['tax_rounding_mode' => $mode]);

    expect((float) $order->tax_amount)->toBe($expected);
})->with([
    'round is half-UP, not banker rounding' => ['round', 124.0],
    'ceil' => ['ceil', 124.0],
    'floor' => ['floor', 123.0],
]);

// ---------------------------------------------------------------------------
// Service charge
// ---------------------------------------------------------------------------

it('adds a service charge and its own tax on top', function () {
    $order = oracleOrder(
        $this,
        [['qty' => 1, 'price' => 1000, 'rate' => 10]],
        ['service_charge_rate' => 10, 'service_charge_tax_rate' => 10],
    );

    expect((float) $order->subtotal)->toBe(1000.0)
        ->and((float) $order->service_charge)->toBe(100.0)
        // 100 line tax + 10 service-charge tax
        ->and((float) $order->tax_amount)->toBe(110.0)
        ->and((float) $order->total_amount)->toBe(1210.0);
});

// ---------------------------------------------------------------------------
// Order-level discount
// ---------------------------------------------------------------------------

it('subtracts an order discount before tax', function () {
    $order = oracleOrder(
        $this,
        [['qty' => 1, 'price' => 1000, 'rate' => 10]],
        [],
        ['discount_amount' => 200],
    );

    expect((float) $order->subtotal)->toBe(1000.0)
        ->and((float) $order->discount_amount)->toBe(200.0)
        // Taxed on the discounted base 800, not on 1000.
        ->and((float) $order->tax_amount)->toBe(80.0)
        ->and((float) $order->total_amount)->toBe(880.0);
});

// ---------------------------------------------------------------------------
// THE reconciliation identity the typed snapshot enforces
// ---------------------------------------------------------------------------

it('always satisfies total = subtotal - discount + serviceCharge + tax', function (array $lines, array $settings, array $overrides) {
    $order = oracleOrder($this, $lines, $settings, $overrides);

    $expected = (float) $order->subtotal
        - (float) $order->discount_amount
        + (float) $order->service_charge
        + ((bool) $order->is_tax_included ? 0.0 : (float) $order->tax_amount);

    expect((float) $order->total_amount)->toBe($expected);
})->with([
    'plain' => [[['qty' => 1, 'price' => 1000, 'rate' => 10]], [], []],
    'multi-rate' => [[['qty' => 2, 'price' => 780, 'rate' => 8], ['qty' => 1, 'price' => 1500, 'rate' => 10]], [], []],
    'service charge' => [[['qty' => 1, 'price' => 1000, 'rate' => 10]], ['service_charge_rate' => 10, 'service_charge_tax_rate' => 10], []],
    'discount' => [[['qty' => 1, 'price' => 1000, 'rate' => 10]], [], ['discount_amount' => 250]],
    'inclusive' => [[['qty' => 1, 'price' => 1100, 'rate' => 10]], ['prices_include_tax' => true], ['is_tax_included' => true]],
    'topping' => [[['qty' => 2, 'price' => 900, 'rate' => 10, 'topping' => 300]], [], []],
]);

// ---------------------------------------------------------------------------
// MINOR-UNIT CONTRACT — where every money bug in this plan has lived
// ---------------------------------------------------------------------------

it('converts a zero-decimal currency without scaling', function () {
    $order = oracleOrder($this, [['qty' => 1, 'price' => 1000, 'rate' => 10]], ['currency_code' => 'JPY']);

    // JPY exponent 0 → minor == major. A x100 here is the markSettled bug.
    expect(CurrencyMinorUnit::exponent('JPY'))->toBe(0)
        ->and(toMinor((float) $order->total_amount, 'JPY'))->toBe(1100);
});

it('scales a two-decimal currency by exactly one hundred', function () {
    expect(CurrencyMinorUnit::exponent('USD'))->toBe(2)
        ->and(toMinor(11.00, 'USD'))->toBe(1100)
        ->and(toMinor(0.01, 'USD'))->toBe(1)
        // The recordTender bug: writing 1100 minor into a major column produced
        // a $1,100 charge for an $11 order.
        ->and(toMinor(1100.0, 'USD'))->toBe(110000);
});

it('treats VND as zero-decimal like JPY', function () {
    // Both VN and JP shops run zero-decimal currencies, so a bug here is
    // invisible domestically and only detonates on a 2-decimal tenant.
    expect(CurrencyMinorUnit::exponent('VND'))->toBe(0)
        ->and(toMinor(25000.0, 'VND'))->toBe(25000);
});

it('keeps the reconciliation identity intact in minor units', function () {
    $order = oracleOrder(
        $this,
        [['qty' => 2, 'price' => 780, 'rate' => 8], ['qty' => 1, 'price' => 1500, 'rate' => 10]],
        ['service_charge_rate' => 10, 'service_charge_tax_rate' => 10],
    );

    $currency = 'JPY';
    $subtotal = toMinor((float) $order->subtotal, $currency);
    $discount = toMinor((float) $order->discount_amount, $currency);
    $service = toMinor((float) $order->service_charge, $currency);
    $tax = toMinor((float) $order->tax_amount, $currency);
    $total = toMinor((float) $order->total_amount, $currency);

    // Exactly the assertion TrustedOrderSnapshot makes before it will construct.
    expect($total)->toBe($subtotal - $discount + $service + $tax);
});
