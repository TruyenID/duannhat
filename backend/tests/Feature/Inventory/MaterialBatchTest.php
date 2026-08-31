<?php

use App\Models\Branch;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialBatchItem;
use App\Models\ProductSku;
use App\Models\Recipe;
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

    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
    ]);

    // Plan-022 T3 — every batch creation now requires an active approved
    // Recipe for the target material. Empty ingredients are fine for tests
    // that supply items explicitly or never go through derivation.
    Recipe::create([
        'sku' => 'R-'.strtoupper(substr($this->material->id, 0, 8)),
        'name' => 'Test recipe',
        'material_id' => $this->material->id,
        'output_quantity' => 100,
        'output_unit' => 'ml',
        'ingredients' => [],
        'is_active' => true,
        'approval_status' => 'approved',
        'organization_id' => $this->orgId,
        'brand_id' => $this->material->brand_id,
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

it('returns 401 when not authenticated for material batches', function () {
    Auth::forgetGuards();

    $this->getJson("{$this->baseUrl}/material-batches")
        ->assertUnauthorized();
});

// =========================================================================
//  Index
// =========================================================================

describe('index', function () {
    it('lists material batches for the user organization', function () {
        MaterialBatch::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/material-batches")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('does not show batches from other organizations', function () {
        MaterialBatch::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);
        MaterialBatch::factory()->create([
            'organization_id' => fake()->uuid(),
            'warehouse_id' => Warehouse::factory()->create()->id,
            'material_id' => $this->material->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/material-batches")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

// =========================================================================
//  Store
// =========================================================================

describe('store', function () {
    it('creates a material batch with items', function () {
        $payload = [
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'multiplier' => 2,
            'planned_yield' => 500,
            'yield_unit' => 'ml',
            'note' => 'Test batch',
            'items' => [
                [
                    'component_type' => 'variant',
                    'product_sku_id' => $this->variant->id,
                    'planned_quantity' => 100,
                    'unit' => 'g',
                ],
            ],
        ];

        $this->postJson("{$this->baseUrl}/material-batches", $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('material_batches', [
            'organization_id' => $this->orgId,
            'status' => 'draft',
        ]);
    });

    it('auto-generates batch code', function () {
        $response = $this->postJson("{$this->baseUrl}/material-batches", [
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'multiplier' => 1,
            'planned_yield' => 100,
            'yield_unit' => 'ml',
        ])->assertCreated();

        expect($response->json('data.batch_code'))->toStartWith('MB-');
    });

    it('validates required fields', function () {
        $this->postJson("{$this->baseUrl}/material-batches", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['warehouse_id', 'material_id', 'multiplier', 'planned_yield', 'yield_unit']);
    });
});

// =========================================================================
//  Show
// =========================================================================

describe('show', function () {
    it('returns a batch with items', function () {
        $batch = MaterialBatch::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/material-batches/{$batch->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $batch->id);
    });

    it('returns 403 for a batch from another organization', function () {
        $batch = MaterialBatch::factory()->create([
            'organization_id' => fake()->uuid(),
            'warehouse_id' => Warehouse::factory()->create()->id,
            'material_id' => $this->material->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->getJson("{$this->baseUrl}/material-batches/{$batch->id}")
            ->assertForbidden();
    });
});

// =========================================================================
//  Submit
// =========================================================================

describe('submit', function () {
    it('submits a draft batch with items to pending', function () {
        $batch = MaterialBatch::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);
        MaterialBatchItem::factory()->create([
            'material_batch_id' => $batch->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'component_type' => 'variant',
        ]);

        $this->postJson("{$this->baseUrl}/material-batches/{$batch->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    });

    // TC-B3-02 — submitting a draft batch with zero items is rejected with a
    // 422 carrying the "must have at least one item" contract message.
    it('cannot submit a batch without items', function () {
        $batch = MaterialBatch::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        expect($batch->items()->count())->toBe(0);

        $this->postJson("{$this->baseUrl}/material-batches/{$batch->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('error', 'INVALID_STATUS_TRANSITION')
            ->assertJson(fn ($json) => $json->where(
                'message',
                'Cannot submit: material batch must have at least one item.'
            )->etc());

        // Batch stays in draft — the failed transition must not mutate state.
        expect($batch->fresh()->status->value ?? $batch->fresh()->status)->toBe('draft');
    });

    it('auto-approves when warehouse auto_approve_batch is enabled', function () {
        $autoWarehouse = Warehouse::factory()->create([
            'organization_id' => $this->orgId,
            'auto_approve_batch' => true,
            'auto_approve_stock_in' => true,
            'auto_approve_stock_out' => true,
        ]);

        $batch = MaterialBatch::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $autoWarehouse->id,
            'material_id' => $this->material->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);
        MaterialBatchItem::factory()->create([
            'material_batch_id' => $batch->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'component_type' => 'variant',
        ]);

        $this->postJson("{$this->baseUrl}/material-batches/{$batch->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    });
});

// =========================================================================
//  Approve + Start + Complete
// =========================================================================

describe('complete', function () {
    it('completes a batch and creates stock transactions', function () {
        // Create stock for input items
        StockLevel::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'quantity' => 500,
        ]);

        // plan-040 M8 (TH.6): yield variance is now measured against the recipe
        // baseline (output_quantity 100 × multiplier). Pin multiplier=1 and
        // planned_yield=100 so the no-variance default holds (this test asserts
        // stock-transaction creation, not variance behaviour).
        $batch = MaterialBatch::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'status' => 'in_progress',
            'multiplier' => 1,
            'planned_yield' => 100,
            'yield_unit' => 'ml',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);
        MaterialBatchItem::factory()->create([
            'material_batch_id' => $batch->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
            'component_type' => 'variant',
            'planned_quantity' => 100,
            'actual_quantity' => null,
            'unit' => 'g',
        ]);

        $this->postJson("{$this->baseUrl}/material-batches/{$batch->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // Verify stock_out transaction was created for inputs
        $stockOut = StockTransaction::where('reference_type', 'material_batch')
            ->where('reference_id', $batch->id)
            ->where('type', 'stock_out')
            ->first();

        expect($stockOut)->not->toBeNull();

        // Verify stock_in transaction was created for output
        $stockIn = StockTransaction::where('reference_type', 'material_batch')
            ->where('reference_id', $batch->id)
            ->where('type', 'stock_in')
            ->first();

        expect($stockIn)->not->toBeNull();
    });

    it('cannot complete a draft batch', function () {
        $batch = MaterialBatch::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->postJson("{$this->baseUrl}/material-batches/{$batch->id}/complete")
            ->assertStatus(422);
    });
});

// =========================================================================
//  Cancel
// =========================================================================

describe('cancel', function () {
    it('cancels a draft batch', function () {
        $batch = MaterialBatch::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->postJson("{$this->baseUrl}/material-batches/{$batch->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    });

    it('cannot cancel a completed batch', function () {
        $batch = MaterialBatch::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'status' => 'completed',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
        ]);

        $this->postJson("{$this->baseUrl}/material-batches/{$batch->id}/cancel")
            ->assertStatus(422);
    });
});

// =========================================================================
//  State-machine guards (status codes) — TC-B3 negative cases
// =========================================================================

describe('state-machine guards', function () {
    // Helper: make a batch in a given status (no stock side-effects).
    $mk = fn ($ctx, $status) => MaterialBatch::factory()->create([
        'organization_id' => $ctx->orgId,
        'warehouse_id' => $ctx->warehouse->id,
        'material_id' => $ctx->material->id,
        'status' => $status,
        'stock_out_transaction_id' => null,
        'stock_in_transaction_id' => null,
    ]);

    // TC-B3-14 — approve only from pending; from draft is rejected.
    it('rejects approve from draft (422)', function () use ($mk) {
        $batch = $mk($this, 'draft');
        $this->postJson("{$this->baseUrl}/material-batches/{$batch->id}/approve")
            ->assertStatus(422);
    });

    // start only from approved; from draft is rejected.
    it('rejects start from draft (422)', function () use ($mk) {
        $batch = $mk($this, 'draft');
        $this->postJson("{$this->baseUrl}/material-batches/{$batch->id}/start")
            ->assertStatus(422);
    });

    // TC-B3-13 — complete only from in_progress; double-complete rejected.
    it('rejects complete on an already-completed batch (422)', function () use ($mk) {
        $batch = $mk($this, 'completed');
        $this->postJson("{$this->baseUrl}/material-batches/{$batch->id}/complete")
            ->assertStatus(422);
    });

    // TC-B3-12 — update only from draft; pending update rejected.
    it('rejects update when pending (422)', function () use ($mk) {
        $batch = $mk($this, 'pending');
        $this->putJson("{$this->baseUrl}/material-batches/{$batch->id}", ['note' => 'x'])
            ->assertStatus(422);
    });

    // TC-B3-10 — delete only from draft/cancelled; completed delete rejected.
    it('rejects delete when completed (422)', function () use ($mk) {
        $batch = $mk($this, 'completed');
        $this->deleteJson("{$this->baseUrl}/material-batches/{$batch->id}")
            ->assertStatus(422);
    });

    // TC-B3-08/09 CORRECTED — cancel IS allowed from approved & in_progress
    // (Plan-022 T7.1 reverses any stamped stock). Only completed/cancelled
    // are terminal. These document the real contract.
    it('allows cancel from approved (200)', function () use ($mk) {
        $batch = $mk($this, 'approved');
        $this->postJson("{$this->baseUrl}/material-batches/{$batch->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    });

    it('allows cancel from in_progress (200)', function () use ($mk) {
        $batch = $mk($this, 'in_progress');
        $this->postJson("{$this->baseUrl}/material-batches/{$batch->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    });
});

// =========================================================================
//  Org Isolation & 404
// =========================================================================

it('returns 403 when updating another org material batch', function () {
    $batch = MaterialBatch::factory()->create([
        'organization_id' => fake()->uuid(),
        'warehouse_id' => Warehouse::factory()->create()->id,
        'material_id' => $this->material->id,
        'status' => 'draft',
        'stock_out_transaction_id' => null,
        'stock_in_transaction_id' => null,
    ]);

    $this->putJson("{$this->baseUrl}/material-batches/{$batch->id}", ['note' => 'Hacked'])
        ->assertForbidden();
});

it('returns 404 for non-existent material batch', function () {
    $this->getJson("{$this->baseUrl}/material-batches/".fake()->uuid())
        ->assertNotFound();
});
