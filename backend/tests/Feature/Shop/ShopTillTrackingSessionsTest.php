<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * Plan 036 — Sessions history endpoint (GET /shops/{slug}/till/sessions).
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
        'slug' => 'till-sessions-shop',
    ]);

    Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff', 'level' => 30]);

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->manager, $this->orgId);

    $this->staff = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->staff->assignRole('staff', $this->orgId);

    $this->till = Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 100,
        'is_active' => true,
    ]);
});

it('returns 401 unauthenticated', function () {
    $this->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions")
        ->assertUnauthorized();
});

it('returns 403 for plain staff', function () {
    $this->actingAs($this->staff)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions")
        ->assertForbidden();
});

it('returns an empty paginated envelope when no sessions exist', function () {
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions")
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('data', []);
});

it('paginates settled sessions and respects per_page', function () {
    TillSession::factory()->settled()->count(15)->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'business_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions?per_page=5")
        ->assertOk();

    $response->assertJsonPath('meta.total', 15);
    $response->assertJsonPath('meta.per_page', 5);
    expect($response->json('data'))->toHaveCount(5);
});

it('filters by status[]', function () {
    TillSession::factory()->settled()->count(3)->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'business_date' => now()->toDateString(),
    ]);
    TillSession::factory()->abandoned()->count(2)->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'business_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions?status[]=settled")
        ->assertOk();

    expect($response->json('meta.total'))->toBe(3);
});

it('never lists sessions belonging to another branch — cross-tenant isolation', function () {
    // This shop: 2 settled sessions.
    TillSession::factory()->settled()->count(2)->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'business_date' => now()->toDateString(),
    ]);

    // A different tenant/branch with its own settled sessions in the same window.
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
        'slug' => 'other-sessions-shop',
    ]);
    $otherTill = Till::create([
        'till_code' => 'OTHER',
        'branch_id' => $otherBranch->id,
        'brand_id' => $otherBrand->id,
        'organization_id' => $otherOrgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
        'is_active' => true,
    ]);
    $otherSessions = TillSession::factory()->settled()->count(3)->create([
        'till_id' => $otherTill->id,
        'branch_id' => $otherBranch->id,
        'brand_id' => $otherBrand->id,
        'organization_id' => $otherOrgId,
        'business_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions?per_page=100")
        ->assertOk();

    $response->assertJsonPath('meta.total', 2);
    $returnedIds = collect($response->json('data'))->pluck('id');
    foreach ($otherSessions as $leak) {
        expect($returnedIds)->not->toContain($leak->id);
    }
});

it('rejects unknown status enum with 422', function () {
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions?status[]=draft")
        ->assertStatus(422);
});

it('rejects from > to with 422', function () {
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions?from=2026-06-20&to=2026-06-15")
        ->assertStatus(422);
});

it('rejects variance buckets outside the enum with 422', function () {
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions?variance=red")
        ->assertStatus(422);
});

it('exports CSV when ?export=csv', function () {
    TillSession::factory()->settled()->count(2)->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'business_date' => now()->toDateString(),
    ]);

    // Regression: at least one row carries an enum-cast `expire_reason` so
    // the CSV serializer must unwrap BackedEnum → ->value. Previously
    // `fputcsv` blew up with 500 "Object of class ExpireReasonEnum could
    // not be converted to string" when an expired session showed up in
    // the result set.
    TillSession::factory()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'business_date' => now()->toDateString(),
        'status' => 'expired',
        'expired_at' => now(),
        'expire_reason' => 'no_activity',
        'expire_threshold_hours' => 48,
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions?export=csv")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    expect($response->getContent())->toContain('session_code');
});

it('CSV export includes every matching session, not just the first page', function () {
    // More rows than the per-page cap (max 100). Before the fix the CSV was
    // fed $paginator->items() so it silently truncated to one page.
    $count = 120;
    TillSession::factory()->settled()->count($count)->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'business_date' => now()->toDateString(),
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions?export=csv&per_page=100")
        ->assertOk();

    // Count data rows (total lines minus the header row). Sessions have no
    // embedded newlines in any exported column, so line count == row count.
    $lines = array_values(array_filter(
        explode("\n", trim($response->getContent())),
        fn ($line) => $line !== '',
    ));

    expect(count($lines) - 1)->toBe($count);
});

it('falls back opener_name to the opener user name when the column is null (issue #1005)', function () {
    // The common POS open flow sets opened_by_id but leaves the denormalized
    // opener_name column null — which is why the list previously showed "—".
    $opener = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'name' => 'Nguyen Van A',
    ]);

    TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'business_date' => now()->toDateString(),
        'opened_by_id' => $opener->id,
        'opener_name' => null,
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions")
        ->assertOk()
        ->assertJsonPath('data.0.opener_name', 'Nguyen Van A');
});
