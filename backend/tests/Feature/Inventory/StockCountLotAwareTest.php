<?php

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockCountService;
use Illuminate\Support\Str;

/**
 * plan-040 Phase E (TE.1–TE.4) — lot-aware stock count.
 *
 * Cluster E scenarios: NEW-STK-3, NEW-STK-4, NEW-STK-5, M2, NEW-STK-6.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
    ]);
    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $this->material->id,
        'unit' => 'kg',
        'ratio' => 1,
        'is_base' => true,
    ]);

    $this->service = app(StockCountService::class);
});

/**
 * Build a material with N lots in the warehouse, each carrying its own
 * per-lot StockLevel row mirroring qty_on_hand. Returns the created lots.
 *
 * @param  array<int, float>  $quantities
 * @return array<int, MaterialLot>
 */
function makeCountSetup(object $ctx, array $quantities): array
{
    $lots = [];
    foreach ($quantities as $i => $qty) {
        $lot = MaterialLot::factory()->create([
            'organization_id' => $ctx->orgId,
            'brand_id' => $ctx->brand->id,
            'material_id' => $ctx->material->id,
            'warehouse_id' => $ctx->warehouse->id,
            'status' => 'active',
            'received_qty' => $qty,
            'qty_on_hand' => $qty,
            'unit' => 'kg',
            'expiry_date' => now()->addMonths($i + 1)->format('Y-m-d'),
            'received_at' => now()->subDays(10 - $i),
        ]);

        StockLevel::factory()->create([
            'warehouse_id' => $ctx->warehouse->id,
            'product_sku_id' => null,
            'material_id' => $ctx->material->id,
            'material_lot_id' => $lot->id,
            'quantity' => $qty,
            'unit' => 'kg',
            'alert_enabled' => false,
        ]);

        $lots[] = $lot;
    }

    return $lots;
}

function makeDraftCount(object $ctx): StockCount
{
    return StockCount::factory()->create([
        'organization_id' => $ctx->orgId,
        'warehouse_id' => $ctx->warehouse->id,
        'scope' => 'partial',
        'status' => 'draft',
    ]);
}

// =========================================================================
//  NEW-STK-3 — addItems snapshots SUM across all lots
// =========================================================================

it('snapshots system_quantity as the SUM of all lots, not one arbitrary lot', function () {
    // 3 lots: 10 + 25 + 7 = 42 kg total.
    makeCountSetup($this, [10.0, 25.0, 7.0]);

    $count = makeDraftCount($this);

    $this->service->addItems($count, [
        ['material_id' => $this->material->id, 'unit' => 'kg'],
    ]);

    $item = StockCountItem::where('stock_count_id', $count->id)->firstOrFail();

    expect((float) $item->system_quantity)->toBe(42.0);
});

// =========================================================================
//  NEW-STK-4 — full-scope snapshot: one item per material
// =========================================================================

it('produces one count item per material for a full-scope count of a 3-lot material', function () {
    makeCountSetup($this, [10.0, 25.0, 7.0]);

    $creator = User::factory()->create();
    $count = $this->service->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'scope' => 'full',
        'created_by_id' => $creator->id,
    ]);

    $items = StockCountItem::where('stock_count_id', $count->id)
        ->where('material_id', $this->material->id)
        ->get();

    expect($items)->toHaveCount(1)
        ->and((float) $items->first()->system_quantity)->toBe(42.0);
});

// =========================================================================
//  NEW-STK-5 — approval reconciles SUM(on-hand) to counted absolute total
// =========================================================================

