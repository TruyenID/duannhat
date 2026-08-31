<?php

/**
 * #857 — applyPricing() must price the lines exactly as they are in the DB
 * *now*, not a stale in-memory `items` collection.
 *
 * Every mutating caller (voidItem / updateItem / addItems / workstation sync)
 * edits a row through a separately-fetched model. If `$order->items` was
 * hydrated before that mutation, the old `loadMissing('items')` re-priced the
 * PRE-mutation cart (voided line still counted, added line missing, resized
 * line at its old quantity) — every money field drifted, and a coupon's
 * min-spend recheck (#550) never fired. The fix is `load('items')`.
 *
 * Each test below deliberately HYDRATES `$order->items` before the mutation so
 * the stale snapshot exists to be (mis)priced. Every item carries a non-null
 * `tax_rate`, otherwise applyPricing's lazy re-stamp branch reloads the
 * collection itself and masks the bug. Verified RED against the pre-fix
 * (loadMissing + band-aid removed) code and GREEN with the fix.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Services\Customer\CustomerOrderService;
use App\Services\Order\Coupon\OrderCouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

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

    $this->orgId = $orgId;
    $this->couponService = app(OrderCouponService::class);
    $this->orderService = app(CustomerOrderService::class);
});

/**
 * Build an open order with one line per spec. Each spec is
 * [unit_price, tax_rate(%), quantity]; tax_rate defaults to 0 (non-null so the
 * lazy re-stamp branch stays dormant), quantity to 1. The order subtotal/total
 * are pre-set to the raw line sum so pre-mutation state is a clean baseline.
 *
 * @param  array<int, array{0: float, 1?: float, 2?: int}>  $lines
 * @return array{0: CustomerOrder, 1: array<int, CustomerOrderItem>}
 */
function makePricedOrder(array $lines, string $orgId, string $brandId, string $branchId, array $orderOverrides = []): array
{
    $order = CustomerOrder::factory()->create(array_merge([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'branch_id' => $branchId,
        'status' => 'open',
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
    ], $orderOverrides));

    $items = [];
    $rawSubtotal = 0.0;
    foreach ($lines as $line) {
        $unitPrice = (float) $line[0];
        $taxRate = (float) ($line[1] ?? 0);
        $quantity = (int) ($line[2] ?? 1);
        $lineSubtotal = $quantity * $unitPrice;
        $rawSubtotal += $lineSubtotal;

        $items[] = CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $lineSubtotal,
            'topping_subtotal' => 0,
            'status' => 'pending',
            'tax_rate' => $taxRate,
        ]);
    }

    $order->update(['subtotal' => $rawSubtotal, 'total_amount' => $rawSubtotal]);
    $order->refresh();

    return [$order, $items];
}

/** Force the `items` relation to hydrate on THIS instance (the stale snapshot). */
function hydrateItems(CustomerOrder $order): void
{
    $order->load('items');
    $order->items->count();
}

it('prices only the surviving lines when a line is voided on a hydrated order (no coupon)', function () {
    // Two 100,000 lines at 0% tax, no coupon.
    [$order, $items] = makePricedOrder([[100000], [100000]], $this->orgId, $this->brand->id, $this->branch->id);

    hydrateItems($order);

    $this->orderService->voidItem($order, $items[0]->id, ['void_reason' => 'trim']);
    $order->refresh();

    // Pre-fix (loadMissing) would keep the voided line → subtotal 200,000.
    expect((float) $order->subtotal)->toBe(100000.0);
    expect((float) $order->tax_amount)->toBe(0.0);
    expect((float) $order->total_amount)->toBe(100000.0);
});

it('includes a newly inserted line when repricing a hydrated order (workstation-style raw insert)', function () {
    // One 100,000 line; hydrate; then insert a second row directly (mirrors the
    // workstation batch sync: $item->save() on a fresh model, then
    // refreshOrderTotals) and reprice.
    [$order, $items] = makePricedOrder([[100000]], $this->orgId, $this->brand->id, $this->branch->id);

    hydrateItems($order);

    CustomerOrderItem::create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $items[0]->product_sku_id,
        'quantity' => 1,
        'unit_price' => 60000,
        'original_unit_price' => 60000,
        'subtotal' => 60000,
        'topping_subtotal' => 0,
        'status' => 'pending',
        'tax_rate' => 0,
    ]);

    // The stale snapshot on $order still holds ONLY the first line.
    $this->orderService->refreshOrderTotals($order);
    $order->refresh();

    // Pre-fix (loadMissing) would price the stale 1-line cart → 100,000.
    expect((float) $order->subtotal)->toBe(160000.0);
    expect((float) $order->total_amount)->toBe(160000.0);
});

