<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Models\Warehouse;
use App\Services\Customer\OrderClosingService;
use Illuminate\Support\Str;

/**
 * Plan-022 T8.1 (raw-sold Path B) — genuinely-missing edge/failure scenarios
 * that the shipped SalesEdgeGenealogyTest (2 happy-path cases) did not cover:
 * multi-lot FEFO split, recipe output_quantity scaling, idempotent retry,
 * voided-item skip, warehouse isolation, and recall blast-radius bounding on
 * a non-first FEFO lot.
 *
 * Fully self-contained per case (no shared $this) — mirrors the existing
 * file's note that factory `inRandomOrder()->first()` fallbacks contaminate
 * recipe / sku setup across a suite run.
 */
function multiLotSalesScenario(int $ingredientQty = 100, float $recipeOutputQty = 1): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create(['console_organization_id' => $orgId, 'console_brand_id' => $brand->id]);
    $warehouse = Warehouse::factory()->create(['organization_id' => $orgId, 'branch_id' => $branch->id, 'is_active' => true]);
    $material = Material::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);

    $recipe = Recipe::create([
        'sku' => 'R-'.Str::upper(Str::random(8)),
        'name' => 'Test',
        'material_id' => $material->id,
        'output_quantity' => $recipeOutputQty,
        'output_unit' => 'g',
        'ingredients' => [
            ['type' => 'material', 'material_id' => $material->id, 'quantity' => $ingredientQty, 'unit' => 'g'],
        ],
        'is_active' => true,
        'approval_status' => 'approved',
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
    ]);

    $product = Product::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'recipe_id' => $recipe->id]);

    return compact('orgId', 'brand', 'branch', 'warehouse', 'material', 'recipe', 'sku');
}

function multiLotActiveLot(array $s, float $qty, string $expiry, ?string $warehouseId = null): MaterialLot
{
    return MaterialLot::factory()->create([
        'organization_id' => $s['orgId'],
        'brand_id' => $s['brand']->id,
        'material_id' => $s['material']->id,
        'warehouse_id' => $warehouseId ?? $s['warehouse']->id,
        'status' => 'active',
        'source' => 'inbound',
        'unit' => 'g',
        'received_qty' => $qty,
        'qty_on_hand' => $qty,
        'expiry_date' => $expiry,
    ]);
}

function multiLotOrder(array $s, float $qty): CustomerOrder
{
    $order = CustomerOrder::factory()->create([
        'organization_id' => $s['orgId'],
        'branch_id' => $s['branch']->id,
        'status' => 'paying',
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $s['sku']->id,
        'quantity' => $qty,
        'status' => 'served',
    ]);

    return $order->fresh()->load('items.productSku.recipe');
}

it('splits a single-ingredient demand across two FEFO lots — one edge per consumed lot', function () {
    // Recipe needs 100g per unit, order qty=8 → 800g. L1=500g (soonest), L2=500g.
    $s = multiLotSalesScenario(ingredientQty: 100, recipeOutputQty: 1);
    $l1 = multiLotActiveLot($s, 500, now()->addDays(2)->toDateString());
    $l2 = multiLotActiveLot($s, 500, now()->addDays(10)->toDateString());

    $order = multiLotOrder($s, 8);
    app(OrderClosingService::class)->recordSalesGenealogy($order, (string) Str::uuid());

    $edges = GenealogyLink::where('customer_order_id', $order->id)
        ->where('source_event_type', 'customer_order')
        ->get()
        ->keyBy(fn ($e) => (string) $e->parent_lot_id);

    expect($edges)->toHaveCount(2)
        ->and($edges)->toHaveKey((string) $l1->id)
        ->and($edges)->toHaveKey((string) $l2->id);

    // FEFO drains the earlier lot fully (500g) then takes the remainder (300g).
    expect((float) $edges[(string) $l1->id]->qty_consumed)->toBe(500.0)
        ->and((float) $edges[(string) $l2->id]->qty_consumed)->toBe(300.0);

    // Conservation: exactly the recipe-scaled demand, no more.
    expect($edges->sum(fn ($e) => (float) $e->qty_consumed))->toBe(800.0);
});

