<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\User;
use App\Support\Iam\RoleTemplateMatrix;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

describe('authorization regression #966', function () {
    it('forbids a production tempo-staff role from creating HQ catalog data', function () {
        $role = Role::create([
            'slug' => 'tempo-staff',
            'name' => 'Tempo Staff',
            'level' => 10,
            'console_organization_id' => $this->orgId,
        ]);
        $role->permissions()->sync(
            Permission::query()
                ->whereIn('slug', RoleTemplateMatrix::for('shop-staff'))
                ->pluck('id'),
        );
        $staff = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        $staff->assignRole($role, $this->orgId);

        $this->actingAs($staff)
            ->postJson("{$this->baseUrl}/products", [
                'product_type_id' => $this->productType->id,
                'name' => 'Forbidden product',
                'brand_id' => $this->brand->id,
            ])
            ->assertForbidden();
    });

    it('allows a production tempo-admin permission grant and honors revocation immediately', function () {
        $role = Role::create([
            'slug' => 'tempo-admin',
            'name' => 'Tempo Admin',
            'level' => 100,
            'console_organization_id' => $this->orgId,
        ]);
        $role->permissions()->sync(
            Permission::query()
                ->whereIn('slug', RoleTemplateMatrix::for('org-admin'))
                ->pluck('id'),
        );
        $admin = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        $admin->assignRole($role, $this->orgId);

        $payload = [
            'product_type_id' => $this->productType->id,
            'name' => 'Permission-backed product',
            'brand_id' => $this->brand->id,
        ];

        $this->actingAs($admin)
            ->postJson("{$this->baseUrl}/products", $payload)
            ->assertCreated();

        $role->permissions()->detach(
            Permission::query()->where('slug', 'catalog.create')->value('id'),
        );

        $this->actingAs($admin)
            ->postJson("{$this->baseUrl}/products", [
                ...$payload,
                'name' => 'Revoked product',
            ])
            ->assertForbidden();
    });
});

// =============================================================================
// Index
// =============================================================================

describe('index', function () {
    it('lists products for the organization', function () {
        Product::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);

        // Other org — should not appear
        Product::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->baseUrl}/products");

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data');
    });

    it('filters products by status', function () {
        Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'status' => 'draft',
            'brand_id' => $this->brand->id,
        ]);

        Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'status' => 'active',
            'brand_id' => $this->brand->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->baseUrl}/products?status=draft");

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'draft');
    });

    it('paginates results', function () {
        Product::factory()->count(30)->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->baseUrl}/products?per_page=10");

        $response->assertSuccessful()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.last_page', 3);
    });
});

// =============================================================================
// Store
// =============================================================================

