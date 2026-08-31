<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialLot;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Models\StockTransaction;
use App\Models\StockTransactionItem;
use App\Models\Warehouse;
use App\Services\Inventory\MaterialBatchService;
use App\Support\BusinessClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    // #1091 — freeze at an instant where the SHOP day and the server day
    // genuinely differ (23:30 UTC = 08:30 next-day Tokyo). Shelf life counts
    // from the shop's day, so an un-frozen clock made these assertions depend
    // on what hour CI happened to run.
    Carbon::setTestNow(Carbon::parse('2026-07-26 23:30:00', 'UTC'));

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
        'timezone' => 'Asia/Tokyo',
    ]);
    // The shop's day, which is what shelf life must count from.
    $this->shopToday = BusinessClock::now((string) $this->branch->id);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'auto_approve_batch' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
    ]);
    $this->batches = app(MaterialBatchService::class);
});

function batchForExpiry(string $orgId, string $brandId, string $warehouseId, string $materialId, ?string $expiryOverride = null): MaterialBatch
{
    return MaterialBatch::factory()->create([
        'organization_id' => $orgId,
        'warehouse_id' => $warehouseId,
        'material_id' => $materialId,
        'planned_yield' => 1000,
        'yield_unit' => 'ml',
        'status' => 'in_progress',
        'expiry_date' => $expiryOverride,
    ]);
}

it('stamps output expiry from material.shelf_life_days when no parent', function () {
    $produced = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'shelf_life_days' => 3,
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $produced->id,
        'unit' => 'ml',
        'is_base' => true,
        'ratio' => 1,
    ]);

    $batch = batchForExpiry($this->orgId, $this->brand->id, $this->warehouse->id, $produced->id);
    $this->batches->complete($batch, (string) Str::uuid(), 1000);

    $output = MaterialLot::where('produced_by_batch_id', $batch->id)->first();

    // Shop day + 3, not server day + 3: a batch produced 08:30 Tokyo on the
    // 27th expires on the 30th Tokyo, even though UTC still reads the 26th.
    expect($output->expiry_date->toDateString())
        ->toEqual($this->shopToday->addDays(3)->toDateString())
        ->and($output->expiry_date->toDateString())
        ->not->toEqual(now()->copy()->addDays(3)->toDateString());
});

it('uses parent min expiry when shorter than policy', function () {
    $produced = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'shelf_life_days' => 30, // policy says 30 days
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $produced->id,
        'unit' => 'ml',
        'is_base' => true,
        'ratio' => 1,
    ]);

    // Build a parent lot expiring in 2 days, then complete a batch that
    // consumes it via stock_out_transaction_items.
    $parent = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $produced->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'active',
        'source' => 'inbound',
        'unit' => 'ml',
        'received_qty' => 10,
        'qty_on_hand' => 10,
        'expiry_date' => now()->copy()->addDays(2)->toDateString(),
    ]);

    $batch = batchForExpiry($this->orgId, $this->brand->id, $this->warehouse->id, $produced->id);

    // Manually inject a stock_out transaction item referencing the parent lot
    // so consumedLots picks it up (mimics what start()→complete() FEFO does).
    $stockOut = StockTransaction::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'type' => 'stock_out',
        'sub_type' => 'production',
        'reference_type' => 'material_batch',
        'reference_id' => $batch->id,
        'status' => 'completed',
    ]);
    StockTransactionItem::factory()->create([
        'stock_transaction_id' => $stockOut->id,
        'material_id' => $produced->id,
        'material_lot_id' => $parent->id,
        'quantity' => 1,
        'base_quantity' => 1,
        'unit' => 'ml',
    ]);
    $batch->update(['stock_out_transaction_id' => $stockOut->id]);

    $this->batches->complete($batch->fresh(), (string) Str::uuid(), 1000);

    $output = MaterialLot::where('produced_by_batch_id', $batch->id)->first();

    expect($output->expiry_date->toDateString())
        ->toEqual(now()->copy()->addDays(2)->toDateString());
});

it('leaves output expiry null when both policy and parent are absent', function () {
    $produced = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'shelf_life_days' => null,
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $produced->id,
        'unit' => 'ml',
        'is_base' => true,
        'ratio' => 1,
    ]);

    $batch = batchForExpiry($this->orgId, $this->brand->id, $this->warehouse->id, $produced->id);
    $this->batches->complete($batch, (string) Str::uuid(), 1000);

    $output = MaterialLot::where('produced_by_batch_id', $batch->id)->first();

    expect($output->expiry_date)->toBeNull();
});

/**
 * Attach one stock_out transaction to $batch consuming a parent lot per
 * entry in $parentExpiries (null = a parent lot with no expiry_date), so
 * complete()'s consumedLots resolution sees them.
 */
