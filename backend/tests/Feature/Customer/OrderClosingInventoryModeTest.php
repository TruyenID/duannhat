<?php

/**
 * Plan-024 T3.2 — G2: Gate SKU stock-out by inventory_mode.
 *
 * Covers TESTS.md scenarios:
 *   - Happy 3: made_to_order SKU → NO sales StockTransaction created
 *   - Happy 4: track_stock SKU → sales StockTransaction IS created + StockLevel decrements
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
        'allow_negative_sales' => false,
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-admin'], ['name' => 'Org Admin', 'level' => 100]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->user->assignRole($role, $this->orgId);
    $this->actingAs($this->user);

    $this->closingService = app(OrderClosingService::class);
});

// =========================================================================
//  Happy 3 — made_to_order SKU at close → NO sales StockTransaction
// =========================================================================

it('does not create a sales StockTransaction for made_to_order SKUs', function () {
    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'made_to_order',
        'recipe_id' => null,
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
        'quantity' => 2,
        'status' => 'served',
    ]);

    $countBefore = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales')
        ->count();

    $this->closingService->close($order->fresh());

    // No sales sub_type transaction should be created for made_to_order
    $countAfter = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales')
        ->count();

    expect($countAfter)->toBe($countBefore);

    // The order should still be closed
    $order->refresh();
    $statusValue = $order->status instanceof BackedEnum
        ? $order->status->value
        : (string) $order->status;
    expect($statusValue)->toBe(CustomerOrderStatusEnum::Closed->value);
});

// =========================================================================
//  Happy 4 — track_stock SKU at close → sales StockTransaction IS created
//  AND StockLevel.quantity actually decrements (T0.6 submit() fix)
// =========================================================================

it('creates a sales StockTransaction and decrements StockLevel for track_stock SKUs', function () {
    // Create a track_stock SKU (no recipe so only SKU stock-out fires, not material deduction)
    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => null,
    ]);

    // Seed a StockLevel for the SKU
    $initialQty = 100;
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => $initialQty,
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
    $orderQty = 3;
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => $orderQty,
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    // A sales StockTransaction should have been created
    $txn = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales')
        ->first();
    expect($txn)->not->toBeNull();

    // The transaction should be completed (not Draft — T0.6 fix)
    $statusValue = $txn->status instanceof BackedEnum
        ? $txn->status->value
        : (string) $txn->status;
    expect($statusValue)->toBe('completed');

    // StockLevel should have decremented by order quantity
    $level = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('product_sku_id', $sku->id)
        ->first();
    expect((float) $level->quantity)->toBe((float) ($initialQty - $orderQty));
});

// =========================================================================
//  Mixed scenario — voided items are skipped
// =========================================================================

it('skips voided order items when determining stock-out', function () {
    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => null,
    ]);

    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 50,
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

    // Voided item — should NOT contribute to stock-out
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 5,
        'status' => 'voided',
    ]);

    // Served item — should contribute
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 2,
        'status' => 'served',
    ]);

    $this->closingService->close($order->fresh());

    // Only the served qty (2) should be deducted, not the voided qty (5)
    $level = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('product_sku_id', $sku->id)
        ->first();
    expect((float) $level->quantity)->toBe(48.0);
});
