<?php

/**
 * Plan-033 — SplitByItemsCalculator parity & semantics tests.
 *
 * Loads the shared fixture file `tests/Fixtures/split_by_items_cases.json`
 * and replays each case through the PHP calculator. The same fixture file
 * will be copied to `pos-web/test/fixtures/split-by-items-cases.json` by the
 * deferred pos-web follow-up plan and replayed through the TS calculator;
 * the test names match so a side-by-side run keeps both stacks bit-aligned.
 */

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderCondition;
use App\Services\Customer\SplitByItemsCalculator;
use App\Support\RoundingMode;
use Tests\TestCase;

uses(TestCase::class);

function ph_loadFixtures(): array
{
    $path = __DIR__.'/../../Fixtures/split_by_items_cases.json';
    expect(file_exists($path))->toBeTrue("Fixture file missing: {$path}");
    $data = json_decode((string) file_get_contents($path), true);
    expect($data)->toBeArray()->and($data['cases'] ?? null)->toBeArray();

    $named = [];
    foreach ($data['cases'] as $case) {
        $named[$case['name']] = $case;
    }

    return $named;
}

/**
 * Dòng sổ `discount` cho một fixture đơn — rỗng khi fixture không có giảm giá.
 *
 * @return list<OrderCondition>
 */
function ph_discountConditions(array $orderFixture): array
{
    $discount = (float) ($orderFixture['discount_amount'] ?? 0);

    if (abs($discount) < 0.000001) {
        return [];
    }

    $condition = new OrderCondition;
    $condition->setRawAttributes([
        'type' => 'discount',
        'amount' => -abs($discount),
    ], true);

    return [$condition];
}

function ph_buildOrder(array $orderFixture): CustomerOrder
{
    $order = new CustomerOrder;
    $order->setRawAttributes([
        'id' => 'order-fixture',
        'subtotal' => $orderFixture['subtotal'] ?? 0,
        'discount_amount' => $orderFixture['discount_amount'] ?? 0,
        'total_amount' => $orderFixture['total_amount'] ?? 0,
    ], true);

    $items = [];
    foreach ($orderFixture['items'] as $i) {
        $item = new CustomerOrderItem;
        $item->setRawAttributes([
            'id' => $i['id'],
            'unit_price' => $i['unit_price'],
            'quantity' => $i['quantity'],
            'topping_subtotal' => $i['topping_subtotal'] ?? 0,
            'status' => $i['status'] ?? 'preparing',
        ], true);
        $items[] = $item;
    }
    $order->setRelation('items', collect($items));
    // #2041 — giảm giá nay sống ở sổ `order_conditions`, không còn là cột. Đơn
    // in-memory phải mang sổ tường minh, nếu không `discount_amount` đọc ra 0.
    // Dấu ÂM là quy ước của sổ; getter `discountAmount` tự `abs()`.
    $order->setRelation('conditions', collect(ph_discountConditions($orderFixture)));

    return $order;
}

it('computes happy 1-item-1-bill with no tax', function () {
    $cases = ph_loadFixtures();
    $c = $cases['happy-1-item-1-bill-no-tax'];

    $result = (new SplitByItemsCalculator)->compute(
        ph_buildOrder($c['order']),
        $c['allocations'],
        $c['rounding_mode'],
        $c['currency_code'],
        (float) $c['tax_rate'],
        (float) $c['service_charge_rate'],
        $c['people_count'],
    );

    expect($result['total_check'])->toBe((float) $c['expected']['total_check']);
    expect($result['bills'][0]['total'])->toBe((float) $c['expected']['bills'][0]['total']);
    expect($result['bills'][0]['is_empty'])->toBeFalse();
});

it('proportionally splits subtotal + tax across two bills', function () {
    $cases = ph_loadFixtures();
    $c = $cases['split-2-bills-with-tax-vnd'];

    $result = (new SplitByItemsCalculator)->compute(
        ph_buildOrder($c['order']),
        $c['allocations'],
        $c['rounding_mode'],
        $c['currency_code'],
        (float) $c['tax_rate'],
        (float) $c['service_charge_rate'],
        $c['people_count'],
    );

    expect($result['total_check'])->toBe(2200.0);
    foreach ($c['expected']['bills'] as $i => $expected) {
        expect($result['bills'][$i]['subtotal'])->toBe((float) $expected['subtotal']);
        expect($result['bills'][$i]['tax'])->toBe((float) $expected['tax']);
        expect($result['bills'][$i]['total'])->toBe((float) $expected['total']);
    }
});