function attachConsumedParents(array $ctx, MaterialBatch $batch, array $parentExpiries): void
{
    $stockOut = StockTransaction::factory()->create([
        'organization_id' => $ctx['orgId'],
        'warehouse_id' => $ctx['warehouse']->id,
        'type' => 'stock_out',
        'sub_type' => 'production',
        'reference_type' => 'material_batch',
        'reference_id' => $batch->id,
        'status' => 'completed',
    ]);

    foreach ($parentExpiries as $expiry) {
        $parent = MaterialLot::factory()->create([
            'organization_id' => $ctx['orgId'],
            'brand_id' => $ctx['brand']->id,
            'material_id' => $batch->material_id,
            'warehouse_id' => $ctx['warehouse']->id,
            'status' => 'active',
            'source' => 'inbound',
            'unit' => 'ml',
            'received_qty' => 10,
            'qty_on_hand' => 10,
            'expiry_date' => $expiry,
        ]);
        StockTransactionItem::factory()->create([
            'stock_transaction_id' => $stockOut->id,
            'material_id' => $batch->material_id,
            'material_lot_id' => $parent->id,
            'quantity' => 1,
            'base_quantity' => 1,
            'unit' => 'ml',
        ]);
    }

    $batch->update(['stock_out_transaction_id' => $stockOut->id]);
}

function producedMlMaterial(array $ctx, ?int $shelfLifeDays): Material
{
    $produced = Material::factory()->create([
        'organization_id' => $ctx['orgId'],
        'brand_id' => $ctx['brand']->id,
        'shelf_life_days' => $shelfLifeDays,
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $produced->id,
        'unit' => 'ml',
        'is_base' => true,
        'ratio' => 1,
    ]);

    return $produced;
}

it('uses the shelf-life policy when it is stricter than every parent', function () {
    $ctx = ['orgId' => $this->orgId, 'brand' => $this->brand, 'warehouse' => $this->warehouse];
    $produced = producedMlMaterial($ctx, shelfLifeDays: 3); // policy = +3

    $batch = batchForExpiry($this->orgId, $this->brand->id, $this->warehouse->id, $produced->id);
    attachConsumedParents($ctx, $batch, [
        now()->copy()->addDays(30)->toDateString(),
        now()->copy()->addDays(60)->toDateString(),
    ]);

    $this->batches->complete($batch->fresh(), (string) Str::uuid(), 1000);

    $output = MaterialLot::where('produced_by_batch_id', $batch->id)->first();

    expect($output->expiry_date->toDateString())
        ->toEqual($this->shopToday->addDays(3)->toDateString());
});

it('takes the minimum non-null parent expiry when one parent has no expiry', function () {
    $ctx = ['orgId' => $this->orgId, 'brand' => $this->brand, 'warehouse' => $this->warehouse];
    $produced = producedMlMaterial($ctx, shelfLifeDays: null); // no policy

    $batch = batchForExpiry($this->orgId, $this->brand->id, $this->warehouse->id, $produced->id);
    attachConsumedParents($ctx, $batch, [
        now()->copy()->addDays(5)->toDateString(),
        null, // no expiry — must be excluded from the min, not treated as 0
        now()->copy()->addDays(10)->toDateString(),
    ]);

    $this->batches->complete($batch->fresh(), (string) Str::uuid(), 1000);

    $output = MaterialLot::where('produced_by_batch_id', $batch->id)->first();

    expect($output->expiry_date->toDateString())
        ->toEqual(now()->copy()->addDays(5)->toDateString());
});

it('lets an operator override win only when it is the strictest candidate', function () {
    $ctx = ['orgId' => $this->orgId, 'brand' => $this->brand, 'warehouse' => $this->warehouse];
    $produced = producedMlMaterial($ctx, shelfLifeDays: 3); // policy = +3

    // Operator sets an even shorter date (+1) on the batch form.
    $batch = batchForExpiry(
        $this->orgId,
        $this->brand->id,
        $this->warehouse->id,
        $produced->id,
        now()->copy()->addDay()->toDateString(),
    );
    attachConsumedParents($ctx, $batch, [now()->copy()->addDays(30)->toDateString()]);

    $this->batches->complete($batch->fresh(), (string) Str::uuid(), 1000);

    $output = MaterialLot::where('produced_by_batch_id', $batch->id)->first();

    expect($output->expiry_date->toDateString())
        ->toEqual(now()->copy()->addDay()->toDateString());
});

it('does NOT let a looser operator override outlive the shelf-life policy', function () {
    // Documents the CURRENT min() semantics: the operator date is one of the
    // candidates, not an absolute override. A +100 operator date cannot beat a
    // +3 policy — the stricter policy still bounds the output lot.
    $ctx = ['orgId' => $this->orgId, 'brand' => $this->brand, 'warehouse' => $this->warehouse];
    $produced = producedMlMaterial($ctx, shelfLifeDays: 3); // policy = +3

    $batch = batchForExpiry(
        $this->orgId,
        $this->brand->id,
        $this->warehouse->id,
        $produced->id,
        now()->copy()->addDays(100)->toDateString(),
    );

    $this->batches->complete($batch->fresh(), (string) Str::uuid(), 1000);

    $output = MaterialLot::where('produced_by_batch_id', $batch->id)->first();

    expect($output->expiry_date->toDateString())
        ->toEqual($this->shopToday->addDays(3)->toDateString());
});

afterEach(function () {
    Carbon::setTestNow();
});
