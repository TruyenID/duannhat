<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
 * Plan 032 T6.4 — GET /pos/till/sessions/stale.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId, 'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'stale-shop',
        'is_active' => true,
    ]);

    $cashierRole = Role::firstOrCreate(['slug' => 'shop-staff'], ['name' => 'Shop Staff', 'level' => 10]);
    $managerRole = Role::firstOrCreate(['slug' => 'shop-manager'], ['name' => 'Shop Manager', 'level' => 60]);
    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole($cashierRole, $this->orgId);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);
    $this->manager->refresh();

    config()->set('pos.shift.stale_timeout_hours', 48);
    // #1220 — filter=open_overdue is banded by the manager-view threshold now, not
    // the reaper's. Pin it so these expectations don't silently follow config/pos.php.
    config()->set('pos.shift.manager_view.overdue_hours', 24);

    $till = Till::firstOrCreate(
        ['branch_id' => $this->shop->id, 'till_code' => 'MAIN'],
        ['default_currency_code' => 'JPY', 'variance_tolerance_amount' => 0, 'is_active' => true,
            'brand_id' => $this->brand->id, 'organization_id' => $this->orgId],
    );

    // 1 stale-overdue open, 1 fresh open, 1 expired, 1 settled.
    TillSession::create([
        'session_code' => 'SHIFT-STALE-1', 'status' => 'open',
        'business_date' => now()->subDays(3)->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0,
        'opened_at' => now()->subHours(72),
        'till_id' => $till->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    TillSession::create([
        'session_code' => 'SHIFT-FRESH-1', 'status' => 'open',
        'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0,
        'opened_at' => now()->subHours(2),
        'till_id' => $till->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    TillSession::create([
        'session_code' => 'SHIFT-EXP-1', 'status' => 'expired',
        'business_date' => now()->subDays(5)->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0,
        'opened_at' => now()->subDays(5), 'expired_at' => now()->subHours(20),
        'expire_reason' => 'no_activity', 'expire_threshold_hours' => 48,
        'till_id' => $till->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    // #1220 — the regression fixture: 30h sits between the manager-view band (24h)
    // and the reaper cutoff (48h). Before the fix the dashboard KPI counted it and
    // NO stale filter would list it.
    TillSession::create([
        'session_code' => 'SHIFT-OVERDUE-30H', 'status' => 'open',
        'business_date' => now()->subDays(2)->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0,
        'opened_at' => now()->subHours(30),
        'till_id' => $till->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    TillSession::create([
        'session_code' => 'SHIFT-SETTLED-1', 'status' => 'settled',
        'business_date' => now()->subDay()->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0,
        'opened_at' => now()->subDay(), 'closed_at' => now()->subHours(8),
        'till_id' => $till->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
});

function actAsMgrSL(): TestCase
{
    Sanctum::actingAs(test()->manager);

    return test()->withHeader('X-Shop-Slug', test()->shop->slug);
}

it('returns open-overdue sessions by default (filter=open_overdue)', function () {
    $response = actAsMgrSL()->getJson('/api/v1/pos/till/sessions/stale')
        ->assertOk()->json();

    $codes = collect($response['data'])->pluck('session_code')->all();
    expect($codes)->toContain('SHIFT-STALE-1');
    // #1220 — a 30h shift is past the 24h manager-view band. It used to be invisible
    // here (48h reaper cutoff) while still counting toward the "Ca treo" KPI.
    expect($codes)->toContain('SHIFT-OVERDUE-30H');
    expect($codes)->not->toContain('SHIFT-FRESH-1');
    expect($codes)->not->toContain('SHIFT-EXP-1');
    expect($response['meta']['threshold_hours'])->toBe(24);
});

it('reports the reaper cutoff as threshold_hours on the terminal filters', function () {
    // meta.threshold_hours is filter-dependent: the band that selected the rows for
    // open_overdue, the (informational) reaper cutoff for the terminal filters.
    expect(actAsMgrSL()->getJson('/api/v1/pos/till/sessions/stale?filter=expired')
        ->assertOk()->json('meta.threshold_hours'))->toBe(48);
    expect(actAsMgrSL()->getJson('/api/v1/pos/till/sessions/stale?filter=all_terminal')
        ->assertOk()->json('meta.threshold_hours'))->toBe(48);
});

it('excludes already-closed expired sessions from filter=expired', function () {
    // expire() never writes closed_at, but workstation sync-up accepts it. Such a
    // shift is resolved — the dashboard KPI has always excluded it, the list now does
    // too, so the two numbers cannot disagree.
    TillSession::where('session_code', 'SHIFT-EXP-1')->update(['closed_at' => now()->subHours(2)]);

    $codes = collect(actAsMgrSL()->getJson('/api/v1/pos/till/sessions/stale?filter=expired')
        ->assertOk()->json('data'))->pluck('session_code')->all();

    expect($codes)->not->toContain('SHIFT-EXP-1');
});

it('returns expired sessions with filter=expired', function () {
    $response = actAsMgrSL()->getJson('/api/v1/pos/till/sessions/stale?filter=expired')
        ->assertOk()->json();

    $codes = collect($response['data'])->pluck('session_code')->all();
    expect($codes)->toContain('SHIFT-EXP-1');
    expect($codes)->not->toContain('SHIFT-STALE-1');
});

it('group_by=actor returns force-abandon + expired summary', function () {
    // Add a force-abandoned row for the summary.
    TillSession::create([
        'session_code' => 'SHIFT-FA-1', 'status' => 'abandoned',
        'business_date' => now()->subDay()->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0,
        'opened_at' => now()->subDay(), 'abandoned_at' => now()->subHours(4),
        'force_abandoned' => true, 'force_abandoned_by_id' => $this->manager->id,
        'force_abandon_reason_code' => 'pos_device_failure',
        'till_id' => Till::where('branch_id', $this->shop->id)->first()->id,
        'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $response = actAsMgrSL()->getJson('/api/v1/pos/till/sessions/stale?group_by=actor&within_days=30')
        ->assertOk()->json('data');

    expect($response['force_abandoned_count'])->toBe(1);
    expect($response['expired_count'])->toBe(1);
    expect($response['per_manager'][0]['manager_id'])->toBe((string) $this->manager->id);
    expect($response['per_manager'][0]['count'])->toBe(1);
});

// =========================================================================
//  Multi-tenant isolation (plan-audit high)
// =========================================================================

it('does NOT leak stale/expired sessions from another org', function () {
    // Foreign org with its own stale-overdue open + expired sessions.
    $foreignOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $foreignOrgId, 'console_organization_id' => $foreignOrgId,
    ]);
    $foreignBrand = Brand::factory()->create(['console_organization_id' => $foreignOrgId]);
    $foreignShop = Branch::factory()->create([
        'console_organization_id' => $foreignOrgId,
        'console_brand_id' => $foreignBrand->console_brand_id,
        'slug' => 'stale-foreign-shop',
        'is_active' => true,
    ]);
    $foreignTill = Till::firstOrCreate(
        ['branch_id' => $foreignShop->id, 'till_code' => 'MAIN'],
        ['default_currency_code' => 'JPY', 'variance_tolerance_amount' => 0, 'is_active' => true,
            'brand_id' => $foreignBrand->id, 'organization_id' => $foreignOrgId],
    );
    TillSession::create([
        'session_code' => 'SHIFT-FOREIGN-STALE', 'status' => 'open',
        'business_date' => now()->subDays(3)->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0,
        'opened_at' => now()->subHours(72),
        'till_id' => $foreignTill->id, 'branch_id' => $foreignShop->id,
        'brand_id' => $foreignBrand->id, 'organization_id' => $foreignOrgId,
    ]);
    TillSession::create([
        'session_code' => 'SHIFT-FOREIGN-EXP', 'status' => 'expired',
        'business_date' => now()->subDays(5)->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0,
        'opened_at' => now()->subDays(5), 'expired_at' => now()->subHours(20),
        'expire_reason' => 'no_activity', 'expire_threshold_hours' => 48,
        'till_id' => $foreignTill->id, 'branch_id' => $foreignShop->id,
        'brand_id' => $foreignBrand->id, 'organization_id' => $foreignOrgId,
    ]);
    // Foreign force-abandon that must not bleed into the org-A actor summary.
    TillSession::create([
        'session_code' => 'SHIFT-FOREIGN-FA', 'status' => 'abandoned',
        'business_date' => now()->subDay()->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0,
        'opened_at' => now()->subDay(), 'abandoned_at' => now()->subHours(4),
        'force_abandoned' => true, 'force_abandoned_by_id' => (string) Str::uuid(),
        'force_abandon_reason_code' => 'pos_device_failure',
        'till_id' => $foreignTill->id, 'branch_id' => $foreignShop->id,
        'brand_id' => $foreignBrand->id, 'organization_id' => $foreignOrgId,
    ]);

    // Manager (org A) lists — foreign org rows must be absent across every filter.
    foreach (['open_overdue', 'expired', 'all_terminal'] as $filter) {
        $codes = collect(
            actAsMgrSL()->getJson("/api/v1/pos/till/sessions/stale?filter={$filter}")
                ->assertOk()->json('data')
        )->pluck('session_code')->all();

        expect($codes)->not->toContain('SHIFT-FOREIGN-STALE');
        expect($codes)->not->toContain('SHIFT-FOREIGN-EXP');
        expect($codes)->not->toContain('SHIFT-FOREIGN-FA');
    }

    // Actor summary is org-scoped too: org A has no force-abandons, so zero.
    $summary = actAsMgrSL()->getJson('/api/v1/pos/till/sessions/stale?group_by=actor&within_days=30')
        ->assertOk()->json('data');
    expect($summary['force_abandoned_count'])->toBe(0);
    expect($summary['expired_count'])->toBe(1); // the org-A SHIFT-EXP-1 only
});

it('forbids cashier (shop-staff) from listing stale sessions (403)', function () {
    Sanctum::actingAs($this->cashier);
    $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/till/sessions/stale')
        ->assertStatus(403);
});
