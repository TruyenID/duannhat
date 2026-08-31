<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\FloatingSection;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
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
    ]);

    $this->adminRole = Role::firstOrCreate(
        ['slug' => 'org-admin'],
        ['name' => 'Org Admin', 'level' => 100],
    );
    $this->adminRole->permissions()->syncWithoutDetaching(collect(['menu.view', 'menu.manage'])
        ->map(fn ($slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'group' => 'menu'])->id));

    $this->admin = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->admin->assignRole($this->adminRole, $this->orgId);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

// =============================================================================
//  CRUD
// =============================================================================

it('can list floating sections', function () {
    FloatingSection::factory()->count(3)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson("{$this->baseUrl}/floating-sections");

    $response->assertOk()->assertJsonCount(3, 'data');
});

it('can create a floating section', function () {
    $response = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections", [
            'name' => 'Happy Hour 17:00-19:00',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Happy Hour 17:00-19:00')
        ->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('floating_sections', [
        'name' => 'Happy Hour 17:00-19:00',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
});

it('validates required name on store', function () {
    // Name is now translatable (Product standard): the "at least one language"
    // check surfaces on ja.name, not the top-level mirror.
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ja.name']);
});

it('persists a per-locale name across ja/en/vi on store', function () {
    $response = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections", [
            'name' => 'ハッピーアワー',
            'ja' => ['name' => 'ハッピーアワー'],
            'en' => ['name' => 'Happy Hour'],
            'vi' => ['name' => 'Giờ vàng'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.translations.ja.name', 'ハッピーアワー')
        ->assertJsonPath('data.translations.en.name', 'Happy Hour')
        ->assertJsonPath('data.translations.vi.name', 'Giờ vàng');

    $id = $response->json('data.id');
    $this->assertDatabaseHas('floating_section_translations', [
        'floating_section_id' => $id,
        'locale' => 'vi',
        'name' => 'Giờ vàng',
    ]);
    $this->assertDatabaseHas('floating_section_translations', [
        'floating_section_id' => $id,
        'locale' => 'en',
        'name' => 'Happy Hour',
    ]);
    // Top-level mirror still holds the default-locale value.
    $this->assertDatabaseHas('floating_sections', [
        'id' => $id,
        'name' => 'ハッピーアワー',
    ]);
});

it('accepts a single-language name and skips empty locales on store', function () {
    // FE Rule-3 strip: only the filled locale is sent. Empty locales must not
    // create blank translation rows (name column is NOT NULL).
    $response = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections", [
            'name' => 'Giờ vàng',
            'vi' => ['name' => 'Giờ vàng'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.translations.vi.name', 'Giờ vàng');

    $id = $response->json('data.id');
    $this->assertDatabaseHas('floating_section_translations', [
        'floating_section_id' => $id,
        'locale' => 'vi',
        'name' => 'Giờ vàng',
    ]);
    $this->assertDatabaseMissing('floating_section_translations', [
        'floating_section_id' => $id,
        'locale' => 'ja',
    ]);
});

it('updates a per-locale name on update', function () {
    $section = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Old',
    ]);

    $this->actingAs($this->admin)
        ->putJson("{$this->baseUrl}/floating-sections/{$section->id}", [
            'name' => 'New JA',
            'ja' => ['name' => 'New JA'],
            'vi' => ['name' => 'Giờ vàng mới'],
        ])
        ->assertOk()
        ->assertJsonPath('data.translations.vi.name', 'Giờ vàng mới');

    $this->assertDatabaseHas('floating_section_translations', [
        'floating_section_id' => $section->id,
        'locale' => 'vi',
        'name' => 'Giờ vàng mới',
    ]);
});

it('can show a floating section', function () {
    $section = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->actingAs($this->admin)
        ->getJson("{$this->baseUrl}/floating-sections/{$section->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $section->id);
});

it('can update a floating section', function () {
    $section = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->putJson("{$this->baseUrl}/floating-sections/{$section->id}", [
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

it('can delete a floating section', function () {
    $section = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->actingAs($this->admin)
        ->deleteJson("{$this->baseUrl}/floating-sections/{$section->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('floating_sections', ['id' => $section->id]);
});

it('bulk-deletes floating sections', function () {
    $sections = FloatingSection::factory()->count(3)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/bulk-delete", [
            'ids' => $sections->pluck('id')->all(),
        ]);

    $response->assertOk()->assertJsonPath('deleted', 3);
    foreach ($sections as $section) {
        $this->assertSoftDeleted('floating_sections', ['id' => $section->id]);
    }
});

it('does not bulk-delete a branch clone from the HQ endpoint', function () {
    $master = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
    ]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $clone = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$master->id}/clone-to-branch", [
            'branch_id' => $branch->id,
        ])
        ->json('data');

    $response = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/bulk-delete", [
            'ids' => [$clone['id']],
        ]);

    $response->assertOk()->assertJsonPath('deleted', 0);
    $this->assertDatabaseHas('floating_sections', ['id' => $clone['id'], 'deleted_at' => null]);
});

// =============================================================================
//  Products
// =============================================================================

