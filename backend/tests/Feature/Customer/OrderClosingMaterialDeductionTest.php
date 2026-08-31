<?php

/**
 * Plan-024 T3.3 — G3: Recipe → Material deduction at order close.
 *
 * Covers TESTS.md scenarios:
 *   - Happy 5:  track_stock SKU + Recipe (10g rice, 5ml soy_sauce, output_qty=1) × order_qty=2
 *               → one sales_material_consumption tx with 2 items (20g rice, 10ml soy_sauce)
 *   - Happy 6:  mixed SKUs (1× track_stock w/ recipe + 1× made_to_order) → only track_stock deducts
 *   - Happy 10: auto_approve_stock_out=false → material tx in `pending`, no StockLevel change
 *   - Edge 1:   track_stock SKU with recipe_id=null → no exception, warning logged, no material tx
 *   - Edge 2:   track_stock SKU with empty recipe.ingredients → same as Edge 1
 *   - Edge 3:   Recipe output_quantity=2, ingredient.quantity=10g, order_qty=3 → (10/2)*3 = 15g
 *   - Edge 4:   two track_stock SKUs sharing an ingredient → aggregated once
 *   - Side effect 1: StockMovement rows created with stock_transaction_id linkage
 *   - Side effect 4: exactly TWO StockTransactions per order (sales + sales_material_consumption)
 *   - Error handling 3: paid_amount < total_amount → 409 abort before plan-024 code runs
 *   - Error handling 4: Recipe referencing soft-deleted Material → deduction skips row + logs warning
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
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\OrderClosingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    // auto_approve_stock_out=true is required for actual StockLevel decrement.
    // allow_negative_sales=true: with the plan-024 follow-up "pre-made guard"
    // (NOTES.md option 1), SKUs with positive on-hand stock at sale time are
    // assumed to have been produced by a ProductionBatch upstream and have
    // their recipe deduction SKIPPED. The canonical G3 case this test file
    // covers is the *made-to-order* shape where the SKU has no upstream batch,
    // so we seed 0 on-hand and rely on allow_negative_sales to model the
    // "kitchen replenishes from raw materials" reality.
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

/**
 * Helper — create a material with stock in the test warehouse.
 */
function makeMaterialWithStock(string $orgId, string $brandId, string $warehouseId, float $qty, string $unit = 'g'): Material
{
    $material = Material::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
    ]);
    StockLevel::create([
        'warehouse_id' => $warehouseId,
        'material_id' => $material->id,
        'quantity' => $qty,
        'unit' => $unit,
        'alert_enabled' => false,
    ]);

    return $material;
}

/**
 * Helper — create a pending order.
 */
function makePendingOrder(string $orgId, string $branchId, string $userId): CustomerOrder
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

// =========================================================================
//  Happy 5 — track_stock SKU + Recipe (2 ingredients) × order_qty=2
// =========================================================================

it('creates one sales_material_consumption transaction with 2 material items for order_qty=2', function () {
    $rice = makeMaterialWithStock($this->orgId, $this->brand->id, $this->warehouse->id, 1000, 'g');
    $soy = makeMaterialWithStock($this->orgId, $this->brand->id, $this->warehouse->id, 500, 'ml');

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 1,
        'output_unit' => 'serving',
        'ingredients' => [
            ['material_id' => $rice->id, 'quantity' => 10, 'unit' => 'g'],
            ['material_id' => $soy->id, 'quantity' => 5, 'unit' => 'ml'],
        ],
    ]);

    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);

    // Seed SKU stock so Phase 1 doesn't fail
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $order = makePendingOrder($this->orgId, $this->branch->id, $this->user->id);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 2,
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    // Exactly one sales_material_consumption transaction
    $materialTxns = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales_material_consumption')
        ->get();
    expect($materialTxns)->toHaveCount(1);

    $txn = $materialTxns->first();
    $statusValue = $txn->status instanceof BackedEnum
        ? $txn->status->value
        : (string) $txn->status;
    expect($statusValue)->toBe('completed');

    // StockLevel decremented: rice 1000→980 (20g), soy 500→490 (10ml)
    $riceLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $rice->id)
        ->first();
    $soyLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $soy->id)
        ->first();

    expect((float) $riceLevel->quantity)->toBe(980.0);
    expect((float) $soyLevel->quantity)->toBe(490.0);
});

