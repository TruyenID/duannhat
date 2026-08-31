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
 * Plan-022 T8 — sales-edge precise FEFO genealogy.
 *
 * Each scenario is fully self-contained (no shared $this state) because
 * earlier exploration showed Pest's factory `inRandomOrder()->first()`
 * fallbacks pick rows from previous tests in the same run, contaminating
 * recipe / sku setup.
 */
function setupSalesScenario(): array
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
        'output_quantity' => 1,
        'output_unit' => 'g',
        'ingredients' => [
            ['type' => 'material', 'material_id' => $material->id, 'quantity' => 100, 'unit' => 'g'],
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

function activeLot(string $orgId, string $brandId, string $materialId, string $warehouseId, float $qty, string $expiry): MaterialLot
{
    return MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'material_id' => $materialId,
        'warehouse_id' => $warehouseId,
        'status' => 'active',
        'source' => 'inbound',
        'unit' => 'g',
        'received_qty' => $qty,
        'qty_on_hand' => $qty,
        'expiry_date' => $expiry,
    ]);
}

function makeOrderForScenario(array $s, float $qty): CustomerOrder
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

it('records exactly one edge to the FEFO-first lot when qty fits', function () {
    $s = setupSalesScenario();
    $earliest = activeLot($s['orgId'], $s['brand']->id, $s['material']->id, $s['warehouse']->id, 1000, now()->addDays(2)->toDateString());
    activeLot($s['orgId'], $s['brand']->id, $s['material']->id, $s['warehouse']->id, 1000, now()->addDays(10)->toDateString());
    activeLot($s['orgId'], $s['brand']->id, $s['material']->id, $s['warehouse']->id, 1000, now()->addDays(20)->toDateString());

    $order = makeOrderForScenario($s, 1);

    app(OrderClosingService::class)->recordSalesGenealogy($order, (string) Str::uuid());

    $edges = GenealogyLink::query()
        ->where('customer_order_id', $order->id)
        ->where('source_event_type', 'customer_order')
        ->get();

    expect($edges)->toHaveCount(1)
        ->and((string) $edges->first()->parent_lot_id)->toEqual($earliest->id);
});

it('does not record the unused later-expiry lots (recall blast radius bound)', function () {
    $s = setupSalesScenario();
    activeLot($s['orgId'], $s['brand']->id, $s['material']->id, $s['warehouse']->id, 1000, now()->addDays(2)->toDateString());
    $unused = activeLot($s['orgId'], $s['brand']->id, $s['material']->id, $s['warehouse']->id, 1000, now()->addDays(20)->toDateString());

    $order = makeOrderForScenario($s, 1);

    app(OrderClosingService::class)->recordSalesGenealogy($order, (string) Str::uuid());

    $edges = GenealogyLink::query()
        ->where('customer_order_id', $order->id)
        ->get();

    $touchedUnused = $edges->contains(fn ($e) => (string) $e->parent_lot_id === $unused->id);

    expect($touchedUnused)->toBeFalse();
});
