<?php

use App\Models\Branch;
use App\Models\ProductSku;
use App\Models\StockCount;
use App\Models\StockCountItem;
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

it('returns 401 when not authenticated for stock counts', function () {
    Auth::forgetGuards();

    $this->getJson("{$this->baseUrl}/stock-counts")
        ->assertUnauthorized();
});

// =========================================================================
//  Index
// =========================================================================

describe('index', function () {
    it('lists stock counts for the user organization', function () {
        StockCount::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);

        $this->getJson("{$this->baseUrl}/stock-counts")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('does not show counts from other organizations', function () {
        StockCount::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);
        StockCount::factory()->create([
            'organization_id' => fake()->uuid(),
            'warehouse_id' => Warehouse::factory()->create()->id,
        ]);

        $this->getJson("{$this->baseUrl}/stock-counts")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

// =========================================================================
//  Store
// =========================================================================

describe('store', function () {
    it('creates a stock count', function () {
        $this->postJson("{$this->baseUrl}/stock-counts", [
            'warehouse_id' => $this->warehouse->id,
            'scope' => 'full',
            'note' => 'Monthly count',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.scope', 'full');

        $this->assertDatabaseHas('stock_counts', [
            'organization_id' => $this->orgId,
            'status' => 'draft',
            'scope' => 'full',
        ]);
    });

    it('auto-generates count code', function () {
        $response = $this->postJson("{$this->baseUrl}/stock-counts", [
            'warehouse_id' => $this->warehouse->id,
            'scope' => 'partial',
        ])->assertCreated();

        expect($response->json('data.count_code'))->toStartWith('SC-');
    });

    it('validates required fields', function () {
        $this->postJson("{$this->baseUrl}/stock-counts", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['warehouse_id', 'scope']);
    });
});

// =========================================================================
//  Show
// =========================================================================

describe('show', function () {
    it('returns a count with items', function () {
        $count = StockCount::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);

        $this->getJson("{$this->baseUrl}/stock-counts/{$count->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $count->id);
    });

    it('returns 403 for a count from another organization', function () {
        $count = StockCount::factory()->create([
            'organization_id' => fake()->uuid(),
            'warehouse_id' => Warehouse::factory()->create()->id,
        ]);

        $this->getJson("{$this->baseUrl}/stock-counts/{$count->id}")
            ->assertForbidden();
    });
});

// =========================================================================
//  Workflow: Start
// =========================================================================

describe('start', function () {
    it('starts a draft count', function () {
        $count = StockCount::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
        ]);

        $this->postJson("{$this->baseUrl}/stock-counts/{$count->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    });

    it('cannot start a completed count', function () {
        $count = StockCount::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'approved',
        ]);

        $this->postJson("{$this->baseUrl}/stock-counts/{$count->id}/start")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Workflow: Add Items
// =========================================================================

describe('add-items', function () {
    it('adds items with system quantity snapshot', function () {
        StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'quantity' => 100,
        ]);

        $count = StockCount::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
        ]);

        $this->postJson("{$this->baseUrl}/stock-counts/{$count->id}/add-items", [
            'items' => [
                ['product_sku_id' => $this->variant->id, 'unit' => 'pcs'],
            ],
        ])
            ->assertOk();

        $item = StockCountItem::where('stock_count_id', $count->id)->first();

        expect($item)->not->toBeNull()
            ->and((float) $item->system_quantity)->toBe(100.0);
    });
});

// =========================================================================
//  Workflow: Update Items + Submit + Approve
// =========================================================================

describe('approve', function () {
    it('creates adjustment transactions for differences on approval', function () {
        $count = StockCount::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'pending_approval',
        ]);

        StockCountItem::factory()->create([
            'stock_count_id' => $count->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'system_quantity' => 100,
            'counted_quantity' => 110,
            'difference' => 10,
            'unit' => 'pcs',
        ]);

        $this->postJson("{$this->baseUrl}/stock-counts/{$count->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // An adjustment_in stock transaction should have been created
        $adjustTxn = StockTransaction::where('reference_type', 'stock_count')
            ->where('reference_id', $count->id)
            ->where('sub_type', 'adjustment_in')
            ->first();

        expect($adjustTxn)->not->toBeNull();
    });

    it('cannot approve a draft count', function () {
        $count = StockCount::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
        ]);

        $this->postJson("{$this->baseUrl}/stock-counts/{$count->id}/approve")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Cancel
// =========================================================================

describe('cancel', function () {
    it('cancels a draft count', function () {
        $count = StockCount::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
        ]);

        $this->postJson("{$this->baseUrl}/stock-counts/{$count->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    });

    it('cannot cancel an approved count', function () {
        $count = StockCount::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'approved',
        ]);

        $this->postJson("{$this->baseUrl}/stock-counts/{$count->id}/cancel")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Org Isolation & 404
// =========================================================================

it('returns 403 when updating another org stock count', function () {
    $count = StockCount::factory()->create([
        'organization_id' => fake()->uuid(),
        'warehouse_id' => Warehouse::factory()->create()->id,
    ]);

    $this->putJson("{$this->baseUrl}/stock-counts/{$count->id}", ['note' => 'Hacked'])
        ->assertForbidden();
});

it('returns 404 for non-existent stock count', function () {
    $this->getJson("{$this->baseUrl}/stock-counts/".fake()->uuid())
        ->assertNotFound();
});
