<?php

use App\Models\Brand;
use App\Models\MenuSection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Authorization tests for MenuSection — regression for #897 (cross-org IDOR).
 *
 * Patterns follow BrandScopeIsolationTest.php (brand isolation → 404) and
 * CategoryControllerContractTest.php (cross-org → ResolveBrandFromSlug 403).
 */
beforeEach(function () {
    $this->orgA = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgA,
        'console_organization_id' => $this->orgA,
    ]);

    $this->orgB = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgB,
        'console_organization_id' => $this->orgB,
    ]);

    $this->brandA = Brand::factory()->create([
        'console_organization_id' => $this->orgA,
        'slug' => 'brand-a-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->brandB = Brand::factory()->create([
        'console_organization_id' => $this->orgA,
        'slug' => 'brand-b-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->brandOtherOrg = Brand::factory()->create([
        'console_organization_id' => $this->orgB,
        'slug' => 'other-org-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgA,
    ]);
    grantOrgAccess($this->user, $this->orgA);

    $this->actingAs($this->user);
});

// =============================================================================
//  Cross-org isolation (403 from ResolveBrandFromSlug — user has no IAM access)
// =============================================================================

describe('cross-org isolation', function () {
    it('lists only sections in the user organization', function () {
        MenuSection::factory()->create([
            'organization_id' => $this->orgA,
            'brand_id' => $this->brandA->id,
        ]);
        MenuSection::factory()->create([
            'organization_id' => $this->orgB,
            'brand_id' => $this->brandOtherOrg->id,
        ]);

        $response = $this->getJson("/api/v1/hq/{$this->brandA->slug}/menu-sections");

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('returns 403 when accessing a sibling org brand route', function () {
        // brandOtherOrg belongs to orgB — user has no IAM access to it.
        $this->getJson("/api/v1/hq/{$this->brandOtherOrg->slug}/menu-sections")
            ->assertForbidden();
    });
});

// =============================================================================
//  Brand isolation (404 — sibling brands within the same org)
// =============================================================================

describe('brand isolation', function () {
    it('shows a section via its own brand route (control)', function () {
        $section = MenuSection::factory()->create([
            'organization_id' => $this->orgA,
            'brand_id' => $this->brandA->id,
        ]);

        $this->getJson("/api/v1/hq/{$this->brandA->slug}/menu-sections/{$section->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $section->id);
    });

    it('returns 404 when reading a section through a sibling brand route', function () {
        $section = MenuSection::factory()->create([
            'organization_id' => $this->orgA,
            'brand_id' => $this->brandB->id,
        ]);

        $this->getJson("/api/v1/hq/{$this->brandA->slug}/menu-sections/{$section->id}")
            ->assertNotFound();
    });

    it('returns 404 when updating a section through a sibling brand route', function () {
        $section = MenuSection::factory()->create([
            'organization_id' => $this->orgA,
            'brand_id' => $this->brandB->id,
        ]);

        $this->putJson("/api/v1/hq/{$this->brandA->slug}/menu-sections/{$section->id}", [
            'name' => 'Hacked',
        ])->assertNotFound();
    });

    it('returns 404 when deleting a section through a sibling brand route', function () {
        $section = MenuSection::factory()->create([
            'organization_id' => $this->orgA,
            'brand_id' => $this->brandB->id,
        ]);

        $this->deleteJson("/api/v1/hq/{$this->brandA->slug}/menu-sections/{$section->id}")
            ->assertNotFound();
    });
});

// =============================================================================
//  Store stamps org/brand from route context
// =============================================================================

describe('store authorization', function () {
    it('creates a section with org/brand stamped from route context', function () {
        $response = $this->postJson("/api/v1/hq/{$this->brandA->slug}/menu-sections", [
            'name' => 'Stamped Section',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('menu_sections', [
            'id' => $response->json('data.id'),
            'organization_id' => $this->orgA,
            'brand_id' => $this->brandA->id,
        ]);
    });

    it('rejects client-supplied organization_id', function () {
        $this->postJson("/api/v1/hq/{$this->brandA->slug}/menu-sections", [
            'name' => 'Spoofed Org',
            'organization_id' => $this->orgB,
        ])->assertCreated(); // org_id is unset from rules, silently ignored

        // The section should be created with the route org, not the spoofed one.
        $sectionId = MenuSection::where('name', 'Spoofed Org')->first()->id;

        $this->assertEquals($this->orgA, MenuSection::find($sectionId)->organization_id);
    });
});