it('reconciles each lot on-hand to the counted absolute total on approve', function () {
    // 3 lots: 10 + 25 + 7 = 42 system. Operator counts 30 absolute (shrinkage 12).
    $lots = makeCountSetup($this, [10.0, 25.0, 7.0]);

    $count = makeDraftCount($this);
    $this->service->addItems($count, [
        ['material_id' => $this->material->id, 'unit' => 'kg'],
    ]);
    $this->service->start($count);

    $item = StockCountItem::where('stock_count_id', $count->id)->firstOrFail();
    $this->service->updateItems($count, [
        ['id' => $item->id, 'counted_quantity' => 30.0],
    ]);
    $this->service->submit($count);

    $approver = User::factory()->create();
    $this->service->approve($count, $approver->id);

    // SUM of lot on-hand across all lots == counted total (30), not 42.
    $sumOnHand = MaterialLot::whereIn('id', collect($lots)->pluck('id'))->sum('qty_on_hand');
    expect((float) $sumOnHand)->toBe(30.0);

    // Mirror StockLevels reconcile to the same total.
    $sumLevels = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $this->material->id)
        ->sum('quantity');
    expect((float) $sumLevels)->toBe(30.0);
});

// =========================================================================
//  M2 — sale interleaved between count create and approve is not overwritten
// =========================================================================

it('reconciles to the interleaved sale rather than overwriting it on approve', function () {
    // 3 lots: 10 + 25 + 7 = 42 system at snapshot.
    $lots = makeCountSetup($this, [10.0, 25.0, 7.0]);

    $count = makeDraftCount($this);
    $this->service->addItems($count, [
        ['material_id' => $this->material->id, 'unit' => 'kg'],
    ]);
    $this->service->start($count);

    $item = StockCountItem::where('stock_count_id', $count->id)->firstOrFail();

    // Operator physically counts 42 (matches snapshot).
    $this->service->updateItems($count, [
        ['id' => $item->id, 'counted_quantity' => 42.0],
    ]);

    // A sale of 5 kg interleaves AFTER update-items but BEFORE approve:
    // drop the oldest lot's on-hand + its StockLevel by 5 (now 5+25+7=37).
    $lots[0]->update(['qty_on_hand' => 5.0]);
    StockLevel::where('material_lot_id', $lots[0]->id)->update(['quantity' => 5.0]);

    $this->service->submit($count);

    $approver = User::factory()->create();
    $this->service->approve($count, $approver->id);

    // The count counted 42; physical now reflects the sale at 37. Re-snapshot
    // under lock yields difference = 42 - 37 = +5, so approve adds 5 back to
    // reconcile to the COUNTED truth (42) — the sale is not silently
    // overwritten by a stale 42==42 zero-difference.
    $item->refresh();
    expect((float) $item->system_quantity)->toBe(37.0)
        ->and((float) $item->difference)->toBe(5.0);

    $sumOnHand = MaterialLot::whereIn('id', collect($lots)->pluck('id'))->sum('qty_on_hand');
    $legacy = (float) StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $this->material->id)
        ->whereNull('material_lot_id')
        ->sum('quantity');
    expect((float) $sumOnHand + $legacy)->toBe(42.0);
});

// =========================================================================
//  NEW-STK-6 — approve locks the item; no adjustment off a half-written diff
// =========================================================================

it('recomputes difference under lock at approve so a stale difference is not used', function () {
    // 3 lots summing 42. updateItems writes counted=50 → difference +8.
    $lots = makeCountSetup($this, [10.0, 25.0, 7.0]);

    $count = makeDraftCount($this);
    $this->service->addItems($count, [
        ['material_id' => $this->material->id, 'unit' => 'kg'],
    ]);
    $this->service->start($count);

    $item = StockCountItem::where('stock_count_id', $count->id)->firstOrFail();
    $this->service->updateItems($count, [
        ['id' => $item->id, 'counted_quantity' => 50.0],
    ]);

    // Simulate a half-written / stale difference column directly on the row
    // (e.g. a racing writer that left difference inconsistent with reality).
    StockCountItem::where('id', $item->id)->update(['difference' => 999.0]);

    $this->service->submit($count);
    $approver = User::factory()->create();
    $this->service->approve($count, $approver->id);

    // Approve ignores the poisoned 999 and recomputes 50 - 42 = +8.
    $sumOnHand = (float) MaterialLot::whereIn('id', collect($lots)->pluck('id'))->sum('qty_on_hand');
    $legacy = (float) StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $this->material->id)
        ->whereNull('material_lot_id')
        ->sum('quantity');
    expect($sumOnHand + $legacy)->toBe(50.0);

    $item->refresh();
    expect((float) $item->difference)->toBe(8.0);
});
