<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\MaterialLotService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * plan-040 Phase B — MaterialLotService split conservation, same-day expiry, and
 * the return-qty upper bound (NEW-LOT-4 / NEW-LOT-8 / L2).
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create(['console_organization_id' => $this->orgId, 'console_brand_id' => $this->brand->id]);
    $this->warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $this->material = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

    // plan-040 NEW-LOT-5: split() now emits a backing StockTransaction whose
    // created_by_id is NOT NULL; the service falls back to auth()->id().
    $this->actingAs(User::factory()->create(['console_organization_id' => $this->orgId]));

    $this->service = app(MaterialLotService::class);
});

function makeSplitConsLot(array $overrides = []): MaterialLot
{
    return MaterialLot::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'material_id' => test()->material->id,
        'warehouse_id' => test()->warehouse->id,
        'status' => 'active',
        'unit' => 'kg',
        'received_qty' => 100,
        'qty_on_hand' => 100,
    ], $overrides));
}

it('NEW-LOT-4: split conserves SUM(received_qty) across the lineage', function () {
    $lot = makeSplitConsLot();

    $result = $this->service->split($lot, [
        ['qty' => 30],
        ['qty' => 40],
        ['qty' => 30],
    ]);

    $parent = $result['parent'];
    $childrenSum = collect($result['children'])->sum(fn ($c) => (float) $c->received_qty);

    // Full split → parent depleted, received_qty drained to 0; children carry the 100.
    expect((float) $parent->received_qty)->toBe(0.0)
        ->and($childrenSum)->toBe(100.0)
        ->and((float) $parent->received_qty + $childrenSum)->toBe(100.0);

    // Each split mints a parent→child genealogy edge.
    expect(GenealogyLink::where('parent_lot_id', $lot->id)->count())->toBe(3);
});

it('NEW-LOT-4: a partial split keeps the lineage total at the original received_qty', function () {
    $lot = makeSplitConsLot();

    $result = $this->service->split($lot, [['qty' => 30], ['qty' => 40]]);

    $childrenSum = collect($result['children'])->sum(fn ($c) => (float) $c->received_qty);

    expect((float) $result['parent']->received_qty)->toBe(30.0)
        ->and((float) $result['parent']->received_qty + $childrenSum)->toBe(100.0);
});

it('NEW-LOT-8: a lot received this afternoon expiring today is accepted (date vs date)', function () {
    $result = $this->service->receive([
        'organization_id' => $this->orgId,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'unit' => 'kg',
        'received_qty' => 10,
        'created_by_id' => (string) Str::uuid(),
        'received_at' => now()->setTime(14, 30),
        'expiry_date' => now()->toDateString(),
    ]);

    expect($result['lot'])->not->toBeNull()
        ->and((float) $result['lot']->received_qty)->toBe(10.0);
});

it('NEW-LOT-8: an expiry_date strictly before received_at is still rejected', function () {
    expect(fn () => $this->service->receive([
        'organization_id' => $this->orgId,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'unit' => 'kg',
        'received_qty' => 10,
        'created_by_id' => (string) Str::uuid(),
        'received_at' => now(),
        'expiry_date' => now()->subDay()->toDateString(),
    ]))->toThrow(ValidationException::class);
});

it('L2: returning more than received_qty allows is rejected', function () {
    $lot = makeSplitConsLot(['received_qty' => 100, 'qty_on_hand' => 20]);

    expect(fn () => $this->service->returnQty($lot, 90, 'over-return'))
        ->toThrow(ValidationException::class);

    // A return within the received_qty ceiling still works.
    $ok = $this->service->returnQty($lot->fresh(), 80, 'within-bound', (string) Str::uuid());
    expect((float) $ok->qty_on_hand)->toBe(100.0);
});

// =============================================================================
// #1238 — a split must not strip the justification off the reading it keeps
// =============================================================================

/**
 * Receiving enforces (temperature, override reason) as ONE unit: an out-of-range
 * reading cannot be accepted at all unless a non-empty reason is supplied
 * (MaterialLotService::receive, the `$outOfRange && ! $overrideReason` throw).
 *
 * split() copied `received_temperature` but not `temperature_override_reason`,
 * so the children displayed the same out-of-range figure with nothing next to
 * it. A cold-chain exception that had been formally accepted became an
 * unexplained one — and the whole model is serialised straight to the inventory
 * API (`MaterialLotResource` is `parent::toArray`), so that is what an auditor
 * reads.
 *
 * `coa_urls` went the same way: the Certificate of Analysis links simply
 * vanished from every child.
 */
it('carries the cold-chain override reason onto split children', function () {
    $lot = makeSplitConsLot([
        'received_temperature' => 12.5,
        'temperature_override_reason' => 'Xe lạnh hỏng máy nén, QA đã kiểm cảm quan và chấp nhận',
        'cost_per_unit' => 42.5,
    ]);

    $result = $this->service->split($lot, [['qty' => 40], ['qty' => 60]]);

    foreach ($result['children'] as $child) {
        expect((float) $child->received_temperature)->toBe(12.5)
            ->and($child->temperature_override_reason)
            ->toBe('Xe lạnh hỏng máy nén, QA đã kiểm cảm quan và chấp nhận')
            // The child already inherits unit_cost (what COGS reads), so leaving
            // its display twin null made the same lot show two different costs
            // depending on which field the screen happened to render.
            ->and((float) $child->cost_per_unit)->toBe(42.5);
    }
});

it('carries the CoA links onto split children', function () {
    $coa = ['https://example.test/coa/lot-a.pdf', 'https://example.test/coa/lot-a-2.pdf'];
    $lot = makeSplitConsLot(['coa_urls' => $coa]);

    $result = $this->service->split($lot, [['qty' => 50], ['qty' => 50]]);

    foreach ($result['children'] as $child) {
        expect($child->coa_urls)->toBe($coa);
    }
});

it('does not invent an as-entered quantity for a child nobody entered', function () {
    // The deliberate NON-copy. entered_quantity/entered_unit record what a human
    // typed at goods-in; a split child was produced by the system from a qty the
    // operator chose in different units-of-thought. Copying the parent's figures
    // would assert someone entered them, which is the kind of quiet fiction an
    // audit trail exists to prevent.
    $lot = makeSplitConsLot(['entered_quantity' => 100, 'entered_unit' => 'kg']);

    $result = $this->service->split($lot, [['qty' => 30], ['qty' => 70]]);

    foreach ($result['children'] as $child) {
        expect($child->entered_quantity)->toBeNull()
            ->and($child->entered_unit)->toBeNull();
    }
});
