<?php

/**
 * Plan-024 T3.1 — ProductSku.inventory_mode default + PATCH update.
 *
 * Covers TESTS.md scenarios:
 *   - Happy 1: default inventory_mode is `made_to_order` when not set
 *   - Happy 2: PUT /hq/{brandSlug}/skus/{id} with inventory_mode=track_stock persists
 *   - Validation 1: invalid inventory_mode value returns 422
 *   - Authz 1: unauthenticated PATCH → 401
 *   - Authz 2: cross-org user PATCH → 403
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
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
        'slug' => 'acme-inv-mode',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->product = Product::factory()->forBrand($this->brand)->create();
    $this->base = "/api/v1/hq/{$this->brand->slug}/skus";
});

// =========================================================================
//  Happy 1 — Default inventory_mode is made_to_order
// =========================================================================

it('defaults inventory_mode to made_to_order when not set', function () {
    $sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
    ]);

    // Reload from DB so the column default is applied — Eloquent does not
    // inject DB defaults into the in-memory model on create().
    $sku = $sku->fresh();

    $modeValue = $sku->inventory_mode instanceof BackedEnum
        ? $sku->inventory_mode->value
        : (string) $sku->inventory_mode;

    expect($modeValue)->toBe('made_to_order');
});

// =========================================================================
//  Happy 2 — PATCH inventory_mode=track_stock persists
// =========================================================================

it('accepts inventory_mode=track_stock via PUT and persists it', function () {
    $sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$sku->id}", [
            'inventory_mode' => 'track_stock',
        ])
        ->assertOk()
        ->assertJsonPath('data.inventory_mode', 'track_stock');

    $sku->refresh();
    $modeValue = $sku->inventory_mode instanceof BackedEnum
        ? $sku->inventory_mode->value
        : (string) $sku->inventory_mode;

    expect($modeValue)->toBe('track_stock');
});

it('accepts inventory_mode=made_to_order via PUT and persists it', function () {
    $sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'inventory_mode' => 'track_stock',
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$sku->id}", [
            'inventory_mode' => 'made_to_order',
        ])
        ->assertOk()
        ->assertJsonPath('data.inventory_mode', 'made_to_order');

    $sku->refresh();
    $modeValue = $sku->inventory_mode instanceof BackedEnum
        ? $sku->inventory_mode->value
        : (string) $sku->inventory_mode;

    expect($modeValue)->toBe('made_to_order');
});

// =========================================================================
//  Validation 1 — invalid inventory_mode value returns 422
// =========================================================================

it('returns 422 when inventory_mode is an invalid value', function () {
    $sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$sku->id}", [
            'inventory_mode' => 'invalid_value',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['inventory_mode']);
});

// =========================================================================
//  Authz 1 — unauthenticated returns 401
// =========================================================================

it('returns 401 when not authenticated', function () {
    $sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
    ]);

    $this->putJson("{$this->base}/{$sku->id}", [
        'inventory_mode' => 'track_stock',
    ])
        ->assertUnauthorized();
});

// =========================================================================
//  Authz 2 — cross-org user returns 403
// =========================================================================

it('returns 403 when user belongs to a different organization', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherUser = User::factory()->create([
        'console_organization_id' => $otherOrgId,
    ]);
    grantOrgAccess($otherUser, $otherOrgId);

    $sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
    ]);

    $this->actingAs($otherUser)
        ->putJson("{$this->base}/{$sku->id}", [
            'inventory_mode' => 'track_stock',
        ])
        ->assertForbidden();
});