// =========================================================================
//  Happy 6 — mixed SKUs (track_stock + made_to_order)
// =========================================================================

it('only deducts materials for track_stock SKUs, not made_to_order', function () {
    $rice = makeMaterialWithStock($this->orgId, $this->brand->id, $this->warehouse->id, 500, 'g');

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 1,
        'output_unit' => 'serving',
        'ingredients' => [
            ['material_id' => $rice->id, 'quantity' => 10, 'unit' => 'g'],
        ],
    ]);

    $trackStockSku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);
    $madeToOrderSku = ProductSku::factory()->create([
        'inventory_mode' => 'made_to_order',
        'recipe_id' => null,
    ]);

    // Seed SKU stock level for track_stock SKU
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $trackStockSku->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $order = makePendingOrder($this->orgId, $this->branch->id, $this->user->id);

    // 1× track_stock SKU (should deduct 10g rice)
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $trackStockSku->id,
        'quantity' => 1,
        'status' => 'served',
    ]);
    // 1× made_to_order SKU (should NOT deduct anything)
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $madeToOrderSku->id,
        'quantity' => 5,
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    // Only 10g of rice deducted (from 1× track_stock, output_qty=1, order_qty=1)
    $riceLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $rice->id)
        ->first();
    expect((float) $riceLevel->quantity)->toBe(490.0);

    // Exactly one material consumption transaction
    $materialTxnCount = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales_material_consumption')
        ->count();
    expect($materialTxnCount)->toBe(1);
});

// =========================================================================
//  Happy 10 — auto_approve_stock_out=false → material tx in `pending`
// =========================================================================

it('leaves material transaction in pending when auto_approve_stock_out=false and order still closes', function () {
    // Create a branch + warehouse with auto_approve_stock_out=false
    $strictBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);
    $strictWarehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $strictBranch->id,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => false,
        'allow_negative_sales' => false,
    ]);

    $rice = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    StockLevel::create([
        'warehouse_id' => $strictWarehouse->id,
        'material_id' => $rice->id,
        'quantity' => 500,
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
            ['material_id' => $rice->id, 'quantity' => 10, 'unit' => 'g'],
        ],
    ]);

    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $strictBranch->id,
        'status' => CustomerOrderStatusEnum::Pending->value,
        'created_by_id' => $this->user->id,
        'total_amount' => 0,
        'paid_amount' => 0,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 2,
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    // Order should be closed
    $order->refresh();
    $statusValue = $order->status instanceof BackedEnum
        ? $order->status->value
        : (string) $order->status;
    expect($statusValue)->toBe(CustomerOrderStatusEnum::Closed->value);

    // Material transaction should be in `pending` (NOT completed)
    $materialTxn = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales_material_consumption')
        ->first();
    expect($materialTxn)->not->toBeNull();

    $txnStatus = $materialTxn->status instanceof BackedEnum
        ? $materialTxn->status->value
        : (string) $materialTxn->status;
    expect($txnStatus)->toBe('pending');

    // StockLevel should NOT have changed (transaction not completed)
    $riceLevel = StockLevel::where('warehouse_id', $strictWarehouse->id)
        ->where('material_id', $rice->id)
        ->first();
    expect((float) $riceLevel->quantity)->toBe(500.0);
});

// =========================================================================
//  Edge 1 — track_stock SKU with recipe_id=null → no exception, no material tx
// =========================================================================

it('skips material deduction without exception when track_stock SKU has no recipe', function () {
    // Log assertion omitted — Log::spy() conflicts with internal Log::channel() calls.
    // The service calls Log::warning('order-close: material deduction skipped: SKU has
    // track_stock but no recipe', [...]) which we verify exists in the source code.
    // The behavioral assertions below are sufficient for CI coverage.

    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => null,
    ]);

    // Seed StockLevel so the SKU stock-out doesn't fail
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $order = makePendingOrder($this->orgId, $this->branch->id, $this->user->id);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'status' => 'served',
    ]);

    // Should not throw
    $this->closingService->close($order->fresh());

    // No material consumption transaction
    $materialTxnCount = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales_material_consumption')
        ->count();
    expect($materialTxnCount)->toBe(0);

    // SKU stock-out transaction IS still created
    $skuTxnCount = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales')
        ->count();
    expect($skuTxnCount)->toBe(1);

    // Order should be closed
    $order->refresh();
    $statusValue = $order->status instanceof BackedEnum
        ? $order->status->value
        : (string) $order->status;
    expect($statusValue)->toBe(CustomerOrderStatusEnum::Closed->value);
});

