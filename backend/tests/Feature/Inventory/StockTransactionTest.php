<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\StockTransactionItem;
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
        'auto_approve_stock_in' => false,
        'auto_approve_stock_out' => false,
    ]);

    $this->variant = ProductSku::factory()->create();

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

it('returns 401 when not authenticated', function () {
    Auth::forgetGuards();

    $this->getJson("{$this->baseUrl}/stock-transactions")
        ->assertUnauthorized();
});

// =========================================================================
//  Index
// =========================================================================

describe('index', function () {
    it('lists stock transactions for the user organization', function () {
        StockTransaction::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);

        $this->getJson("{$this->baseUrl}/stock-transactions")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('does not show transactions from other organizations', function () {
        StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);
        StockTransaction::factory()->create([
            'organization_id' => fake()->uuid(),
            'warehouse_id' => Warehouse::factory()->create()->id,
        ]);

        $this->getJson("{$this->baseUrl}/stock-transactions")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('filters transactions by type', function () {
        StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'stock_in',
        ]);
        StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'stock_out',
        ]);

        $this->getJson("{$this->baseUrl}/stock-transactions?type=stock_in")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('filters transactions by status', function () {
        StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
        ]);
        StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'completed',
        ]);

        $this->getJson("{$this->baseUrl}/stock-transactions?status=draft")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('searches transactions by transaction_code', function () {
        StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'transaction_code' => 'SI-20260403-001',
        ]);
        StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'transaction_code' => 'SO-20260403-001',
        ]);

        $this->getJson("{$this->baseUrl}/stock-transactions?search=SI-2026")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('includes items_count in the response', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);
        StockTransactionItem::factory()->count(2)->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/stock-transactions")
            ->assertOk()
            ->assertJsonPath('data.0.items_count', 2);
    });
});

// =========================================================================
//  Store
// =========================================================================

describe('store', function () {
    it('creates a stock transaction with items', function () {
        $payload = [
            'warehouse_id' => $this->warehouse->id,
            'type' => 'stock_in',
            'sub_type' => 'purchase',
            'note' => 'Test purchase',
            'items' => [
                [
                    'product_sku_id' => $this->variant->id,
                    'quantity' => 100,
                    'unit' => 'pcs',
                    'unit_price' => 10.50,
                ],
            ],
        ];

        $this->postJson("{$this->baseUrl}/stock-transactions", $payload)
            ->assertCreated()
            ->assertJsonPath('data.type', 'stock_in')
            ->assertJsonPath('data.sub_type', 'purchase')
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('stock_transactions', [
            'organization_id' => $this->orgId,
            'type' => 'stock_in',
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('stock_transaction_items', [
            'product_sku_id' => $this->variant->id,
            'quantity' => 100,
        ]);
    });

    it('auto-generates transaction code', function () {
        $payload = [
            'warehouse_id' => $this->warehouse->id,
            'type' => 'stock_in',
            'sub_type' => 'purchase',
            'items' => [
                ['product_sku_id' => $this->variant->id, 'quantity' => 10, 'unit' => 'pcs'],
            ],
        ];

        $response = $this->postJson("{$this->baseUrl}/stock-transactions", $payload)
            ->assertCreated();

        expect($response->json('data.transaction_code'))->toStartWith('SI-');
    });

    it('validates required fields', function () {
        $this->postJson("{$this->baseUrl}/stock-transactions", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['warehouse_id', 'type', 'sub_type', 'items']);
    });

    it('validates type enum', function () {
        $this->postJson("{$this->baseUrl}/stock-transactions", [
            'warehouse_id' => $this->warehouse->id,
            'type' => 'invalid_type',
            'sub_type' => 'purchase',
            'items' => [
                ['product_sku_id' => $this->variant->id, 'quantity' => 10],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    });

    // #851 — cross-tenant inventory IDOR: an org-A caller must not be able to
    // write a stock transaction against a warehouse owned by org B.
    it('rejects a warehouse_id belonging to another organization', function () {
        $otherOrg = Organization::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create([
            'organization_id' => $otherOrg->id,
            'is_active' => true,
        ]);

        $this->postJson("{$this->baseUrl}/stock-transactions", [
            'warehouse_id' => $foreignWarehouse->id,
            'type' => 'stock_in',
            'sub_type' => 'purchase',
            'items' => [
                ['product_sku_id' => $this->variant->id, 'quantity' => 10, 'unit' => 'pcs'],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['warehouse_id']);

        $this->assertDatabaseMissing('stock_transactions', [
            'warehouse_id' => $foreignWarehouse->id,
        ]);
    });
});

// =========================================================================
//  Show
// =========================================================================

describe('show', function () {
    it('returns a transaction with items', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);
        StockTransactionItem::factory()->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/stock-transactions/{$txn->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $txn->id)
            ->assertJsonStructure(['data' => ['id', 'transaction_code', 'items']]);
    });

    it('returns 403 for a transaction from another organization', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => fake()->uuid(),
            'warehouse_id' => Warehouse::factory()->create()->id,
        ]);

        $this->getJson("{$this->baseUrl}/stock-transactions/{$txn->id}")
            ->assertForbidden();
    });

    it('returns 404 for non-existent transaction', function () {
        $this->getJson("{$this->baseUrl}/stock-transactions/".fake()->uuid())
            ->assertNotFound();
    });
});

// =========================================================================
//  Update
// =========================================================================

describe('update', function () {
    it('updates a draft transaction', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
        ]);

        $this->putJson("{$this->baseUrl}/stock-transactions/{$txn->id}", [
            'note' => 'Updated note',
        ])
            ->assertOk()
            ->assertJsonPath('data.note', 'Updated note');
    });

    it('cannot update a completed transaction', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'completed',
        ]);

        $this->putJson("{$this->baseUrl}/stock-transactions/{$txn->id}", [
            'note' => 'Should fail',
        ])
            ->assertStatus(422);
    });
});

