<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use App\Services\Shop\ShopTillTrackingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
 * Plan 036 — Manager Till Tracking: plan-audit coverage top-up.
 *
 * These close the test-gap findings raised against plan-036:
 *   1. [high]   Z-report PDF *body* — the rendered manager-intervention block
 *               (force-abandon actor/reason + system-expiry) was previously
 *               asserted only by the %PDF magic bytes.
 *   2. [high]   Cross-branch multi-tenant isolation *at the endpoint*. The
 *               existing endpoint tests exercise cross-ORG isolation; the
 *               subtler risk is a same-ORG shop-manager scoped to branch B
 *               reaching branch A (the middleware IAM check is org-scoped, so
 *               it passes — only the branch-scoped Gate denies).
 *   3. [medium] Validation / edge / money paths: 365-day cap, per_page clamp,
 *               out_of_tolerance variance filter, null variance passthrough,
 *               data-corruption idle guard, reason-detail no-truncation,
 *               invalid-uuid 404, multi-status filter exclusion.
 *
 * Lives under Feature/Shop/TillTracking so tests/Pest.php strict-mode
 * (preventLazyLoading) applies — same as the other plan-036 feature files.
 */

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
        'slug' => 'till-gaps-shop',
    ]);

    Role::firstOrCreate(['slug' => 'shop-manager'], ['name' => 'Shop Manager', 'level' => 60]);
    Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff', 'level' => 30]);

    // Fixed, apostrophe-bearing name — issue #1113. A faker-random name here
    // made the z-report body test flake: ~1.5% of fake()->name() draws carry
    // an apostrophe (O'Connell, O'Reilly, D'Amore, …) and Blade's {{ }}
    // escapes it to &#039; in the rendered HTML, so a raw toContain($name)
    // missed. Pinning the name keeps that escaping edge covered on every run.
    $this->manager = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'name' => "Liana O'Connell",
    ]);
    grantOrgAccess($this->manager, $this->orgId);

    $this->till = Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 1000,
        'is_active' => true,
    ]);
});

// =============================================================================
//  Gap 1 — Z-report PDF BODY (rendered manager-intervention block)
// =============================================================================

it('renders the force-abandon actor, reason_code and reason_detail into the z-report body', function () {
    $session = TillSession::factory()->abandoned()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'force_abandoned' => true,
        'force_abandoned_by_id' => $this->manager->id,
        'force_abandon_reason_code' => 'pos_device_failure',
        'force_abandon_reason_detail' => 'Register froze mid-shift, cashier sent home',
    ]);

    /** @var ShopTillTrackingService $service */
    $service = app(ShopTillTrackingService::class);
    $html = view('till.z-report', $service->buildZReportPayload($session))->render();

    // Not just %PDF — the intervention block must carry every field the
    // accountant/auditor needs to reconstruct why the shift was cut short.
    expect($html)->toContain('マネージャー強制終了 / Manager force-abandon');
    // Compare against the HTML-escaped rendering — Blade {{ }} escapes the
    // apostrophe in "O'Connell" to &#039; (issue #1113 flake root cause).
    expect($html)->toContain(e($this->manager->name));
    expect($html)->toContain('pos_device_failure');
    expect($html)->toContain('Register froze mid-shift, cashier sent home');
});

it('renders the system-expiry intervention block (scheduler actor + threshold) in the z-report body', function () {
    $session = TillSession::factory()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'status' => 'expired',
        'expired_at' => now(),
        'expire_reason' => 'no_activity',
        'expire_threshold_hours' => 48,
        'force_abandoned' => false,
    ]);

    // Endpoint still produces a real PDF for an expired (never-closed) shift.
    $response = $this->actingAs($this->manager)
        ->get("/api/v1/shops/{$this->shop->slug}/till/sessions/{$session->id}/z-report.pdf")
        ->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');

    /** @var ShopTillTrackingService $service */
    $service = app(ShopTillTrackingService::class);
    $html = view('till.z-report', $service->buildZReportPayload($session))->render();

    expect($html)->toContain('システムによる失効 / System expiry');
    expect($html)->toContain('(System: scheduler)');
    expect($html)->toContain('no_activity');
    expect($html)->toContain('48'); // threshold hours line
});

// =============================================================================
//  Gap 2 — Cross-BRANCH isolation at the endpoint (same org, different branch)
// =============================================================================

it('denies a same-org shop-manager scoped to another branch — dashboard 403', function () {
    // A second branch in the SAME organization.
    $branchB = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'till-gaps-shop-b',
    ]);

    // Manager whose shop-manager role is scoped to branch B only. The
    // ResolveShopFromSlug IAM check is ORG-scoped, so this user clears the
    // middleware for shop A — only the branch-scoped policy should stop them.
    $managerB = User::factory()->create(['console_organization_id' => $this->orgId]);
    $managerB->assignRole('shop-manager', $this->orgId, $branchB->id);

    $this->actingAs($managerB)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/dashboard")
        ->assertForbidden();
});

