<?php

declare(strict_types=1);

use App\Models\ToppingGroup;
use App\Omnify\Enums\ToppingGroupPriceStrategyEnum;
use App\Services\Topping\ToppingPricingService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->service = new ToppingPricingService;
});

/**
 * Helper: build an unsaved ToppingGroup with the strategy + free_quantity
 * we need. We never save it — pricing is pure math, no DB needed for
 * priceLine() unit tests.
 */
function pricingGroup(string $strategy, int $freeQuantity = 0): ToppingGroup
{
    $group = new ToppingGroup;
    $group->forceFill([
        'price_strategy' => $strategy,
        'free_quantity' => $freeQuantity,
    ]);

    return $group;
}

function pricingSelection(string $itemId, string $skuId, int $quantity, float $unitPrice): array
{
    return [
        'topping_group_item_id' => $itemId,
        'product_sku_id' => $skuId,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
    ];
}

// ---------------------------------------------------------------------------
//  Empty + degenerate inputs
// ---------------------------------------------------------------------------

it('returns zero subtotal for empty selections', function () {
    $group = pricingGroup(ToppingGroupPriceStrategyEnum::Flat->value);

    $result = $this->service->priceLine([], $group->price_strategy, (int) ($group->free_quantity ?? 0));

    expect($result)->toMatchArray([
        'topping_subtotal' => 0.0,
        'breakdown' => [],
    ]);
});

it('treats free_up_to_n with zero free_quantity as flat', function () {
    $group = pricingGroup(ToppingGroupPriceStrategyEnum::FreeUpToN->value, 0);

    $result = $this->service->priceLine([
        pricingSelection('i1', 's1', 1, 50.0),
        pricingSelection('i2', 's2', 1, 80.0),
    ], $group->price_strategy, (int) ($group->free_quantity ?? 0));

    expect($result['topping_subtotal'])->toBe(130.0);
    expect($result['breakdown'])->toHaveCount(2);
    foreach ($result['breakdown'] as $entry) {
        expect($entry['charged'])->toBeTrue();
    }
});

// ---------------------------------------------------------------------------
//  TESTS.md "Pricing parity" — flat strategy
// ---------------------------------------------------------------------------

it('flat strategy: three selections at 30, 50, 100 each qty=1 → subtotal 180', function () {
    $group = pricingGroup(ToppingGroupPriceStrategyEnum::Flat->value);

    $result = $this->service->priceLine([
        pricingSelection('i1', 's1', 1, 30.0),
        pricingSelection('i2', 's2', 1, 50.0),
        pricingSelection('i3', 's3', 1, 100.0),
    ], $group->price_strategy, (int) ($group->free_quantity ?? 0));

    expect($result['topping_subtotal'])->toBe(180.0);
});

it('flat strategy: respects per-selection quantity', function () {
    $group = pricingGroup(ToppingGroupPriceStrategyEnum::Flat->value);

    // 50 × 2 + 80 × 1 = 180
    $result = $this->service->priceLine([
        pricingSelection('i1', 's1', 2, 50.0),
        pricingSelection('i2', 's2', 1, 80.0),
    ], $group->price_strategy, (int) ($group->free_quantity ?? 0));

    expect($result['topping_subtotal'])->toBe(180.0);
    expect($result['breakdown'])->toHaveCount(3); // expanded by qty
});

// ---------------------------------------------------------------------------
//  TESTS.md "Pricing parity" — free_up_to_n strategy
// ---------------------------------------------------------------------------

it('free_up_to_n waives the most expensive N (free_quantity=2, four selections at 50/80/100/120)', function () {
    $group = pricingGroup(ToppingGroupPriceStrategyEnum::FreeUpToN->value, 2);

    $result = $this->service->priceLine([
        pricingSelection('i1', 's1', 1, 50.0),
        pricingSelection('i2', 's2', 1, 80.0),
        pricingSelection('i3', 's3', 1, 100.0),
        pricingSelection('i4', 's4', 1, 120.0),
    ], $group->price_strategy, (int) ($group->free_quantity ?? 0));

    // Waive 120 + 100 (most expensive 2), charge 80 + 50 = 130
    expect($result['topping_subtotal'])->toBe(130.0);

    $charged = collect($result['breakdown'])->where('charged', true);
    $waived = collect($result['breakdown'])->where('charged', false);

    expect($charged)->toHaveCount(2);
    expect($waived)->toHaveCount(2);
    expect($waived->pluck('unit_price')->sort()->values()->all())->toBe([100.0, 120.0]);
});

it('free_up_to_n treats one selection of qty=3 as three units (free_quantity=1)', function () {
    $group = pricingGroup(ToppingGroupPriceStrategyEnum::FreeUpToN->value, 1);

    // qty=3 × ¥50 → 3 units of 50; waive 1, charge 2 = 100
    $result = $this->service->priceLine([
        pricingSelection('i1', 's1', 3, 50.0),
    ], $group->price_strategy, (int) ($group->free_quantity ?? 0));

    expect($result['topping_subtotal'])->toBe(100.0);
    expect($result['breakdown'])->toHaveCount(3);
    expect(collect($result['breakdown'])->where('charged', false))->toHaveCount(1);
});

