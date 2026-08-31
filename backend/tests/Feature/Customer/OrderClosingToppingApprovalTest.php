<?php

/**
 * plan-040 Cluster H sales-path — OrderClosingService consumption.
 *
 *  - M5 (TH.3): selected toppings that carry their own recipe materials are
 *               deducted at order close (not just the base SKU recipe).
 *  - M7 (TH.4): a draft (unapproved) recipe linked to a sellable SKU does NOT
 *               bleed inventory at sale time — deduction is blocked.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Material;
use App\Models\OrderItemTopping;
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

/**
 * Material + stocked StockLevel in the test warehouse.
 */
function makeToppingMaterial(object $self, float $qty, string $unit = 'g'): Material
{
    $material = Material::factory()->create([
        'organization_id' => $self->orgId,
        'brand_id' => $self->brand->id,
    ]);
    StockLevel::create([
        'warehouse_id' => $self->warehouse->id,
        'material_id' => $material->id,
        'quantity' => $qty,
        'unit' => $unit,
        'alert_enabled' => false,
    ]);

    return $material;
}

/**
 * Pending order with the given total/paid (default fully paid at 0).
 */
function makeToppingOrder(object $self): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $self->orgId,
        'branch_id' => $self->branch->id,
        'status' => CustomerOrderStatusEnum::Pending->value,
        'created_by_id' => $self->user->id,
        'total_amount' => 0,
        'paid_amount' => 0,
    ]);
}

it('deducts a selected topping recipe materials at order close (M5)', function () {
    $cheese = makeToppingMaterial($this, 500, 'g');

    // Topping SKU with its own approved recipe (5g cheese / serving).
    $toppingRecipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 1,
        'output_unit' => 'serving',
        'ingredients' => [
            ['type' => 'material', 'material_id' => $cheese->id, 'quantity' => 5, 'unit' => 'g'],
        ],
    ]);
    $toppingSku = ProductSku::factory()->create([
        'inventory_mode' => 'made_to_order',
        'recipe_id' => $toppingRecipe->id,
    ]);

    // Base SKU is made_to_order with no recipe — only the topping has materials.
    $baseSku = ProductSku::factory()->create([
        'inventory_mode' => 'made_to_order',
        'recipe_id' => null,
    ]);

    $order = makeToppingOrder($this);
    $orderItem = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $baseSku->id,
        'quantity' => 2,
        'status' => 'served',
    ]);
    OrderItemTopping::factory()->create([
        'customer_order_item_id' => $orderItem->id,
        'product_sku_id' => $toppingSku->id,
        'quantity' => 1,
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    // 2 dishes × 1 topping × (5g / output_qty 1) = 10g cheese deducted.
    $cheeseLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $cheese->id)
        ->first();
    expect((float) $cheeseLevel->quantity)->toBe(490.0);

    expect(
        StockTransaction::where('reference_id', $order->id)
            ->where('sub_type', 'sales_material_consumption')
            ->count()
    )->toBe(1);
});

it('blocks material deduction when the SKU recipe is not approved (M7)', function () {
    $rice = makeToppingMaterial($this, 500, 'g');

    // Draft (unapproved) recipe linked to a sellable track_stock SKU.
    $draftRecipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Draft->value,
        'output_quantity' => 1,
        'output_unit' => 'serving',
        'ingredients' => [
            ['type' => 'material', 'material_id' => $rice->id, 'quantity' => 10, 'unit' => 'g'],
        ],
    ]);
    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => $draftRecipe->id,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $order = makeToppingOrder($this);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 2,
        'status' => 'served',
    ]);

    $closed = $this->closingService->close($order->fresh());

    // Order still closes…
    expect($closed->status->value)->toBe('closed');

    // …but the draft recipe deducts nothing (no consumption tx, rice untouched).
    expect(
        StockTransaction::where('reference_id', $order->id)
            ->where('sub_type', 'sales_material_consumption')
            ->count()
    )->toBe(0);

    $riceLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $rice->id)
        ->first();
    expect((float) $riceLevel->quantity)->toBe(500.0);
});