// =========================================================================
//  Destroy
// =========================================================================

describe('destroy', function () {
    it('soft deletes a draft transaction', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
        ]);

        $this->deleteJson("{$this->baseUrl}/stock-transactions/{$txn->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('stock_transactions', ['id' => $txn->id]);
    });

    it('cannot delete a pending transaction', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'pending',
        ]);

        $this->deleteJson("{$this->baseUrl}/stock-transactions/{$txn->id}")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Submit
// =========================================================================

describe('submit', function () {
    it('submits a draft transaction to pending', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
        ]);
        StockTransactionItem::factory()->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $this->postJson("{$this->baseUrl}/stock-transactions/{$txn->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    });

    it('auto-completes when warehouse auto_approve is enabled for stock_in', function () {
        $autoWarehouse = Warehouse::factory()->create([
            'organization_id' => $this->orgId,
            'auto_approve_stock_in' => true,
        ]);

        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $autoWarehouse->id,
            'type' => 'stock_in',
            'status' => 'draft',
        ]);
        StockTransactionItem::factory()->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'quantity' => 50,
            'base_quantity' => 50,
        ]);

        $this->postJson("{$this->baseUrl}/stock-transactions/{$txn->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    });

    it('cannot submit a completed transaction', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'completed',
        ]);

        $this->postJson("{$this->baseUrl}/stock-transactions/{$txn->id}/submit")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Approve
// =========================================================================

describe('approve', function () {
    it('approves a pending transaction and updates stock levels', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'stock_in',
            'status' => 'pending',
        ]);
        StockTransactionItem::factory()->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'quantity' => 100,
            'base_quantity' => 100,
        ]);

        $this->postJson("{$this->baseUrl}/stock-transactions/{$txn->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $stockLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
            ->where('product_sku_id', $this->variant->id)
            ->first();

        expect((float) $stockLevel->quantity)->toBe(100.0);
    });

    it('creates stock movement records on completion', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'stock_in',
            'status' => 'pending',
        ]);
        StockTransactionItem::factory()->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'quantity' => 50,
            'base_quantity' => 50,
        ]);

        $this->postJson("{$this->baseUrl}/stock-transactions/{$txn->id}/approve")
            ->assertOk();

        $movement = StockMovement::where('stock_transaction_id', $txn->id)->first();

        expect($movement)->not->toBeNull()
            ->and($movement->movement_type->value)->toBe('in')
            ->and((float) $movement->quantity_before)->toBe(0.0)
            ->and((float) $movement->quantity_after)->toBe(50.0);
    });

    it('throws insufficient stock for stock_out when not enough quantity', function () {
        StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'quantity' => 10,
        ]);

        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'stock_out',
            'status' => 'pending',
        ]);
        StockTransactionItem::factory()->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'quantity' => 100,
            'base_quantity' => 100,
        ]);

        $this->postJson("{$this->baseUrl}/stock-transactions/{$txn->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error', 'INSUFFICIENT_STOCK');
    });

    it('deducts stock on stock_out approval', function () {
        StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'quantity' => 200,
        ]);

        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'stock_out',
            'status' => 'pending',
        ]);
        StockTransactionItem::factory()->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'quantity' => 50,
            'base_quantity' => 50,
        ]);

        $this->postJson("{$this->baseUrl}/stock-transactions/{$txn->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $stockLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
            ->where('product_sku_id', $this->variant->id)
            ->first();

        expect((float) $stockLevel->quantity)->toBe(150.0);
    });

    it('cannot approve a draft transaction', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
        ]);

        $this->postJson("{$this->baseUrl}/stock-transactions/{$txn->id}/approve")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Cancel
// =========================================================================

describe('cancel', function () {
    it('cancels a draft transaction', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
        ]);

        $this->postJson("{$this->baseUrl}/stock-transactions/{$txn->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    });

    it('cancels a pending transaction', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'pending',
        ]);

        $this->postJson("{$this->baseUrl}/stock-transactions/{$txn->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    });

    it('cannot cancel a completed transaction', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'completed',
        ]);

        $this->postJson("{$this->baseUrl}/stock-transactions/{$txn->id}/cancel")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Org Isolation
// =========================================================================

it('returns 403 when updating another org transaction', function () {
    $txn = StockTransaction::factory()->create([
        'organization_id' => fake()->uuid(),
        'warehouse_id' => Warehouse::factory()->create()->id,
        'status' => 'draft',
    ]);

    $this->putJson("{$this->baseUrl}/stock-transactions/{$txn->id}", ['note' => 'Hacked'])
        ->assertForbidden();
});

it('returns 403 when deleting another org transaction', function () {
    $txn = StockTransaction::factory()->create([
        'organization_id' => fake()->uuid(),
        'warehouse_id' => Warehouse::factory()->create()->id,
        'status' => 'draft',
    ]);

    $this->deleteJson("{$this->baseUrl}/stock-transactions/{$txn->id}")
        ->assertForbidden();
});

// =========================================================================
//  404
// =========================================================================

it('returns 404 for non-existent stock transaction', function () {
    $this->getJson("{$this->baseUrl}/stock-transactions/".fake()->uuid())
        ->assertNotFound();
});

it('returns 404 when showing a soft-deleted stock transaction', function () {
    $txn = StockTransaction::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
    ]);
    $txn->delete();

    $this->getJson("{$this->baseUrl}/stock-transactions/{$txn->id}")
        ->assertNotFound();
});