it('free_up_to_n with 1 selection at 50 (qty=3) and 1 at 120 (qty=1), free_quantity=1', function () {
    $group = pricingGroup(ToppingGroupPriceStrategyEnum::FreeUpToN->value, 1);

    // Units: [50, 50, 50, 120]. Sort desc: [120, 50, 50, 50].
    // Waive 120 (most expensive). Charge 50 + 50 + 50 = 150.
    $result = $this->service->priceLine([
        pricingSelection('i1', 's1', 3, 50.0),
        pricingSelection('i2', 's2', 1, 120.0),
    ], $group->price_strategy, (int) ($group->free_quantity ?? 0));

    expect($result['topping_subtotal'])->toBe(150.0);
});

it('free_up_to_n with free_quantity ≥ unit count waives everything', function () {
    $group = pricingGroup(ToppingGroupPriceStrategyEnum::FreeUpToN->value, 5);

    $result = $this->service->priceLine([
        pricingSelection('i1', 's1', 1, 50.0),
        pricingSelection('i2', 's2', 1, 80.0),
    ], $group->price_strategy, (int) ($group->free_quantity ?? 0));

    expect($result['topping_subtotal'])->toBe(0.0);
});

// ---------------------------------------------------------------------------
//  Cross-group aggregation
// ---------------------------------------------------------------------------

it('priceLineAcrossGroups sums correctly across groups with mixed strategies', function () {
    $flatGroup = pricingGroup(ToppingGroupPriceStrategyEnum::Flat->value);
    $freeGroup = pricingGroup(ToppingGroupPriceStrategyEnum::FreeUpToN->value, 1);

    $result = $this->service->priceLineAcrossGroups([
        'g-flat' => [
            'price_strategy' => $flatGroup->price_strategy,
            'free_quantity' => (int) ($flatGroup->free_quantity ?? 0),
            'selections' => [
                pricingSelection('i1', 's1', 1, 30.0),
                pricingSelection('i2', 's2', 1, 50.0),
            ],
        ],
        'g-free' => [
            'price_strategy' => $freeGroup->price_strategy,
            'free_quantity' => (int) ($freeGroup->free_quantity ?? 0),
            'selections' => [
                pricingSelection('i3', 's3', 1, 80.0),
                pricingSelection('i4', 's4', 1, 120.0),
            ],
        ],
    ]);

    // flat group: 30 + 50 = 80
    // free_up_to_n group with free_quantity=1: waive 120, charge 80
    // total: 80 + 80 = 160
    expect($result['topping_subtotal'])->toBe(160.0);
    expect($result['breakdown'])->toHaveCount(4);

    // Each entry must carry its source group id
    expect(collect($result['breakdown'])->pluck('topping_group_id')->unique()->sort()->values()->all())
        ->toBe(['g-flat', 'g-free']);
});

// ---------------------------------------------------------------------------
//  Rounding behaviour (Decimal(15,2) — values must round to 2 places)
// ---------------------------------------------------------------------------

it('rounds subtotal to 2 decimal places', function () {
    $group = pricingGroup(ToppingGroupPriceStrategyEnum::Flat->value);

    // 33.333 + 33.333 + 33.334 = 100.000
    $result = $this->service->priceLine([
        pricingSelection('i1', 's1', 1, 33.333),
        pricingSelection('i2', 's2', 1, 33.333),
        pricingSelection('i3', 's3', 1, 33.334),
    ], $group->price_strategy, (int) ($group->free_quantity ?? 0));

    expect($result['topping_subtotal'])->toBe(100.0);
});

// ---------------------------------------------------------------------------
//  Stable ordering (parity with TS implementation)
// ---------------------------------------------------------------------------

it('breakdown preserves input order even after free_up_to_n sort', function () {
    $group = pricingGroup(ToppingGroupPriceStrategyEnum::FreeUpToN->value, 1);

    $result = $this->service->priceLine([
        pricingSelection('i1', 's1', 1, 50.0),
        pricingSelection('i2', 's2', 1, 120.0),
        pricingSelection('i3', 's3', 1, 80.0),
    ], $group->price_strategy, (int) ($group->free_quantity ?? 0));

    // Receipt rendering depends on input-order breakdown
    expect($result['breakdown'][0]['topping_group_item_id'])->toBe('i1');
    expect($result['breakdown'][1]['topping_group_item_id'])->toBe('i2');
    expect($result['breakdown'][2]['topping_group_item_id'])->toBe('i3');

    // i2 (120) is the most expensive → waived
    expect($result['breakdown'][0]['charged'])->toBeTrue();
    expect($result['breakdown'][1]['charged'])->toBeFalse();
    expect($result['breakdown'][2]['charged'])->toBeTrue();
});
