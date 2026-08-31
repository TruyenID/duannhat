<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Support\BusinessClock;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * #1091 — BusinessClock is the single business-time source. Every assertion
 * freezes the clock (matrix rule §4.1) and runs the UTC/Tokyo/Ho_Chi_Minh
 * triple.
 */
function bcBranch(string $timezone): Branch
{
    $org = Organization::query()->first() ?? Organization::factory()->create();
    $brand = Brand::factory()->create(['console_organization_id' => $org->id]);

    return Branch::factory()->create([
        'console_organization_id' => $org->id,
        'console_brand_id' => $brand->console_brand_id,
        'timezone' => $timezone,
        'is_active' => true,
    ]);
}

/** A branch whose organization lookup is valid through console_organization_id. */
function bcCountryBranch(string $country, ?string $timezone): Branch
{
    $organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $organizationId,
        'console_organization_id' => $organizationId,
        'operating_country' => $country,
    ]);
    $brand = Brand::factory()->create(['console_organization_id' => $organizationId]);

    return Branch::factory()->create([
        'console_organization_id' => $organizationId,
        'console_brand_id' => $brand->console_brand_id,
        'timezone' => $timezone,
        'is_active' => true,
    ]);
}

/** Count SELECTs whose driving table is branches, including joined lookups. */
function bcCountBranchReads(Closure $work): int
{
    $count = 0;
    DB::listen(function ($query) use (&$count): void {
        $sql = strtolower($query->sql);
        if (str_starts_with(ltrim($sql), 'select')
            && (str_contains($sql, 'from "branches"') || str_contains($sql, 'from `branches`'))) {
            $count++;
        }
    });

    $work();

    return $count;
}

afterEach(function (): void {
    Carbon::setTestNow();
    BusinessClock::flushTimezoneMemo();
});

it('resolves today at the branch across the cross-day window', function (string $timezone, string $expectedDate) {
    // 2026-07-25 23:59:59 UTC — Tokyo and Ho Chi Minh are already on the 26th.
    Carbon::setTestNow(Carbon::parse('2026-07-25 23:59:59', 'UTC'));

    $branch = bcBranch($timezone);

    expect(BusinessClock::businessDate((string) $branch->id))->toBe($expectedDate)
        ->and(BusinessClock::now((string) $branch->id)->timezoneName)->toBe($timezone);
})->with([
    'UTC stays on the 25th' => ['UTC', '2026-07-25'],
    'Tokyo +9 on the 26th' => ['Asia/Tokyo', '2026-07-26'],
    'Ho Chi Minh +7 on the 26th' => ['Asia/Ho_Chi_Minh', '2026-07-26'],
]);

it('converts a supplied past instant to the branch business date', function () {
    $branch = bcBranch('Asia/Tokyo');

    // 15:30 UTC on the 25th = 00:30 JST on the 26th — an offline shift open
    // synced up later must land on the 26th.
    $instant = Carbon::parse('2026-07-25 15:30:00', 'UTC');

    expect(BusinessClock::businessDateAt((string) $branch->id, $instant))->toBe('2026-07-26');
});

it('exactly at the branch midnight boundary the date flips', function () {
    $branch = bcBranch('Asia/Tokyo');

    Carbon::setTestNow(Carbon::parse('2026-07-25 14:59:59', 'UTC')); // 23:59:59 JST
    expect(BusinessClock::businessDate((string) $branch->id))->toBe('2026-07-25');

    Carbon::setTestNow(Carbon::parse('2026-07-25 15:00:00', 'UTC')); // 00:00:00 JST
    expect(BusinessClock::businessDate((string) $branch->id))->toBe('2026-07-26');
});

it('falls back (with a log) when the branch has no usable timezone', function () {
    config(['app.operations_timezone' => 'Asia/Tokyo']);

    Log::spy();

    // Unknown branch id → fallback, no branch row to blame.
    expect(BusinessClock::timezoneForBranch(null))->toBe('Asia/Tokyo');

    // Garbage timezone on a real branch → fallback + warning.
    $branch = bcBranch('Asia/Tokyo');
    Branch::query()->whereKey($branch->id)->update(['timezone' => 'Not/AZone']);

    expect(BusinessClock::timezoneForBranch((string) $branch->id))->toBe('Asia/Tokyo');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => $message === 'business_clock_invalid_branch_timezone')
        ->once();
});

// =========================================================================
//  #2838 — head-office fallback is per operating country
// =========================================================================

it('falls back to the head office of the branch country', function () {
    config([
        'app.operations_timezones' => [
            'JP' => 'Asia/Tokyo',
            'VN' => 'Asia/Ho_Chi_Minh',
        ],
        'app.operations_timezone' => 'UTC',
    ]);

    $japan = bcCountryBranch('JP', null);
    $vietnam = bcCountryBranch('VN', null);

    expect(BusinessClock::timezoneForBranch((string) $japan->id))->toBe('Asia/Tokyo')
        ->and(BusinessClock::timezoneForBranch((string) $vietnam->id))->toBe('Asia/Ho_Chi_Minh');
});

