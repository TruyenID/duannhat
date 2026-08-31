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

/*
 * Plan 036 — Dashboard endpoint (GET /shops/{slug}/till/dashboard).
 */

beforeEach(function () {
    // Pin the clock to midday. These fixtures build timestamps from now()±offsets
    // and then assert against a "today" filter; run them near midnight and the
    // offset lands on the previous date, so the filter legitimately finds nothing.
    // See #1091 — this is the same date-boundary class as the Sunday-only and
    // 23:59-only failures fixed in 6e124f62.
    Carbon::setTestNow(Carbon::create(2026, 1, 8, 12, 0, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'till-tracking-shop',
    ]);

    Role::firstOrCreate(['slug' => 'shop-manager'], ['name' => 'Shop Manager', 'level' => 60]);
    Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff', 'level' => 30]);

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->manager, $this->orgId);

    // Staff has the staff role only — assignRole stamps role_user_pivots so
    // the ResolveShopFromSlug IAM check passes; the policy then denies on role.
    $this->staff = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->staff->assignRole('staff', $this->orgId);
});

function makeTill(string $branchId, string $brandId, string $orgId, string $code = 'MAIN'): Till
{
    return Till::create([
        'till_code' => $code,
        'branch_id' => $branchId,
        'brand_id' => $brandId,
        'organization_id' => $orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 100,
        'is_active' => true,
    ]);
}

it('returns 401 when unauthenticated', function () {
    $this->getJson("/api/v1/shops/{$this->shop->slug}/till/dashboard")
        ->assertUnauthorized();
});

it('returns 403 to a plain staff user', function () {
    $this->actingAs($this->staff)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/dashboard")
        ->assertForbidden();
});

it('rejects invalid trend_days values with 422', function () {
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/dashboard?trend_days=21")
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_TREND_DAYS');
});

it('returns the dashboard envelope with zero data for an empty shop', function () {
    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/dashboard")
        ->assertOk();

    $response->assertJsonPath('data.kpis.open_till_count', 0);
    $response->assertJsonPath('data.kpis.settled_today.count', 0);
    $response->assertJsonPath('data.kpis.settled_today.gross_total_amount', 0);
    $response->assertJsonPath('data.kpis.stale_count.open_overdue', 0);
    $response->assertJsonPath('data.kpis.stale_count.expired', 0);
    $response->assertJsonPath('meta.trend_days', 14);
    expect($response->json('data.variance_trend'))->toHaveCount(14);
});

