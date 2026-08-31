<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use App\Omnify\Enums\ProductSkuInventoryModeEnum;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Commands\CreateProductSkuCommand;
use App\Services\Product\Commands\ReviseProductSkuCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\ValueObjects\ProductSkuPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $sharedOrgId = (string) Str::uuid();
    $this->organization = Organization::factory()->create([
        'id' => $sharedOrgId,
        'console_organization_id' => $sharedOrgId,
    ]);
    $this->orgId = $sharedOrgId;

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $sharedOrgId,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $sharedOrgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);

    $this->product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
        'brand_id' => $this->brand->id,
    ]);
});

// =============================================================================
// Authentication
// =============================================================================

describe('authentication', function () {
    it('returns 401 for unauthenticated requests on lookup endpoint', function () {
        $this->getJson("/api/v1/hq/{$this->brand->slug}/skus/lookup")
            ->assertUnauthorized();
    });

    it('returns 401 for unauthenticated requests on nested skus endpoint', function () {
        $this->getJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/skus")
            ->assertUnauthorized();
    });
});

// =============================================================================
// Index (nested under product)
// =============================================================================

describe('index (nested)', function () {
    it('returns a successful response for product SKUs', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'size',
            'position' => 1,
        ]);

        $values = collect(['s', 'm', 'l'])->map(fn ($v) => ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => $v,
        ]));

        $values->each(fn ($val) => ProductSku::factory()->withOptionValues($val)->create([
            'product_id' => $this->product->id,
        ]));

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/skus");

        $response->assertSuccessful()
            ->assertJsonStructure(['data']);
    });
});

// =============================================================================
// Store
// =============================================================================

describe('store', function () {
    it('creates a SKU with option values', function () {
        $colorOption = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $red = ProductOptionValue::factory()->create([
            'option_id' => $colorOption->id,
            'value' => 'red',
            'label' => 'Red',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/skus", [
                'option_value1_id' => $red->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.option_value1_id', $red->id);

        $sku = ProductSku::find($response->json('data.id'));
        expect($sku->option_signature)->toBe($red->id);
    });

    it('creates a default SKU without option values', function () {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/skus", [
                'name' => 'Default SKU',
            ]);

        $response->assertCreated();

        $sku = ProductSku::find($response->json('data.id'));
        expect($sku->option_signature)->toBe('');
    });

    it('rejects option value from wrong position', function () {
        $colorOption = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $red = ProductOptionValue::factory()->create([
            'option_id' => $colorOption->id,
            'value' => 'red',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/skus", [
                'option_value2_id' => $red->id,
            ]);

        $response->assertStatus(422);
    });

    it('rejects option value from different product', function () {
        $otherProduct = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $this->productType->id,
            'brand_id' => $this->brand->id,
        ]);

        $otherOption = ProductOption::factory()->create([
            'product_id' => $otherProduct->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $otherValue = ProductOptionValue::factory()->create([
            'option_id' => $otherOption->id,
            'value' => 'red',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/skus", [
                'option_value1_id' => $otherValue->id,
            ]);

        $response->assertStatus(422);
    });
});

// =============================================================================
// Show
// =============================================================================

describe('show', function () {
    it('shows a SKU with option value relations', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $red = ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'red',
            'label' => 'Red',
        ]);

        $sku = ProductSku::factory()->withOptionValues($red)->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/hq/{$this->brand->slug}/skus/{$sku->id}");

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $sku->id)
            ->assertJsonPath('data.option_value1_id', $red->id);
    });

    it('returns 404 for a SKU from another organization', function () {
        $otherOrg = Organization::factory()->create();
        $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrg->console_organization_id]);
        $otherProduct = Product::factory()->create([
            'organization_id' => $otherOrg->id,
            'brand_id' => $otherBrand->id,
        ]);
        $otherSku = ProductSku::factory()->create([
            'product_id' => $otherProduct->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/hq/{$this->brand->slug}/skus/{$otherSku->id}");

        $response->assertNotFound();
    });

    it('returns 404 for a non-existent SKU', function () {
        $fakeId = (string) Str::uuid();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/hq/{$this->brand->slug}/skus/{$fakeId}");

        $response->assertNotFound();
    });
});

