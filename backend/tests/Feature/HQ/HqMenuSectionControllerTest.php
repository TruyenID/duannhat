<?php

/**
 * #130 P1 — HQ/MenuSectionController coverage
 *
 * Endpoints (auth: sso, scope: brand):
 *   GET    /api/v1/hq/{brandSlug}/menu-sections                — list
 *   POST   /api/v1/hq/{brandSlug}/menu-sections                — create
 *   GET    /api/v1/hq/{brandSlug}/menu-sections/{id}           — show
 *   PUT    /api/v1/hq/{brandSlug}/menu-sections/{id}           — update
 *   DELETE /api/v1/hq/{brandSlug}/menu-sections/{id}           — destroy
 */

use App\Models\Brand;
use App\Models\MenuSection;
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
        'slug' => 'hqms-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/menu-sections";
});

// =============================================================================
// CRUD
// =============================================================================

it('paginates menu sections', function () {
    MenuSection::factory()->count(3)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);
});

it('creates a menu section returning 201', function () {
    $this->actingAs($this->user)
        ->postJson($this->base, ['name' => '⭐ Featured'])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'name']]);

    $this->assertDatabaseHas('menu_sections', ['name' => '⭐ Featured']);
});

it('rejects creating a section with missing name', function () {
    $this->actingAs($this->user)
        ->postJson($this->base, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('returns 401 on store without auth', function () {
    $this->postJson($this->base, ['name' => 'Test'])->assertUnauthorized();
});

it('returns a section by id', function () {
    $section = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$section->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $section->id);
});

it('returns 404 for non-existent section id', function () {
    $this->actingAs($this->user)
        ->getJson("{$this->base}/".Str::uuid())
        ->assertNotFound();
});

it('updates a section', function () {
    $section = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$section->id}", ['name' => 'Updated'])
        ->assertOk();

    expect($section->fresh()->name)->toBe('Updated');
});

it('deletes a section returning 204', function () {
    $section = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$section->id}")
        ->assertNoContent();
});

it('returns 401 on index without auth', function () {
    $this->getJson($this->base)->assertUnauthorized();
});
