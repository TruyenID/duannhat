<?php

/**
 * plan-051 (#1149 × #1150) — void × stock compensation truth table.
 *
 * | line state   | stock_effect   | expectation                                  |
 * |--------------|----------------|----------------------------------------------|
 * | not deducted | any            | nothing (voided line skipped from deduction) |
 * | deducted     | restock        | adjustment_in reversing the recorded rows,   |
 * |              |                | note references the original transaction     |
 * | deducted     | waste          | NO compensation (material truly consumed)    |
 * | deducted     | none           | NO compensation + warning log                |
 * | deducted     | unknown (text) | NO compensation + warning log (legacy path)  |
 *
 * Also: workstation sync-UP void with a reason id compensates the same way
 * (transportWorkstationVoidItem), and the POS HTTP surface accepts
 * void_reason_id + snapshots the label into the void_reason text column.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\ShopOrderSetting;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\VoidReason;
use App\Models\Warehouse;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\CustomerOrderService;
use App\Services\Inventory\StockDeductionService;
use App\Services\Order\Internal\EloquentOrderPersistence;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'void-comp-shop',
        'is_active' => true,
    ]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'allow_negative_sales' => true,
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->user->assignRole($role, $this->orgId);
    grantOrgAccess($this->user, $this->orgId);
    $this->actingAs($this->user);

    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
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
            ['material_id' => $this->material->id, 'quantity' => 10, 'unit' => 'g'],
        ],
    ]);
    $product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'inventory_mode' => 'track_stock',
        'recipe_id' => $recipe->id,
        'selling_price' => 500,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_sku_id' => $this->sku->id,
        'quantity' => 0,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    // on_preparing timing + every status voidable — the deducted-then-voided
    // scenario is only reachable pre-close on the hook timings.
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->branch->id],
        [
            'organization_id' => $this->orgId,
            'stock_deduction_timing' => 'on_preparing',
            'item_voidable_statuses' => ['pending', 'preparing', 'ready', 'served'],
        ],
    );

    $this->orders = app(CustomerOrderService::class);
});

function vscReason(array $overrides = [], string $label = 'Bấm nhầm'): VoidReason
{
    return VoidReason::create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'stock_effect' => 'restock',
        'requires_note' => false,
        'is_active' => true,
        'sort_order' => 0,
    ], $overrides, [
        'en' => ['label' => $label],
    ]));
}

/** Open order + one line of qty 2, deducted at pending→preparing (−20g). */
function vscDeductedLine(): array
{
    $order = CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'order_type' => 'dine_in',
        'status' => CustomerOrderStatusEnum::Open->value,
        'created_by_id' => test()->user->id,
        'total_amount' => 0,
        'paid_amount' => 0,
    ]);
    $item = test()->orders->addItems($order, ['items' => [[
        'product_sku_id' => test()->sku->id,
        'quantity' => 2,
    ]]])[0];

    test()->orders->updateItem($order->fresh(), $item->id, ['status' => 'preparing']);
    $item = $item->fresh();
    expect($item->stock_deducted_at)->not->toBeNull();
    expect(vscMaterialLevel())->toBe(980.0);

    return [$order->fresh(), $item];
}

function vscMaterialLevel(): float
{
    return (float) StockLevel::where('warehouse_id', test()->warehouse->id)
        ->where('material_id', test()->material->id)
        ->value('quantity');
}

function vscAdjustmentIns(CustomerOrder $order)
{
    return StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'adjustment_in')
        ->with('items')
        ->get();
}

// =========================================================================
//  Truth table
// =========================================================================

it('restock: voiding a deducted line emits an adjustment_in referencing the original transaction and restores the level', function () {
    [$order, $item] = vscDeductedLine();
    $reason = vscReason(['stock_effect' => 'restock']);

    $this->orders->voidItem($order, $item->id, ['void_reason_id' => $reason->id]);

    $fresh = $item->fresh();
    expect($fresh->status->value)->toBe('voided');
    expect($fresh->void_reason_id)->toBe($reason->id);
    expect($fresh->void_reason)->toBe('Bấm nhầm'); // label snapshot — history self-contained

    $adjustments = vscAdjustmentIns($order);
    expect($adjustments)->toHaveCount(1);
    $adj = $adjustments->first();
    expect($adj->note)->toContain((string) $fresh->stock_out_transaction_id); // references the original
    expect($adj->note)->toContain('plan-051 stock compensation');
    // Full reversal: the SKU row (2 pcs) + the material row (20 g).
    expect((float) $adj->items->firstWhere('material_id', $this->material->id)->quantity)->toBe(20.0);
    expect((float) $adj->items->firstWhere('product_sku_id', $this->sku->id)->quantity)->toBe(2.0);

    expect(vscMaterialLevel())->toBe(1000.0);
});

