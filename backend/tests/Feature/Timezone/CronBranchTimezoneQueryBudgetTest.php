<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Support\BusinessClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * #1161 — the cron layer's branch-timezone query budget.
 *
 * This is the guard for the failure that started all of it. `BusinessClock`
 * queried per CALL, so a command walking lots with chunkById issued one query
 * per ROW. Rather than fix the class, the command hand-rolled
 * `$lot->warehouse?->branch?->timezone ?: $default` — a copy without the IANA
 * check and without the fallback warning. The cost WAS the cause.
 *
 * A memo alone is not enough: it makes the cost one query per DISTINCT BRANCH,
 * which on a fleet of hundreds of shops is still N+1 wearing a different name.
 * Hence warmFor(), and hence this budget — asserted in queries, because a
 * comment saying "batched" cannot fail.
 */
function budgetBranch(string $timezone, string $country = 'JP'): Branch
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
        'operating_country' => $country,
    ]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);

    return Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'timezone' => $timezone,
        'is_active' => true,
    ]);
}

/** A long-expired lot in its own warehouse on $branch. */
function budgetExpiredLot(Branch $branch): MaterialLot
{
    $orgId = (string) $branch->console_organization_id;

    $warehouse = Warehouse::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
    ]);

    $brandId = Brand::query()->where('console_organization_id', $orgId)->value('id');

    $material = Material::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
    ]);

    return MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'warehouse_id' => $warehouse->id,
        'material_id' => $material->id,
        'status' => MaterialLotStatusEnum::Active->value,
        'source' => 'inbound',
        // Well before "today" in every zone under test, so each lot reaches
        // the per-branch decision rather than being filtered by the coarse net.
        'expiry_date' => '2026-07-01',
    ]);
}

/** Count `branches` reads while $work runs. */
function countBranchReads(Closure $work): int
{
    $count = 0;

    DB::listen(function ($query) use (&$count): void {
        $sql = $query->sql;
        if (str_starts_with(strtolower(ltrim($sql)), 'select')
            && (str_contains($sql, 'from "branches"') || str_contains($sql, 'from `branches`'))) {
            $count++;
        }
    });

    $work();

    return $count;
}

afterEach(fn () => Carbon::setTestNow());

it('reads branches once for a whole sweep, not once per shop', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-01 02:00:00', 'UTC'));

    // Eight shops across four zones and two countries — every lot expired, so
    // each one reaches the per-branch business-date decision.
    $branches = collect([
        ['Asia/Tokyo', 'JP'],
        ['Asia/Ho_Chi_Minh', 'VN'],
        ['America/New_York', 'US'],
        ['Europe/Paris', 'FR'],
        ['Asia/Tokyo', 'JP'],
        ['Asia/Ho_Chi_Minh', 'VN'],
        ['America/Los_Angeles', 'US'],
        ['UTC', 'JP'],
    ])->map(fn (array $spec) => budgetBranch($spec[0], $spec[1]));

    $branches->each(fn (Branch $branch) => budgetExpiredLot($branch));

    BusinessClock::flushTimezoneMemo();

    $reads = countBranchReads(fn () => Artisan::call('material-lots:auto-expire'));

    // One chunk of lots → exactly one batched resolve. Removing warmFor() from
    // the command turns this into 8 — one per shop — which is the shape that
    // drove the cron layer off BusinessClock in the first place.
    expect($reads)->toBe(1);

    expect(MaterialLot::query()->where('status', MaterialLotStatusEnum::Expired->value)->count())
        ->toBe($branches->count());
});

it('does not re-read branches it already resolved earlier in the process', function () {
    $branch = budgetBranch('Asia/Ho_Chi_Minh', 'VN');
    BusinessClock::flushTimezoneMemo();

    $first = countBranchReads(fn () => BusinessClock::timezoneForBranch((string) $branch->id));
    $second = countBranchReads(function () use ($branch) {
        foreach (range(1, 50) as $ignored) {
            BusinessClock::businessDate((string) $branch->id);
            BusinessClock::now((string) $branch->id);
        }
    });

    expect($first)->toBe(1)
        ->and($second)->toBe(0);
});

it('warms a mixed batch of known and unknown branches in one read', function () {
    $known = collect(range(1, 4))->map(fn () => budgetBranch('Asia/Tokyo'));
    BusinessClock::flushTimezoneMemo();

    // Resolve one up front so the batch is genuinely mixed.
    BusinessClock::timezoneForBranch((string) $known->first()->id);

    $reads = countBranchReads(function () use ($known) {
        BusinessClock::warmFor($known->map(fn (Branch $b) => (string) $b->id));
    });

    expect($reads)->toBe(1);
});