it('distributes discount proportional to subtotal', function () {
    $cases = ph_loadFixtures();
    $c = $cases['proportional-discount-2-bills'];

    $result = (new SplitByItemsCalculator)->compute(
        ph_buildOrder($c['order']),
        $c['allocations'],
        $c['rounding_mode'],
        $c['currency_code'],
        (float) $c['tax_rate'],
        (float) $c['service_charge_rate'],
        $c['people_count'],
    );

    expect($result['total_check'])->toBe(2000.0);
    foreach ($c['expected']['bills'] as $i => $expected) {
        expect($result['bills'][$i]['discount'])->toBe((float) $expected['discount']);
        expect($result['bills'][$i]['total'])->toBe((float) $expected['total']);
    }
});

it('reconciles drift on the last non-empty bill (positive clamp)', function () {
    $cases = ph_loadFixtures();
    $c = $cases['reconcile-drift-last-absorbs'];

    $result = (new SplitByItemsCalculator)->compute(
        ph_buildOrder($c['order']),
        $c['allocations'],
        $c['rounding_mode'],
        $c['currency_code'],
        (float) $c['tax_rate'],
        (float) $c['service_charge_rate'],
        $c['people_count'],
    );

    expect($result['total_check'])->toBe(300.0);
    $last = end($result['bills']);
    expect($last['tax'])->toBe(-1.0);
    expect($last['total'])->toBe(100.0);
});

it('renders empty bills with zero totals and is_empty=true', function () {
    $cases = ph_loadFixtures();
    $c = $cases['empty-bills-mixed'];

    $result = (new SplitByItemsCalculator)->compute(
        ph_buildOrder($c['order']),
        $c['allocations'],
        $c['rounding_mode'],
        $c['currency_code'],
        (float) $c['tax_rate'],
        (float) $c['service_charge_rate'],
        $c['people_count'],
    );

    expect($result['bills'][0]['is_empty'])->toBeTrue();
    expect($result['bills'][1]['is_empty'])->toBeFalse();
    expect($result['bills'][2]['is_empty'])->toBeTrue();
    expect($result['total_check'])->toBe(1000.0);
});

it('rounds up to integer when mode=integer regardless of currency', function () {
    $cases = ph_loadFixtures();
    $c = $cases['mode-integer-rounds-up-usd'];

    $result = (new SplitByItemsCalculator)->compute(
        ph_buildOrder($c['order']),
        $c['allocations'],
        $c['rounding_mode'],
        $c['currency_code'],
        (float) $c['tax_rate'],
        (float) $c['service_charge_rate'],
        $c['people_count'],
    );

    foreach ($result['bills'] as $bill) {
        expect($bill['total'])->toBe(floor($bill['total']));
    }
    expect($result['total_check'])->toBe((float) $c['expected']['total_check']);
});

it('passes through with mode=none for zero-decimal currency', function () {
    $cases = ph_loadFixtures();
    $c = $cases['mode-none-vnd-with-drift'];

    $result = (new SplitByItemsCalculator)->compute(
        ph_buildOrder($c['order']),
        $c['allocations'],
        $c['rounding_mode'],
        $c['currency_code'],
        (float) $c['tax_rate'],
        (float) $c['service_charge_rate'],
        $c['people_count'],
    );

    expect($result['total_check'])->toBe(10001.0);
    expect($result['bills'][0]['total'])->toBe(10001.0);
});

it('applies tax and service charge with two_decimals mode', function () {
    $cases = ph_loadFixtures();
    $c = $cases['mode-two-decimals-vnd-tax-service'];

    $result = (new SplitByItemsCalculator)->compute(
        ph_buildOrder($c['order']),
        $c['allocations'],
        $c['rounding_mode'],
        $c['currency_code'],
        (float) $c['tax_rate'],
        (float) $c['service_charge_rate'],
        $c['people_count'],
    );

    expect($result['bills'][0]['tax'])->toBe(100.0);
    expect($result['bills'][0]['service'])->toBe(50.0);
    expect($result['bills'][0]['total'])->toBe(1150.0);
});

it('filters voided items out of allocations and unassigned counts', function () {
    $order = ph_buildOrder([
        'subtotal' => 1000,
        'discount_amount' => 0,
        'total_amount' => 1000,
        'items' => [
            ['id' => 'i-1', 'unit_price' => 1000, 'quantity' => 1, 'topping_subtotal' => 0, 'status' => 'preparing'],
            ['id' => 'i-2', 'unit_price' => 500, 'quantity' => 1, 'topping_subtotal' => 0, 'status' => 'voided'],
        ],
    ]);

    $result = (new SplitByItemsCalculator)->compute(
        $order,
        [
            ['item_id' => 'i-1', 'units' => 1, 'bill_index' => 0],
            ['item_id' => 'i-2', 'units' => 1, 'bill_index' => 0],
        ],
        'auto',
        'VND',
        0.0,
        0.0,
        1,
    );

    expect($result['bills'][0]['subtotal'])->toBe(1000.0);
    expect($result['unassigned_units'])->toBe([]);
});

