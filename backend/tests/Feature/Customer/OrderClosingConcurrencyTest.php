<?php

/**
 * Plan-024 T3.8 — Concurrency: double-close a CustomerOrder is idempotent.
 *
 * Covers TESTS.md scenarios:
 *   - Happy 11: two concurrent close() calls → only one StockTransaction created
 *               (DB-level lock via lockForUpdate ensures idempotency)
 *
 * Note: True parallelism is not easily achievable in a single PHP Pest process.
 * We simulate the race condition by:
 *   1. Calling close() a first time → order transitions Pending → Closed, stock deducted.
 *   2. Calling close() a second time with the SAME order instance (which still holds
 *      Pending status in memory) → lockForUpdate re-reads the row and sees Closed,
 *      returns early without creating another StockTransaction.
 * This is the exact path the idempotency guard is designed for.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\ProductSku;
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
//  Happy 11 — double close() is idempotent (DB lock guard)
// =========================================================================

it('is idempotent when close() is called twice on the same order', function () {
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
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 2,
        'status' => 'served',
    ]);

    // First close — normal path
    $this->closingService->close($order->fresh());

    $txnCountAfterFirst = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales')
        ->count();

    // Simulate a concurrent/duplicate call: pass the original (stale) order instance
    // which still has status=Pending in memory. The service reloads it with lockForUpdate
    // and discovers it's already Closed → returns early without duplicating stock-out.
    $this->closingService->close($order); // $order still has old status in memory

    $txnCountAfterSecond = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales')
        ->count();

    // Only one sales transaction should exist regardless of the second call
    expect($txnCountAfterSecond)->toBe($txnCountAfterFirst);
    expect($txnCountAfterSecond)->toBe(1);

    // Stock should only have been deducted once
    $level = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('product_sku_id', $sku->id)
        ->first();
    expect((float) $level->quantity)->toBe(48.0); // 50 - 2 (not 50 - 4)
});

it('remains closed and does not abort when close() is called on an already-closed order', function () {
    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'made_to_order',
        'recipe_id' => null,
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'status' => CustomerOrderStatusEnum::Closed->value, // already closed
        'created_by_id' => $this->user->id,
        'total_amount' => 0,
        'paid_amount' => 0,
        'closed_at' => now(),
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'status' => 'served',
    ]);

    // Should not throw, should return the order in Closed state
    $result = $this->closingService->close($order);

    $statusValue = $result->status instanceof BackedEnum
        ? $result->status->value
        : (string) $result->status;

    expect($statusValue)->toBe(CustomerOrderStatusEnum::Closed->value);
});

it('does not create duplicate sales transactions when called twice sequentially', function () {
    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => null,
    ]);

    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 100,
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
        'quantity' => 5,
        'status' => 'served',
    ]);

    // First call — full close path
    $this->closingService->close($order->fresh());

    // Second call with a freshly-loaded (now-Closed) order — idempotent path
    $this->closingService->close($order->fresh());

    $txnCount = StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales')
        ->count();

    expect($txnCount)->toBe(1);

    // StockLevel decremented exactly once (100 - 5 = 95)
    $level = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('product_sku_id', $sku->id)
        ->first();
    expect((float) $level->quantity)->toBe(95.0);
});