// =============================================================================
// Update
// =============================================================================

describe('update', function () {
    it('updates SKU name', function () {
        $sku = ProductSku::factory()->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/hq/{$this->brand->slug}/skus/{$sku->id}", [
                'name' => 'Updated SKU Name',
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Updated SKU Name');
    });

    it('ignores option_value updates because the combination is immutable', function () {
        // Per ProductSkuUpdateRequest, option_value{N}_id is intentionally
        // stripped from PUT updates so the (product_id, option_signature)
        // unique invariant is preserved. Clients must delete + recreate the
        // SKU to change its option combination.
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);

        $red = ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'red',
        ]);

        $blue = ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'blue',
        ]);

        $sku = ProductSku::factory()->withOptionValues($red)->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/hq/{$this->brand->slug}/skus/{$sku->id}", [
                'option_value1_id' => $blue->id,
                'name' => 'Updated Name',
            ]);

        $response->assertSuccessful();

        $sku->refresh();
        // The name change went through, but option_value1_id stayed at red.
        expect($sku->name)->toBe('Updated Name')
            ->and($sku->option_value1_id)->toBe($red->id)
            ->and($sku->option_signature)->toBe($red->id);
    });

    it('returns 404 when updating a SKU from another organization', function () {
        $otherOrg = Organization::factory()->create();
        $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrg->console_organization_id]);
        $otherProduct = Product::factory()->create([
            'organization_id' => $otherOrg->id,
            'brand_id' => $otherBrand->id,
        ]);
        $otherSku = ProductSku::factory()->create([
            'product_id' => $otherProduct->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/hq/{$this->brand->slug}/skus/{$otherSku->id}", [
                'name' => 'Hacked',
            ]);

        $response->assertNotFound();
    });
});

// =============================================================================
// Destroy
// =============================================================================

describe('destroy', function () {
    it('soft deletes a SKU', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);
        $v1 = ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 'red']);
        $v2 = ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 'blue']);

        ProductSku::factory()->withOptionValues($v1)->create([
            'product_id' => $this->product->id,
        ]);
        $sku = ProductSku::factory()->withOptionValues($v2)->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/hq/{$this->brand->slug}/skus/{$sku->id}");

        $response->assertNoContent();

        expect(ProductSku::find($sku->id))->toBeNull()
            ->and(ProductSku::withTrashed()->find($sku->id))->not->toBeNull();
    });

    it('blocks deleting the last SKU of a product', function () {
        $sku = ProductSku::factory()->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/hq/{$this->brand->slug}/skus/{$sku->id}");

        $response->assertStatus(422);
    });

    it('returns 409 with blocking_menus when SKU is referenced by a menu', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id, 'key' => 'color', 'position' => 1,
        ]);
        $red = ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 'red']);
        $blue = ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 'blue']);

        $sku1 = ProductSku::factory()->withOptionValues($red)->create(['product_id' => $this->product->id]);
        ProductSku::factory()->withOptionValues($blue)->create(['product_id' => $this->product->id]);

        $branch = Branch::factory()->create(['console_organization_id' => $this->orgId]);
        $menu = Menu::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $branch->id,
            'is_master' => false,
        ]);
        $menuProduct = MenuProduct::factory()->create([
            'menu_id' => $menu->id,
            'product_id' => $this->product->id,
        ]);
        MenuProductSku::factory()->create([
            'menu_product_id' => $menuProduct->id,
            'product_sku_id' => $sku1->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/hq/{$this->brand->slug}/skus/{$sku1->id}");

        $response->assertStatus(409)
            ->assertJsonPath('error', 'SKU_IN_MENU')
            ->assertJsonCount(1, 'blocking_menus')
            ->assertJsonPath('blocking_menus.0.name', $menu->name);

        // SKU must still exist
        expect(ProductSku::find($sku1->id))->not->toBeNull();
    });

    it('allows delete when SKU has no menu reference', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id, 'key' => 'size', 'position' => 1,
        ]);
        $small = ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 'small']);
        $large = ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 'large']);

        ProductSku::factory()->withOptionValues($small)->create(['product_id' => $this->product->id]);
        $sku2 = ProductSku::factory()->withOptionValues($large)->create(['product_id' => $this->product->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/hq/{$this->brand->slug}/skus/{$sku2->id}")
            ->assertNoContent();

        expect(ProductSku::withTrashed()->find($sku2->id)?->deleted_at)->not->toBeNull();
    });

    it('returns 404 when deleting a SKU from another organization', function () {
        $otherOrg = Organization::factory()->create();
        $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrg->console_organization_id]);
        $otherProduct = Product::factory()->create([
            'organization_id' => $otherOrg->id,
            'brand_id' => $otherBrand->id,
        ]);
        $otherSku = ProductSku::factory()->create([
            'product_id' => $otherProduct->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/hq/{$this->brand->slug}/skus/{$otherSku->id}");

        $response->assertNotFound();
    });
});

