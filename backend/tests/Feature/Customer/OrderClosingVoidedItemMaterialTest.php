<?php

/**
 * Plan-024 — voided/return path no-regression test.
 *
 * Covers TESTS.md scenario:
 *   - Side effects #5: an order with a `voided` item must NOT produce
 *                       material consumption for that item. The existing
 *                       disposal/return path (out of plan-024 scope) is not
 *                       exercised by plan-024 code paths — closing an order
 *                       skips voided items in both Phase 1 (SKU stock-out)
 *                       and Phase 2 (Recipe → Material deduction).
 *
 * The companion test `OrderClosingInventoryModeTest::skips voided` proves
 * Phase 1 (SKU stock-out) skips voided items. This file pins Phase 2
 * (Material deduction) which is the path plan-024 introduced.
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
    // allow_negative_sales=true + 0 SKU stock = canonical made-to-order
    // setup for plan-024 G3 recipe deduction (sale-time material deduct
    // without an upstream ProductionBatch). The new pre-made guard
    // (NOTES.md option 1) skips recipe when SKU has positive on-hand,
    // which is NOT what this test wants to cover.
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

it('does not deduct material consumption for voided line items', function () {
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $material->id,
        'quantity' => 100,
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
            ['material_id' => $material->id, 'quantity' => 10, 'unit' => 'g'],
        ],
    ]);
    $servedSku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);
    $voidedSku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
    ]);

    foreach ([$servedSku->id, $voidedSku->id] as $id) {
        StockLevel::create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $id,
            'quantity' => 0,
            'unit' => 'pcs',
            'alert_enabled' => false,
        ]);
    }

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'status' => CustomerOrderStatusEnum::Pending->value,
        'created_by_id' => $this->user->id,
        'total_amount' => 0,
        'paid_amount' => 0,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $servedSku->id,
        'quantity' => 2,
        'status' => 'served',
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $voidedSku->id,
        'quantity' => 5,
        'status' => 'voided',
    ]);

    $this->closingService->close($order->fresh());

    // Exactly ONE sales_material_consumption transaction, and its single
    // material line only accounts for the served qty (2 × 10g = 20g) —
    // the voided 5 × 10g = 50g must NOT be added.
    $materialTxns = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales_material_consumption')
        ->with('items')
        ->get();
    expect($materialTxns)->toHaveCount(1);

    $items = $materialTxns->first()->items;
    expect($items)->toHaveCount(1);
    expect((float) $items->first()->quantity)->toBe(20.0);

    $level = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $material->id)
        ->first();
    expect((float) $level->quantity)->toBe(80.0); // 100 - 20
});

it('still closes the order successfully when all line items are voided (no stock movement at all)', function () {
    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => null,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 10,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'status' => CustomerOrderStatusEnum::Pending->value,
        'created_by_id' => $this->user->id,
        'total_amount' => 0,
        'paid_amount' => 0,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 3,
        'status' => 'voided',
    ]);

    $this->closingService->close($order->fresh());

    expect(
        StockTransaction::where('reference_id', $order->id)->exists()
    )->toBeFalse();

    $level = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('product_sku_id', $sku->id)
        ->first();
    expect((float) $level->quantity)->toBe(10.0); // unchanged
});
