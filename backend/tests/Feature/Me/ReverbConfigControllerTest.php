<?php

/**
 * /me/reverb-config tests (plan-012 T4.6).
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
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
        'slug' => 'rc-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->brand->refresh();
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
});

it('returns the brand public reverb key for a user in the brand org', function () {
    $this->actingAs($this->user)
        ->getJson("/api/v1/me/reverb-config?brand={$this->brand->slug}")
        ->assertOk()
        ->assertJsonPath('data.app_key', $this->brand->reverb_app_key);
});

it('resolves a duplicate brand slug inside the user organization', function () {
    $otherOrganizationId = (string) Str::uuid();
    Organization::factory()->create([
        'console_organization_id' => $otherOrganizationId,
    ]);
    Brand::factory()->create([
        'console_organization_id' => $otherOrganizationId,
        'slug' => $this->brand->slug,
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/me/reverb-config?brand={$this->brand->slug}")
        ->assertOk()
        ->assertJsonPath('data.app_key', $this->brand->reverb_app_key);
});

it('returns 403 for a user outside the brand organization', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $outsider = User::factory()->create(['console_organization_id' => $otherOrgId]);

    $this->actingAs($outsider)
        ->getJson("/api/v1/me/reverb-config?brand={$this->brand->slug}")
        ->assertForbidden();
});

it('returns 404 for an unknown brand slug', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/me/reverb-config?brand=does-not-exist')
        ->assertNotFound();
});

it("falls back to the user's first brand when no context is provided", function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/me/reverb-config')
        ->assertOk()
        ->assertJsonPath('data.app_key', $this->brand->reverb_app_key);
});

it('resolves the brand via ?shop={slug} when only the shop slug is known', function () {
    $branch = Branch::factory()->create([
        'console_brand_id' => $this->brand->console_brand_id,
        'console_organization_id' => $this->orgId,
        'slug' => 'shop-'.Str::random(4),
    ]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/me/reverb-config?shop={$branch->slug}")
        ->assertOk()
        ->assertJsonPath('data.app_key', $this->brand->reverb_app_key);
});
