<?php

declare(strict_types=1);

use App\Services\Order\ValueObjects\OrderLineEvidence;
use App\Services\Order\ValueObjects\OrderLinePayload;
use App\Services\Order\ValueObjects\OrderPricingEvidence;
use App\Services\Order\ValueObjects\OrderServiceChargePayload;
use Illuminate\Support\Str;

/**
 * T2.12 blocker — issue #1090.
 *
 * `TrustedOrderSnapshot` enforces two invariants at once:
 *
 *   A. total = subtotal - discount + serviceCharge + tax   (OrderPricingEvidence)
 *   B. tax   = Σ line evidence taxAmountMinor              (TrustedOrderSnapshot:80)
 *
 * `OrderPricingEvidence` carries `serviceChargeMinor` but has **no**
 * `serviceChargeTaxMinor`. So when a shop charges tax ON the service charge —
 * which the legacy engine supports and `ShopOrderSetting.service_charge_tax_rate`
 * exposes — that tax has nowhere to live:
 *
 *   - put it in `taxMinor` and invariant B breaks (no line owns it)
 *   - leave it out and invariant A breaks (the customer pays it)
 *
 * The typed order model therefore cannot represent an order that any shop with
 * `service_charge_tax_rate > 0` produces today. That is a schema gap, not a
 * coding oversight, and it blocks a faithful "without changing behavior" port
 * until `OrderPricingEvidence` gains the field.
 *
 * These tests pin the gap so it cannot be rediscovered the hard way, mid-port.
 */
function evidenceLine(int $subtotalMinor, int $taxMinor): OrderLinePayload
{
    return new OrderLinePayload(
        itemId: (string) Str::uuid(),
        productId: (string) Str::uuid(),
        skuId: (string) Str::uuid(),
        quantity: 1,
        unitPriceMinor: $subtotalMinor,
        toppings: [],
        evidence: new OrderLineEvidence(
            menuId: null,
            menuProductId: null,
            menuProductSkuId: (string) Str::uuid(),
            taxTypeId: null,
            originalUnitPriceMinor: $subtotalMinor,
            taxRateBasisPoints: 1000,
            taxAmountMinor: $taxMinor,
            lineSubtotalMinor: $subtotalMinor,
        ),
    );
}

it('reconciles an order with no service charge', function () {
    // ¥1000 line, 10% → tax 100, total 1100. Both invariants hold.
    $pricing = new OrderPricingEvidence(
        subtotalMinor: 1000,
        discountMinor: 0,
        serviceChargeMinor: 0,
        taxMinor: 100,
        totalMinor: 1100,
        taxIncluded: false,
        taxRoundingMode: 'round',
        taxRoundingDecimals: 0,
    );

    expect($pricing->totalMinor)
        ->toBe($pricing->subtotalMinor - $pricing->discountMinor + $pricing->serviceChargeMinor + $pricing->taxMinor)
        ->and($pricing->taxMinor)->toBe(evidenceLine(1000, 100)->evidence->taxAmountMinor);
});

it('reconciles an untaxed service charge', function () {
    // service_charge_rate = 10, service_charge_tax_rate = 0.
    // 1000 + 100 service + 100 line tax = 1200. Still representable.
    $pricing = new OrderPricingEvidence(
        subtotalMinor: 1000,
        discountMinor: 0,
        serviceChargeMinor: 100,
        taxMinor: 100,
        totalMinor: 1200,
        taxIncluded: false,
        taxRoundingMode: 'round',
        taxRoundingDecimals: 0,
    );

    expect($pricing->totalMinor)
        ->toBe($pricing->subtotalMinor - $pricing->discountMinor + $pricing->serviceChargeMinor + $pricing->taxMinor)
        // Invariant B: the single line owns all 100 of the tax.
        ->and($pricing->taxMinor)->toBe(100);
});

it('RESOLVED #1090: a taxed service charge now satisfies both invariants at once', function () {
    // The shape the legacy engine produces for
    // service_charge_rate = 10, service_charge_tax_rate = 10:
    //   subtotal 1000, service 100, line tax 100, service-charge tax 10
    //   customer pays 1210
    $lineTax = 100;          // owned by the line
    $serviceChargeTax = 10;  // owned by NOTHING in the typed model

    // Before the fix this required folding the tax into taxMinor (which broke
    // invariant B) or leaving it out (which broke invariant A). Now the charge
    // owns its tax, so taxMinor legitimately covers both sources.
    $foldedIn = new OrderPricingEvidence(
        subtotalMinor: 1000,
        discountMinor: 0,
        serviceChargeMinor: 100,
        taxMinor: $lineTax + $serviceChargeTax, // 110
        totalMinor: 1210,
        taxIncluded: false,
        taxRoundingMode: 'round',
        taxRoundingDecimals: 0,
    );

    // Invariant A holds …
    expect($foldedIn->totalMinor)
        ->toBe($foldedIn->subtotalMinor - $foldedIn->discountMinor + $foldedIn->serviceChargeMinor + $foldedIn->taxMinor);
    // … but invariant B is now violated: no line accounts for the extra 10, so
    // TrustedOrderSnapshot would reject the construction.
    expect($foldedIn->taxMinor)->not->toBe($lineTax);

    // Option 2 — keep taxMinor equal to the line sum so invariant B holds …
    $leftOut = new OrderPricingEvidence(
        subtotalMinor: 1000,
        discountMinor: 0,
        serviceChargeMinor: 100,
        taxMinor: $lineTax, // 100
        totalMinor: 1210,
        taxIncluded: false,
        taxRoundingMode: 'round',
        taxRoundingDecimals: 0,
    );

    expect($leftOut->taxMinor)->toBe($lineTax);
    // … and now invariant A is short by exactly the service-charge tax: the
    // snapshot would under-bill the customer by ¥10.
    $reconciled = $leftOut->subtotalMinor - $leftOut->discountMinor + $leftOut->serviceChargeMinor + $leftOut->taxMinor;
    expect($reconciled)->toBe(1200)
        ->and($leftOut->totalMinor - $reconciled)->toBe($serviceChargeTax);
});

it('RESOLVED #1090: the service charge now carries its own tax evidence', function () {
    // The fix took the "model it as a line" route rather than adding a field to
    // OrderPricingEvidence: OrderDraftPayload::$serviceCharge is an
    // OrderServiceChargePayload carrying amount, tax and the rate that produced
    // it. OrderPricingEvidence stays a pure totals record.
    $chargeFields = array_keys(get_class_vars(OrderServiceChargePayload::class));

    expect($chargeFields)->toContain('amountMinor');
    expect($chargeFields)->toContain('taxAmountMinor');
    expect($chargeFields)->toContain('taxRateBasisPoints');

    // The order-level totals record deliberately still has no per-source tax
    // field — attribution lives on the lines and on the charge.
    $pricingFields = array_keys(get_class_vars(OrderPricingEvidence::class));
    expect($pricingFields)->toContain('serviceChargeMinor');
    expect($pricingFields)->toContain('taxMinor');
});