it('recomputes a percent coupon against the live subtotal when a hydrated line shrinks in quantity', function () {
    $coupon = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'PCT20QTY',
        'discount_type' => 'percent',
        'discount_value' => 20,
        'max_discount_cap' => null,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    // One line, qty 2 @ 100,000 = 200,000 → 20% = 40,000.
    [$order, $items] = makePricedOrder([[100000, 0, 2]], $this->orgId, $this->brand->id, $this->branch->id);

    $this->couponService->apply($order, $coupon->code);
    $order->refresh();
    expect((float) $order->discount_amount)->toBe(40000.0);

    hydrateItems($order);

    // Drop qty 2 → 1: live subtotal 100,000 → 20% should be 20,000.
    $this->orderService->updateItem($order, $items[0]->id, ['quantity' => 1]);
    $order->refresh();

    // Pre-fix (loadMissing) would keep qty-2 subtotal → discount 40,000, total 160,000.
    expect((float) $order->subtotal)->toBe(100000.0);
    expect((float) $order->discount_amount)->toBe(20000.0);
    expect((float) $order->total_amount)->toBe(80000.0);
});

it('recomputes per-rate tax from the live lines when a multi-rate cart is trimmed', function () {
    // 10% line + 8% line, tax-excluded, VND (no ShopOrderSetting → sc 0, excluded).
    [$order, $items] = makePricedOrder(
        [[100000, 10], [100000, 8]],
        $this->orgId,
        $this->brand->id,
        $this->branch->id,
    );

    // Price the full cart first: subtotal 200,000, tax 10,000 + 8,000 = 18,000.
    $this->orderService->refreshOrderTotals($order);
    $order->refresh();
    expect((float) $order->tax_amount)->toBe(18000.0);
    expect((float) $order->total_amount)->toBe(218000.0);

    hydrateItems($order);

    // Void the 10% line → only the 8% group survives.
    $this->orderService->voidItem($order, $items[0]->id, ['void_reason' => 'trim']);
    $order->refresh();

    expect((float) $order->subtotal)->toBe(100000.0);
    expect((float) $order->tax_amount)->toBe(8000.0);       // only the 8% group
    expect((float) $order->total_amount)->toBe(108000.0);

    // Per-line snapshot of the survivor equals its group tax (Σ line == group).
    $survivor = $items[1]->fresh();
    expect((float) $survivor->tax_amount)->toBe(8000.0);
});

it('stays correct across a compound add-then-void-then-update sequence on one long-lived hydrated order', function () {
    // The real POS flow: mutate repeatedly without ever re-fetching the order.
    [$order, $items] = makePricedOrder([[100000], [50000]], $this->orgId, $this->brand->id, $this->branch->id);

    hydrateItems($order);

    // (1) add a 30,000 line
    CustomerOrderItem::create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $items[0]->product_sku_id,
        'quantity' => 1,
        'unit_price' => 30000,
        'original_unit_price' => 30000,
        'subtotal' => 30000,
        'topping_subtotal' => 0,
        'status' => 'pending',
        'tax_rate' => 0,
    ]);
    $this->orderService->refreshOrderTotals($order);
    $order->refresh();
    expect((float) $order->subtotal)->toBe(180000.0); // 100k + 50k + 30k

    // (2) void the 50,000 line
    $this->orderService->voidItem($order, $items[1]->id, ['void_reason' => 'trim']);
    $order->refresh();
    expect((float) $order->subtotal)->toBe(130000.0); // 100k + 30k

    // (3) bump the 100,000 line to qty 2
    $this->orderService->updateItem($order, $items[0]->id, ['quantity' => 2]);
    $order->refresh();
    expect((float) $order->subtotal)->toBe(230000.0); // 200k + 30k
    expect((float) $order->total_amount)->toBe(230000.0);
});

it('reloads the items collection with exactly one query (perf regression guard)', function () {
    // #857 merge-gate — the fix reloads once via load('items'); a revert to
    // loadMissing on a pre-hydrated order drops this to 0 (stale), and an N+1
    // in the pricing calculator pushes it above 1.
    [$order] = makePricedOrder([[100000], [50000]], $this->orgId, $this->brand->id, $this->branch->id);

    // Pre-hydrate the stale snapshot, then measure a bare recompute (no voidItem
    // whose own firstOrFail/count would confound the item-table SELECT count).
    hydrateItems($order);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->orderService->refreshOrderTotals($order);

    $itemSelects = collect(DB::getQueryLog())
        ->filter(fn ($q) => str_contains(strtolower($q['query']), 'select')
            && str_contains($q['query'], 'customer_order_items'))
        ->count();

    DB::disableQueryLog();

    expect($itemSelects)->toBe(1);
});
