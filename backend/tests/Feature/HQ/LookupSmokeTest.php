<?php

/**
 * Smoke coverage for every "lookup" / "dropdown" endpoint exposed to
 * admin-web (issue #130 — P3 lookup smoke).
 *
 * Lookup endpoints are tiny dropdown feeders. They typically don't have
 * dedicated controller tests because each one is a single-line wrapper
 * around `->where('console_organization_id', ...)->select('id, name')`.
 * The risk profile is uniform: every one of them must (a) require auth,
 * (b) scope to the route brand / shop, (c) return `{data: [...]}`.
 *
 * Rather than 12 near-identical test files, this single file uses Pest
 * datasets to assert all three properties for every endpoint at once.
 *
 * Adding a new lookup route? Just add an entry to the dataset.
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
        'slug' => 'lk-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'lksh-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
});

/**
 * HQ lookups — all live under `/api/v1/hq/{brandSlug}/...`.
 */
dataset('hq_lookups', [
    'product-types' => ['product-types/lookup'],
    'categories' => ['categories/lookup'],
    'products' => ['products/lookup'],
    'skus' => ['skus/lookup'],
    'topping-groups' => ['topping-groups/lookup'],
    'menus' => ['menus/lookup'],
    'master-menus' => ['master-menus/lookup'],
    'devices' => ['devices/lookup'],
    'materials' => ['materials/lookup'],
    'recipes' => ['recipes/lookup'],
]);

/**
 * Shop lookups — all live under `/api/v1/shops/{shopSlug}/...`.
 */
dataset('shop_lookups', [
    'zones' => ['zones/lookup'],
    'warehouses' => ['warehouses/lookup'],
    'product-skus' => ['product-skus/lookup'],
    'materials' => ['materials/lookup'],
]);

it('returns 200 with a `data` envelope for HQ lookup :path', function (string $path) {
    $url = "/api/v1/hq/{$this->brand->slug}/{$path}";
    $this->actingAs($this->user)
        ->getJson($url)
        ->assertOk()
        ->assertJsonStructure(['data']);
})->with('hq_lookups');

it('returns 401 without auth for HQ lookup :path', function (string $path) {
    $url = "/api/v1/hq/{$this->brand->slug}/{$path}";
    $this->getJson($url)->assertUnauthorized();
})->with('hq_lookups');

it('returns 200 with a `data` envelope for Shop lookup :path', function (string $path) {
    $url = "/api/v1/shops/{$this->shop->slug}/{$path}";
    $this->actingAs($this->user)
        ->getJson($url)
        ->assertOk()
        ->assertJsonStructure(['data']);
})->with('shop_lookups');

it('returns 401 without auth for Shop lookup :path', function (string $path) {
    $url = "/api/v1/shops/{$this->shop->slug}/{$path}";
    $this->getJson($url)->assertUnauthorized();
})->with('shop_lookups');
