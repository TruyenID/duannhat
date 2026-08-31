<?php

/**
 * #130 P1 — HQ/BrandInfoController coverage
 *
 * Endpoint: GET /api/v1/hq/{brandSlug} — brand metadata for HQ layout
 * (slug validation, theme colors). Auth via SSO + ResolveBrandFromSlug.
 */

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
        'slug' => 'hqb-'.Str::random(4),
        'is_active' => true,
        'name' => 'Brand Test',
        'primary_color' => '#FF0000',
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
});

it('returns brand metadata via GET /hq/{brandSlug}', function () {
    $this->actingAs($this->user)
        ->getJson("/api/v1/hq/{$this->brand->slug}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->brand->id)
        ->assertJsonPath('data.name', 'Brand Test')
        ->assertJsonPath('data.primary_color', '#FF0000');
});

it('resolves a duplicate slug inside the authenticated organization', function () {
    $otherConsoleOrganizationId = (string) Str::uuid();
    Organization::factory()->create([
        'console_organization_id' => $otherConsoleOrganizationId,
    ]);
    Brand::factory()->create([
        'console_organization_id' => $otherConsoleOrganizationId,
        'slug' => $this->brand->slug,
        'is_active' => true,
        'name' => 'Foreign Brand With Same Slug',
    ]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/hq/{$this->brand->slug}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->brand->id)
        ->assertJsonPath('data.name', 'Brand Test');
});

it('response shape includes expected theme keys', function () {
    $this->actingAs($this->user)
        ->getJson("/api/v1/hq/{$this->brand->slug}")
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'id', 'slug', 'name', 'description', 'logo_url',
            'primary_color', 'secondary_color', 'accent_color', 'text_color',
        ]]);
});

it('returns 404 for non-existent brand slug', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/hq/does-not-exist')
        ->assertNotFound();
});

it('returns 401 without auth', function () {
    $this->getJson("/api/v1/hq/{$this->brand->slug}")->assertUnauthorized();
});

it('returns 403 or 404 when user does not belong to the brand org', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $otherOrgId,
        'slug' => 'hqb-other-'.Str::random(4),
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/hq/{$otherBrand->slug}");
    expect($response->status())->toBeIn([403, 404]);
});
