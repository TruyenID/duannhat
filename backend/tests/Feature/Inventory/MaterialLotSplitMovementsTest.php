<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\MaterialLotService;
use Illuminate\Support\Str;

/**
 * plan-040 NEW-LOT-5 — MaterialLotService::split() must emit balanced
 * StockMovement rows (an `out` on the parent lot and an `in` on each child lot)
 * tied to a real backing StockTransaction, and replaying those movements must
 * reconcile to the stock_levels the split wrote.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create(['console_organization_id' => $this->orgId, 'console_brand_id' => $this->brand->id]);
    $this->warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $this->material = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

    // The split's backing StockTransaction needs a non-null created_by_id; the
    // service falls back to auth()->id().
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->actingAs($this->user);

    $this->service = app(MaterialLotService::class);
});

function makeSplitLot(array $overrides = []): MaterialLot
{
    $lot = MaterialLot::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'material_id' => test()->material->id,
        'warehouse_id' => test()->warehouse->id,
        'status' => 'active',
        'unit' => 'kg',
        'received_qty' => 100,
        'qty_on_hand' => 100,
    ], $overrides));

    // The parent's per-lot stock_level mirrors qty_on_hand — split reads it.
    StockLevel::create([
        'warehouse_id' => $lot->warehouse_id,
        'material_id' => $lot->material_id,
        'material_lot_id' => $lot->id,
        'quantity' => $lot->qty_on_hand,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    return $lot;
}

it('NEW-LOT-5: split emits a balanced out/in StockMovement set tied to one backing transaction', function () {
    $lot = makeSplitLot();

    $result = $this->service->split($lot, [['qty' => 30], ['qty' => 40]]);

    $movements = StockMovement::where('material_id', $this->material->id)->get();

    // One `out` on the parent + one `in` per child = 3 rows, all on one txn.
    expect($movements)->toHaveCount(3)
        ->and($movements->pluck('stock_transaction_id')->unique())->toHaveCount(1);

    $out = $movements->first(fn ($m) => $m->movement_type->value === 'out');
    expect((string) $out->material_lot_id)->toBe((string) $lot->id)
        ->and((float) $out->quantity)->toBe(70.0)
        ->and((float) $out->quantity_before)->toBe(100.0)
        ->and((float) $out->quantity_after)->toBe(30.0);

    $ins = $movements->filter(fn ($m) => $m->movement_type->value === 'in');
    expect($ins)->toHaveCount(2)
        ->and($ins->sum(fn ($m) => (float) $m->quantity))->toBe(70.0);

    // Balanced: total in == total out (the split moved 70 between lots).
    expect($ins->sum(fn ($m) => (float) $m->quantity))->toBe((float) $out->quantity);

    // The backing transaction FK is non-null (StockMovement requires it).
    expect($out->stock_transaction_id)->not->toBeNull();
});

it('NEW-LOT-5: replaying movements reconciles to each lot stock_level', function () {
    $lot = makeSplitLot();

    $result = $this->service->split($lot, [['qty' => 30], ['qty' => 40]]);

    $lots = array_merge([$result['parent']], $result['children']);

    foreach ($lots as $childOrParent) {
        $level = StockLevel::where('material_lot_id', $childOrParent->id)->first();

        // Net of signed movements for this lot must equal the stock_level.
        $net = StockMovement::where('material_lot_id', $childOrParent->id)
            ->get()
            ->sum(fn ($m) => $m->movement_type->value === 'in'
                ? (float) $m->quantity
                : -(float) $m->quantity);

        // Parent: started at 100 (one `in` was never written for it because its
        // initial stock came from receive, so its net here is just the split
        // `out` of -70). Reconciliation check is "final stock_level == its own
        // pre-split level + net movement", which equals qty_on_hand.
        expect((float) $level->quantity)->toBe((float) $childOrParent->fresh()->qty_on_hand);

        if ($childOrParent->id === $result['parent']->id) {
            // Parent: 100 - 70 = 30.
            expect($net)->toBe(-70.0)
                ->and((float) $level->quantity)->toBe(30.0);
        } else {
            // Children: a single `in` equal to their stock_level.
            expect($net)->toBe((float) $level->quantity);
        }
    }
});

it('NEW-LOT-5: a cross-warehouse split lands the child in movement in the target warehouse', function () {
    $lot = makeSplitLot();
    $target = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);

    $result = $this->service->split($lot, [['qty' => 25, 'target_warehouse_id' => $target->id]]);

    $child = $result['children'][0];
    $childIn = StockMovement::where('material_lot_id', $child->id)->first();

    expect((string) $childIn->warehouse_id)->toBe((string) $target->id)
        ->and($childIn->movement_type->value)->toBe('in')
        ->and((float) $childIn->quantity)->toBe(25.0);

    // The parent `out` stays in the source warehouse.
    $parentOut = StockMovement::where('material_lot_id', $lot->id)->first();
    expect((string) $parentOut->warehouse_id)->toBe((string) $this->warehouse->id)
        ->and($parentOut->movement_type->value)->toBe('out');
});
