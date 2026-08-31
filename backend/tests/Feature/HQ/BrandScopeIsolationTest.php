<?php

use App\Models\Brand;
use App\Models\Material;
use App\Models\Recipe;
use App\Models\User;

/**
 * Regression for the brand-isolation gap surfaced during plan-040 manual QA
 * (finding F1 / TC-011): single-resource HQ endpoints bound the model by its
 * global id and only checked the organization, never the route's brand. A
 * member of an org could therefore read/mutate a *sibling brand's* resource by
 * swapping the `{brandSlug}` in the URL. `authorizeBrand()` now closes it with
 * a 404 (so existence is not leaked across brands).
 */
beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    // Two brands in the SAME organization.
    $this->brandA = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    $this->brandB = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->actingAs($this->user);
});

describe('material brand isolation', function () {
    it('shows a material via its own brand route (control)', function () {
        $mat = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brandA->id,
        ]);

        $this->getJson("/api/v1/hq/{$this->brandA->slug}/materials/{$mat->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $mat->id);
    });

    it('returns 404 when reading a material through a sibling brand route', function () {
        $matB = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brandB->id,
        ]);

        // Same org, different brand — must NOT leak.
        $this->getJson("/api/v1/hq/{$this->brandA->slug}/materials/{$matB->id}")
            ->assertNotFound();
    });

    it('refuses to UPDATE a material through a sibling brand route', function () {
        $matB = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brandB->id,
            'yield_quantity' => 1000,
        ]);

        $this->putJson(
            "/api/v1/hq/{$this->brandA->slug}/materials/{$matB->id}",
            ['name' => 'cross-brand write attempt']
        )->assertNotFound();

        // The sibling brand's row is untouched.
        expect(Material::find($matB->id)->name)->not->toBe('cross-brand write attempt');
    });

    it('refuses to DELETE a material through a sibling brand route', function () {
        $matB = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brandB->id,
        ]);

        $this->deleteJson("/api/v1/hq/{$this->brandA->slug}/materials/{$matB->id}")
            ->assertNotFound();

        expect(Material::find($matB->id))->not->toBeNull();
    });
});

describe('recipe brand isolation', function () {
    it('returns 404 when reading a recipe through a sibling brand route', function () {
        $recipeB = Recipe::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brandB->id,
        ]);

        $this->getJson("/api/v1/hq/{$this->brandA->slug}/recipes/{$recipeB->id}")
            ->assertNotFound();
    });
});