describe('store', function () {
    it('reuses the aggregate identity when the same idempotency key is retried', function () {
        $payload = [
            'product_type_id' => $this->productType->id,
            'name' => 'Retry-safe product',
        ];

        $first = $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'pos-request-001')
            ->postJson("{$this->baseUrl}/products", $payload)
            ->assertCreated();
        $replay = $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'pos-request-001')
            ->postJson("{$this->baseUrl}/products", $payload)
            ->assertOk();

        expect($replay->json('data.id'))->toBe($first->json('data.id'))
            ->and(Product::whereKey($first->json('data.id'))->count())->toBe(1)
            ->and(ProductSku::where('product_id', $first->json('data.id'))->count())->toBe(1);
    });

    it('retries an idempotent nested product as one complete graph', function () {
        $payload = [
            'product_type_id' => $this->productType->id,
            'name' => 'Nested retry product',
            'options' => [[
                'key' => 'size', 'name' => 'Size', 'position' => 1,
                'values' => [['value' => 'small', 'label' => 'Small', 'position' => 1]],
            ]],
            'skus' => [['value_indices' => [0], 'selling_price' => 500]],
        ];

        $first = $this->actingAs($this->user)->withHeader('Idempotency-Key', 'nested-001')
            ->postJson("{$this->baseUrl}/products", $payload)->assertCreated();
        $this->actingAs($this->user)->withHeader('Idempotency-Key', 'nested-001')
            ->postJson("{$this->baseUrl}/products", $payload)->assertOk();

        $productId = $first->json('data.id');
        expect(Product::whereKey($productId)->count())->toBe(1)
            ->and(ProductOption::where('product_id', $productId)->count())->toBe(1)
            ->and(ProductOptionValue::whereIn('option_id', ProductOption::where('product_id', $productId)->pluck('id'))->count())->toBe(1)
            ->and(ProductSku::where('product_id', $productId)->count())->toBe(1);
    });

    it('rejects blank and oversized idempotency keys', function (string $key) {
        $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("{$this->baseUrl}/products", [
                'product_type_id' => $this->productType->id,
                'name' => 'Invalid key product',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);
    })->with(['blank' => '   ', 'oversized' => str_repeat('x', 256)]);

    it('rejects duplicate nested option positions before payload construction', function () {
        $this->actingAs($this->user)->postJson("{$this->baseUrl}/products", [
            'product_type_id' => $this->productType->id,
            'name' => 'Duplicate positions',
            'options' => [
                ['key' => 'size', 'name' => 'Size', 'position' => 1, 'values' => [['value' => 's', 'label' => 'S']]],
                ['key' => 'milk', 'name' => 'Milk', 'position' => 1, 'values' => [['value' => 'oat', 'label' => 'Oat']]],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['options.0.position', 'options.1.position']);
    });

    it('rejects duplicate value slugs and positions within one option', function (array $values, string $errorKey) {
        $this->actingAs($this->user)->postJson("{$this->baseUrl}/products", [
            'product_type_id' => $this->productType->id,
            'name' => 'Duplicate option values',
            'options' => [[
                'key' => 'size', 'name' => 'Size', 'position' => 1, 'values' => $values,
            ]],
            'skus' => [['value_indices' => [0], 'selling_price' => 500]],
        ])->assertUnprocessable()->assertJsonValidationErrors([$errorKey]);
    })->with([
        'slug' => [[
            ['value' => 'small', 'label' => 'Small', 'position' => 1],
            ['value' => 'small', 'label' => 'Small duplicate', 'position' => 2],
        ], 'options.0.values.1.value'],
        'position' => [[
            ['value' => 'small', 'label' => 'Small', 'position' => 1],
            ['value' => 'large', 'label' => 'Large', 'position' => 1],
        ], 'options.0.values.1.position'],
    ]);

    it('allows the same value slug under different options', function () {
        $this->actingAs($this->user)->postJson("{$this->baseUrl}/products", [
            'product_type_id' => $this->productType->id,
            'name' => 'Scoped option value slugs',
            'options' => [
                ['key' => 'size', 'name' => 'Size', 'position' => 1, 'values' => [['value' => 'default', 'label' => 'Default', 'position' => 1]]],
                ['key' => 'milk', 'name' => 'Milk', 'position' => 2, 'values' => [['value' => 'default', 'label' => 'Default', 'position' => 1]]],
            ],
            'skus' => [['value_indices' => [0, 0], 'selling_price' => 500]],
        ])->assertCreated();
    });

    it('returns conflict when an idempotency key is reused with another payload', function () {
        $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'pos-request-002')
            ->postJson("{$this->baseUrl}/products", [
                'product_type_id' => $this->productType->id,
                'name' => 'Original product',
            ])
            ->assertCreated();

        $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'pos-request-002')
            ->postJson("{$this->baseUrl}/products", [
                'product_type_id' => $this->productType->id,
                'name' => 'Changed product',
            ])
            ->assertConflict()
            ->assertJsonPath('error', 'IDEMPOTENCY_PAYLOAD_MISMATCH');

        expect(Product::where('name', 'Original product')->count())->toBe(1)
            ->and(Product::where('name', 'Changed product')->count())->toBe(0);
    });

    it('creates a product with product_type_id and name', function () {
        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/products", [
                'product_type_id' => $this->productType->id,
                'name' => 'Test Product',
                'slug' => 'test-product',
                'brand_id' => $this->brand->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Test Product')
            ->assertJsonPath('data.product_type_id', $this->productType->id);
    });

    it('auto-generates a SKU when not provided', function () {
        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/products", [
                'product_type_id' => $this->productType->id,
                'name' => 'Auto SKU Product',
                'slug' => 'auto-sku-product',
                'brand_id' => $this->brand->id,
            ]);

        $response->assertCreated();

        $productId = $response->json('data.id');
        expect(ProductSku::where('product_id', $productId)->count())->toBeGreaterThanOrEqual(1);
    });

    it('creates a default variant when no variants provided', function () {
        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/products", [
                'product_type_id' => $this->productType->id,
                'name' => 'Default Variant Product',
                'slug' => 'default-variant-product',
                'brand_id' => $this->brand->id,
            ]);

        $response->assertCreated();

        $productId = $response->json('data.id');
        expect(ProductSku::where('product_id', $productId)->count())->toBe(1);
    });

    it('validates required fields', function () {
        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/products", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['product_type_id', 'ja.name']);
    });
});

// =============================================================================
// Show
// =============================================================================