it("lets the branch's stored timezone beat its country head office", function () {
    config(['app.operations_timezones' => ['VN' => 'Asia/Ho_Chi_Minh']]);

    $tokyoShopOfVietnamOrg = bcCountryBranch('VN', 'Asia/Tokyo');

    expect(BusinessClock::timezoneForBranch((string) $tokyoShopOfVietnamOrg->id))
        ->toBe('Asia/Tokyo');
});

it('uses branch timezones for countries with several zones instead of growing the map', function () {
    config([
        'app.operations_timezones' => ['JP' => 'Asia/Tokyo'],
        'app.operations_timezone' => 'UTC',
    ]);

    $newYork = bcCountryBranch('US', 'America/New_York');
    $losAngeles = bcCountryBranch('US', 'America/Los_Angeles');

    expect(BusinessClock::timezoneForBranch((string) $newYork->id))->toBe('America/New_York')
        ->and(BusinessClock::timezoneForBranch((string) $losAngeles->id))->toBe('America/Los_Angeles');
});

it('falls back globally for an unmapped or empty country entry', function (string $country) {
    config([
        'app.operations_timezones' => ['JP' => 'Asia/Tokyo', 'SG' => ''],
        'app.operations_timezone' => 'Europe/Paris',
    ]);

    $branch = bcCountryBranch($country, null);

    expect(BusinessClock::timezoneForBranch((string) $branch->id))->toBe('Europe/Paris');
})->with([
    'unmapped country' => ['US'],
    'empty mapped value' => ['SG'],
]);

it('normalizes country codes and keeps a domain default ahead of head office', function () {
    config([
        'app.operations_timezones' => ['VN' => 'Asia/Ho_Chi_Minh'],
        'app.operations_timezone' => 'UTC',
    ]);

    expect(BusinessClock::headOfficeTimezone(' vn '))->toBe('Asia/Ho_Chi_Minh');

    $branch = bcCountryBranch('VN', null);
    expect(BusinessClock::timezoneForBranch((string) $branch->id, 'Europe/Paris'))
        ->toBe('Europe/Paris');
});

it('loads timezone and country in one query and batches many branches', function () {
    config(['app.operations_timezones' => ['JP' => 'Asia/Tokyo', 'VN' => 'Asia/Ho_Chi_Minh']]);

    $branches = collect([
        bcCountryBranch('JP', null),
        bcCountryBranch('VN', null),
        bcCountryBranch('US', 'America/New_York'),
        bcCountryBranch('US', 'America/Los_Angeles'),
    ]);
    BusinessClock::flushTimezoneMemo();

    $reads = bcCountBranchReads(function () use ($branches): void {
        BusinessClock::warmFor($branches->pluck('id'));
        $branches->each(fn (Branch $branch) => BusinessClock::timezoneForBranch((string) $branch->id));
    });

    expect($reads)->toBe(1)
        ->and(BusinessClock::timezoneForBranch((string) $branches[0]->id))->toBe('Asia/Tokyo')
        ->and(BusinessClock::timezoneForBranch((string) $branches[1]->id))->toBe('Asia/Ho_Chi_Minh');
});

it('memoizes a dangling branch id instead of querying on every call', function () {
    $branchId = '00000000-0000-7000-8000-000000000000';

    $reads = bcCountBranchReads(function () use ($branchId): void {
        foreach (range(1, 20) as $ignored) {
            BusinessClock::timezoneForBranch($branchId);
        }
    });

    expect($reads)->toBe(1);
});

it('flushes the memo between queued jobs', function () {
    $branch = bcCountryBranch('JP', 'Asia/Tokyo');
    $branchId = (string) $branch->id;

    expect(BusinessClock::timezoneForBranch($branchId))->toBe('Asia/Tokyo');

    Branch::query()->whereKey($branchId)->update(['timezone' => 'Asia/Ho_Chi_Minh']);
    expect(BusinessClock::timezoneForBranch($branchId))->toBe('Asia/Tokyo');

    Event::dispatch(new Looping('sync', 'default'));

    expect(BusinessClock::timezoneForBranch($branchId))->toBe('Asia/Ho_Chi_Minh');
});

// =========================================================================
//  #1091 — utcRangeForBusinessDates (branch-local date filters)
// =========================================================================

it('converts a branch-local date range to UTC instant bounds', function () {
    $branch = bcBranch('Asia/Tokyo');

    [$from, $until] = BusinessClock::utcRangeForBusinessDates(
        (string) $branch->id, '2026-07-27', '2026-07-27',
    );

    // The Tokyo 27th runs 26th 15:00 UTC → 27th 15:00 UTC (exclusive).
    expect($from->toIso8601String())->toBe('2026-07-26T15:00:00+00:00')
        ->and($until->toIso8601String())->toBe('2026-07-27T15:00:00+00:00');
});

it('leaves an absent or malformed side of the range unbounded', function () {
    $branch = bcBranch('Asia/Tokyo');

    [$from, $until] = BusinessClock::utcRangeForBusinessDates((string) $branch->id, '2026-07-27', null);
    expect($from)->not->toBeNull()->and($until)->toBeNull();

    [$from, $until] = BusinessClock::utcRangeForBusinessDates((string) $branch->id, null, 'not-a-date--');
    expect($from)->toBeNull()->and($until)->toBeNull();
});
