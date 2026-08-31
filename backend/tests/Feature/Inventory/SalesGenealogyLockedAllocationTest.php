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
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\ProductSkuInventoryModeEnum;
use App\Services\Customer\OrderClosingService;
use App\Services\Inventory\RecallService;
use Illuminate\Support\Str;

/**
 * plan-040 C8 — sales-edge genealogy must be recorded from the LOCKED FEFO
 * allocation that actually drained the lot, NOT from a post-depletion preview
 * (which, after the earliest lot is emptied, points at the next FEFO lot).
 *
 * Setup: a track_stock SKU + recipe consuming 100g of a material, two active
 * lots — lot X (expiring sooner, 100g) and lot Y (expiring later, 1000g). An
 * order for 1 unit drains lot X to exactly 0. The genealogy edge must reference
 * lot X (the one consumed), and a recall on X must catch the just-closed order.
 */
function lockedAllocScenario(): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create(['console_organization_id' => $orgId, 'console_brand_id' => $brand->id]);
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        // The track_stock SKU itself has no SKU-grain stock seeded (its inventory
        // of record is the raw material). Allow the Phase-1 SKU stock_out to go
        // negative so the focus stays on the Phase-2 material FEFO consumption.
        'allow_negative_sales' => true,
    ]);
    $material = Material::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);

    // Recipe consumes 100g of the material per output; SKU is track_stock so the
    // sale fires a real `sales_material_consumption` stock_out (locked FEFO).
    $recipe = Recipe::create([
        'sku' => 'R-'.Str::upper(Str::random(8)),
        'name' => 'Locked Alloc Test',
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
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'recipe_id' => $recipe->id,
        'inventory_mode' => ProductSkuInventoryModeEnum::TrackStock->value,
    ]);

    // Lot X expires sooner with EXACTLY 100g (drained to 0 by the sale). Lot Y
    // expires later with surplus — it must NOT be the recorded edge.
    $lotX = MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'material_id' => $material->id,
        'warehouse_id' => $warehouse->id,
        'status' => 'active',
        'unit' => 'g',
        'received_qty' => 100,
        'qty_on_hand' => 100,
        'expiry_date' => now()->addDays(2)->toDateString(),
    ]);
    $lotY = MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'material_id' => $material->id,
        'warehouse_id' => $warehouse->id,
        'status' => 'active',
        'unit' => 'g',
        'received_qty' => 1000,
        'qty_on_hand' => 1000,
        'expiry_date' => now()->addDays(30)->toDateString(),
    ]);
    foreach ([$lotX, $lotY] as $lot) {
        StockLevel::create([
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'material_lot_id' => $lot->id,
            'quantity' => $lot->qty_on_hand,
            'unit' => 'g',
            'alert_enabled' => false,
        ]);
    }

    $order = CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Pending->value,
        'total_amount' => 0,
        'paid_amount' => 0,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'status' => 'served',
    ]);

    return compact('orgId', 'warehouse', 'material', 'lotX', 'lotY', 'order');
}

it('C8: records the sales genealogy edge against the lot the locked FEFO allocation drained, not the next FEFO lot', function () {
    $s = lockedAllocScenario();

    app(OrderClosingService::class)->close($s['order']->fresh());

    $edges = GenealogyLink::where('customer_order_id', $s['order']->id)
        ->where('source_event_type', 'customer_order')
        ->get();

    // Exactly one edge, anchored to lot X (drained to 0) — NOT lot Y.
    expect($edges)->toHaveCount(1)
        ->and((string) $edges->first()->parent_lot_id)->toBe((string) $s['lotX']->id)
        ->and((string) $edges->first()->parent_lot_id)->not->toBe((string) $s['lotY']->id);

    // Lot X really was drained to 0 by the locked consumption.
    expect((float) $s['lotX']->fresh()->qty_on_hand)->toBe(0.0);
});

it('C8: a recall on the drained lot X includes the just-closed order in its blast radius', function () {
    $s = lockedAllocScenario();

    app(OrderClosingService::class)->close($s['order']->fresh());

    $preview = app(RecallService::class)->preview((string) $s['lotX']->id);

    expect($preview['affected_order_ids'])->toContain((string) $s['order']->id);
});