it('reconciles negative drift by clamping the last bill to zero and forwarding overshoot to the first (negative clamp)', function () {
    // Drift so negative it would push the last non-empty bill below zero:
    //   grossSum = 30 + 100 = 130, but order.total_amount = 20 → diff = -110.
    // Last non-empty bill (bill 1, total 100) projects to -10 < 0, so it clamps
    // to 0 and forwards the 10 overshoot to the first non-empty bill (bill 0).
    // Exercises SplitByItemsCalculator::compute() lines 194-214 (the else branch),
    // the money-moving path documented in plan-021 DESIGN Q2 that only the
    // positive-clamp sibling test covered before.
    $order = ph_buildOrder([
        'subtotal' => 130,
        'discount_amount' => 0,
        'total_amount' => 20,
        'items' => [
            ['id' => 'i-1', 'unit_price' => 30, 'quantity' => 1, 'topping_subtotal' => 0, 'status' => 'preparing'],
            ['id' => 'i-2', 'unit_price' => 100, 'quantity' => 1, 'topping_subtotal' => 0, 'status' => 'preparing'],
        ],
    ]);

    $result = (new SplitByItemsCalculator)->compute(
        $order,
        [
            ['item_id' => 'i-1', 'units' => 1, 'bill_index' => 0],
            ['item_id' => 'i-2', 'units' => 1, 'bill_index' => 1],
        ],
        'none', // step 0 → no round-up interference, drift comes purely from total_amount
        'VND',
        0.0,
        0.0,
        2,
    );

    // Last non-empty bill clamped to zero; its tax carries the full negation.
    expect($result['bills'][1]['total'])->toBe(0.0);
    expect($result['bills'][1]['tax'])->toBe(-100.0);

    // First non-empty bill absorbs the 10 overshoot (30 - 10 = 20).
    expect($result['bills'][0]['total'])->toBe(20.0);
    expect($result['bills'][0]['tax'])->toBe(-10.0);

    // Invariant preserved: Σ bill.total === order.total_amount, no money lost.
    expect($result['total_check'])->toBe(20.0);
});

it('reports unassigned units when allocations do not cover all units', function () {
    $order = ph_buildOrder([
        'subtotal' => 2000,
        'discount_amount' => 0,
        'total_amount' => 2000,
        'items' => [
            ['id' => 'i-1', 'unit_price' => 1000, 'quantity' => 2, 'topping_subtotal' => 0, 'status' => 'preparing'],
        ],
    ]);

    $result = (new SplitByItemsCalculator)->compute(
        $order,
        [
            ['item_id' => 'i-1', 'units' => 1, 'bill_index' => 0],
        ],
        'auto',
        'VND',
        0.0,
        0.0,
        2,
    );

    expect($result['unassigned_units'])->toHaveCount(1);
    expect($result['unassigned_units'][0]['item_id'])->toBe('i-1');
});

it('rounds money components to the currency minor unit for a 2-decimal currency (USD)', function () {
    // Regression: roundHalfUp used to floor to whole integers unconditionally,
    // so a USD bill of $10.50 + 8% tax collapsed to subtotal=$11 / tax=$1.
    // With currency-step rounding the cent precision survives.
    $order = ph_buildOrder([
        'subtotal' => 10.50,
        'discount_amount' => 0,
        'total_amount' => 11.34, // 10.50 + 0.84 tax, so reconcile is a no-op
        'items' => [
            ['id' => 'i-1', 'unit_price' => 10.50, 'quantity' => 1, 'topping_subtotal' => 0, 'status' => 'preparing'],
        ],
    ]);

    $result = (new SplitByItemsCalculator)->compute(
        $order,
        [['item_id' => 'i-1', 'units' => 1, 'bill_index' => 0]],
        'two_decimals',
        'USD',
        8.0,
        0.0,
        1,
    );

    expect($result['bills'][0]['subtotal'])->toEqualWithDelta(10.50, 0.0001);
    expect($result['bills'][0]['tax'])->toEqualWithDelta(0.84, 0.0001);
    expect($result['bills'][0]['total'])->toEqualWithDelta(11.34, 0.0001);
});

