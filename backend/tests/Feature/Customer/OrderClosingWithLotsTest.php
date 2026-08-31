<?php

/**
 * Sales-edge genealogy + disposal-edge genealogy via the
 * StockTransactionService::completeTransaction hook (plan-017 §sales/disposal).
 *
 * Closes that REVIEW.md issues #1 + #2 — verifies that:
 *  - OrderClosingService.recordSalesGenealogy walks recipes and writes
 *    GenealogyLink rows with source_event_type=customer_order
 *  - StockTransactionService.completeTransaction with sub_type=disposal
 *    on a material item emits source_event_type=disposal edges
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\OrderClosingService;
use App\Services\Inventory\StockTransactionService;
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
    ]);

    // Authenticate so OrderClosingService can fall back to auth()->id() for
    // created_by_id when the order itself doesn't carry one.
    $role = Role::firstOrCreate(['slug' => 'org-admin'], ['name' => 'Org Admin', 'level' => 100]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->user->assignRole($role, $this->orgId);
    $this->actingAs($this->user);

    $this->closingService = app(OrderClosingService::class);
    $this->stockService = app(StockTransactionService::class);
});

it('writes sales-edge genealogy_links when a customer order with a recipe-backed SKU is closed', function () {
    // Material upstream + an active supplier lot for it
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 1000,
        'qty_on_hand' => 1000,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $material->id,
        'material_lot_id' => $lot->id,
        'quantity' => 1000,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    // Recipe → ProductSku → CustomerOrderItem chain
    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_unit' => 'kg',
        'output_quantity' => 1,
        'ingredients' => [],
    ]);
    $sku = ProductSku::factory()->create(['recipe_id' => $recipe->id]);

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
        'quantity' => 2,
        'status' => 'served',
    ]);

    // Act — close the order
    $this->closingService->close($order->fresh());

    // Assert — exactly one GenealogyLink edge for (lot → order)
    $edges = GenealogyLink::where('customer_order_id', $order->id)
        ->where('source_event_type', 'customer_order')
        ->get();

    expect($edges)->toHaveCount(1)
        ->and((string) $edges->first()->parent_lot_id)->toBe((string) $lot->id)
        ->and($edges->first()->child_lot_id)->toBeNull();
});

it('does not write sales edges when the SKU has no recipe', function () {
    $sku = ProductSku::factory()->create(['recipe_id' => null]);
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
        'quantity' => 1,
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    expect(GenealogyLink::where('customer_order_id', $order->id)->count())->toBe(0);
});

it('still writes sales-edge for a comp scenario where total_amount reaches 0 after comp', function () {
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 1000,
        'qty_on_hand' => 1000,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $material->id,
        'material_lot_id' => $lot->id,
        'quantity' => 1000,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_unit' => 'kg',
        'output_quantity' => 1,
        'ingredients' => [],
    ]);
    $sku = ProductSku::factory()->create(['recipe_id' => $recipe->id]);

    // Total = 1000, paid = 0 — but we set total_amount = 0 to simulate a
    // post-comp state where the order was fully comped. paid_amount=0 still
    // satisfies paid >= total when total = 0.
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
        'quantity' => 1,
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    expect(GenealogyLink::where('customer_order_id', $order->id)->count())
        ->toBe(1, 'Comp scenarios still write genealogy edges so recall trace can find them');
});

it('does NOT write sales-edge for voided items', function () {
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 100,
        'qty_on_hand' => 100,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $material->id,
        'material_lot_id' => $lot->id,
        'quantity' => 100,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_unit' => 'kg',
        'output_quantity' => 1,
        'ingredients' => [],
    ]);
    $sku = ProductSku::factory()->create(['recipe_id' => $recipe->id]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'status' => CustomerOrderStatusEnum::Pending->value,
        'created_by_id' => $this->user->id,
        'total_amount' => 0,
        'paid_amount' => 0,
    ]);
    // Single item, voided — recordSalesGenealogy walks status != voided.
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'status' => 'voided',
    ]);

    $this->closingService->close($order->fresh());

    expect(GenealogyLink::where('customer_order_id', $order->id)->count())->toBe(0);
});

it('writes disposal-edge genealogy_links when a disposal stock_out runs through the FEFO hook', function () {
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 100,
        'qty_on_hand' => 100,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $material->id,
        'material_lot_id' => $lot->id,
        'quantity' => 100,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    $disposalRecordId = (string) Str::uuid();

    $txn = $this->stockService->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'type' => 'stock_out',
        'sub_type' => 'disposal',
        'reference_type' => 'disposal_record',
        'reference_id' => $disposalRecordId,
        'created_by_id' => $this->user->id,
        'items' => [[
            'material_id' => $material->id,
            'quantity' => 25,
            'unit' => 'kg',
        ]],
    ]);
    $this->stockService->submit($txn);

    $edges = GenealogyLink::where('source_event_type', 'disposal')
        ->where('source_event_id', $disposalRecordId)
        ->get();

    expect($edges)->toHaveCount(1)
        ->and((string) $edges->first()->parent_lot_id)->toBe((string) $lot->id)
        ->and((float) $edges->first()->qty_consumed)->toBe(25.0);
});