it('waste: voiding a deducted line does NOT compensate — the material was truly consumed', function () {
    [$order, $item] = vscDeductedLine();
    $reason = vscReason(['stock_effect' => 'waste'], 'Nấu hỏng');

    $this->orders->voidItem($order, $item->id, ['void_reason_id' => $reason->id]);

    expect($item->fresh()->status->value)->toBe('voided');
    expect(vscAdjustmentIns($order))->toHaveCount(0);
    expect(vscMaterialLevel())->toBe(980.0);
});

it('none: voiding a deducted line does NOT compensate', function () {
    [$order, $item] = vscDeductedLine();
    $reason = vscReason(['stock_effect' => 'none'], 'Comp cho khách');

    $this->orders->voidItem($order, $item->id, ['void_reason_id' => $reason->id, 'void_reason' => 'VIP']);

    expect(vscAdjustmentIns($order))->toHaveCount(0);
    expect(vscMaterialLevel())->toBe(980.0);
    expect($item->fresh()->void_reason)->toBe('Comp cho khách: VIP');
});

it('unknown (legacy free text): voiding a deducted line does NOT compensate and warns ops', function () {
    Log::spy();

    [$order, $item] = vscDeductedLine();

    $this->orders->voidItem($order, $item->id, ['void_reason' => 'khách huỷ vì chờ lâu quá']);

    expect(vscAdjustmentIns($order))->toHaveCount(0);
    expect(vscMaterialLevel())->toBe(980.0);
    expect($item->fresh()->void_reason_id)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => is_string($message) && str_contains($message, 'no stock compensation'))
        ->atLeast()->once();
});

it('not deducted: voiding a pending line under on_close with a restock reason moves no stock at all', function () {
    ShopOrderSetting::where('branch_id', $this->branch->id)
        ->update(['stock_deduction_timing' => 'on_close']);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'order_type' => 'dine_in',
        'status' => CustomerOrderStatusEnum::Open->value,
        'created_by_id' => $this->user->id,
        'total_amount' => 0,
        'paid_amount' => 0,
    ]);
    $item = $this->orders->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id,
        'quantity' => 2,
    ]]])[0];
    expect($item->fresh()->stock_deducted_at)->toBeNull();

    $reason = vscReason(['stock_effect' => 'restock']);
    $this->orders->voidItem($order->fresh(), $item->id, ['void_reason_id' => $reason->id]);

    expect(StockTransaction::where('reference_id', $order->id)->exists())->toBeFalse();
    expect(vscMaterialLevel())->toBe(1000.0);
});

// =========================================================================
//  Cross-tier — workstation sync-UP void
// =========================================================================

it('workstation sync void with a restock reason id compensates like the Cloud path', function () {
    [$order, $item] = vscDeductedLine();
    $reason = vscReason(['stock_effect' => 'restock']);

    app(EloquentOrderPersistence::class)
        ->transportWorkstationVoidItem($item->fresh(), 'ws void', $reason->id);

    $fresh = $item->fresh();
    expect($fresh->status->value)->toBe('voided');
    expect($fresh->void_reason_id)->toBe($reason->id);
    expect(vscAdjustmentIns($order))->toHaveCount(1);
    expect(vscMaterialLevel())->toBe(1000.0);
});

it('workstation sync void with an unresolvable reason id degrades to the legacy no-compensation path', function () {
    [$order, $item] = vscDeductedLine();
    $foreignReason = VoidReason::create([
        'organization_id' => (string) Str::uuid(),
        'brand_id' => (string) Str::uuid(), // different brand
        'stock_effect' => 'restock',
        'requires_note' => false,
        'is_active' => true,
        'sort_order' => 0,
        'en' => ['label' => 'Foreign'],
    ]);

    app(EloquentOrderPersistence::class)
        ->transportWorkstationVoidItem($item->fresh(), 'ws void', $foreignReason->id);

    $fresh = $item->fresh();
    expect($fresh->status->value)->toBe('voided');
    expect($fresh->void_reason_id)->toBeNull();
    expect(vscAdjustmentIns($order))->toHaveCount(0);
    expect(vscMaterialLevel())->toBe(980.0);
});

// =========================================================================
//  POS HTTP surface — void_reason_id accepted end to end
// =========================================================================

it('POS void endpoint accepts void_reason_id, compensates, and snapshots the label + note', function () {
    [$order, $item] = vscDeductedLine();
    $reason = vscReason(['stock_effect' => 'restock', 'requires_note' => true], 'Khách đổi món');

    $this->actingAs($this->user)
        ->postJson("/api/v1/shops/{$this->branch->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason_id' => $reason->id,
            'void_reason' => 'đổi sang cỡ L',
        ])
        ->assertOk();

    $fresh = $item->fresh();
    expect($fresh->status->value)->toBe('voided');
    expect($fresh->void_reason_id)->toBe($reason->id);
    expect($fresh->void_reason)->toBe('Khách đổi món: đổi sang cỡ L');
    expect(vscAdjustmentIns($order))->toHaveCount(1);
    expect(vscMaterialLevel())->toBe(1000.0);
});