describe('show', function () {
    it('returns a product with relations', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        ProductSku::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->baseUrl}/products/{$product->id}");

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonStructure([
                'data' => ['id', 'name', 'slug', 'status', 'product_type_id'],
            ]);
    });

    it('returns 403 for a product from another organization', function () {
        $otherProduct = Product::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->baseUrl}/products/{$otherProduct->id}");

        $response->assertForbidden();
    });

    // The HQ product edit screen renders <VariantsDisplay /> from
    // `data.options[].values` and `data.skus[].option_value{1,2,3}`.
    // findById() must eager-load these, otherwise the panel falls back to
    // the "no variants" empty state for every product. Regression caught
    // 2026-04-15 — see ProductService::findById override.
    it('eager-loads options + skus with their option values for the detail page', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        $sizeOption = ProductOption::factory()->create([
            'product_id' => $product->id,
            'name' => 'Size',
            'position' => 1,
        ]);
        $small = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id,
            'label' => 'S',
            'position' => 1,
        ]);
        $large = ProductOptionValue::factory()->create([
            'option_id' => $sizeOption->id,
            'label' => 'L',
            'position' => 2,
        ]);

        ProductSku::factory()
            ->withOptionValues($small)
            ->create(['product_id' => $product->id, 'sku' => 'TST-S']);
        ProductSku::factory()
            ->withOptionValues($large)
            ->create(['product_id' => $product->id, 'sku' => 'TST-L']);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->baseUrl}/products/{$product->id}");

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonCount(1, 'data.options')
            ->assertJsonPath('data.options.0.name', 'Size')
            ->assertJsonCount(2, 'data.options.0.values')
            ->assertJsonCount(2, 'data.skus');

        $valueLabels = collect($response->json('data.options.0.values'))->pluck('label')->sort()->values();
        expect($valueLabels->all())->toBe(['L', 'S']);

        $skuLabels = collect($response->json('data.skus'))
            ->map(fn ($sku) => $sku['option_value1']['label'] ?? null)
            ->sort()
            ->values();
        expect($skuLabels->all())->toBe(['L', 'S']);
    });
});

// =============================================================================
// Update
// =============================================================================

describe('update', function () {
    it('updates the product name', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("{$this->baseUrl}/products/{$product->id}", [
                'name' => 'Updated Name',
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Updated Name');
    });

    it('syncs category_ids', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        $categories = Category::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("{$this->baseUrl}/products/{$product->id}", [
                'category_ids' => $categories->pluck('id')->toArray(),
            ]);

        $response->assertSuccessful();

        expect($product->fresh()->categories)->toHaveCount(2);
    });
});

// =============================================================================
// Destroy
// =============================================================================

describe('destroy', function () {
    it('soft deletes a product and cascades to variants', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        ProductSku::factory()->count(2)->withSequencedOption()->create(['product_id' => $product->id]);

        $response = $this->actingAs($this->user)
            ->deleteJson("{$this->baseUrl}/products/{$product->id}");

        $response->assertNoContent();

        expect(Product::find($product->id))->toBeNull()
            ->and(Product::withTrashed()->find($product->id))->not->toBeNull()
            ->and(ProductSku::where('product_id', $product->id)->count())->toBe(0)
            ->and(ProductSku::withTrashed()->where('product_id', $product->id)->count())->toBe(2);
    });
});

// =============================================================================
// Restore
// =============================================================================

describe('restore', function () {
    it('restores a product and its variants', function () {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'created_by_id' => $this->user->id,
            'brand_id' => $this->brand->id,
        ]);

        $variants = ProductSku::factory()->count(2)->withSequencedOption()->create(['product_id' => $product->id]);

        // Soft delete via service to cascade correctly
        $this->actingAs($this->user)
            ->deleteJson("{$this->baseUrl}/products/{$product->id}")
            ->assertNoContent();

        $response = $this->actingAs($this->user)
            ->postJson("{$this->baseUrl}/products/{$product->id}/restore");

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $product->id);

        expect(Product::find($product->id))->not->toBeNull()
            ->and(ProductSku::where('product_id', $product->id)->count())->toBe(2);
    });
});

// =============================================================================
// Authentication
// =============================================================================

it('returns 401 when not authenticated', function () {
    $this->getJson("{$this->baseUrl}/products")
        ->assertUnauthorized();
});

// =============================================================================
// Org Isolation
// =============================================================================

it('returns 403 when updating another org product', function () {
    $otherProduct = Product::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->baseUrl}/products/{$otherProduct->id}", ['name' => 'Hacked'])
        ->assertForbidden();
});

it('returns 403 when deleting another org product', function () {
    $otherProduct = Product::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
    ]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->baseUrl}/products/{$otherProduct->id}")
        ->assertForbidden();
});

// =============================================================================
// 404 — Non-existent & Soft-deleted
// =============================================================================

it('returns 404 for non-existent product', function () {
    $this->actingAs($this->user)
        ->getJson("{$this->baseUrl}/products/".fake()->uuid())
        ->assertNotFound();
});

it('returns 404 when showing a soft-deleted product', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
    ]);
    $product->delete();

    $this->actingAs($this->user)
        ->getJson("{$this->baseUrl}/products/{$product->id}")
        ->assertNotFound();
});

// =============================================================================
// Lookup
// =============================================================================

describe('lookup', function () {
    it('only returns active products belonging to the current brand', function () {
        $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);

        Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
            'status' => 'active',
        ]);

        // Different brand — must not appear
        Product::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $otherBrand->id,
            'status' => 'active',
        ]);

        // Draft in same brand — must not appear
        Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->baseUrl}/products/lookup");

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    });
});