it('can add, toggle, price-override per SKU, and remove products in a floating section', function () {
    $section = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true, 'selling_price' => 500]);

    $addResponse = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$section->id}/products", [
            'product_ids' => [$product->id],
        ]);
    $addResponse->assertCreated()->assertJsonCount(1, 'data');
    $floatingSectionProductId = $addResponse->json('data.0.id');

    // A row is seeded per active SKU, price copied from the catalog.
    $floatingSectionProduct = $section->products()->findOrFail($floatingSectionProductId);
    $floatingSectionSkuId = $floatingSectionProduct->skus()->where('product_sku_id', $sku->id)->firstOrFail()->id;
    expect((float) $floatingSectionProduct->skus()->first()->selling_price)->toBe(500.0);

    // Duplicate add is a no-op, not a duplicate row.
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$section->id}/products", [
            'product_ids' => [$product->id],
        ])
        ->assertCreated()
        ->assertJsonCount(0, 'data');
    expect($section->products()->count())->toBe(1);

    // Toggle (product-level, whole product on/off)
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$section->id}/products/{$floatingSectionProductId}/toggle")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    // Toggle a single SKU
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$section->id}/products/{$floatingSectionProductId}/skus/{$floatingSectionSkuId}/toggle")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    // Price override — per SKU
    $this->actingAs($this->admin)
        ->patchJson("{$this->baseUrl}/floating-sections/{$section->id}/products/{$floatingSectionProductId}/skus/{$floatingSectionSkuId}/price", [
            'selling_price' => 19999,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_price_overridden', true);

    // Reset price — back to the catalog SKU's current price
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$section->id}/products/{$floatingSectionProductId}/skus/{$floatingSectionSkuId}/price/reset")
        ->assertOk()
        ->assertJsonPath('data.is_price_overridden', false)
        ->assertJsonPath('data.selling_price', '500.00');

    // Remove
    $this->actingAs($this->admin)
        ->deleteJson("{$this->baseUrl}/floating-sections/{$section->id}/products/{$floatingSectionProductId}")
        ->assertNoContent();
    expect($section->products()->count())->toBe(0);
});

it('rejects products from another brand', function () {
    $section = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $otherType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $otherBrand->id,
    ]);
    $foreignProduct = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $otherBrand->id,
        'product_type_id' => $otherType->id,
    ]);

    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$section->id}/products", [
            'product_ids' => [$foreignProduct->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('product_ids.0');

    expect($section->products()->count())->toBe(0);
});

it('does not mutate SKU rows on GET and tops up only on explicit re-add', function () {
    $section = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
    ]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
    ]);
    ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true]);
    $this->actingAs($this->admin)->postJson("{$this->baseUrl}/floating-sections/{$section->id}/products", [
        'product_ids' => [$product->id],
    ])->assertCreated();

    ProductSku::factory()->withSequencedOption()->create(['product_id' => $product->id, 'is_active' => true]);
    $floatingProduct = $section->products()->firstOrFail();
    expect($floatingProduct->skus()->count())->toBe(1);

    $this->actingAs($this->admin)
        ->getJson("{$this->baseUrl}/floating-sections/{$section->id}")
        ->assertOk();
    expect($floatingProduct->skus()->count())->toBe(1);

    $this->actingAs($this->admin)->postJson("{$this->baseUrl}/floating-sections/{$section->id}/products", [
        'product_ids' => [$product->id],
    ])->assertCreated();
    expect($floatingProduct->skus()->count())->toBe(2);
});

it('allows menu.view to read but requires menu.manage to mutate', function () {
    $viewerRole = Role::create(['slug' => 'floating-viewer', 'name' => 'Viewer', 'level' => 10]);
    $viewPermission = Permission::firstOrCreate(
        ['slug' => 'menu.view'],
        ['name' => 'menu.view', 'group' => 'menu'],
    );
    $viewerRole->permissions()->attach($viewPermission);
    $viewer = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $viewer->assignRole($viewerRole, $this->orgId);
    $section = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->actingAs($viewer)
        ->getJson("{$this->baseUrl}/floating-sections/{$section->id}")
        ->assertOk();
    $this->actingAs($viewer)
        ->putJson("{$this->baseUrl}/floating-sections/{$section->id}", ['name' => 'Forbidden'])
        ->assertForbidden();
});

it('can reorder products in a floating section', function () {
    $section = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $products = Product::factory()->count(3)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
    ]);

    $added = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$section->id}/products", [
            'product_ids' => $products->pluck('id')->all(),
        ])
        ->json('data');

    $reversedIds = collect($added)->pluck('id')->reverse()->values()->all();

    $this->actingAs($this->admin)
        ->putJson("{$this->baseUrl}/floating-sections/{$section->id}/products/reorder", [
            'ordered_ids' => $reversedIds,
        ])
        ->assertOk();

    $ordered = $section->products()->orderBy('display_order')->pluck('id')->all();
    expect($ordered)->toBe($reversedIds);
});

// =============================================================================
//  Authorization
// =============================================================================

it('forbids org-admin from org-A accessing a floating section in org-B', function () {
    $section = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherUser = User::factory()->create([
        'console_organization_id' => $otherOrgId,
    ]);
    $otherUser->assignRole($this->adminRole, $otherOrgId);
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $otherOrgId,
    ]);

    $this->actingAs($otherUser)
        ->getJson("/api/v1/hq/{$otherBrand->slug}/floating-sections/{$section->id}")
        ->assertForbidden();
});

it('returns 401 for unauthenticated requests', function () {
    $this->getJson("{$this->baseUrl}/floating-sections")->assertUnauthorized();
    $this->postJson("{$this->baseUrl}/floating-sections", ['name' => 'Test'])->assertUnauthorized();
});