it('counts open tills correctly and exposes per-till status cards', function () {
    Cache::flush();
    $tillA = makeTill($this->shop->id, $this->brand->id, $this->orgId, 'A');
    $tillB = makeTill($this->shop->id, $this->brand->id, $this->orgId, 'B');
    $tillC = makeTill($this->shop->id, $this->brand->id, $this->orgId, 'C');

    $openA = TillSession::factory()->open()->create([
        'till_id' => $tillA->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'opened_at' => now()->subHours(3),
    ]);
    $openB = TillSession::factory()->open()->create([
        'till_id' => $tillB->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'opened_at' => now()->subHours(30), // stale warning
    ]);
    $tillA->update(['current_session_id' => $openA->id]);
    $tillB->update(['current_session_id' => $openB->id]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/dashboard")
        ->assertOk();

    $response->assertJsonPath('data.kpis.open_till_count', 2);
    $response->assertJsonPath('data.kpis.total_till_count', 3);

    $tills = collect($response->json('data.tills'));
    expect($tills)->toHaveCount(3);
    $bCard = $tills->firstWhere('name', 'B');
    expect($bCard['current_session']['stale_warning'])->toBeTrue();
});

it('aggregates today settled gross + variance', function () {
    Cache::flush();
    $till = makeTill($this->shop->id, $this->brand->id, $this->orgId);
    TillSession::factory()->settled()->count(3)->create([
        'till_id' => $till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'cash_variance_amount' => -100,
        'closed_at' => now()->subHours(1),
        'business_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/dashboard")
        ->assertOk();

    $response->assertJsonPath('data.kpis.settled_today.count', 3);
    $response->assertJsonPath('data.kpis.variance_today.net_amount', -300);
});

it('populates the variance trend with each day\'s average — not a flat zero', function () {
    // Regression: business_date casts to a Carbon datetime, so keying the trend
    // rows by a plain string cast ("2026-06-23 00:00:00") never matched the
    // date-only lookup ("2026-06-23"), flatlining every point to zero.
    Cache::flush();
    $till = makeTill($this->shop->id, $this->brand->id, $this->orgId);
    TillSession::factory()->settled()->count(2)->create([
        'till_id' => $till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'cash_variance_amount' => -400,
        'closed_at' => now()->subHours(1),
        'business_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/dashboard?trend_days=14")
        ->assertOk();

    $trend = $response->json('data.variance_trend');
    $today = collect($trend)->firstWhere('date', now()->toDateString());

    expect($today)->not->toBeNull();
    expect((float) $today['avg_amount'])->toBe(-400.0);
    expect($today['session_count'])->toBe(2);
});

it('isolates dashboard aggregates to the resolved branch — no cross-tenant leak', function () {
    Cache::flush();

    // This shop: 1 open till + 1 settled-today session.
    $till = makeTill($this->shop->id, $this->brand->id, $this->orgId, 'MINE');
    $open = TillSession::factory()->open()->create([
        'till_id' => $till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'opened_at' => now()->subHours(2),
    ]);
    $till->update(['current_session_id' => $open->id]);
    TillSession::factory()->settled()->create([
        'till_id' => $till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'cash_variance_amount' => -100,
        'closed_at' => now()->subHour(),
        'business_date' => now()->toDateString(),
    ]);

    // A DIFFERENT tenant/branch with its own open till + settled sessions today.
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
        'slug' => 'other-tenant-shop',
    ]);
    $otherTill = makeTill($otherBranch->id, $otherBrand->id, $otherOrgId, 'THEIRS');
    $otherOpen = TillSession::factory()->open()->create([
        'till_id' => $otherTill->id,
        'branch_id' => $otherBranch->id,
        'brand_id' => $otherBrand->id,
        'organization_id' => $otherOrgId,
        'opened_at' => now()->subHours(2),
    ]);
    $otherTill->update(['current_session_id' => $otherOpen->id]);
    TillSession::factory()->settled()->count(4)->create([
        'till_id' => $otherTill->id,
        'branch_id' => $otherBranch->id,
        'brand_id' => $otherBrand->id,
        'organization_id' => $otherOrgId,
        'cash_variance_amount' => -9999,
        'closed_at' => now()->subHour(),
        'business_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/dashboard")
        ->assertOk();

    // Only this branch's numbers — the other tenant is invisible.
    $response->assertJsonPath('data.kpis.open_till_count', 1);
    $response->assertJsonPath('data.kpis.total_till_count', 1);
    $response->assertJsonPath('data.kpis.settled_today.count', 1);
    $response->assertJsonPath('data.kpis.variance_today.net_amount', -100);

    $tills = collect($response->json('data.tills'));
    expect($tills)->toHaveCount(1);
    expect($tills->pluck('name'))->not->toContain('THEIRS');

    $recent = collect($response->json('data.recent_settlements'));
    expect($recent)->toHaveCount(1);
    expect($recent->pluck('variance_amount'))->not->toContain(-9999.0);
});

it('caches the dashboard envelope for 5 seconds', function () {
    Cache::flush();

    // Warm cache.
    $first = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/dashboard")
        ->json();

    // Mutate underlying data — settling a session.
    $till = makeTill($this->shop->id, $this->brand->id, $this->orgId);
    TillSession::factory()->settled()->create([
        'till_id' => $till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'closed_at' => now(),
        'business_date' => now()->toDateString(),
    ]);

    // Within the 5s bucket → cached.
    $second = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/dashboard")
        ->json();

    expect($second['data']['kpis']['settled_today']['count'])->toBe($first['data']['kpis']['settled_today']['count']);
});
