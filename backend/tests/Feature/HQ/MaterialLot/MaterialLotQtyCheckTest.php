<?php

/**
 * Plan-018 Group A — negative-stock guard at lot grain (qty_on_hand >= 0).
 *
 * Omnify YAML cannot express a DB CHECK constraint and this repo blocks
 * hand-written migrations, so the invariant is enforced in the ONE path that
 * drains a lot: StockTransactionService::completeTransaction. These tests drive
 * that path genuinely (no skip) — a stock-out that would push a lot's
 * qty_on_hand below zero is rejected, and the lot balance is never persisted
 * negative.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\StockLevel;
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
        'slug' => 'chk-'.Str::random(4),
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
    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->service = app(StockTransactionService::class);
});

/**
 * Build + submit (auto-approve) a manual stock-out that draws `qty` from an
 * explicit lot. The StockLevel for (warehouse, material, lot) is seeded to
 * `levelQty` so the stock_level shortage gate is cleared and the lot-grain
 * guard is the thing under test.
 */
function drainLot(
    StockTransactionService $service,
    string $orgId,
    Warehouse $warehouse,
    Material $material,
    MaterialLot $lot,
    float $qty,
    float $levelQty,
) {
    StockLevel::create([
        'warehouse_id' => $warehouse->id,
        'material_id' => $material->id,
        'material_lot_id' => $lot->id,
        'quantity' => $levelQty,
        'unit' => 'g',
        'alert_enabled' => true,
    ]);

    $txn = $service->create([
        'organization_id' => $orgId,
        'warehouse_id' => $warehouse->id,
        'type' => 'stock_out',
        'sub_type' => 'adjustment_out',
        'created_by_id' => (string) Str::uuid(),
        'items' => [[
            'material_id' => $material->id,
            'material_lot_id' => $lot->id,
            'quantity' => $qty,
            'unit' => 'g',
        ]],
    ]);

    return $service->submit($txn);
}

it('rejects a stock-out that would drive a lot qty_on_hand below zero', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'active',
        'source' => 'inbound',
        'unit' => 'g',
        'received_qty' => 5,
        'qty_on_hand' => 5,
    ]);

    // StockLevel says 100 (divergence / tampering), but the lot holds only 5.
    // Draining 10 clears the stock_level gate yet would take the lot negative.
    expect(fn () => drainLot($this->service, $this->orgId, $this->warehouse, $this->material, $lot, 10, 100))
        ->toThrow(RuntimeException::class);

    // Transaction rolled back — the lot balance is untouched, never negative.
    expect((float) MaterialLot::find($lot->id)->qty_on_hand)->toBe(5.0);
});

it('allows a stock-out that drains a lot to exactly zero', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'active',
        'source' => 'inbound',
        'unit' => 'g',
        'received_qty' => 10,
        'qty_on_hand' => 10,
    ]);

    drainLot($this->service, $this->orgId, $this->warehouse, $this->material, $lot, 10, 10);

    expect((float) MaterialLot::find($lot->id)->qty_on_hand)->toBe(0.0);
});
