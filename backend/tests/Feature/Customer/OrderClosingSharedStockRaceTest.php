<?php

/**
 * Plan-024 test-gap — no double-spend when TWO DISTINCT orders drain the
 * SAME shared StockLevel.
 *
 * The pre-existing OrderClosingConcurrencyTest only double-closes a SINGLE
 * order (idempotency guard). It never exercises the risk the DESIGN calls
 * out explicitly:
 *
 *   "Concurrent OrderClosingService::close calls on PARALLEL orders consuming
 *    the same lot → race condition double-spend. Mitigation: completeTransaction
 *    already uses StockLevel::lockForUpdate() per-row. Verify with a Pest
 *    concurrency test."  (DESIGN.md Risks table + TESTS Error-handling 2)
 *
 * True OS-thread parallelism is not achievable under the SQLite :memory:
 * single-connection test harness, so these tests assert the *accounting
 * invariant* that the per-row lockForUpdate exists to guarantee: two separate
 * orders closing against one shared StockLevel deduct EXACTLY once each — the
 * combined on-hand never over- or under-drains, and the strict (allow_negative
 * =false) floor is never crossed by the second order.
 */

use App\Exceptions\InsufficientStockException;
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

    $role = Role::firstOrCreate(['slug' => 'org-admin'], ['name' => 'Org Admin', 'level' => 100]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->user->assignRole($role, $this->orgId);
    $this->actingAs($this->user);

    $this->closingService = app(OrderClosingService::class);
});

/**
 * Warehouse bound to the shared branch. allow_negative_sales toggles the
 * strict-floor behaviour under test.
 */
function makeSharedWarehouse(string $orgId, string $branchId, bool $allowNegative): Warehouse
{
    return Warehouse::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branchId,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'allow_negative_sales' => $allowNegative,
    ]);
}

/**
 * A pending order on the shared branch with a single served line for $sku.
 */
function makeOrderForSku(string $orgId, string $branchId, string $userId, string $skuId, int $qty): CustomerOrder
{
    $order = CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branchId,
        'status' => CustomerOrderStatusEnum::Pending->value,
        'created_by_id' => $userId,
        'total_amount' => 0,
        'paid_amount' => 0,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $skuId,
        'quantity' => $qty,
        'status' => 'served',
    ]);

    return $order;
}

// =========================================================================
//  SKU-level: two distinct orders share one SKU StockLevel, enough for both
// =========================================================================

it('deducts a shared SKU StockLevel exactly once per order when two orders close', function () {
    makeSharedWarehouse($this->orgId, $this->branch->id, allowNegative: false);
    $warehouse = Warehouse::where('branch_id', $this->branch->id)->first();

    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => null,
    ]);
    StockLevel::create([
        'warehouse_id' => $warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 50,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $orderA = makeOrderForSku($this->orgId, $this->branch->id, $this->user->id, $sku->id, 12);
    $orderB = makeOrderForSku($this->orgId, $this->branch->id, $this->user->id, $sku->id, 8);

    $this->closingService->close($orderA->fresh());
    $this->closingService->close($orderB->fresh());

    // 50 - 12 - 8 = 30. Neither order lost nor doubled the other's write.
    $level = StockLevel::where('warehouse_id', $warehouse->id)
        ->where('product_sku_id', $sku->id)
        ->first();
    expect((float) $level->quantity)->toBe(30.0);

    // One sales transaction per order — never a shared/duplicated row.
    expect(StockTransaction::where('reference_id', $orderA->id)->where('sub_type', 'sales')->count())->toBe(1);
    expect(StockTransaction::where('reference_id', $orderB->id)->where('sub_type', 'sales')->count())->toBe(1);

    // Both orders reach Closed.
    expect($orderA->fresh()->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);
    expect($orderB->fresh()->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);
});

// =========================================================================
//  SKU-level strict floor: order A drains to the floor, order B is refused
// =========================================================================