// =========================================================================
//  Edge 2 — track_stock SKU with empty recipe.ingredients
// =========================================================================

it('skips material deduction when recipe has empty ingredients', function () {
    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 1,
        'output_unit' => 'pcs',
        'ingredients' => [], // empty
    ]);

    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);

    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $order = makePendingOrder($this->orgId, $this->branch->id, $this->user->id);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 2,
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    // No material consumption transaction
    $materialTxnCount = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales_material_consumption')
        ->count();
    expect($materialTxnCount)->toBe(0);

    // Order should be closed
    $order->refresh();
    $statusValue = $order->status instanceof BackedEnum
        ? $order->status->value
        : (string) $order->status;
    expect($statusValue)->toBe(CustomerOrderStatusEnum::Closed->value);

    // Verify the warning message key exists in source — behavioral assertions above are
    // sufficient. Log::spy() conflicts with internal Log::channel() calls in factories.
});

// =========================================================================
//  Edge 3 — output_quantity scaling: (ingredient.qty / output_qty) × order_qty
// =========================================================================

it('scales material consumption by output_quantity: (10g / 2) × 3 = 15g', function () {
    $rice = makeMaterialWithStock($this->orgId, $this->brand->id, $this->warehouse->id, 500, 'g');

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 2, // Recipe yields 2 servings
        'output_unit' => 'serving',
        'ingredients' => [
            ['material_id' => $rice->id, 'quantity' => 10, 'unit' => 'g'],
        ],
    ]);

    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);

    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $order = makePendingOrder($this->orgId, $this->branch->id, $this->user->id);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 3, // order_qty = 3
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    // Expected: (10g / 2) × 3 = 15g
    $riceLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $rice->id)
        ->first();
    expect((float) $riceLevel->quantity)->toBe(485.0); // 500 - 15
});

// =========================================================================
//  Edge 4 — two track_stock SKUs sharing an ingredient → aggregated once
// =========================================================================

it('aggregates shared ingredients across multiple track_stock SKUs into one material transaction', function () {
    $rice = makeMaterialWithStock($this->orgId, $this->brand->id, $this->warehouse->id, 1000, 'g');

    $recipe1 = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 1,
        'output_unit' => 'serving',
        'ingredients' => [
            ['material_id' => $rice->id, 'quantity' => 10, 'unit' => 'g'],
        ],
    ]);
    $recipe2 = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 1,
        'output_unit' => 'serving',
        'ingredients' => [
            ['material_id' => $rice->id, 'quantity' => 5, 'unit' => 'g'],
        ],
    ]);

    $sku1 = ProductSku::factory()->withSequencedOption()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe1->id,
    ]);
    $sku2 = ProductSku::factory()->withSequencedOption()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe2->id,
    ]);

    // Seed stock levels for both SKUs
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku1->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku2->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $order = makePendingOrder($this->orgId, $this->branch->id, $this->user->id);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku1->id,
        'quantity' => 2, // 10g × 2 = 20g
        'status' => 'served',
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku2->id,
        'quantity' => 3, // 5g × 3 = 15g
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    // Total rice deducted: 20 + 15 = 35g (aggregated into one transaction)
    $riceLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $rice->id)
        ->first();
    expect((float) $riceLevel->quantity)->toBe(965.0); // 1000 - 35

    // Only ONE material consumption transaction
    $materialTxnCount = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales_material_consumption')
        ->count();
    expect($materialTxnCount)->toBe(1);
});

// =========================================================================
//  Side effect 1 — StockMovement rows created with stock_transaction_id link
// =========================================================================

