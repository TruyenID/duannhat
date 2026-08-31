<?php

/**
 * Plan-024 follow-up — NOTES.md option 1 (pre-made SKU double-deduct guard).
 *
 * When a `track_stock` SKU has on-hand stock at sale time we assume the SKU
 * was produced by a ProductionBatch upstream (which already committed the
 * material) and skip the recipe walk to avoid double-deducting. The check
 * runs after Phase 1's stock-out submit, so we reconstruct pre-sale qty as
 * `post_sale + sold_in_this_order`.
 *
 * Covers the four scenarios called out in NOTES.md:62:
 *   (a) pre-made SKU with on-hand → recipe skipped
 *   (b) made-to-order SKU with 0 on-hand → recipe still deducts
 *   (c) mixed order (one of each kind) → only the on-hand line skips recipe
 *   (d) on-hand=0 due to allow-negative-sales prior drain → recipe still deducts
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Material;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\OrderClosingService;
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

    $this->closingService = app(OrderClosingService::class);
});

function mkMaterial(string $orgId, string $brandId, string $warehouseId, float $stockQty = 1000): Material
{
    $material = Material::factory()->create(['organization_id' => $orgId, 'brand_id' => $brandId]);
    StockLevel::create([
        'warehouse_id' => $warehouseId,
        'material_id' => $material->id,
        'quantity' => $stockQty,
        'unit' => 'g',
        'alert_enabled' => false,
    ]);

    return $material;
}

function mkRecipe(string $orgId, string $brandId, string $materialId): Recipe
{
    return Recipe::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 1,
        'output_unit' => 'serving',
        'ingredients' => [
            ['material_id' => $materialId, 'quantity' => 10, 'unit' => 'g'],
        ],
    ]);
}

function mkPendingOrder(string $orgId, string $branchId, string $userId): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branchId,
        'status' => CustomerOrderStatusEnum::Pending->value,
        'created_by_id' => $userId,
        'total_amount' => 0,
        'paid_amount' => 0,
    ]);
}

it('(a) skips recipe deduction when SKU was pre-made (on-hand stock at sale time)', function () {
    $rice = mkMaterial($this->orgId, $this->brand->id, $this->warehouse->id);
    $recipe = mkRecipe($this->orgId, $this->brand->id, $rice->id);
    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);
    // Pre-made via batch: 10 on hand.
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 10,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $order = mkPendingOrder($this->orgId, $this->branch->id, $this->user->id);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    // No sales_material_consumption transaction — guard skipped the recipe.
    $materialTxn = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales_material_consumption')
        ->first();
    expect($materialTxn)->toBeNull();

    // Rice stock unchanged.
    $riceLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $rice->id)
        ->first();
    expect((float) $riceLevel->quantity)->toBe(1000.0);
});

it('(b) still deducts recipe for made-to-order SKU with no StockLevel row', function () {
    $rice = mkMaterial($this->orgId, $this->brand->id, $this->warehouse->id);
    $recipe = mkRecipe($this->orgId, $this->brand->id, $rice->id);
    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);
    // No StockLevel row — pure made-to-order shape.

    $order = mkPendingOrder($this->orgId, $this->branch->id, $this->user->id);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    $materialTxn = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales_material_consumption')
        ->first();
    expect($materialTxn)->not->toBeNull();

    $riceLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $rice->id)
        ->first();
    expect((float) $riceLevel->quantity)->toBe(990.0);
});

it('(c) mixed order — skips recipe only for the pre-made line', function () {
    $rice = mkMaterial($this->orgId, $this->brand->id, $this->warehouse->id);
    $recipe = mkRecipe($this->orgId, $this->brand->id, $rice->id);

    $preMadeSku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $preMadeSku->id,
        'quantity' => 5,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $madeToOrderSku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);
    // No StockLevel row for the made-to-order one.

    $order = mkPendingOrder($this->orgId, $this->branch->id, $this->user->id);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $preMadeSku->id,
        'quantity' => 1,
        'status' => 'served',
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $madeToOrderSku->id,
        'quantity' => 1,
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    // One material transaction, covering only the made-to-order line.
    $materialTxn = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales_material_consumption')
        ->first();
    expect($materialTxn)->not->toBeNull();

    $riceLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $rice->id)
        ->first();
    // Only 10g for the made-to-order line, NOT 20g for both.
    expect((float) $riceLevel->quantity)->toBe(990.0);
});

it('(d) still deducts when post-sale is negative due to allow-negative-sales drain', function () {
    $rice = mkMaterial($this->orgId, $this->brand->id, $this->warehouse->id);
    $recipe = mkRecipe($this->orgId, $this->brand->id, $rice->id);
    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);
    // Existing StockLevel row at 0 (was drained earlier under allow-negative).
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $order = mkPendingOrder($this->orgId, $this->branch->id, $this->user->id);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    $materialTxn = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales_material_consumption')
        ->first();
    expect($materialTxn)->not->toBeNull();

    $riceLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $rice->id)
        ->first();
    expect((float) $riceLevel->quantity)->toBe(990.0);
});
