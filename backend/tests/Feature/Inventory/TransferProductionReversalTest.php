<?php

// plan-040 Phase D (Cluster D) — Transfer & production reversal.
// Covers: H1 (receive bound + partial shrinkage), H2 (transfer cancel reversal),
// H5 (production cancel-after-start reversal), M1 (batch cancel auto-approve),
// NEW-STK-2 (sent/received base-quantity conversion), NEW-STK-6 (receive FormRequest).

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialLot;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Models\ProductionOrder;
use App\Models\ProductSku;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\StockTransactionItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\ProductionOrderService;
use Illuminate\Support\Str;

function makeTransferSetup(bool $autoApprove = true): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->id,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'console_organization_id' => $orgId,
    ]);
    grantOrgAccess($user, $orgId);

    $source = Warehouse::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
        'is_active' => true,
        'auto_approve_stock_in' => $autoApprove,
        'auto_approve_stock_out' => $autoApprove,
    ]);
    $dest = Warehouse::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
        'is_active' => true,
        'auto_approve_stock_in' => $autoApprove,
        'auto_approve_stock_out' => $autoApprove,
    ]);

    return compact('orgId', 'brand', 'branch', 'user', 'source', 'dest');
}

// =========================================================================
//  H1 — receive bound + partial shrinkage
// =========================================================================

