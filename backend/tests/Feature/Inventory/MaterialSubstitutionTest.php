<?php

/**
 * Plan-018 Group E.2 — Material substitution tests.
 *
 * plan-018 audit fix — these now drive the REAL consumption path (a sales
 * stock_out transaction) rather than reflecting into pickLotsForConsumption with
 * a method flag no production caller ever set. The opt-in lives on the rule
 * (`auto_substitute` + validity window), and a substitution must leave a
 * genealogy edge (source_event_type='substitution').
 */

use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\MaterialSubstitutionRule;
use App\Models\Organization;
use App\Models\StockTransactionItem;
use App\Models\Warehouse;
use App\Services\Inventory\StockTransactionService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'sub-'.Str::random(4),
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'auto_approve_stock_out' => true,
    ]);

    $this->primaryMaterial = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->substituteMaterial = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->service = app(StockTransactionService::class);

    $this->substituteLot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->substituteMaterial->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 100,
        'received_qty' => 100,
        'status' => 'active',
        'expiry_date' => now()->addDays(30)->format('Y-m-d'),
    ]);

    // Substitute lot needs a StockLevel row to be drained by the stock_out.
    $this->substituteLot->stockLevels()->create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->substituteMaterial->id,
        'quantity' => 100,
        'unit' => $this->substituteLot->unit,
    ]);
});

if (! function_exists('reflectPick')) {
    function reflectPick(StockTransactionService $service, string $materialId, string $warehouseId, float $qty): array
    {
        $method = new ReflectionMethod($service, 'pickLotsForConsumption');

        return $method->invoke($service, $materialId, $warehouseId, $qty, 'kg');
    }
}

it('opted-in rule (auto_substitute=true, in window) falls back to substitute lot', function () {
    MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $this->primaryMaterial->id,
        'substitute_material_id' => $this->substituteMaterial->id,
        'conversion_factor' => 1.0,
        'priority' => 0,
        'is_active' => true,
        'auto_substitute' => true,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDay(),
    ]);

    $allocations = reflectPick($this->service, $this->primaryMaterial->id, $this->warehouse->id, 10.0);

    expect($allocations)->toHaveCount(1);
    expect($allocations[0]['qty'])->toBe(10.0);
    expect($allocations[0]['substituted'])->toBeTrue();
    expect($allocations[0]['material_id'])->toBe($this->substituteMaterial->id);
});

it('rule with auto_substitute=false does NOT substitute (per-rule opt-in)', function () {
    MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $this->primaryMaterial->id,
        'substitute_material_id' => $this->substituteMaterial->id,
        'conversion_factor' => 1.0,
        'priority' => 0,
        'is_active' => true,
        'auto_substitute' => false,
    ]);

    // Off-by-default: no fallback, so demand for the (stockless) primary throws.
    reflectPick($this->service, $this->primaryMaterial->id, $this->warehouse->id, 10.0);
})->throws(InsufficientStockException::class);

it('rule outside its temporal window does NOT substitute', function () {
    MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $this->primaryMaterial->id,
        'substitute_material_id' => $this->substituteMaterial->id,
        'conversion_factor' => 1.0,
        'priority' => 0,
        'is_active' => true,
        'auto_substitute' => true,
        'valid_from' => now()->addDays(5),
        'valid_until' => now()->addDays(10),
    ]);

    reflectPick($this->service, $this->primaryMaterial->id, $this->warehouse->id, 10.0);
})->throws(InsufficientStockException::class);

it('conversion_factor scales the substitute quantity drawn', function () {
    MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $this->primaryMaterial->id,
        'substitute_material_id' => $this->substituteMaterial->id,
        'conversion_factor' => 1.5,
        'priority' => 0,
        'is_active' => true,
        'auto_substitute' => true,
    ]);

    $allocations = reflectPick($this->service, $this->primaryMaterial->id, $this->warehouse->id, 10.0);

    // 10 units of primary × 1.5 ratio = 15 units of substitute drawn.
    expect($allocations)->toHaveCount(1);
    expect($allocations[0]['qty'])->toBe(15.0);
});

it('conversion_factor producing a fractional draw is money-exact', function () {
    // Test-gap: existing coverage only asserts whole-number conversion results
    // (1.5×10=15). A binary-exact fractional factor (1.25) on a demand of 10
    // must draw exactly 12.5 of the substitute — no truncation to an integer,
    // no float drift.
    MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $this->primaryMaterial->id,
        'substitute_material_id' => $this->substituteMaterial->id,
        'conversion_factor' => 1.25,
        'priority' => 0,
        'is_active' => true,
        'auto_substitute' => true,
    ]);

    $allocations = reflectPick($this->service, $this->primaryMaterial->id, $this->warehouse->id, 10.0);

    // 10 × 1.25 = 12.5 units of substitute (lot has 100 on hand → single alloc).
    expect($allocations)->toHaveCount(1);
    expect($allocations[0]['qty'])->toBe(12.5);
    expect($allocations[0]['material_id'])->toBe($this->substituteMaterial->id);
});

it('fractional conversion draw is capped by substitute availability and residual short', function () {
    // Substitute lot holds only 100. A factor of 1.25 on a demand of 96 needs
    // 120 of the substitute — availability caps the draw at exactly 100.0 (money
    // exact) and the residual primary demand (100/1.25 = 80 covered → 16 unmet)
    // falls through, so with no allow-short the pick raises InsufficientStock.
    MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $this->primaryMaterial->id,
        'substitute_material_id' => $this->substituteMaterial->id,
        'conversion_factor' => 1.25,
        'priority' => 0,
        'is_active' => true,
        'auto_substitute' => true,
    ]);

    reflectPick($this->service, $this->primaryMaterial->id, $this->warehouse->id, 96.0);
})->throws(InsufficientStockException::class);

it('real consumption path substitutes end-to-end and records a genealogy edge', function () {
    MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $this->primaryMaterial->id,
        'substitute_material_id' => $this->substituteMaterial->id,
        'conversion_factor' => 1.0,
        'priority' => 0,
        'is_active' => true,
        'auto_substitute' => true,
    ]);

    // Primary is out of active lots → the sales stock_out must fall back.
    $transaction = $this->service->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'type' => 'stock_out',
        'sub_type' => 'sales',
        'created_by_id' => (string) Str::uuid(),
        'items' => [[
            'material_id' => $this->primaryMaterial->id,
            'quantity' => 10,
            'unit' => $this->substituteLot->unit,
        ]],
    ]);

    $this->service->submit($transaction);

    // The item that actually shipped carries the SUBSTITUTE material + lot.
    $item = StockTransactionItem::where('stock_transaction_id', $transaction->id)
        ->whereNotNull('material_lot_id')
        ->first();

    expect($item)->not->toBeNull();
    expect($item->material_id)->toBe($this->substituteMaterial->id);
    expect($item->material_lot_id)->toBe($this->substituteLot->id);

    // The substitute lot was drained (started at 100, consumed 10).
    expect((float) $this->substituteLot->fresh()->qty_on_hand)->toBe(90.0);

    // Finding 2 — a substitution genealogy edge exists tying the substitute lot
    // to this transaction.
    $edge = GenealogyLink::where('parent_lot_id', $this->substituteLot->id)
        ->where('source_event_type', 'substitution')
        ->where('source_event_id', $transaction->id)
        ->first();

    expect($edge)->not->toBeNull();
    expect((float) $edge->qty_consumed)->toBe(10.0);
});