it('scales consumed qty by recipe output_quantity', function () {
    // output_quantity=10 → each sold unit consumes 100/10=10g. qty=3 → 30g.
    $s = multiLotSalesScenario(ingredientQty: 100, recipeOutputQty: 10);
    $l1 = multiLotActiveLot($s, 1000, now()->addDays(2)->toDateString());

    $order = multiLotOrder($s, 3);
    app(OrderClosingService::class)->recordSalesGenealogy($order, (string) Str::uuid());

    $edges = GenealogyLink::where('customer_order_id', $order->id)->get();

    expect($edges)->toHaveCount(1)
        ->and((string) $edges->first()->parent_lot_id)->toEqual($l1->id)
        ->and((float) $edges->first()->qty_consumed)->toBe(30.0);
});

it('is idempotent when replayed with the same transaction id', function () {
    $s = multiLotSalesScenario();
    multiLotActiveLot($s, 1000, now()->addDays(2)->toDateString());

    $order = multiLotOrder($s, 1);
    $txnId = (string) Str::uuid();

    app(OrderClosingService::class)->recordSalesGenealogy($order, $txnId);
    app(OrderClosingService::class)->recordSalesGenealogy($order, $txnId);

    expect(GenealogyLink::where('customer_order_id', $order->id)->count())->toBe(1);
});

it('records no edges for a voided order item', function () {
    $s = multiLotSalesScenario();
    multiLotActiveLot($s, 1000, now()->addDays(2)->toDateString());

    $order = CustomerOrder::factory()->create([
        'organization_id' => $s['orgId'],
        'branch_id' => $s['branch']->id,
        'status' => 'paying',
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $s['sku']->id,
        'quantity' => 1,
        'status' => 'voided',
    ]);
    $order = $order->fresh()->load('items.productSku.recipe');

    app(OrderClosingService::class)->recordSalesGenealogy($order, (string) Str::uuid());

    expect(GenealogyLink::where('customer_order_id', $order->id)->count())->toBe(0);
});

it('never picks a lot from a different warehouse (isolation)', function () {
    $s = multiLotSalesScenario();
    $otherWarehouse = Warehouse::factory()->create([
        'organization_id' => $s['orgId'],
        'branch_id' => $s['branch']->id,
        'is_active' => true,
    ]);
    // Earlier-expiring lot lives in the WRONG warehouse — must be ignored.
    $wrong = multiLotActiveLot($s, 1000, now()->addDay()->toDateString(), $otherWarehouse->id);
    $right = multiLotActiveLot($s, 1000, now()->addDays(10)->toDateString());

    $order = multiLotOrder($s, 1);
    app(OrderClosingService::class)->recordSalesGenealogy($order, (string) Str::uuid());

    $edges = GenealogyLink::where('customer_order_id', $order->id)->get();

    expect($edges)->toHaveCount(1)
        ->and((string) $edges->first()->parent_lot_id)->toEqual($right->id)
        ->and($edges->contains(fn ($e) => (string) $e->parent_lot_id === (string) $wrong->id))->toBeFalse();
});

it('bounds recall blast radius to the FEFO-first lot when demand fits one lot', function () {
    // Three lots, demand fits the earliest — recall on the 3rd must not flag the order.
    $s = multiLotSalesScenario(ingredientQty: 100, recipeOutputQty: 1);
    multiLotActiveLot($s, 1000, now()->addDays(2)->toDateString());
    multiLotActiveLot($s, 1000, now()->addDays(10)->toDateString());
    $third = multiLotActiveLot($s, 1000, now()->addDays(30)->toDateString());

    $order = multiLotOrder($s, 1); // 100g < 1000g → single edge to earliest
    app(OrderClosingService::class)->recordSalesGenealogy($order, (string) Str::uuid());

    $touchedThird = GenealogyLink::where('customer_order_id', $order->id)
        ->where('parent_lot_id', $third->id)
        ->exists();

    expect($touchedThird)->toBeFalse();
});