// =============================================================================
// Restore
// =============================================================================

describe('restore', function () {
    it('restores a soft-deleted SKU', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'size',
            'position' => 1,
        ]);
        $v1 = ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 'small']);
        $v2 = ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 'large']);

        ProductSku::factory()->withOptionValues($v1)->create([
            'product_id' => $this->product->id,
        ]);
        $sku = ProductSku::factory()->withOptionValues($v2)->create([
            'product_id' => $this->product->id,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/hq/{$this->brand->slug}/skus/{$sku->id}")
            ->assertNoContent();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/skus/{$sku->id}/restore");

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $sku->id);

        expect(ProductSku::find($sku->id))->not->toBeNull();
    });
});

// =============================================================================
// Toggle Status
// =============================================================================

describe('toggleStatus', function () {
    it('toggles the is_active flag', function () {
        $sku = ProductSku::factory()->inactive()->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/skus/{$sku->id}/toggle-status");

        $response->assertSuccessful();

        expect($sku->fresh()->is_active)->toBeTrue();
    });
});

// =============================================================================
// Lookup
// =============================================================================

describe('lookup', function () {
    it('returns active SKUs for the organization', function () {
        $option = ProductOption::factory()->create([
            'product_id' => $this->product->id,
            'key' => 'color',
            'position' => 1,
        ]);
        $v1 = ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 'red']);
        $v2 = ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 'blue']);

        ProductSku::factory()->withOptionValues($v1)->create([
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);
        ProductSku::factory()->withOptionValues($v2)->inactive()->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/hq/{$this->brand->slug}/skus/lookup");

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    });
});

// =============================================================================
// Check Usage
// =============================================================================

describe('checkUsage', function () {
    it('returns usage data for a SKU', function () {
        $sku = ProductSku::factory()->create([
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/hq/{$this->brand->slug}/skus/{$sku->id}/check-usage");

        $response->assertSuccessful()
            ->assertJsonStructure(['data']);
    });
});

it('preserves exact decimal prices through typed SKU create and revise', function () {
    $created = $this->actingAs($this->user)->postJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/skus", [
        'name' => 'Decimal SKU',
        'selling_price' => '1234.50',
        'cost_price' => '12.34',
        'cost_price_auto' => '11.11',
        'is_cost_override' => true,
    ])->assertCreated();

    $sku = ProductSku::findOrFail($created->json('data.id'));
    expect($sku->selling_price)->toBe('1234.50')->and($sku->cost_price)->toBe('12.34');

    $this->putJson("/api/v1/hq/{$this->brand->slug}/skus/{$sku->id}", ['selling_price' => '987.65'])->assertOk();
    expect($sku->fresh()->selling_price)->toBe('987.65');
});

it('clears an explicitly emptied SKU locale without touching other locales', function () {
    $sku = ProductSku::factory()->create(['product_id' => $this->product->id, 'name' => 'Base']);
    DB::table('product_sku_translations')->updateOrInsert(
        ['product_sku_id' => $sku->id, 'locale' => 'en'],
        ['name' => 'English'],
    );
    DB::table('product_sku_translations')->updateOrInsert(
        ['product_sku_id' => $sku->id, 'locale' => 'vi'],
        ['name' => 'Tiếng Việt'],
    );

    $this->actingAs($this->user)
        ->putJson("/api/v1/hq/{$this->brand->slug}/skus/{$sku->id}", [
            'en' => ['name' => ''],
        ])
        ->assertOk();

    expect($sku->translations()->where('locale', 'en')->exists())->toBeFalse()
        ->and($sku->translations()->where('locale', 'vi')->value('name'))->toBe('Tiếng Việt');
});

