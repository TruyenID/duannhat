<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * #1220 — the "Ca treo" KPI and the "Ca treo" list must describe the SAME set.
 *
 * They are served by two endpoints written months apart:
 *   GET /shops/{slug}/till/dashboard        → data.kpis.stale_count.{open_overdue,expired}
 *   GET /pos/till/sessions/stale?filter=..  → data[] + meta.total
 *
 * They drifted: the KPI counted `open|closing` older than the 24h manager-view band
 * while the list only listed rows older than the reaper's 48h cutoff, and the KPI
 * excluded `expired` rows carrying a `closed_at` while the list did not. A shift open
 * 24-48h therefore showed up in the badge and in NO filter — unreachable through the UI.
 *
 * These tests assert set equality of session codes, not just equal totals: two counts
 * can coincide while describing different rows.
 */

beforeEach(function () {
    // Frozen clock — fixtures are now()±offsets and the dashboard buckets its cache
    // key on floor(now()/5s). Same reason as ShopTillTrackingDashboardTest.
    Carbon::setTestNow(Carbon::create(2026, 1, 8, 12, 0, 0));

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId, 'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'stale-parity-shop',
        'is_active' => true,
    ]);

    Role::firstOrCreate(['slug' => 'shop-manager'], ['name' => 'Shop Manager', 'level' => 60]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    // org-admin — satisfies ShopTillTrackingPolicy::viewDashboard AND
    // TillSessionPolicy::viewStale (both accept isHqRole), plus the
    // ResolvesShopContext IAM check via the org-wide pivot.
    grantOrgAccess($this->manager, $this->orgId);

    config()->set('pos.shift.stale_timeout_hours', 48);
    config()->set('pos.shift.manager_view.overdue_hours', 24);

    $till = Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
        'is_active' => true,
    ]);

    $shift = function (array $attrs) use ($till) {
        TillSession::create(array_merge([
            'business_date' => now()->toDateString(),
            'default_currency_code' => 'JPY',
            'opening_float_amount' => 0,
            'till_id' => $till->id,
            'branch_id' => $this->shop->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ], $attrs));
    };

    // Fresh — under the band on any threshold.
    $shift(['session_code' => 'OPEN-10H', 'status' => 'open', 'opened_at' => now()->subHours(10)]);
    // THE regression rows: between the 24h band and the 48h reaper cutoff.
    $shift(['session_code' => 'OPEN-30H', 'status' => 'open', 'opened_at' => now()->subHours(30)]);
    $shift(['session_code' => 'CLOSING-30H', 'status' => 'closing', 'opened_at' => now()->subHours(30)]);
    // Past both thresholds — visible before and after the fix.
    $shift(['session_code' => 'OPEN-50H', 'status' => 'open', 'opened_at' => now()->subHours(50)]);
    // Expired, unresolved — belongs in both.
    $shift([
        'session_code' => 'EXPIRED-OPEN', 'status' => 'expired',
        'opened_at' => now()->subDays(4), 'expired_at' => now()->subHours(20),
        'expire_reason' => 'no_activity', 'expire_threshold_hours' => 48,
    ]);
    // Expired but already closed (workstation sync-up path) — belongs in neither.
    $shift([
        'session_code' => 'EXPIRED-CLOSED', 'status' => 'expired',
        'opened_at' => now()->subDays(4), 'expired_at' => now()->subHours(20),
        'closed_at' => now()->subHours(3),
        'expire_reason' => 'no_activity', 'expire_threshold_hours' => 48,
    ]);
    // Terminal — in neither bucket.
    $shift([
        'session_code' => 'SETTLED', 'status' => 'settled',
        'opened_at' => now()->subDay(), 'closed_at' => now()->subHours(8),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Codes the LIST returns for a filter, via the X-Shop-Slug-resolved POS endpoint. */
function staleCodes(string $filter): array
{
    Sanctum::actingAs(test()->manager);
    $json = test()->withHeader('X-Shop-Slug', test()->shop->slug)
        ->getJson("/api/v1/pos/till/sessions/stale?filter={$filter}")
        ->assertOk()->json();

    return [
        'codes' => collect($json['data'])->pluck('session_code')->sort()->values()->all(),
        'total' => $json['meta']['total'],
        'threshold_hours' => $json['meta']['threshold_hours'],
    ];
}

/** The dashboard envelope, cache flushed so the frozen clock can't replay a stale bucket. */
function dashboardJson(): array
{
    Cache::flush();

    return test()->actingAs(test()->manager)
        ->getJson('/api/v1/shops/'.test()->shop->slug.'/till/dashboard')
        ->assertOk()->json();
}

it('KPI open_overdue counts exactly the rows filter=open_overdue lists', function () {
    $list = staleCodes('open_overdue');
    $kpi = dashboardJson()['data']['kpis']['stale_count']['open_overdue'];

    // The 30h rows are the ones that used to be counted but not listed.
    expect($list['codes'])->toBe(['CLOSING-30H', 'OPEN-30H', 'OPEN-50H']);
    expect($kpi)->toBe($list['total'])->toBe(3);
});

it('KPI expired counts exactly the rows filter=expired lists', function () {
    $list = staleCodes('expired');
    $kpi = dashboardJson()['data']['kpis']['stale_count']['expired'];

    // EXPIRED-CLOSED is already resolved — excluded on both sides.
    expect($list['codes'])->toBe(['EXPIRED-OPEN']);
    expect($kpi)->toBe($list['total'])->toBe(1);
});

it('reports the same overdue band on both endpoints', function () {
    expect(staleCodes('open_overdue')['threshold_hours'])
        ->toBe(dashboardJson()['meta']['warning_hours'])
        ->toBe(24);
});

it('emits the reaper cutoff as meta.stale_threshold_hours', function () {
    // plan-036 §API (plan đã xoá #2188 — git history) — this is POS_SHIFT_STALE_TIMEOUT_HOURS, not the 40h
    // critical-badge constant it shipped with.
    expect(dashboardJson()['meta']['stale_threshold_hours'])->toBe(48);
});

it('keeps both endpoints on one config key when the band is retuned', function () {
    // Proves single-source: re-hardcoding either side makes this fail. 6h pulls the
    // 10h shift in, so both the set and the reported band have to move together.
    config()->set('pos.shift.manager_view.overdue_hours', 6);

    $list = staleCodes('open_overdue');
    $kpi = dashboardJson()['data']['kpis']['stale_count']['open_overdue'];

    expect($list['codes'])->toBe(['CLOSING-30H', 'OPEN-10H', 'OPEN-30H', 'OPEN-50H']);
    expect($kpi)->toBe($list['total'])->toBe(4);
    expect($list['threshold_hours'])->toBe(6);
});
