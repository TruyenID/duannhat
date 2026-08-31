<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\StockLevel;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->sourceWarehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
    ]);

    $this->destWarehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
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

it('returns 401 when not authenticated for stock transfers', function () {
    Auth::forgetGuards();

    $this->getJson("{$this->baseUrl}/stock-transfers")
        ->assertUnauthorized();
});

// =========================================================================
//  Index
// =========================================================================

describe('index', function () {
    it('lists stock transfers for the user organization', function () {
        StockTransfer::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'draft',
        ]);

        $this->getJson("{$this->baseUrl}/stock-transfers")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('does not show transfers from other organizations', function () {
        StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'draft',
        ]);
        StockTransfer::factory()->create([
            'organization_id' => fake()->uuid(),
            'source_warehouse_id' => Warehouse::factory()->create()->id,
            'destination_warehouse_id' => Warehouse::factory()->create()->id,
            'status' => 'draft',
        ]);

        $this->getJson("{$this->baseUrl}/stock-transfers")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('filters transfers by status', function () {
        StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'draft',
        ]);
        StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'completed',
        ]);

        $this->getJson("{$this->baseUrl}/stock-transfers?status=draft")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

// =========================================================================
//  Store
// =========================================================================

describe('store', function () {
    it('creates a stock transfer with items', function () {
        $payload = [
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'note' => 'Test transfer',
            'items' => [
                [
                    'product_sku_id' => $this->variant->id,
                    'sent_quantity' => 50,
                    'unit' => 'pcs',
                ],
            ],
        ];

        $this->postJson("{$this->baseUrl}/stock-transfers", $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('stock_transfers', [
            'organization_id' => $this->orgId,
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('stock_transfer_items', [
            'product_sku_id' => $this->variant->id,
            'sent_quantity' => 50,
        ]);
    });

    it('auto-generates transfer code', function () {
        $payload = [
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'items' => [
                ['product_sku_id' => $this->variant->id, 'sent_quantity' => 10, 'unit' => 'pcs'],
            ],
        ];

        $response = $this->postJson("{$this->baseUrl}/stock-transfers", $payload)
            ->assertCreated();

        expect($response->json('data.transfer_code'))->toStartWith('TR-');
    });

    it('validates source and destination must be different', function () {
        $this->postJson("{$this->baseUrl}/stock-transfers", [
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->sourceWarehouse->id,
            'items' => [
                ['product_sku_id' => $this->variant->id, 'sent_quantity' => 10],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['destination_warehouse_id']);
    });

    it('validates required fields', function () {
        $this->postJson("{$this->baseUrl}/stock-transfers", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source_warehouse_id', 'destination_warehouse_id', 'items']);
    });

    // #851 — cross-tenant inventory IDOR: neither the source nor destination
    // warehouse may belong to a different organization than the caller.
    it('rejects a source_warehouse_id belonging to another organization', function () {
        $otherOrg = Organization::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create([
            'organization_id' => $otherOrg->id,
            'is_active' => true,
        ]);

        $this->postJson("{$this->baseUrl}/stock-transfers", [
            'source_warehouse_id' => $foreignWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'items' => [
                ['product_sku_id' => $this->variant->id, 'sent_quantity' => 10, 'unit' => 'pcs'],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source_warehouse_id']);

        $this->assertDatabaseMissing('stock_transfers', [
            'source_warehouse_id' => $foreignWarehouse->id,
        ]);
    });

    it('rejects a destination_warehouse_id belonging to another organization', function () {
        $otherOrg = Organization::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create([
            'organization_id' => $otherOrg->id,
            'is_active' => true,
        ]);

        $this->postJson("{$this->baseUrl}/stock-transfers", [
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $foreignWarehouse->id,
            'items' => [
                ['product_sku_id' => $this->variant->id, 'sent_quantity' => 10, 'unit' => 'pcs'],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['destination_warehouse_id']);

        $this->assertDatabaseMissing('stock_transfers', [
            'destination_warehouse_id' => $foreignWarehouse->id,
        ]);
    });
});

// =========================================================================
//  Show
// =========================================================================

describe('show', function () {
    it('returns a transfer with items', function () {
        $transfer = StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'draft',
        ]);
        StockTransferItem::factory()->create([
            'stock_transfer_id' => $transfer->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/stock-transfers/{$transfer->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $transfer->id)
            ->assertJsonStructure(['data' => ['id', 'transfer_code', 'items']]);
    });

    it('returns 403 for a transfer from another organization', function () {
        $transfer = StockTransfer::factory()->create([
            'organization_id' => fake()->uuid(),
            'source_warehouse_id' => Warehouse::factory()->create()->id,
            'destination_warehouse_id' => Warehouse::factory()->create()->id,
            'status' => 'draft',
        ]);

        $this->getJson("{$this->baseUrl}/stock-transfers/{$transfer->id}")
            ->assertForbidden();
    });
});

// =========================================================================
//  Update
// =========================================================================

describe('update', function () {
    it('updates a draft transfer', function () {
        $transfer = StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'draft',
        ]);

        $this->putJson("{$this->baseUrl}/stock-transfers/{$transfer->id}", [
            'note' => 'Updated note',
        ])
            ->assertOk()
            ->assertJsonPath('data.note', 'Updated note');
    });

    it('cannot update a completed transfer', function () {
        $transfer = StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'completed',
        ]);

        $this->putJson("{$this->baseUrl}/stock-transfers/{$transfer->id}", [
            'note' => 'Should fail',
        ])
            ->assertStatus(422);
    });
});

// =========================================================================
//  Destroy
// =========================================================================

describe('destroy', function () {
    it('soft deletes a draft transfer', function () {
        $transfer = StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'draft',
        ]);

        $this->deleteJson("{$this->baseUrl}/stock-transfers/{$transfer->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('stock_transfers', ['id' => $transfer->id]);
    });
});

// =========================================================================
//  Submit
// =========================================================================

describe('submit', function () {
    it('submits a draft transfer to pending', function () {
        $transfer = StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'draft',
        ]);

        $this->postJson("{$this->baseUrl}/stock-transfers/{$transfer->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    });

    it('cannot submit a completed transfer', function () {
        $transfer = StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'completed',
        ]);

        $this->postJson("{$this->baseUrl}/stock-transfers/{$transfer->id}/submit")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Approve
// =========================================================================

describe('approve', function () {
    it('approves a pending transfer and creates stock_out transaction', function () {
        // Stock must exist in source warehouse for stock_out
        StockLevel::factory()->create([
            'warehouse_id' => $this->sourceWarehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'quantity' => 200,
        ]);

        $transfer = StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'pending',
        ]);
        StockTransferItem::factory()->create([
            'stock_transfer_id' => $transfer->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'sent_quantity' => 50,
            'sent_base_quantity' => 50,
        ]);

        $this->postJson("{$this->baseUrl}/stock-transfers/{$transfer->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Verify stock was deducted from source warehouse
        $stockLevel = StockLevel::where('warehouse_id', $this->sourceWarehouse->id)
            ->where('product_sku_id', $this->variant->id)
            ->first();

        expect((float) $stockLevel->quantity)->toBe(150.0);
    });

    it('cannot approve a draft transfer', function () {
        $transfer = StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'draft',
        ]);

        $this->postJson("{$this->baseUrl}/stock-transfers/{$transfer->id}/approve")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Receive
// =========================================================================

describe('receive', function () {
    it('receives an approved transfer and adds stock to destination', function () {
        StockLevel::factory()->create([
            'warehouse_id' => $this->sourceWarehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'quantity' => 200,
        ]);

        $transfer = StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'pending',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);
        StockTransferItem::factory()->create([
            'stock_transfer_id' => $transfer->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'sent_quantity' => 50,
            'sent_base_quantity' => 50,
            'received_quantity' => null,
            'received_base_quantity' => null,
        ]);

        // First approve
        $this->postJson("{$this->baseUrl}/stock-transfers/{$transfer->id}/approve")
            ->assertOk();

        // Then receive
        $this->postJson("{$this->baseUrl}/stock-transfers/{$transfer->id}/receive")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // Verify stock was added to destination warehouse
        $destLevel = StockLevel::where('warehouse_id', $this->destWarehouse->id)
            ->where('product_sku_id', $this->variant->id)
            ->first();

        expect($destLevel)->not->toBeNull()
            ->and((float) $destLevel->quantity)->toBe(50.0);
    });

    it('cannot receive a draft transfer', function () {
        $transfer = StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'draft',
        ]);

        $this->postJson("{$this->baseUrl}/stock-transfers/{$transfer->id}/receive")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Cancel
// =========================================================================

describe('cancel', function () {
    it('cancels a draft transfer', function () {
        $transfer = StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'draft',
        ]);

        $this->postJson("{$this->baseUrl}/stock-transfers/{$transfer->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    });

    it('cancels a pending transfer', function () {
        $transfer = StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'pending',
        ]);

        $this->postJson("{$this->baseUrl}/stock-transfers/{$transfer->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    });

    it('cannot cancel a completed transfer', function () {
        $transfer = StockTransfer::factory()->create([
            'organization_id' => $this->orgId,
            'source_warehouse_id' => $this->sourceWarehouse->id,
            'destination_warehouse_id' => $this->destWarehouse->id,
            'status' => 'completed',
        ]);

        $this->postJson("{$this->baseUrl}/stock-transfers/{$transfer->id}/cancel")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Org Isolation
// =========================================================================

it('returns 403 when updating another org transfer', function () {
    $transfer = StockTransfer::factory()->create([
        'organization_id' => fake()->uuid(),
        'source_warehouse_id' => Warehouse::factory()->create()->id,
        'destination_warehouse_id' => Warehouse::factory()->create()->id,
        'status' => 'draft',
    ]);

    $this->putJson("{$this->baseUrl}/stock-transfers/{$transfer->id}", ['note' => 'Hacked'])
        ->assertForbidden();
});

it('returns 403 when deleting another org transfer', function () {
    $transfer = StockTransfer::factory()->create([
        'organization_id' => fake()->uuid(),
        'source_warehouse_id' => Warehouse::factory()->create()->id,
        'destination_warehouse_id' => Warehouse::factory()->create()->id,
        'status' => 'draft',
    ]);

    $this->deleteJson("{$this->baseUrl}/stock-transfers/{$transfer->id}")
        ->assertForbidden();
});

it('returns 404 for non-existent stock transfer', function () {
    $this->getJson("{$this->baseUrl}/stock-transfers/".fake()->uuid())
        ->assertNotFound();
});