it('denies a same-org shop-manager scoped to another branch — sessions list 403', function () {
    $branchB = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'till-gaps-shop-b2',
    ]);
    $managerB = User::factory()->create(['console_organization_id' => $this->orgId]);
    $managerB->assignRole('shop-manager', $this->orgId, $branchB->id);

    $this->actingAs($managerB)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions")
        ->assertForbidden();
});

it('grants the same-org shop-manager on their OWN branch — control for the isolation test', function () {
    // Sanity control: the same role assignment DOES pass on the branch it was
    // scoped to, proving the 403 above is branch-isolation, not a broken role.
    $branchB = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'till-gaps-shop-b3',
    ]);
    $managerB = User::factory()->create(['console_organization_id' => $this->orgId]);
    $managerB->assignRole('shop-manager', $this->orgId, $branchB->id);

    $this->actingAs($managerB)
        ->getJson("/api/v1/shops/{$branchB->slug}/till/dashboard")
        ->assertOk();
});

// =============================================================================
//  Gap 3 — Validation / edge / money paths
// =============================================================================

it('rejects a date range wider than 365 days with 422 INVALID_DATE_RANGE', function () {
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions?from=2024-01-01&to=2026-06-30")
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'INVALID_DATE_RANGE: date range may not exceed 365 days.']);
});

it('rejects per_page above the 100 hard cap with 422', function () {
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions?per_page=200")
        ->assertStatus(422);
});

it('variance=out_of_tolerance returns only sessions whose |variance| exceeds the till tolerance', function () {
    // till.variance_tolerance_amount = 1000 (beforeEach).
    $outOfTolerance = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'cash_variance_amount' => -5000,
        'business_date' => now()->toDateString(),
    ]);
    // Within tolerance — must be excluded.
    TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'cash_variance_amount' => -200,
        'business_date' => now()->toDateString(),
    ]);
    // Exactly at tolerance (|1000| is NOT > 1000) — must be excluded too.
    TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'cash_variance_amount' => 1000,
        'business_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions?variance=out_of_tolerance&per_page=100")
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1);
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$outOfTolerance->id]);
    // JSON round-trips -5000.0 → -5000; assert the numeric value, not the PHP type.
    expect((float) $response->json('data.0.cash_variance_amount'))->toBe(-5000.0);
});

it('passes cash_variance_amount through as null (not 0) for a still-open session', function () {
    // open() leaves cash_variance_amount = null (factory definition default).
    TillSession::factory()->open()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'business_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions?status[]=open&per_page=100")
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1);
    // Must be a genuine null — never coerced to 0 or a magic sentinel.
    expect($response->json('data.0.cash_variance_amount'))->toBeNull();
});

it('renders a till as idle when its current_session_id points at an abandoned session (data-corruption guard)', function () {
    Cache::flush();

    // Corrupt state: current_session_id set but the session is terminal.
    $abandoned = TillSession::factory()->abandoned()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'opened_at' => now()->subHours(3),
    ]);
    $this->till->update(['current_session_id' => $abandoned->id]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/dashboard")
        ->assertOk();

    // The service guards on status === open, not merely current_session_id != null.
    $response->assertJsonPath('data.kpis.open_till_count', 0);
    $tills = collect($response->json('data.tills'));
    expect($tills->firstWhere('name', 'MAIN')['status'])->toBe('idle');
});

it('preserves a long force_abandon_reason_detail unchanged in the session detail (no truncation)', function () {
    $detail = str_repeat('あ', 200); // 200-char multibyte reason.
    $session = TillSession::factory()->abandoned()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'force_abandoned' => true,
        'force_abandoned_by_id' => $this->manager->id,
        'force_abandon_reason_code' => 'other',
        'force_abandon_reason_detail' => $detail,
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions/{$session->id}")
        ->assertOk()
        ->assertJsonPath('data.force_abandon_reason_detail', $detail);
});

it('returns 404 for a non-existent session id (route binding findOrFail)', function () {
    $this->actingAs($this->manager)
        ->getJson('/api/v1/shops/'.$this->shop->slug.'/till/sessions/'.((string) Str::uuid()))
        ->assertNotFound();
});

it('multi-value status filter returns the union and excludes unlisted statuses', function () {
    TillSession::factory()->settled()->count(3)->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'business_date' => now()->toDateString(),
    ]);
    TillSession::factory()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'status' => 'expired',
        'expired_at' => now(),
        'expire_reason' => 'no_activity',
        'expire_threshold_hours' => 48,
        'business_date' => now()->toDateString(),
    ]);
    // Abandoned — NOT in the filter, must be excluded.
    TillSession::factory()->abandoned()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'business_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions?status[]=settled&status[]=expired&per_page=100")
        ->assertOk();

    expect($response->json('meta.total'))->toBe(4);
    expect(collect($response->json('data'))->pluck('status')->unique()->sort()->values()->all())
        ->toBe(['expired', 'settled']);
});
