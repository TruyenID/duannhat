<?php

/**
 * Plan-019 — feature tests for HQ\MenuPromotionController.
 *
 * Read-only cross-shop list (T4.9). HQ org-admin / org-manager only;
 * shop-manager NOT allowed (Decision: HQ surface is for cross-shop
 * reporting, shop-manager uses /shops/{slug}/promotions).
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\MenuPromotion;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'hq-promo-'.Str::random(4),
    ]);

    $this->shopA = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'shop-a',
        'is_active' => true,
    ]);
    $this->shopB = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'shop-b',
        'is_active' => true,
    ]);

    $orgMgr = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->orgManager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->orgManager->assignRole($orgMgr, $this->orgId);
    grantOrgAccess($this->orgManager, $this->orgId);

    // Two promotions across two branches in the same brand.
    $this->p1 = MenuPromotion::factory()->create(seedHqPromo($this->shopA, $this->brand, $this->orgId, 'Shop A promo', 10));
    $this->p2 = MenuPromotion::factory()->create(seedHqPromo($this->shopB, $this->brand, $this->orgId, 'Shop B promo', 30));

    $this->base = "/api/v1/hq/{$this->brand->slug}/promotions";
});

it('lists promotions across every branch in the brand', function () {
    $response = $this->actingAs($this->orgManager)
        ->getJson($this->base)
        ->assertOk();

    $ids = $response->json('data.*.id');
    expect($ids)->toContain($this->p1->id)->toContain($this->p2->id);
});

it('filters by branch_id', function () {
    $response = $this->actingAs($this->orgManager)
        ->getJson("{$this->base}?branch_id={$this->shopA->id}")
        ->assertOk();

    $ids = $response->json('data.*.id');
    expect($ids)->toContain($this->p1->id);
    expect($ids)->not->toContain($this->p2->id);
});

it('returns 401 without auth', function () {
    $this->getJson($this->base)->assertUnauthorized();
});

it('rejects users without HQ org-admin/manager role on the HQ list', function () {
    // Build a user with NO IAM role assignment at all — they will hit
    // ResolveBrandFromSlug's auth check before the policy fires, but
    // the practical guarantee (non-HQ users cannot access this surface)
    // is what we want to assert.
    $bare = User::factory()->create(['console_organization_id' => $this->orgId]);

    $response = $this->actingAs($bare)->getJson($this->base);

    expect($response->status())->toBeIn([403, 404]); // either policy or middleware blocks
});

// ─── helpers ────────────────────────────────────────────────────────────

function seedHqPromo(Branch $branch, Brand $brand, string $orgId, string $name, float $pct): array
{
    return [
        'branch_id' => $branch->id,
        'brand_id' => $brand->id,
        'organization_id' => $orgId,
        'name' => $name,
        'discount_percent' => $pct,
        'applies_to' => 'all_items',
        'daily_time_from' => null,
        'daily_time_to' => null,
        'weekdays' => null,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'stacking_mode' => 'stackable_with_coupons',
        'is_active' => true,
    ];
}
