<?php

/**
 * plan-051 (#1150) — stock_deduction_timing × per-line marker matrix.
 *
 * Pins (TESTS.md "Stock timing × void"):
 *   - on_close (default): behaviour identical to the legacy phase-5 —
 *     transaction shape (type/sub_type/reference/note/items) compared field
 *     by field on the same fixture, per-order marker stamped, nothing moves
 *     before close.
 *   - on_preparing: pending→preparing (and any transition THROUGH preparing,
 *     e.g. pending→served) deducts immediately + stamps the per-line marker;
 *     close never double-deducts. Born-at-status: a line created at/past
 *     preparing (no-KDS shops, default_order_item_status) deducts at
 *     creation; born-pending waits for the transition.
 *   - on_add: add deducts immediately; merge bumps and qty revises on a
 *     deducted pending line delta-adjust (extra deduction / partial
 *     compensation).
 *   - Mid-day timing flip: marker per line → no double deduction, undeducted
 *     lines are swept at close.
 *   - Idempotent replay + #1091 caller-supplied event instant.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\ShopOrderSetting;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\VoidReason;
use App\Models\Warehouse;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\OrderClosingService;
use App\Services\Inventory\StockDeductionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);
    // allow_negative_sales=true + 0 SKU stock = canonical made-to-order setup
    // (plan-024 G3): recipe materials deduct at sale time, the pre-made guard
    // stays quiet. Mirrors OrderClosingVoidedItemMaterialTest.
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'allow_negative_sales' => true,
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-admin'], ['name' => 'Org Admin', 'level' => 100]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->user->assignRole($role, $this->orgId);
    $this->actingAs($this->user);

    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'quantity' => 1000,
        'unit' => 'g',
        'alert_enabled' => false,
    ]);

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 1,
        'output_unit' => 'serving',
        'ingredients' => [
            ['material_id' => $this->material->id, 'quantity' => 10, 'unit' => 'g'],
        ],
    ]);

    $product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
        'selling_price' => 500,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $this->sku->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $this->orders = app(CustomerOrderService::class);
    $this->closing = app(OrderClosingService::class);
});

function sdtSetting(array $attrs): void
{
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => test()->branch->id],
        array_merge(['organization_id' => test()->orgId], $attrs),
    );
}

function sdtOrder(): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'order_type' => 'dine_in',
        'status' => CustomerOrderStatusEnum::Open->value,
        'created_by_id' => test()->user->id,
        'total_amount' => 0,
        'paid_amount' => 0,
    ]);
}

function sdtAddItem(CustomerOrder $order, float $qty = 2): CustomerOrderItem
{
    $items = test()->orders->addItems($order, ['items' => [[
        'product_sku_id' => test()->sku->id,
        'quantity' => $qty,
    ]]]);

    return $items[0]->fresh();
}

/** Mark the order fully paid (addItems recomputed real totals) then close it. */
function sdtClose(CustomerOrder $order): void
{
    $fresh = $order->fresh();
    $fresh->forceFill(['paid_amount' => $fresh->total_amount])->save();
    test()->closing->close($fresh);
}

function sdtMaterialLevel(): float
{
    return (float) StockLevel::where('warehouse_id', test()->warehouse->id)
        ->where('material_id', test()->material->id)
        ->value('quantity');
}

function sdtSalesTxs(CustomerOrder $order)
{
    return StockTransaction::where('reference_type', 'customer_order')
        ->where('reference_id', $order->id)
        ->where('sub_type', 'sales')
        ->with('items')
        ->get();
}

function sdtConsumptionTxs(CustomerOrder $order)
{
    return StockTransaction::where('reference_type', 'customer_order')
        ->where('reference_id', $order->id)
        ->where('sub_type', 'sales_material_consumption')
        ->with('items')
        ->get();
}

// =========================================================================
//  on_close (default) — legacy phase-5 regression pin
// =========================================================================

it('on_close default: nothing is deducted before close and no marker is stamped', function () {
    sdtSetting([]); // stock_deduction_timing defaults to on_close

    $order = sdtOrder();
    $item = sdtAddItem($order, 2);

    expect(StockTransaction::where('reference_id', $order->id)->exists())->toBeFalse();
    expect($item->stock_deducted_at)->toBeNull();
    expect($item->stock_out_transaction_id)->toBeNull();
    expect(sdtMaterialLevel())->toBe(1000.0);
});