// =========================================================================
//  #1205 — the lost-compensation repair sweep
// =========================================================================

/**
 * The void path calls compensateVoid inside a try/catch so an inventory failure
 * cannot swallow the void. Nothing read the resulting log, so the material
 * stayed deducted forever. These pin the recovery: detect the stranded line,
 * repair it, and stay a no-op once it is whole again.
 */
it('#1205 detects a voided line whose compensation never ran, and repairs it', function () {
    [$order, $item] = vscDeductedLine();
    $reason = vscReason(['stock_effect' => 'restock']);

    // Void the line the way the funnel does, but with compensation suppressed —
    // exactly the state the swallowed exception leaves behind.
    $item->fresh()->update([
        'status' => 'voided',
        'voided_at' => now(),
        'void_reason_id' => $reason->id,
    ]);

    $stock = app(StockDeductionService::class);
    expect($stock->hasOutstandingDeduction((string) $item->id))->toBeTrue()
        ->and(vscMaterialLevel())->toBe(980.0);

    // Dry run: reports it, writes nothing.
    $this->artisan('stock:repair-void-compensation')
        ->expectsOutputToContain('stranded=1')
        ->assertSuccessful();
    expect(vscMaterialLevel())->toBe(980.0)
        ->and(vscAdjustmentIns($order))->toHaveCount(0);

    // Repair: the material comes back, using the reason stored on the line.
    $this->artisan('stock:repair-void-compensation', ['--repair' => true])->assertSuccessful();
    expect(vscMaterialLevel())->toBe(1000.0)
        ->and(vscAdjustmentIns($order))->toHaveCount(1);
});

it('#1205 is a no-op on a line that already compensated — repair never double-restocks', function () {
    [$order, $item] = vscDeductedLine();
    $reason = vscReason(['stock_effect' => 'restock']);

    // The normal path: void + compensate.
    $this->orders->voidItem($order, $item->id, ['void_reason_id' => $reason->id]);
    expect(vscMaterialLevel())->toBe(1000.0);

    expect(app(StockDeductionService::class)
        ->hasOutstandingDeduction((string) $item->id))->toBeFalse();

    $this->artisan('stock:repair-void-compensation', ['--repair' => true])
        ->expectsOutputToContain('stranded=0')
        ->assertSuccessful();

    // Still exactly one adjustment_in, level unchanged.
    expect(vscMaterialLevel())->toBe(1000.0)
        ->and(vscAdjustmentIns($order))->toHaveCount(1);
});

// =========================================================================
//  #1206 — fail-open contract on the two void funnels
// =========================================================================

/**
 * Compensation is ring-fenced: it nests its own transaction, so an inventory
 * failure rolls back only the compensation and the void itself stands. Nothing
 * tested that. #1205 added a repair sweep for the resulting drift, but a repair
 * command is not the same guarantee — these pin that the VOID survives at all.
 *
 * Without them the branch could silently flip to fail-CLOSED, and refusing to
 * void a dish because a stock row would not write is far worse than a stock
 * number that #1205 can put back.
 */
it('#1206 keeps the void when the compensation blows up, and logs it (Cloud path)', function () {
    [$order, $item] = vscDeductedLine();
    $reason = vscReason(['stock_effect' => 'restock']);

    Schema::drop('stock_transaction_items');

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'void stock compensation failed')
            && ($context['item_id'] ?? null) === $item->id);
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $this->orders->voidItem($order, $item->id, ['void_reason_id' => $reason->id]);

    // The void stands, reason snapshotted — only the stock move was lost, which
    // is exactly what `stock:repair-void-compensation` (#1205) can recover.
    $fresh = $item->fresh();
    expect($fresh->status->value)->toBe('voided')
        ->and($fresh->void_reason_id)->toBe($reason->id)
        ->and($fresh->stock_deducted_at)->not->toBeNull();
});

it('#1206 keeps the void when the compensation blows up, and logs it (workstation replay)', function () {
    [$order, $item] = vscDeductedLine();
    $reason = vscReason(['stock_effect' => 'restock']);

    Schema::drop('stock_transaction_items');

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'workstation void stock compensation failed'));
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    app(EloquentOrderPersistence::class)
        ->transportWorkstationVoidItem($item->fresh(), 'ws void', $reason->id);

    $fresh = $item->fresh();
    expect($fresh->status->value)->toBe('voided')
        ->and($fresh->stock_deducted_at)->not->toBeNull();
});
