<?php

/**
 * Plan-017 T9.5.B7 — production batch cost breakdown endpoint coverage.
 *
 * MaterialBatchService::getCostBreakdown should:
 *   - read stock_out_transaction_items with material_lot_id stamped
 *     by FEFO during complete()
 *   - join back to MaterialLot for unit_cost / lot_code / supplier
 *   - return per-line breakdown + total + yield + computed output unit cost
 *   - return NULL when batch has no stock_out_transaction (i.e. not
 *     completed yet)
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialBatchItem;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialBatchStatusEnum;
use App\Services\Inventory\MaterialBatchService;
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
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'auto_approve_batch' => true,
    ]);
    $this->compA = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->compB = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->outputMaterial = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->service = app(MaterialBatchService::class);

    $role = Role::firstOrCreate(['slug' => 'org-admin'], ['name' => 'Org Admin', 'level' => 100]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->user->assignRole($role, $this->orgId);
});

it('returns NULL when batch has not been completed (no stock_out)', function () {
    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->outputMaterial->id,
        'status' => MaterialBatchStatusEnum::InProgress->value,
        'stock_out_transaction_id' => null,
    ]);

    expect($this->service->getCostBreakdown($batch))->toBeNull();
});

it('decomposes consumed lots into per-line cost rows after complete()', function () {
    // Lot A: 10kg @ ¥100/kg = ¥1000. Lot B: 5kg @ ¥200/kg = ¥1000.
    // Yield 10kg → expected output_unit_cost = 200 / kg, total = 2000.
    $lotA = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->compA->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 10,
        'qty_on_hand' => 10,
        'unit_cost' => 100,
        'total_cost' => 1000,
        'currency' => 'JPY',
        'cost_basis' => 'manual',
        'expiry_date' => now()->addDays(30)->toDateString(),
        'supplier_name' => 'Supplier A',
    ]);
    $lotB = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->compB->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 5,
        'qty_on_hand' => 5,
        'unit_cost' => 200,
        'total_cost' => 1000,
        'currency' => 'JPY',
        'cost_basis' => 'manual',
        'expiry_date' => now()->addDays(60)->toDateString(),
        'supplier_name' => 'Supplier B',
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->compA->id,
        'material_lot_id' => $lotA->id,
        'quantity' => 10,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->compB->id,
        'material_lot_id' => $lotB->id,
        'quantity' => 5,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->outputMaterial->id,
        'multiplier' => 1,
        'planned_yield' => 10,
        'yield_unit' => 'kg',
        'status' => MaterialBatchStatusEnum::InProgress->value,
        'started_at' => now()->subHour(),
    ]);
    MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'component_type' => 'material',
        'material_id' => $this->compA->id,
        'planned_quantity' => 10,
        'actual_quantity' => 10,
        'unit' => 'kg',
    ]);
    MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'component_type' => 'material',
        'material_id' => $this->compB->id,
        'planned_quantity' => 5,
        'actual_quantity' => 5,
        'unit' => 'kg',
    ]);

    $this->service->complete($batch, (string) $this->user->id, actualYield: 10);
    $batch->refresh();

    $breakdown = $this->service->getCostBreakdown($batch);

    expect($breakdown)->not->toBeNull()
        ->and($breakdown['currency'])->toBe('JPY')
        ->and($breakdown['total_consumed_cost'])->toBe(2000.0)
        ->and($breakdown['yield_qty'])->toBe(10.0)
        ->and($breakdown['output_unit_cost'])->toBe(200.0)
        ->and($breakdown['lines'])->toHaveCount(2);

    $byLot = collect($breakdown['lines'])->keyBy('lot_id');
    expect($byLot[$lotA->id]['qty_consumed'])->toBe(10.0)
        ->and($byLot[$lotA->id]['unit_cost'])->toBe(100.0)
        ->and($byLot[$lotA->id]['line_total'])->toBe(1000.0)
        ->and($byLot[$lotA->id]['supplier_name'])->toBe('Supplier A')
        ->and($byLot[$lotB->id]['line_total'])->toBe(1000.0);
});

it('exposes the cost-breakdown HTTP endpoint via Shop scope', function () {
    grantOrgAccess($this->user, $this->orgId);
    $this->actingAs($this->user);

    $shop = $this->branch;
    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->outputMaterial->id,
        'status' => MaterialBatchStatusEnum::Draft->value,
        'stock_out_transaction_id' => null,
    ]);

    $response = $this->getJson(
        "/api/v1/shops/{$shop->slug}/material-batches/{$batch->id}/cost-breakdown"
    )->assertOk();

    // Draft batch → no stock_out yet → service returns null → endpoint
    // wraps null inside { "data": null }.
    expect($response->json('data'))->toBeNull();
});