it('on_close default: close emits the exact legacy two-phase transaction shape and stamps both markers', function () {
    sdtSetting([]);

    $order = sdtOrder();
    $itemA = sdtAddItem($order, 2);
    // Second SKU line so the phase-1 tx carries two rows like the legacy path.
    $itemB = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $this->sku->id,
        'quantity' => 1,
        'unit_price' => 500,
        'subtotal' => 500,
        'status' => 'served',
        'note' => null,
    ]);

    sdtClose($order);

    // Phase 1 — exactly ONE combined `sales` stock_out, legacy note verbatim
    // (no plan-051 tag on the close sweep), one row per line with its qty.
    $sales = sdtSalesTxs($order);
    expect($sales)->toHaveCount(1);
    $salesTx = $sales->first();
    expect($salesTx->type->value)->toBe('stock_out');
    expect($salesTx->note)->toBe("Auto stock-out for order {$order->order_code}");
    expect($salesTx->items->pluck('quantity')->map(fn ($q) => (float) $q)->sort()->values()->all())->toBe([1.0, 2.0]);
    expect($salesTx->items->pluck('product_sku_id')->unique()->all())->toBe([$this->sku->id]);

    // Phase 2 — exactly ONE aggregated consumption tx, legacy note verbatim,
    // single material row of 3 servings × 10g.
    $consumption = sdtConsumptionTxs($order);
    expect($consumption)->toHaveCount(1);
    $consTx = $consumption->first();
    expect($consTx->note)->toBe("Recipe-based material deduction for order {$order->order_code}");
    expect($consTx->items)->toHaveCount(1);
    expect((float) $consTx->items->first()->quantity)->toBe(30.0);
    expect(sdtMaterialLevel())->toBe(970.0);

    // Legacy per-ORDER marker preserved + new per-LINE markers stamped.
    expect((string) $order->fresh()->stock_out_transaction_id)->toBe((string) $salesTx->id);
    foreach ([$itemA, $itemB] as $line) {
        $fresh = $line->fresh();
        expect($fresh->stock_deducted_at)->not->toBeNull();
        expect((string) $fresh->stock_out_transaction_id)->toBe((string) $salesTx->id);
    }
});

// =========================================================================
//  on_preparing — transition hook + idempotent close
// =========================================================================

it('on_preparing: pending→preparing deducts immediately, stamps the marker, and close never double-deducts', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-28 03:00:00', 'UTC'));
    sdtSetting(['stock_deduction_timing' => 'on_preparing']);

    $order = sdtOrder();
    $item = sdtAddItem($order, 2);
    expect($item->stock_deducted_at)->toBeNull(); // born pending — waits

    $this->orders->updateItem($order->fresh(), $item->id, ['status' => 'preparing']);

    $item = $item->fresh();
    expect($item->stock_deducted_at)->not->toBeNull();
    expect($item->stock_deducted_at->equalTo(Carbon::parse('2026-07-28 03:00:00', 'UTC')))->toBeTrue();
    expect(sdtMaterialLevel())->toBe(980.0);
    $salesBefore = sdtSalesTxs($order)->count();
    $consBefore = sdtConsumptionTxs($order)->count();
    expect($salesBefore)->toBe(1);
    expect($consBefore)->toBe(1);

    // Close — the sweep finds no unmarked line: no new transactions, level unchanged.
    sdtClose($order);
    expect(sdtSalesTxs($order))->toHaveCount($salesBefore);
    expect(sdtConsumptionTxs($order))->toHaveCount($consBefore);
    expect(sdtMaterialLevel())->toBe(980.0);

    Carbon::setTestNow();
});

it('on_preparing: a transition THROUGH preparing (pending→served) also deducts', function () {
    sdtSetting(['stock_deduction_timing' => 'on_preparing']);

    $order = sdtOrder();
    $item = sdtAddItem($order, 1);

    $this->orders->updateItem($order->fresh(), $item->id, ['status' => 'served']);

    expect($item->fresh()->stock_deducted_at)->not->toBeNull();
    expect(sdtMaterialLevel())->toBe(990.0);
});

