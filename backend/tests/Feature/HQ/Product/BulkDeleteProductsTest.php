<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Plan 003 — T5.3e BulkDeleteProductsTest
 *
 * Covers TESTS.md scenarios:
 *   - Error handling #6 — bulk-delete returns deleted count AND per-id errors
 *                         for ids that belong to another brand
 *   - Validation         — ids array is required / capped at 100
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-bulk',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/products";
});

it('bulk-deletes multiple products and soft-deletes the rows', function () {
    $ids = Product::factory()
        ->forBrand($this->brand)
        ->count(3)
        ->create()
        ->pluck('id')
        ->toArray();

    $response = $this->actingAs($this->user)
        ->postJson("{$this->base}/bulk-delete", ['ids' => $ids]);

    $response->assertOk()
        ->assertJsonPath('deleted', 3);

    foreach ($ids as $id) {
        expect(Product::find($id))->toBeNull();
        expect(Product::withTrashed()->find($id))->not->toBeNull();
    }
});

it('reports per-id errors for ids from another organization', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $otherOrgId,
        'is_active' => true,
    ]);

    $mine = Product::factory()->forBrand($this->brand)->create();
    $foreign = Product::factory()->forBrand($otherBrand)->create();

    $response = $this->actingAs($this->user)
        ->postJson("{$this->base}/bulk-delete", [
            'ids' => [$mine->id, $foreign->id],
        ]);

    $response->assertOk()
        ->assertJsonPath('deleted', 1);

    $errors = $response->json('errors');
    expect($errors)->toHaveCount(1);
    expect($errors[0]['id'])->toBe($foreign->id);

    expect(Product::find($mine->id))->toBeNull();
    expect(Product::find($foreign->id))->not->toBeNull();
});

it('returns 422 when bulk-deleting more than 100 ids', function () {
    $ids = collect(range(1, 101))->map(fn () => (string) Str::uuid())->toArray();

    $this->actingAs($this->user)
        ->postJson("{$this->base}/bulk-delete", ['ids' => $ids])
        ->assertUnprocessable();
});
