<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Plan 003 — T5.1 ListProductsTest
 *
 * Covers TESTS.md scenarios:
 *   - Happy path #1  — empty brand returns [] with meta.total=0
 *   - Happy path #2  — pagination with per_page / page cursors
 *   - Validation #19 — per_page is capped at 100
 *   - Auth #21       — unauthenticated returns 401
 *   - Auth #22       — cross-org brand access returns 403
 *   - Edge    #34    — with_trashed / only_trashed filters
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-products',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/products";
});

describe('happy path', function () {
    it('returns an empty data array with meta.total=0 for an empty brand', function () {
        $this->actingAs($this->user)
            ->getJson($this->base)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    });

    it('paginates results with per_page and page query params', function () {
        Product::factory()
            ->forBrand($this->brand)
            ->count(30)
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson($this->base.'?per_page=10&page=2');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 30);
    });
});

describe('validation', function () {
    it('caps per_page at 100 so the UI can detect truncation', function () {
        Product::factory()
            ->forBrand($this->brand)
            ->count(105)
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson($this->base.'?per_page=500');

        $response->assertOk()
            ->assertJsonPath('meta.total', 105)
            ->assertJsonCount(100, 'data');
    });
});

describe('authorization', function () {
    it('returns 401 when unauthenticated', function () {
        $this->getJson($this->base)->assertUnauthorized();
    });

    it('returns 403 when accessing a brand from another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'slug' => 'other-products',
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/hq/{$otherBrand->slug}/products")
            ->assertForbidden();
    });
});

describe('edge cases', function () {
    it('supports with_trashed and only_trashed filters', function () {
        $active = Product::factory()->forBrand($this->brand)->create();
        $trashed = Product::factory()->forBrand($this->brand)->create();
        $trashed->delete();

        // Default: only active rows
        $this->actingAs($this->user)
            ->getJson($this->base)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // with_trashed: returns both
        $this->actingAs($this->user)
            ->getJson($this->base.'?with_trashed=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // only_trashed: returns only the soft-deleted row
        $response = $this->actingAs($this->user)
            ->getJson($this->base.'?only_trashed=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        expect($response->json('data.0.id'))->toBe($trashed->id);
    });
});