it('never double-spends the shared-stock floor when the second order closes (allow_negative=false)', function () {
    makeSharedWarehouse($this->orgId, $this->branch->id, allowNegative: false);
    $warehouse = Warehouse::where('branch_id', $this->branch->id)->first();

    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => null,
    ]);
    // Only 10 on hand: enough for order A (10), NOT for order B (3).
    StockLevel::create([
        'warehouse_id' => $warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 10,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $orderA = makeOrderForSku($this->orgId, $this->branch->id, $this->user->id, $sku->id, 10);
    $orderB = makeOrderForSku($this->orgId, $this->branch->id, $this->user->id, $sku->id, 3);

    // A drains the level to exactly 0.
    $this->closingService->close($orderA->fresh());
    $level = StockLevel::where('warehouse_id', $warehouse->id)
        ->where('product_sku_id', $sku->id)
        ->first();
    expect((float) $level->quantity)->toBe(0.0);

    // B cannot cross the floor. plan-004 money-safety fix: a collected payment
    // must NEVER be rolled back by a stock shortage, so close() no longer
    // propagates InsufficientStockException — it ring-fences the stock
    // deduction in its own savepoint. The shortage rolls back ONLY the stock
    // writes (logged for reconciliation); the order still closes.
    expect(fn () => $this->closingService->close($orderB->fresh()))
        ->not->toThrow(InsufficientStockException::class);

    // No double-spend: the strict floor held — the level is still exactly 0,
    // not -3. This is the invariant the per-row lockForUpdate exists to protect
    // and it survives the money-safety decoupling untouched.
    $level = StockLevel::where('warehouse_id', $warehouse->id)
        ->where('product_sku_id', $sku->id)
        ->first();
    expect((float) $level->quantity)->toBe(0.0);

    // Order B still reaches Closed (money is never lost), but the rolled-back
    // stock deduction left NO sales transaction persisted for it.
    expect($orderB->fresh()->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);
    expect(StockTransaction::where('reference_id', $orderB->id)->count())->toBe(0);

    // Order A's write is intact.
    expect($orderA->fresh()->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);
    expect(StockTransaction::where('reference_id', $orderA->id)->where('sub_type', 'sales')->count())->toBe(1);
});

// =========================================================================
//  Material-level: two distinct orders share one MATERIAL StockLevel via recipe
// =========================================================================

it('deducts a shared MATERIAL StockLevel exactly once per order across two recipe-backed closes', function () {
    // Made-to-order shape (no SKU StockLevel row) so the recipe walk runs and
    // the shared resource under contention is the MATERIAL, not the SKU.
    // allow_negative_sales=true lets Phase-1 SKU stock-out go negative freely;
    // the invariant under test is the material's per-row accounting.
    makeSharedWarehouse($this->orgId, $this->branch->id, allowNegative: true);
    $warehouse = Warehouse::where('branch_id', $this->branch->id)->first();

    $rice = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    StockLevel::create([
        'warehouse_id' => $warehouse->id,
        'material_id' => $rice->id,
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
            ['material_id' => $rice->id, 'quantity' => 10, 'unit' => 'g'],
        ],
    ]);
    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);

    // Order A consumes 10g × 4 = 40g; order B consumes 10g × 6 = 60g.
    $orderA = makeOrderForSku($this->orgId, $this->branch->id, $this->user->id, $sku->id, 4);
    $orderB = makeOrderForSku($this->orgId, $this->branch->id, $this->user->id, $sku->id, 6);

    $this->closingService->close($orderA->fresh());
    $this->closingService->close($orderB->fresh());

    // 1000 - 40 - 60 = 900. Exactly one deduction per order, shared cleanly.
    $level = StockLevel::where('warehouse_id', $warehouse->id)
        ->where('material_id', $rice->id)
        ->whereNull('material_lot_id')
        ->first();
    expect((float) $level->quantity)->toBe(900.0);

    // Each order produced its own combined material transaction.
    expect(StockTransaction::where('reference_id', $orderA->id)->where('sub_type', 'sales_material_consumption')->count())->toBe(1);
    expect(StockTransaction::where('reference_id', $orderB->id)->where('sub_type', 'sales_material_consumption')->count())->toBe(1);
});
