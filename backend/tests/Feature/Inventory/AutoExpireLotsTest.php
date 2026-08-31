<?php

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotStatusEnum;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
    ]);
    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
});

function makeLotWithExpiry(string $orgId, string $brandId, string $materialId, string $warehouseId, ?string $expiry, string $status = 'active'): MaterialLot
{
    return MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'material_id' => $materialId,
        'warehouse_id' => $warehouseId,
        'status' => $status,
        'source' => 'inbound',
        'expiry_date' => $expiry,
    ]);
}

it('flips active lots past expiry to expired', function () {
    $stale = makeLotWithExpiry($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, now()->subDay()->toDateString());

    $this->artisan('material-lots:auto-expire')->assertExitCode(0);

    expect($stale->fresh()->status)
        ->toBe(MaterialLotStatusEnum::Expired);
});

it('leaves active lots not yet expired untouched', function () {
    $fresh = makeLotWithExpiry($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, now()->addDays(5)->toDateString());

    $this->artisan('material-lots:auto-expire')->assertExitCode(0);

    expect($fresh->fresh()->status)
        ->toBe(MaterialLotStatusEnum::Active);
});

it('also expires quarantined lots past expiry', function () {
    $q = makeLotWithExpiry($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, now()->subDays(3)->toDateString(), 'quarantined');

    $this->artisan('material-lots:auto-expire')->assertExitCode(0);

    expect($q->fresh()->status)
        ->toBe(MaterialLotStatusEnum::Expired);
});

it('is idempotent — running twice flips zero additional rows', function () {
    makeLotWithExpiry($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, now()->subDay()->toDateString());

    $this->artisan('material-lots:auto-expire')->assertExitCode(0);
    $expiredCountAfterFirst = MaterialLot::where('status', MaterialLotStatusEnum::Expired->value)->count();

    $this->artisan('material-lots:auto-expire')->assertExitCode(0);
    $expiredCountAfterSecond = MaterialLot::where('status', MaterialLotStatusEnum::Expired->value)->count();

    expect($expiredCountAfterSecond)->toEqual($expiredCountAfterFirst);
});

it('leaves lots without an expiry_date alone', function () {
    $foreverFresh = makeLotWithExpiry($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, null);

    $this->artisan('material-lots:auto-expire')->assertExitCode(0);

    expect($foreverFresh->fresh()->status)
        ->toBe(MaterialLotStatusEnum::Active);
});

it('does NOT flip a lot expiring today (strict less-than boundary)', function () {
    // The command flips only lots whose expiry_date is strictly before the
    // branch "today". A lot expiring today is still good for the whole day.
    $tz = config('app.default_branch_timezone', 'Asia/Tokyo');
    $today = makeLotWithExpiry($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, today($tz)->toDateString());

    $this->artisan('material-lots:auto-expire')->assertExitCode(0);

    expect($today->fresh()->status)
        ->toBe(MaterialLotStatusEnum::Active);
});

it('does NOT touch disposed lots past expiry (only active/quarantined are swept)', function () {
    $disposed = makeLotWithExpiry(
        $this->orgId,
        $this->brand->id,
        $this->material->id,
        $this->warehouse->id,
        now()->subDays(3)->toDateString(),
        'disposed',
    );

    $this->artisan('material-lots:auto-expire')->assertExitCode(0);

    expect($disposed->fresh()->status)
        ->toBe(MaterialLotStatusEnum::Disposed);
});

it('leaves an already-expired lot expired (no churn)', function () {
    $already = makeLotWithExpiry(
        $this->orgId,
        $this->brand->id,
        $this->material->id,
        $this->warehouse->id,
        now()->subDays(10)->toDateString(),
        'expired',
    );

    $this->artisan('material-lots:auto-expire')->assertExitCode(0);

    expect($already->fresh()->status)
        ->toBe(MaterialLotStatusEnum::Expired);
});