it('on_preparing: a line BORN served (no-KDS default_order_item_status) deducts at creation', function () {
    sdtSetting([
        'stock_deduction_timing' => 'on_preparing',
        'default_order_item_status' => 'served',
    ]);

    $order = sdtOrder();
    $item = sdtAddItem($order, 2);

    expect($item->status->value)->toBe('served');
    expect($item->stock_deducted_at)->not->toBeNull();
    expect(sdtMaterialLevel())->toBe(980.0);
});

it('on_preparing: a born-pending line does NOT deduct at creation', function () {
    sdtSetting(['stock_deduction_timing' => 'on_preparing']);

    $order = sdtOrder();
    $item = sdtAddItem($order, 2);

    expect($item->stock_deducted_at)->toBeNull();
    expect(sdtMaterialLevel())->toBe(1000.0);
});

// =========================================================================
//  on_add — add hook + delta adjust
// =========================================================================

it('on_add: adding a line deducts immediately with the marker', function () {
    sdtSetting(['stock_deduction_timing' => 'on_add']);

    $order = sdtOrder();
    $item = sdtAddItem($order, 2);

    expect($item->stock_deducted_at)->not->toBeNull();
    expect($item->stock_out_transaction_id)->not->toBeNull();
    expect(sdtMaterialLevel())->toBe(980.0);
});

it('on_add: a merge bump onto a deducted pending line deducts only the delta', function () {
    sdtSetting(['stock_deduction_timing' => 'on_add']);

    $order = sdtOrder();
    $item = sdtAddItem($order, 2);
    expect(sdtMaterialLevel())->toBe(980.0);

    // Same SKU / price / no note → BR-OI06 merges into the same line.
    $merged = sdtAddItem($order->fresh(), 1);

    expect($merged->id)->toBe($item->id);
    expect((float) $merged->quantity)->toBe(3.0);
    expect(sdtMaterialLevel())->toBe(970.0); // only +1 serving deducted
});

it('on_add: revising qty 2→3 deducts delta 1; 3→1 compensates 2', function () {
    sdtSetting(['stock_deduction_timing' => 'on_add']);

    $order = sdtOrder();
    $item = sdtAddItem($order, 2);
    expect(sdtMaterialLevel())->toBe(980.0);

    $this->orders->updateItem($order->fresh(), $item->id, ['quantity' => 3]);
    expect(sdtMaterialLevel())->toBe(970.0);

    $this->orders->updateItem($order->fresh(), $item->id, ['quantity' => 1]);
    expect(sdtMaterialLevel())->toBe(990.0); // net = 1 serving = 10g

    // The compensation is an adjustment_in referencing the order.
    $adjustments = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'adjustment_in')
        ->get();
    expect($adjustments)->toHaveCount(1);
    expect($adjustments->first()->note)->toContain('plan-051 stock compensation');
});

// =========================================================================
//  Mixed timing — mid-day setting flip (marker pin)
// =========================================================================

it('mid-day flip: a line deducted under on_preparing is not double-deducted when close sweeps a later on_close line', function () {
    sdtSetting(['stock_deduction_timing' => 'on_preparing']);

    $order = sdtOrder();
    $itemA = sdtAddItem($order, 2);
    $this->orders->updateItem($order->fresh(), $itemA->id, ['status' => 'preparing']);
    expect(sdtMaterialLevel())->toBe(980.0);

    // Shop flips back to on_close; line B is added afterwards.
    sdtSetting(['stock_deduction_timing' => 'on_close']);
    $itemB = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $this->sku->id,
        'quantity' => 1,
        'unit_price' => 500,
        'subtotal' => 500,
        'status' => 'pending',
        'note' => null,
    ]);

    sdtClose($order);

    // Close deducted ONLY line B: 980 − 10 = 970 (A's 2 servings not repeated).
    expect(sdtMaterialLevel())->toBe(970.0);

    // The sweep's phase-1 tx carries only B's row.
    $sweepTx = sdtSalesTxs($order)->first(fn ($tx) => ! str_contains((string) $tx->note, 'plan051'));
    expect($sweepTx)->not->toBeNull();
    expect($sweepTx->items)->toHaveCount(1);
    expect((float) $sweepTx->items->first()->quantity)->toBe(1.0);
    expect($itemB->fresh()->stock_deducted_at)->not->toBeNull();
});

