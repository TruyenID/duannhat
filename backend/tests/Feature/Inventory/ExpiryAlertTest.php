<?php

/**
 * Plan-017 Tier 1.C — daily expiry-scan cron coverage.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\ExpiryAlert;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Services\Inventory\ExpiryAlertService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    // plan-040 TF.6 (M18): the scan now resolves "today" in the branch's timezone.
    // This suite builds expiry_date via Carbon::today() (app tz = UTC) and tests the
    // THRESHOLD-crossing logic, not timezone handling, so pin the branch tz to UTC —
    // otherwise the BranchFactory's default 'Asia/Tokyo' makes daysUntilExpiry drift
    // by a day whenever the suite runs during UTC evening (Tokyo already next day),
    // flaking the counts. Per-branch timezone behaviour is covered by Plan040AlertsJobsTest.
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
        'timezone' => config('app.timezone'),
    ]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->service = app(ExpiryAlertService::class);
});

function lotExpiringIn(int $days, array $overrides = []): MaterialLot
{
    return MaterialLot::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'material_id' => test()->material->id,
        'warehouse_id' => test()->warehouse->id,
        'status' => MaterialLotStatusEnum::Active->value,
        'expiry_date' => Carbon::today()->addDays($days)->toDateString(),
    ], $overrides));
}

it('creates exactly one alert per (lot, threshold) crossing', function () {
    lotExpiringIn(7);
    lotExpiringIn(3);
    lotExpiringIn(1);
    lotExpiringIn(15); // outside any threshold

    $result = $this->service->scan();

    expect($result['alerts_created'])->toBe(3)
        ->and(ExpiryAlert::count())->toBe(3);
});

it('is idempotent — running twice the same day produces zero new alerts', function () {
    lotExpiringIn(7);
    lotExpiringIn(3);

    $first = $this->service->scan();
    $second = $this->service->scan();

    expect($first['alerts_created'])->toBe(2)
        ->and($second['alerts_created'])->toBe(0)
        ->and(ExpiryAlert::count())->toBe(2);
});

it('respects a custom expiry_alert_thresholds override on the material', function () {
    $this->material->update(['expiry_alert_thresholds' => [14, 5]]);

    lotExpiringIn(14);
    lotExpiringIn(5);
    lotExpiringIn(7); // would fire under default but not under override

    $result = $this->service->scan();

    expect($result['alerts_created'])->toBe(2);
});

it('skips already-expired lots (expiry_date < today)', function () {
    lotExpiringIn(-2);
    lotExpiringIn(7);

    $result = $this->service->scan();

    expect($result['alerts_created'])->toBe(1);
});

it('skips non-active lots', function () {
    lotExpiringIn(7, ['status' => MaterialLotStatusEnum::Quarantined->value, 'quarantine_reason' => 'lab']);

    $result = $this->service->scan();

    expect($result['alerts_created'])->toBe(0);
});

it('exposes the artisan command material-lots:scan-expiring', function () {
    lotExpiringIn(7);

    $this->artisan('material-lots:scan-expiring')
        ->assertSuccessful();

    expect(ExpiryAlert::count())->toBe(1);
});