it('rounds tax to the 3-decimal minor unit for a 3-decimal currency (KWD, auto)', function () {
    // TESTS.md T70 — KWD (3-decimal) + auto → step 0.001. Item money columns
    // cast to 2dp in this schema, so we exercise the 3rd decimal via a computed
    // tax: 2% of 10.30 = 0.206. The old floor(v+0.5) rounded this to 0.
    $order = ph_buildOrder([
        'subtotal' => 10.30,
        'discount_amount' => 0,
        'total_amount' => 10.51, // order money is 2dp; keep reconcile near-zero
        'items' => [
            ['id' => 'i-1', 'unit_price' => 10.30, 'quantity' => 1, 'topping_subtotal' => 0, 'status' => 'preparing'],
        ],
    ]);

    $result = (new SplitByItemsCalculator)->compute(
        $order,
        [['item_id' => 'i-1', 'units' => 1, 'bill_index' => 0]],
        'auto',
        'KWD',
        2.0,
        0.0,
        1,
        reconcile: false, // isolate the money-component rounding from drift reconcile
    );

    expect($result['bills'][0]['subtotal'])->toEqualWithDelta(10.30, 0.00001);
    expect($result['bills'][0]['tax'])->toEqualWithDelta(0.206, 0.00001);
    expect($result['bills'][0]['total'])->toEqualWithDelta(10.506, 0.00001);
});

it('RoundingMode::step returns expected step per (mode, currency)', function () {
    expect(RoundingMode::step('auto', 'VND'))->toBe(1.0);
    expect(RoundingMode::step('auto', 'JPY'))->toBe(1.0);
    expect(RoundingMode::step('auto', 'USD'))->toBe(0.01);
    expect(RoundingMode::step('auto', 'KWD'))->toBe(0.001);
    expect(RoundingMode::step('integer', 'USD'))->toBe(1.0);
    expect(RoundingMode::step('two_decimals', 'VND'))->toBe(0.01);
    expect(RoundingMode::step('none', 'VND'))->toBe(0.0);
    expect(RoundingMode::step(null, null))->toBe(1.0);
});

it('RoundingMode::roundUpToStep rounds up and passes through when step=0', function () {
    expect(RoundingMode::roundUpToStep(3.34, 0.01))->toBe(3.34);
    expect(RoundingMode::roundUpToStep(3.341, 0.01))->toBe(3.35);
    expect(RoundingMode::roundUpToStep(1234.5, 1.0))->toBe(1235.0);
    expect(RoundingMode::roundUpToStep(1234.5, 0.0))->toBe(1234.5);
});

/**
 * plan-043 T1.9 — mixed-rate bills. Each bill groups its own items by snapshot
 * tax_rate and rounds tax once per group (bentō 8% + beer 10%).
 */
function ph_mixedRateOrder(): CustomerOrder
{
    $order = new CustomerOrder;
    $order->setRawAttributes([
        'id' => 'order-mixed', 'subtotal' => 1500, 'discount_amount' => 0, 'total_amount' => 1630,
    ], true);
    $bento = new CustomerOrderItem;
    $bento->setRawAttributes([
        'id' => 'bento', 'unit_price' => 1000, 'quantity' => 1, 'topping_subtotal' => 0,
        'status' => 'preparing', 'tax_rate' => 8,
    ], true);
    $beer = new CustomerOrderItem;
    $beer->setRawAttributes([
        'id' => 'beer', 'unit_price' => 500, 'quantity' => 1, 'topping_subtotal' => 0,
        'status' => 'preparing', 'tax_rate' => 10,
    ], true);
    $order->setRelation('items', collect([$bento, $beer]));

    return $order;
}

it('taxes a single mixed-rate bill per rate group (8% + 10%)', function () {
    $result = (new SplitByItemsCalculator)->compute(
        ph_mixedRateOrder(),
        [
            ['item_id' => 'bento', 'units' => 1, 'bill_index' => 0],
            ['item_id' => 'beer', 'units' => 1, 'bill_index' => 0],
        ],
        'auto', 'JPY', 0.0, 0.0, 1,
    );

    // 80 (8% of 1000) + 50 (10% of 500) = 130.
    expect($result['bills'][0]['tax'])->toBe(130.0)
        ->and($result['bills'][0]['total'])->toBe(1630.0);
});

it('splits a mixed-rate order across two bills, each taxed at its own rate', function () {
    $result = (new SplitByItemsCalculator)->compute(
        ph_mixedRateOrder(),
        [
            ['item_id' => 'bento', 'units' => 1, 'bill_index' => 0],
            ['item_id' => 'beer', 'units' => 1, 'bill_index' => 1],
        ],
        'auto', 'JPY', 0.0, 0.0, 2,
    );

    expect($result['bills'][0]['tax'])->toBe(80.0)   // bentō only → 8%
        ->and($result['bills'][1]['tax'])->toBe(50.0) // beer only → 10%
        ->and($result['total_check'])->toBe(1630.0);  // Σ = order total
});