// =========================================================================
//  Idempotency + event instant
// =========================================================================

it('deductLine is idempotent on replay (marker pin)', function () {
    sdtSetting(['stock_deduction_timing' => 'on_preparing']);

    $order = sdtOrder();
    $item = sdtAddItem($order, 2);

    $service = app(StockDeductionService::class);
    $service->deductLine((string) $item->getKey(), 'on_preparing');
    $service->deductLine((string) $item->getKey(), 'on_preparing'); // replay

    expect(sdtSalesTxs($order))->toHaveCount(1);
    expect(sdtConsumptionTxs($order))->toHaveCount(1);
    expect(sdtMaterialLevel())->toBe(980.0);
});

it('deductLine honours a caller-supplied event instant for stock_deducted_at (#1091 — offline sale time)', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-28 09:00:00', 'UTC')); // "sync arrives next morning"
    sdtSetting(['stock_deduction_timing' => 'on_preparing']);

    $order = sdtOrder();
    $item = sdtAddItem($order, 1);

    $soldAt = Carbon::parse('2026-07-27 20:00:00', 'UTC'); // the REAL sale instant
    app(StockDeductionService::class)->deductLine((string) $item->getKey(), 'on_preparing', $soldAt);

    $fresh = $item->fresh();
    expect($fresh->stock_deducted_at->equalTo($soldAt))->toBeTrue();
    expect($fresh->stock_deducted_at->equalTo(Carbon::parse('2026-07-28 09:00:00', 'UTC')))->toBeFalse();

    Carbon::setTestNow();
});

// =========================================================================
//  #1206 — fail-open contract at close
// =========================================================================

/**
 * Closing an order deducts stock inside its own transaction, wrapped in a
 * try/catch: an inventory failure must not undo a close whose money is already
 * collected. Nothing tested that, so the branch could silently become
 * fail-CLOSED — refusing to close a PAID order because a stock row would not
 * write is far worse than a stock number that needs repairing.
 *
 * The other half — that the lost deduction is then invisible, and unlike the
 * void case cannot be found afterwards (`stock_deducted_at = null` reads the
 * same as "not due to deduct yet") — is #1206's subject and is not fixed here.
 */
it('#1206 keeps a paid order closed when the stock deduction blows up, and logs it', function () {
    sdtSetting([]);

    $order = sdtOrder();
    sdtAddItem($order, 2);

    // Fault injection: the child rows of every stock transaction can no longer
    // be written, so deductStock throws where the catch lives.
    Schema::drop('stock_transaction_items');

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'order-close: stock deduction failed')
            && ($context['order_id'] ?? null) === $order->id);
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    sdtClose($order);

    // The close stands: money already collected is never rolled back over a
    // stock write.
    $fresh = $order->fresh();
    expect($fresh->status->value)->toBe('closed')
        ->and((float) $fresh->paid_amount)->toBe((float) $fresh->total_amount);
});

/**
 * #1206 — the same fail-open contract at ADD time. Deducting on add is wrapped
 * so an inventory failure cannot undo the line the customer just ordered; the
 * order mutation is the thing that must survive.
 *
 * This is the drift direction #1205's repair sweep CANNOT reach: the line ends
 * up with no `stock_deducted_at`, which reads exactly like "not due to deduct
 * yet" under on_close timing, so nothing can tell afterwards that a deduction
 * was lost. Hence #1206 — it has to be recorded when it happens.
 */
it('#1206 keeps the added line when the on_add deduction blows up, and logs it', function () {
    sdtSetting(['stock_deduction_timing' => 'on_add']);

    $order = sdtOrder();

    Schema::drop('stock_transaction_items');

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'add-time stock deduction failed')
            && ($context['order_id'] ?? null) === $order->id);
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $item = sdtAddItem($order, 2);

    // The line stands, unmarked — the customer's order is not rolled back over
    // a stock write, and nothing later can tell this deduction was lost.
    expect($item->exists)->toBeTrue()
        ->and((float) $item->quantity)->toBe(2.0)
        ->and($item->stock_deducted_at)->toBeNull();
});