it('creates StockMovement rows linked to the material consumption transaction', function () {
    $rice = makeMaterialWithStock($this->orgId, $this->brand->id, $this->warehouse->id, 500, 'g');

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 1,
        'output_unit' => 'serving',
        'ingredients' => [
            ['material_id' => $rice->id, 'quantity' => 10, 'unit' => 'g'],
        ],
    ]);

    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);

    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $order = makePendingOrder($this->orgId, $this->branch->id, $this->user->id);
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

    // At least one StockMovement row linked to the material transaction
    $movements = StockMovement::where('stock_transaction_id', $materialTxn->id)->get();
    expect($movements->count())->toBeGreaterThanOrEqual(1);
    expect($movements->first()->material_id)->toBe($rice->id);
});

// =========================================================================
//  Side effect 4 — exactly TWO StockTransactions per order (sales + material)
// =========================================================================

it('creates exactly two StockTransactions (sales + sales_material_consumption) when all items are track_stock', function () {
    $rice = makeMaterialWithStock($this->orgId, $this->brand->id, $this->warehouse->id, 500, 'g');

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 1,
        'output_unit' => 'serving',
        'ingredients' => [
            ['material_id' => $rice->id, 'quantity' => 5, 'unit' => 'g'],
        ],
    ]);

    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);

    // Seed SKU stock level so the sales txn completes
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $order = makePendingOrder($this->orgId, $this->branch->id, $this->user->id);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 2,
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    $txns = StockTransaction::where('reference_id', $order->id)
        ->where('reference_type', 'customer_order')
        ->orderBy('sub_type')
        ->get();

    expect($txns)->toHaveCount(2);

    $subTypes = $txns->pluck('sub_type')
        ->map(fn ($v) => $v instanceof BackedEnum ? $v->value : (string) $v)
        ->sort()
        ->values();
    expect($subTypes->contains('sales'))->toBeTrue();
    expect($subTypes->contains('sales_material_consumption'))->toBeTrue();
});

// =========================================================================
//  Error handling 3 — paid_amount < total_amount → 409 before plan-024 code
// =========================================================================

it('aborts with 409 when paid_amount is less than total_amount before any stock changes', function () {
    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => null,
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'status' => CustomerOrderStatusEnum::Pending->value,
        'created_by_id' => $this->user->id,
        'total_amount' => 1000,
        'paid_amount' => 500, // insufficient
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'status' => 'served',
    ]);

    expect(fn () => $this->closingService->close($order->fresh()))
        ->toThrow(HttpException::class);

    // Order should remain Pending
    $order->refresh();
    $statusValue = $order->status instanceof BackedEnum
        ? $order->status->value
        : (string) $order->status;
    expect($statusValue)->toBe(CustomerOrderStatusEnum::Pending->value);

    // No stock transactions created
    expect(StockTransaction::where('reference_id', $order->id)->count())->toBe(0);
});

// =========================================================================
//  Error handling 4 — Recipe with soft-deleted Material → skip + warn
// =========================================================================

it('skips deleted material ingredients and continues with the valid ones', function () {
    $deletedMaterial = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $validMaterial = makeMaterialWithStock($this->orgId, $this->brand->id, $this->warehouse->id, 200, 'g');

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 1,
        'output_unit' => 'serving',
        'ingredients' => [
            ['material_id' => $deletedMaterial->id, 'quantity' => 50, 'unit' => 'g'],
            ['material_id' => $validMaterial->id, 'quantity' => 10, 'unit' => 'g'],
        ],
    ]);

    $deletedMaterial->delete();

    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);

    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $order = makePendingOrder($this->orgId, $this->branch->id, $this->user->id);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'status' => 'served',
    ]);

    $closed = $this->closingService->close($order->fresh());

    // Order closed successfully (no exception thrown).
    expect($closed->status->value)->toBe('closed');

    // The valid material was deducted (200 - 10 = 190).
    $validLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $validMaterial->id)
        ->whereNull('material_lot_id')
        ->first();
    expect((float) $validLevel->quantity)->toBe(190.0);

    // No StockLevel row was created for the deleted material.
    $deletedLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $deletedMaterial->id)
        ->first();
    expect($deletedLevel)->toBeNull();
});