it('persists the command SKU ID and treats an identical command replay as unchanged', function () {
    $skuId = (string) Str::uuid();
    $payload = new ProductSkuPayload(
        $skuId,
        'COMMAND-SKU',
        '123.45',
        [null, null, null],
        true,
        'Command SKU',
        null,
        '1',
        '12.34',
        '0',
        true,
        ProductSkuInventoryModeEnum::MadeToOrder,
    );
    $command = new CreateProductSkuCommand(
        new MutationContext($this->orgId, $this->user->id, (string) Str::uuid(), 'sku-command-replay'),
        $this->product->id,
        $this->brand->id,
        $payload,
        $payload->fingerprint(),
    );
    $mutations = app(ProductMutationFacade::class);

    $first = $mutations->createSku($command);
    $replay = $mutations->createSku($command);

    expect($first->aggregateId)->toBe($skuId)
        ->and($first->changed)->toBeTrue()
        ->and($replay->aggregateId)->toBe($skuId)
        ->and($replay->changed)->toBeFalse()
        ->and(ProductSku::whereKey($skuId)->count())->toBe(1);
});

it('rejects changing option-value IDs through the typed revise command', function () {
    $option = ProductOption::factory()->create([
        'product_id' => $this->product->id,
        'key' => 'color',
        'position' => 1,
    ]);
    $red = ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 'red']);
    $blue = ProductOptionValue::factory()->create(['option_id' => $option->id, 'value' => 'blue']);
    $sku = ProductSku::factory()->withOptionValues($red)->create(['product_id' => $this->product->id]);
    $payload = new ProductSkuPayload(
        $sku->id,
        $sku->sku,
        $sku->selling_price,
        [$blue->id, null, null],
        (bool) $sku->is_active,
        $sku->name,
        $sku->recipe_id,
        $sku->recipe_multiplier,
        $sku->cost_price,
        $sku->cost_price_auto,
        (bool) $sku->is_cost_override,
        $sku->inventory_mode ?? ProductSkuInventoryModeEnum::MadeToOrder,
    );
    $command = new ReviseProductSkuCommand(
        new MutationContext($this->orgId, $this->user->id, (string) Str::uuid(), 'sku-immutable-options', 1),
        $this->brand->id,
        $payload,
        $payload->fingerprint(),
    );

    expect(fn () => app(ProductMutationFacade::class)->reviseSku($command))
        ->toThrow(ValidationException::class, 'option combination is immutable');
    expect($sku->fresh()->option_value1_id)->toBe($red->id);
});

it('does not expose or mutate sibling-brand SKUs through another brand route', function () {
    $this->actingAs($this->user);
    $sibling = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $siblingType = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $sibling->id]);
    $siblingProduct = Product::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $sibling->id, 'product_type_id' => $siblingType->id]);
    $sku = ProductSku::factory()->create(['product_id' => $siblingProduct->id, 'name' => 'Sibling', 'is_active' => true]);

    $base = "/api/v1/hq/{$this->brand->slug}/skus/{$sku->id}";
    $this->getJson($base)->assertNotFound();
    $this->putJson($base, ['name' => 'Hacked'])->assertNotFound();
    $this->postJson("{$base}/toggle-status")->assertNotFound();
    $this->getJson("{$base}/check-usage")->assertNotFound();
    $this->deleteJson($base)->assertNotFound();
    expect($sku->fresh()->name)->toBe('Sibling')->and($sku->is_active)->toBeTrue()->and($sku->deleted_at)->toBeNull();

    $sku->delete();
    $this->postJson("{$base}/restore")->assertNotFound();
    expect($sku->fresh()->trashed())->toBeTrue();
});
