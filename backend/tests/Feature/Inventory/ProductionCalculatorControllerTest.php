<?php

/**
 * #130 P1 — Inventory/ProductionCalculatorController coverage
 *
 * Endpoints (auth: sso, scope: shop):
 *   GET  /shops/{slug}/production-calculator/variants    — pickable variants
 *   POST /shops/{slug}/production-calculator/calculate   — max producible per variant
 *   POST /shops/{slug}/production-calculator/shortage    — reverse: ingredient shortages
 *
 * Service-level math is heavily covered elsewhere — these tests focus on:
 *   - validation (required fields)
 *   - 401 unauth
 *   - basic happy path returns the expected response shape
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
        'is_active' => true,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'pc-shop-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/shops/{$this->shop->slug}/production-calculator";
});

// =============================================================================
// /variants
// =============================================================================

it('returns variants list (empty when no products with recipes)', function () {
    $this->actingAs($this->user)
        ->getJson("{$this->base}/variants")
        ->assertOk()
        ->assertJsonStructure(['data']);
});

it('returns 401 on /variants without auth', function () {
    $this->getJson("{$this->base}/variants")->assertUnauthorized();
});

// =============================================================================
// /calculate
// =============================================================================

it('rejects calculate without required fields', function () {
    $this->actingAs($this->user)
        ->postJson("{$this->base}/calculate", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['warehouse_id', 'variant_ids']);
});

it('rejects calculate with empty variant_ids array', function () {
    $this->actingAs($this->user)
        ->postJson("{$this->base}/calculate", [
            'warehouse_id' => Str::uuid(),
            'variant_ids' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['variant_ids']);
});

it('rejects calculate with non-uuid warehouse_id', function () {
    $this->actingAs($this->user)
        ->postJson("{$this->base}/calculate", [
            'warehouse_id' => 'not-a-uuid',
            'variant_ids' => [Str::uuid()],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['warehouse_id']);
});

it('returns 401 on /calculate without auth', function () {
    $this->postJson("{$this->base}/calculate", [])->assertUnauthorized();
});

// =============================================================================
// /shortage
// =============================================================================

it('rejects shortage without required fields', function () {
    $this->actingAs($this->user)
        ->postJson("{$this->base}/shortage", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['warehouse_id', 'variant_id', 'desired_quantity']);
});

it('rejects shortage with desired_quantity ≤ 0', function () {
    $this->actingAs($this->user)
        ->postJson("{$this->base}/shortage", [
            'warehouse_id' => Str::uuid(),
            'variant_id' => Str::uuid(),
            'desired_quantity' => 0,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['desired_quantity']);
});

it('returns 401 on /shortage without auth', function () {
    $this->postJson("{$this->base}/shortage", [])->assertUnauthorized();
});
