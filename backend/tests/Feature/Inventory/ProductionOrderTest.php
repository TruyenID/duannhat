<?php

use App\Models\Branch;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductSku;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'auto_approve_batch' => false,
    ]);

    $this->outputVariant = ProductSku::factory()->create();
    $this->inputMaterial = Material::factory()->create([
        'organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/shops/{$this->shop->slug}";

    $this->actingAs($this->user);
});

// =========================================================================
//  Authentication
// =========================================================================

it('returns 401 when not authenticated for production orders', function () {
    Auth::forgetGuards();

    $this->getJson("{$this->baseUrl}/production-orders")
        ->assertUnauthorized();
});

// =========================================================================
//  Index
// =========================================================================

describe('index', function () {
    it('lists production orders for the user organization', function () {
        ProductionOrder::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'output_variant_id' => $this->outputVariant->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/production-orders")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('does not show orders from other organizations', function () {
        ProductionOrder::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'output_variant_id' => $this->outputVariant->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);
        ProductionOrder::factory()->create([
            'organization_id' => fake()->uuid(),
            'warehouse_id' => Warehouse::factory()->create()->id,
            'output_variant_id' => $this->outputVariant->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/production-orders")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

// =========================================================================
//  Store
// =========================================================================

describe('store', function () {
    it('creates a production order with items', function () {
        $payload = [
            'warehouse_id' => $this->warehouse->id,
            'output_variant_id' => $this->outputVariant->id,
            'planned_quantity' => 100,
            'output_unit' => 'pcs',
            'recipe_multiplier' => 2,
            'note' => 'Test production',
            'items' => [
                [
                    'component_type' => 'material',
                    'material_id' => $this->inputMaterial->id,
                    'planned_quantity' => 50,
                    'unit' => 'g',
                ],
            ],
        ];

        $this->postJson("{$this->baseUrl}/production-orders", $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('production_orders', [
            'organization_id' => $this->orgId,
            'status' => 'draft',
        ]);
    });

    it('auto-generates order code', function () {
        $response = $this->postJson("{$this->baseUrl}/production-orders", [
            'warehouse_id' => $this->warehouse->id,
            'output_variant_id' => $this->outputVariant->id,
            'planned_quantity' => 100,
            'output_unit' => 'pcs',
            'recipe_multiplier' => 1,
        ])->assertCreated();

        expect($response->json('data.order_code'))->toStartWith('PO-');
    });

    it('validates required fields', function () {
        $this->postJson("{$this->baseUrl}/production-orders", [])
            ->assertUnprocessable()
            // recipe_multiplier is no longer required at the request level —
            // ProductionOrderService backfills it from the output SKU when
            // omitted (BR-02 / NOTES.md follow-up).
            ->assertJsonValidationErrors(['warehouse_id', 'output_variant_id', 'planned_quantity', 'output_unit']);
    });
});

// =========================================================================
//  Show
// =========================================================================

describe('show', function () {
    it('returns an order with items', function () {
        $order = ProductionOrder::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'output_variant_id' => $this->outputVariant->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/production-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id);
    });

    it('returns 403 for an order from another organization', function () {
        $order = ProductionOrder::factory()->create([
            'organization_id' => fake()->uuid(),
            'warehouse_id' => Warehouse::factory()->create()->id,
            'output_variant_id' => $this->outputVariant->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/production-orders/{$order->id}")
            ->assertForbidden();
    });
});

// =========================================================================
//  Submit
// =========================================================================

describe('submit', function () {
    it('submits a draft order with items to pending', function () {
        $order = ProductionOrder::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'output_variant_id' => $this->outputVariant->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);
        ProductionOrderItem::factory()->create([
            'production_order_id' => $order->id,
            'material_id' => $this->inputMaterial->id,
            'product_sku_id' => null,
            'component_type' => 'material',
        ]);

        $this->postJson("{$this->baseUrl}/production-orders/{$order->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    });

    it('cannot submit an order without items', function () {
        $order = ProductionOrder::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'output_variant_id' => $this->outputVariant->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->postJson("{$this->baseUrl}/production-orders/{$order->id}/submit")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Start
// =========================================================================

describe('start', function () {
    it('emits a stock_out for NVL items when transitioning approved → in_progress', function () {
        $lot = MaterialLot::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->inputMaterial->brand_id,
            'material_id' => $this->inputMaterial->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'active',
            'received_qty' => 500,
            'qty_on_hand' => 500,
        ]);
        StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => null,
            'material_id' => $this->inputMaterial->id,
            'material_lot_id' => $lot->id,
            'quantity' => 500,
        ]);

        $order = ProductionOrder::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'output_variant_id' => $this->outputVariant->id,
            'planned_quantity' => 100,
            'output_unit' => 'pcs',
            'status' => 'approved',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);
        ProductionOrderItem::factory()->create([
            'production_order_id' => $order->id,
            'material_id' => $this->inputMaterial->id,
            'product_sku_id' => null,
            'component_type' => 'material',
            'planned_quantity' => 50,
            'unit' => 'g',
        ]);

        $this->postJson("{$this->baseUrl}/production-orders/{$order->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        // stock_out transaction created at start (not waiting for complete).
        $stockOut = StockTransaction::where('reference_type', 'production_order')
            ->where('reference_id', $order->id)
            ->where('type', 'stock_out')
            ->first();
        expect($stockOut)->not->toBeNull();

        // No stock_in yet — that lands at complete().
        $stockIn = StockTransaction::where('reference_type', 'production_order')
            ->where('reference_id', $order->id)
            ->where('type', 'stock_in')
            ->first();
        expect($stockIn)->toBeNull();

        // The material StockLevel decreased by the planned quantity.
        $level = StockLevel::where('warehouse_id', $this->warehouse->id)
            ->where('material_id', $this->inputMaterial->id)
            ->first();
        expect((float) $level->quantity)->toBe(450.0);
    });
});

// =========================================================================
//  Complete
// =========================================================================

describe('complete', function () {
    it('completes an order and creates stock_in for the output (stock_out already exists from start)', function () {
        // Create stock for input materials
        StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => null,
            'material_id' => $this->inputMaterial->id,
            'quantity' => 500,
        ]);

        // NVL stock_out lands at start() under the new semantics — seed it
        // here so the order is already in the expected post-start shape.
        $existingStockOut = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'stock_out',
            'sub_type' => 'production',
            'reference_type' => 'production_order',
            'status' => 'completed',
        ]);

        $order = ProductionOrder::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'output_variant_id' => $this->outputVariant->id,
            'planned_quantity' => 100,
            'output_unit' => 'pcs',
            'status' => 'in_progress',
            'stock_out_transaction_id' => $existingStockOut->id,
            'stock_in_transaction_id' => null,
        ]);
        $existingStockOut->update(['reference_id' => $order->id]);

        ProductionOrderItem::factory()->create([
            'production_order_id' => $order->id,
            'material_id' => $this->inputMaterial->id,
            'product_sku_id' => null,
            'component_type' => 'material',
            'planned_quantity' => 50,
            'actual_quantity' => null,
            'unit' => 'g',
        ]);

        $this->postJson("{$this->baseUrl}/production-orders/{$order->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // stock_out still present (from start()).
        $stockOut = StockTransaction::where('reference_type', 'production_order')
            ->where('reference_id', $order->id)
            ->where('type', 'stock_out')
            ->first();
        expect($stockOut)->not->toBeNull();

        // stock_in created by complete().
        $stockIn = StockTransaction::where('reference_type', 'production_order')
            ->where('reference_id', $order->id)
            ->where('type', 'stock_in')
            ->first();
        expect($stockIn)->not->toBeNull();
    });

    it('cannot complete a draft order', function () {
        $order = ProductionOrder::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'output_variant_id' => $this->outputVariant->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->postJson("{$this->baseUrl}/production-orders/{$order->id}/complete")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Cancel
// =========================================================================

describe('cancel', function () {
    it('cancels a draft order', function () {
        $order = ProductionOrder::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'output_variant_id' => $this->outputVariant->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->postJson("{$this->baseUrl}/production-orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    });

    it('cancels a pending order', function () {
        $order = ProductionOrder::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'output_variant_id' => $this->outputVariant->id,
            'status' => 'pending',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->postJson("{$this->baseUrl}/production-orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    });

    it('cannot cancel a completed order', function () {
        $order = ProductionOrder::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'output_variant_id' => $this->outputVariant->id,
            'status' => 'completed',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->postJson("{$this->baseUrl}/production-orders/{$order->id}/cancel")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Org Isolation & 404
// =========================================================================

it('returns 403 when updating another org production order', function () {
    $order = ProductionOrder::factory()->create([
        'organization_id' => fake()->uuid(),
        'warehouse_id' => Warehouse::factory()->create()->id,
        'output_variant_id' => $this->outputVariant->id,
        'status' => 'draft',
        'stock_out_transaction_id' => null,
        'stock_in_transaction_id' => null,
    ]);

    $this->putJson("{$this->baseUrl}/production-orders/{$order->id}", ['note' => 'Hacked'])
        ->assertForbidden();
});

it('returns 404 for non-existent production order', function () {
    $this->getJson("{$this->baseUrl}/production-orders/".fake()->uuid())
        ->assertNotFound();
});