describe('H1 receive validation + shrinkage', function () {
    it('rejects received_quantity greater than sent_quantity with 422', function () {
        $s = makeTransferSetup();
        $this->actingAs($s['user']);
        $variant = ProductSku::factory()->create();

        StockLevel::factory()->create([
            'warehouse_id' => $s['source']->id,
            'product_sku_id' => $variant->id,
            'material_id' => null,
            'quantity' => 200,
        ]);

        $transfer = StockTransfer::factory()->create([
            'organization_id' => $s['orgId'],
            'source_warehouse_id' => $s['source']->id,
            'destination_warehouse_id' => $s['dest']->id,
            'status' => 'pending',
        ]);
        $item = StockTransferItem::factory()->create([
            'stock_transfer_id' => $transfer->id,
            'product_sku_id' => $variant->id,
            'material_id' => null,
            'sent_quantity' => 10,
            'sent_base_quantity' => 10,
            'received_quantity' => null,
            'received_base_quantity' => null,
            'unit' => 'pcs',
        ]);

        $base = "/api/v1/shops/{$s['branch']->slug}";
        $this->postJson("{$base}/stock-transfers/{$transfer->id}/approve")->assertOk();

        $this->postJson("{$base}/stock-transfers/{$transfer->id}/receive", [
            'items' => [
                ['id' => $item->id, 'received_quantity' => 1000000],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.received_quantity']);
    });

    it('records a shrinkage adjustment when received is less than sent', function () {
        $s = makeTransferSetup();
        $this->actingAs($s['user']);
        $variant = ProductSku::factory()->create();

        StockLevel::factory()->create([
            'warehouse_id' => $s['source']->id,
            'product_sku_id' => $variant->id,
            'material_id' => null,
            'quantity' => 200,
        ]);

        $transfer = StockTransfer::factory()->create([
            'organization_id' => $s['orgId'],
            'source_warehouse_id' => $s['source']->id,
            'destination_warehouse_id' => $s['dest']->id,
            'status' => 'pending',
        ]);
        $item = StockTransferItem::factory()->create([
            'stock_transfer_id' => $transfer->id,
            'product_sku_id' => $variant->id,
            'material_id' => null,
            'sent_quantity' => 10,
            'sent_base_quantity' => 10,
            'received_quantity' => null,
            'received_base_quantity' => null,
            'unit' => 'pcs',
        ]);

        $base = "/api/v1/shops/{$s['branch']->slug}";
        $this->postJson("{$base}/stock-transfers/{$transfer->id}/approve")->assertOk();

        $this->postJson("{$base}/stock-transfers/{$transfer->id}/receive", [
            'items' => [
                ['id' => $item->id, 'received_quantity' => 8],
            ],
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        // Destination nets +8 (stock_in 10 − shrinkage 2).
        $destLevel = StockLevel::where('warehouse_id', $s['dest']->id)
            ->where('product_sku_id', $variant->id)
            ->first();
        expect((float) $destLevel->quantity)->toBe(8.0);

        // An adjustment_out shrinkage transaction of 2 exists for this transfer.
        $shrink = StockTransaction::where('reference_type', 'stock_transfer')
            ->where('reference_id', $transfer->id)
            ->where('sub_type', 'adjustment_out')
            ->first();
        expect($shrink)->not->toBeNull();
        $shrinkItem = StockTransactionItem::where('stock_transaction_id', $shrink->id)->first();
        expect((float) $shrinkItem->quantity)->toBe(2.0);
    });
});

// =========================================================================
//  NEW-STK-6 — receive FormRequest
// =========================================================================

describe('NEW-STK-6 receive FormRequest', function () {
    it('rejects a negative received_quantity with 422', function () {
        $s = makeTransferSetup();
        $this->actingAs($s['user']);
        $variant = ProductSku::factory()->create();

        StockLevel::factory()->create([
            'warehouse_id' => $s['source']->id,
            'product_sku_id' => $variant->id,
            'material_id' => null,
            'quantity' => 200,
        ]);

        $transfer = StockTransfer::factory()->create([
            'organization_id' => $s['orgId'],
            'source_warehouse_id' => $s['source']->id,
            'destination_warehouse_id' => $s['dest']->id,
            'status' => 'pending',
        ]);
        $item = StockTransferItem::factory()->create([
            'stock_transfer_id' => $transfer->id,
            'product_sku_id' => $variant->id,
            'material_id' => null,
            'sent_quantity' => 10,
            'sent_base_quantity' => 10,
            'received_quantity' => null,
            'received_base_quantity' => null,
            'unit' => 'pcs',
        ]);

        $base = "/api/v1/shops/{$s['branch']->slug}";
        $this->postJson("{$base}/stock-transfers/{$transfer->id}/approve")->assertOk();

        $this->postJson("{$base}/stock-transfers/{$transfer->id}/receive", [
            'items' => [
                ['id' => $item->id, 'received_quantity' => -5],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.received_quantity']);
    });

    it('rejects items referencing an id not in the transfer with 422', function () {
        $s = makeTransferSetup();
        $this->actingAs($s['user']);
        $variant = ProductSku::factory()->create();

        StockLevel::factory()->create([
            'warehouse_id' => $s['source']->id,
            'product_sku_id' => $variant->id,
            'material_id' => null,
            'quantity' => 200,
        ]);

        $transfer = StockTransfer::factory()->create([
            'organization_id' => $s['orgId'],
            'source_warehouse_id' => $s['source']->id,
            'destination_warehouse_id' => $s['dest']->id,
            'status' => 'pending',
        ]);
        StockTransferItem::factory()->create([
            'stock_transfer_id' => $transfer->id,
            'product_sku_id' => $variant->id,
            'material_id' => null,
            'sent_quantity' => 10,
            'sent_base_quantity' => 10,
            'received_quantity' => null,
            'received_base_quantity' => null,
            'unit' => 'pcs',
        ]);

        $base = "/api/v1/shops/{$s['branch']->slug}";
        $this->postJson("{$base}/stock-transfers/{$transfer->id}/approve")->assertOk();

        $this->postJson("{$base}/stock-transfers/{$transfer->id}/receive", [
            'items' => [
                ['id' => (string) Str::uuid(), 'received_quantity' => 5],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.id']);
    });
});

// =========================================================================
//  H2 — transfer cancel reversal
// =========================================================================

it('H2: cancelling an approved transfer reverses stock_in to source and sets cancelled', function () {
    $s = makeTransferSetup();
    $this->actingAs($s['user']);
    $variant = ProductSku::factory()->create();

    StockLevel::factory()->create([
        'warehouse_id' => $s['source']->id,
        'product_sku_id' => $variant->id,
        'material_id' => null,
        'quantity' => 200,
    ]);

    $transfer = StockTransfer::factory()->create([
        'organization_id' => $s['orgId'],
        'source_warehouse_id' => $s['source']->id,
        'destination_warehouse_id' => $s['dest']->id,
        'status' => 'pending',
    ]);
    StockTransferItem::factory()->create([
        'stock_transfer_id' => $transfer->id,
        'product_sku_id' => $variant->id,
        'material_id' => null,
        'sent_quantity' => 50,
        'sent_base_quantity' => 50,
        'received_quantity' => null,
        'received_base_quantity' => null,
        'unit' => 'pcs',
    ]);

    $base = "/api/v1/shops/{$s['branch']->slug}";
    $this->postJson("{$base}/stock-transfers/{$transfer->id}/approve")->assertOk();

    // Source deducted by 50 → 150.
    $srcLevel = StockLevel::where('warehouse_id', $s['source']->id)
        ->where('product_sku_id', $variant->id)->first();
    expect((float) $srcLevel->quantity)->toBe(150.0);

    $this->postJson("{$base}/stock-transfers/{$transfer->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    // Reversal stock_in restored source to 200.
    $srcLevel->refresh();
    expect((float) $srcLevel->quantity)->toBe(200.0);

    $reversal = StockTransaction::where('reference_type', 'stock_transfer')
        ->where('reference_id', $transfer->id)
        ->where('sub_type', 'adjustment_in')
        ->first();
    expect($reversal)->not->toBeNull();
});

// =========================================================================
//  H5 — production cancel-after-start reversal
// =========================================================================

it('H5: cancelling a started production order reverses consumed materials', function () {
    $s = makeTransferSetup();
    $orders = app(ProductionOrderService::class);

    $material = Material::factory()->create([
        'organization_id' => $s['orgId'],
        'brand_id' => $s['brand']->id,
    ]);

    StockLevel::factory()->create([
        'warehouse_id' => $s['source']->id,
        'product_sku_id' => null,
        'material_id' => $material->id,
        'quantity' => 100,
    ]);

    $order = ProductionOrder::factory()->create([
        'organization_id' => $s['orgId'],
        'warehouse_id' => $s['source']->id,
        'status' => 'in_progress',
    ]);

    // Simulate the stock_out booked by start().
    $stockOut = StockTransaction::factory()->create([
        'organization_id' => $s['orgId'],
        'warehouse_id' => $s['source']->id,
        'type' => 'stock_out',
        'sub_type' => 'production',
        'reference_type' => 'production_order',
        'reference_id' => $order->id,
        'status' => 'completed',
    ]);
    StockTransactionItem::factory()->create([
        'stock_transaction_id' => $stockOut->id,
        'material_id' => $material->id,
        'product_sku_id' => null,
        'material_lot_id' => null,
        'quantity' => 30,
        'base_quantity' => 30,
        'unit' => 'g',
    ]);
    // Reflect the deduction in stock level (100 − 30 = 70).
    StockLevel::where('warehouse_id', $s['source']->id)
        ->where('material_id', $material->id)
        ->update(['quantity' => 70]);

    $order->update(['stock_out_transaction_id' => $stockOut->id]);

    $orders->cancel($order->fresh(), $s['user']->id);

    expect($order->fresh()->status->value)->toBe('cancelled')
        ->and($order->fresh()->stock_out_transaction_id)->toBeNull();

    // Materials restored: 70 + 30 = 100.
    $level = StockLevel::where('warehouse_id', $s['source']->id)
        ->where('material_id', $material->id)->first();
    expect((float) $level->quantity)->toBe(100.0);

    $reversal = StockTransaction::where('reference_type', 'production_order')
        ->where('reference_id', $order->id)
        ->where('sub_type', 'adjustment_in')
        ->first();
    expect($reversal)->not->toBeNull();
});

// =========================================================================
//  M1 — batch cancel auto-approves reversal even when warehouse doesn't
// =========================================================================

it('M1: batch cancel auto-approves the reversal stock_in (warehouse auto_approve off)', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->id,
    ]);
    $user = User::factory()->create(['console_organization_id' => $orgId]);
    grantOrgAccess($user, $orgId);

    // auto_approve_stock_in = false — the reversal must still complete because
    // the controller threads the user id (TD.5).
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
        'auto_approve_stock_in' => false,
        'auto_approve_stock_out' => false,
        'auto_approve_batch' => true,
    ]);
    $material = Material::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);

    $lot = MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'material_id' => $material->id,
        'warehouse_id' => $warehouse->id,
        'status' => 'active',
        'source' => 'inbound',
        'unit' => 'g',
        'received_qty' => 1000,
        'qty_on_hand' => 950,
    ]);
    StockLevel::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_sku_id' => null,
        'material_id' => $material->id,
        'material_lot_id' => $lot->id,
        'quantity' => 950,
    ]);

    $batch = MaterialBatch::factory()->create([
        'organization_id' => $orgId,
        'warehouse_id' => $warehouse->id,
        'material_id' => $material->id,
        'status' => 'in_progress',
    ]);
    $stockOut = StockTransaction::factory()->create([
        'organization_id' => $orgId,
        'warehouse_id' => $warehouse->id,
        'type' => 'stock_out',
        'sub_type' => 'production',
        'reference_type' => 'material_batch',
        'reference_id' => $batch->id,
        'status' => 'completed',
    ]);
    StockTransactionItem::factory()->create([
        'stock_transaction_id' => $stockOut->id,
        'material_id' => $material->id,
        'product_sku_id' => null,
        'material_lot_id' => $lot->id,
        'quantity' => 50,
        'base_quantity' => 50,
        'unit' => 'g',
    ]);
    $batch->update(['stock_out_transaction_id' => $stockOut->id]);

    $this->actingAs($user);
    $base = "/api/v1/shops/{$branch->slug}";
    $this->postJson("{$base}/material-batches/{$batch->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    // Reversal stock_in completed (auto-approved via user id) → lot restored.
    $reversal = StockTransaction::where('reference_type', 'material_batch')
        ->where('reference_id', $batch->id)
        ->where('sub_type', 'adjustment_in')
        ->first();
    expect($reversal)->not->toBeNull()
        ->and($reversal->status->value)->toBe('completed');

    // The auto-approved reversal increments the lot-grained stock level back
    // up (950 → 1000). Without TD.5 threading the user id, the reversal would
    // stay `pending` and the level would remain at 950.
    $level = StockLevel::where('warehouse_id', $warehouse->id)
        ->where('material_id', $material->id)
        ->where('material_lot_id', $lot->id)
        ->first();
    expect((float) $level->quantity)->toBe(1000.0);
});

// =========================================================================
//  NEW-STK-2 — base-quantity conversion on send + receive
// =========================================================================

describe('NEW-STK-2 base-quantity conversion', function () {
    it('computes sent_base_quantity via unit→base conversion on create', function () {
        $s = makeTransferSetup();
        $this->actingAs($s['user']);

        $material = Material::factory()->create([
            'organization_id' => $s['orgId'],
            'brand_id' => $s['brand']->id,
        ]);
        MaterialUnit::factory()->create([
            'material_id' => $material->id,
            'unit' => 'g',
            'ratio' => 1,
            'is_base' => true,
        ]);
        MaterialUnit::factory()->create([
            'material_id' => $material->id,
            'unit' => 'bag',
            'ratio' => 25,
            'is_base' => false,
        ]);

        $base = "/api/v1/shops/{$s['branch']->slug}";
        $response = $this->postJson("{$base}/stock-transfers", [
            'source_warehouse_id' => $s['source']->id,
            'destination_warehouse_id' => $s['dest']->id,
            'items' => [
                ['material_id' => $material->id, 'sent_quantity' => 2, 'unit' => 'bag'],
            ],
        ])->assertCreated();

        $transferId = $response->json('data.id');
        $item = StockTransferItem::where('stock_transfer_id', $transferId)->first();
        expect((float) $item->sent_base_quantity)->toBe(50.0);
    });

    it('computes received_base_quantity via unit→base conversion on receive', function () {
        $s = makeTransferSetup();
        $this->actingAs($s['user']);

        $material = Material::factory()->create([
            'organization_id' => $s['orgId'],
            'brand_id' => $s['brand']->id,
        ]);
        MaterialUnit::factory()->create([
            'material_id' => $material->id,
            'unit' => 'g',
            'ratio' => 1,
            'is_base' => true,
        ]);
        MaterialUnit::factory()->create([
            'material_id' => $material->id,
            'unit' => 'bag',
            'ratio' => 25,
            'is_base' => false,
        ]);

        StockLevel::factory()->create([
            'warehouse_id' => $s['source']->id,
            'product_sku_id' => null,
            'material_id' => $material->id,
            'material_lot_id' => MaterialLot::factory()->create([
                'organization_id' => $s['orgId'],
                'brand_id' => $s['brand']->id,
                'material_id' => $material->id,
                'warehouse_id' => $s['source']->id,
                'status' => 'active',
                'received_qty' => 500,
                'qty_on_hand' => 500,
            ])->id,
            'quantity' => 500,
        ]);

        $transfer = StockTransfer::factory()->create([
            'organization_id' => $s['orgId'],
            'source_warehouse_id' => $s['source']->id,
            'destination_warehouse_id' => $s['dest']->id,
            'status' => 'pending',
        ]);
        $item = StockTransferItem::factory()->create([
            'stock_transfer_id' => $transfer->id,
            'product_sku_id' => null,
            'material_id' => $material->id,
            'sent_quantity' => 2,
            'sent_base_quantity' => 50,
            'received_quantity' => null,
            'received_base_quantity' => null,
            'unit' => 'bag',
        ]);

        $base = "/api/v1/shops/{$s['branch']->slug}";
        $this->postJson("{$base}/stock-transfers/{$transfer->id}/approve")->assertOk();

        $this->postJson("{$base}/stock-transfers/{$transfer->id}/receive", [
            'items' => [
                ['id' => $item->id, 'received_quantity' => 2],
            ],
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        expect((float) $item->fresh()->received_base_quantity)->toBe(50.0);
    });
});