/**
 * #1206 — and the same at the item-update hook, the third stock funnel. Moving
 * a line to `preparing` under on_preparing timing deducts it; that write is
 * wrapped so the status change the kitchen just made cannot be undone by an
 * inventory failure.
 *
 * Same unrecoverable shape as the add-time case: the line keeps a NULL marker,
 * indistinguishable from "not deducted yet".
 */
it('#1206 keeps the status change when the on_preparing deduction blows up, and logs it', function () {
    sdtSetting(['stock_deduction_timing' => 'on_preparing']);

    $order = sdtOrder();
    $item = sdtAddItem($order, 2);
    expect($item->stock_deducted_at)->toBeNull();

    Schema::drop('stock_transaction_items');

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'item-update stock hook failed')
            && ($context['item_id'] ?? null) === $item->id);
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $this->orders->updateItem($order->fresh(), $item->id, ['status' => 'preparing']);

    // The kitchen's status change stands; only the stock write was lost.
    $fresh = $item->fresh();
    expect($fresh->status->value)->toBe('preparing')
        ->and($fresh->stock_deducted_at)->toBeNull();
});

/**
 * #1283 — ORDER-level voids compensate too.
 *
 * The item-level voids ran the plan-051 truth table per line; the four
 * order-level paths bulk-updated every line in one statement and ran no
 * compensation at all. Invisible under the default `on_close` timing (nothing
 * is deducted before close), but a shop on `on_add`/`on_preparing` lost the
 * material permanently — no log, no audit row, and the #1257 repair sweep could
 * not see it either, because it resolves `void_reason_id` from the line and
 * these paths never wrote one.
 *
 * Both directions are asserted: a `restock` reason returns the material, an
 * absent reason does NOT (unknown reason never restocks — deliberate), and the
 * line is left in a state the sweep can find.
 */
test('#1283 on_add: voiding the whole ORDER with a restock reason returns the material', function () {
    sdtSetting(['stock_deduction_timing' => 'on_add']);

    $before = sdtMaterialLevel();
    $order = sdtOrder();
    $item = sdtAddItem($order, 2);

    expect($item->stock_deducted_at)->not->toBeNull();
    expect(sdtMaterialLevel())->toBeLessThan($before);

    $reason = VoidReason::factory()->create([
        'brand_id' => test()->brand->id,
        'is_active' => true,
        'stock_effect' => 'restock',
        'requires_note' => false,
    ]);

    test()->orders->voidOrder($order->fresh(), [
        'void_reason' => 'customer left',
        'void_reason_id' => $reason->id,
    ]);

    expect(sdtMaterialLevel())->toBe($before);
    expect($item->fresh()->status->value)->toBe('voided');
});

test('#1283 on_add: voiding the whole ORDER without a reason keeps the stock out but leaves a repairable trail', function () {
    sdtSetting(['stock_deduction_timing' => 'on_add']);

    $before = sdtMaterialLevel();
    $order = sdtOrder();
    $item = sdtAddItem($order, 2);
    $deducted = sdtMaterialLevel();

    expect($deducted)->toBeLessThan($before);

    test()->orders->voidOrder($order->fresh(), ['void_reason' => 'customer left']);

    // Unknown reason never restocks — that branch is deliberate.
    expect(sdtMaterialLevel())->toBe($deducted);

    // But the line must be findable by the #1257 repair sweep: voided, with the
    // deduction marker still standing.
    $fresh = $item->fresh();
    expect($fresh->status->value)->toBe('voided')
        ->and($fresh->stock_deducted_at)->not->toBeNull();
});

test('#1283 workstation LAN void marks the ORDER items voided, not just the order', function () {
    sdtSetting(['stock_deduction_timing' => 'on_add']);

    $order = sdtOrder();
    $item = sdtAddItem($order, 2);

    test()->orders->transportWorkstationVoid($order->fresh(), 'voided_by_workstation');

    // Used to leave the line reading `pending` under a Voided order — wrong for
    // anything counting by item status, and invisible to the repair sweep.
    expect($item->fresh()->status->value)->toBe('voided')
        ->and($order->fresh()->status->value)->toBe('voided');
});
