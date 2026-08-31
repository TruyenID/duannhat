<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Omnify\Enums\ProductStatusEnum;
use Illuminate\Support\Str;

/**
 * issue #124 — the generic PUT /hq/{brand}/products/{product} update path must
 * not let a product jump straight into a lifecycle state (active/inactive),
 * bypassing the approval workflow. Lifecycle moves are only legal once the
 * product is approved.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-status-guard',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/products";
});

it('rejects draft → active via the update path with a 422', function () {
    $product = Product::factory()->forBrand($this->brand)->create([
        'status' => ProductStatusEnum::Draft->value,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$product->id}", ['status' => 'active'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'INVALID_STATUS_TRANSITION');

    expect($product->refresh()->status)->toBe(ProductStatusEnum::Draft);
});

it('rejects draft → inactive via the update path with a 422', function () {
    $product = Product::factory()->forBrand($this->brand)->create([
        'status' => ProductStatusEnum::Draft->value,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$product->id}", ['status' => 'inactive'])
        ->assertStatus(422);

    expect($product->refresh()->status)->toBe(ProductStatusEnum::Draft);
});

it('allows approved → active via the update path', function () {
    $product = Product::factory()->forBrand($this->brand)->create([
        'status' => ProductStatusEnum::Approved->value,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$product->id}", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect($product->refresh()->status)->toBe(ProductStatusEnum::Active);
});

it('allows active → inactive via the update path', function () {
    $product = Product::factory()->forBrand($this->brand)->create([
        'status' => ProductStatusEnum::Active->value,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$product->id}", ['status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    expect($product->refresh()->status)->toBe(ProductStatusEnum::Inactive);
});

it('rejects approved → draft (demotion) via the update path', function () {
    $product = Product::factory()->forBrand($this->brand)->create([
        'status' => ProductStatusEnum::Approved->value,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$product->id}", ['status' => 'draft'])
        ->assertStatus(422);

    expect($product->refresh()->status)->toBe(ProductStatusEnum::Approved);
});

it('allows an update that keeps the same status (no-op) and edits other fields', function () {
    $product = Product::factory()->forBrand($this->brand)->create([
        'status' => ProductStatusEnum::Draft->value,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$product->id}", [
            'status' => 'draft',
            'name' => 'Renamed draft',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed draft');

    expect($product->refresh()->status)->toBe(ProductStatusEnum::Draft);
});
