<?php

/**
 * Plan 013 — ToppingGroupItemTest
 *
 * Covers:
 *   Happy H6  — POST /items with any product → 201 + auto-creates ToppingGroupItemSku
 *   Validation V4 — product from different brand → 422/404
 *   Validation V5 — same product already in group → 422 (duplicate)
 *   Side effect SE1 — DELETE /items/{item} → all ToppingGroupItemSku rows hard-deleted
 *   Side effect SE2 — simple topping → exactly 1 ToppingGroupItemSku created
 *   Edge E4 — DELETE /items/{item} → item soft-deleted (withTrashed finds it)
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
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
        'slug' => 'acme-tgi-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->toppingType = ProductType::factory()->create([
        'code' => 'topping',
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->group = ToppingGroup::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->base = "/api/v1/hq/{$this->brand->slug}/topping-groups/{$this->group->id}/items";
});

describe('happy path', function () {
    it('adds a topping product and returns 201', function () {
        $product = Product::factory()->create([
            'product_type_id' => $this->toppingType->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);

        $response = $this->actingAs($this->user)->postJson($this->base, [
            'product_id' => $product->id,
        ]);

        $response->assertCreated();
    });
});

describe('validation', function () {
    it('returns 422 when the product belongs to a different brand', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'is_active' => true,
        ]);
        $otherType = ProductType::factory()->create([
            'code' => 'topping',
            'brand_id' => $otherBrand->id,
            'organization_id' => $otherOrgId,
        ]);
        $otherProduct = Product::factory()->create([
            'product_type_id' => $otherType->id,
            'brand_id' => $otherBrand->id,
            'organization_id' => $otherOrgId,
        ]);

        $this->actingAs($this->user)
            ->postJson($this->base, ['product_id' => $otherProduct->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id']);
    });

    it('returns 422 when the product is already in the group', function () {
        $product = Product::factory()->create([
            'product_type_id' => $this->toppingType->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);

        ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => $product->id,
        ]);

        $this->actingAs($this->user)
            ->postJson($this->base, ['product_id' => $product->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id']);
    });
});

describe('side effects', function () {
    it('auto-creates exactly one ToppingGroupItemSku for a simple topping with no variant skus', function () {
        $product = Product::factory()->create([
            'product_type_id' => $this->toppingType->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);

        // Ensure the product SKU has no variant (option_value1_id = null)
        $sku = ProductSku::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($this->user)->postJson($this->base, [
            'product_id' => $product->id,
        ]);

        $response->assertCreated();
        $itemId = $response->json('data.id');

        $skus = ToppingGroupItemSku::where('topping_group_item_id', $itemId)->get();
        expect($skus)->toHaveCount(1)
            // #1708: a simple (non-variant) topping now binds the product's own
            // SKU instead of a NULL wildcard, so the POS picker can resolve a
            // product_sku_id and the order-write path accepts it.
            ->and((string) $skus->first()->product_sku_id)->toBe((string) $sku->id)
            ->and($skus->first()->extra_price)->toBe('0.00');
    });

    it('hard-deletes all ToppingGroupItemSku rows when item is soft-deleted', function () {
        $product = Product::factory()->create([
            'product_type_id' => $this->toppingType->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);

        $item = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => $product->id,
        ]);

        ToppingGroupItemSku::factory()->create([
            'topping_group_item_id' => $item->id,
            'product_sku_id' => null,
            'extra_price' => 0,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$item->id}")
            ->assertNoContent();

        // SKU rows must be hard-deleted (no withTrashed — table has no deleted_at)
        expect(ToppingGroupItemSku::where('topping_group_item_id', $item->id)->count())->toBe(0);
    });
});

describe('edge cases', function () {
    it('soft-deletes the item — withTrashed still finds it', function () {
        $product = Product::factory()->create([
            'product_type_id' => $this->toppingType->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);

        $item = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => $product->id,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$item->id}")
            ->assertNoContent();

        $found = ToppingGroupItem::withTrashed()->find($item->id);
        expect($found)->not->toBeNull()
            ->and($found->deleted_at)->not->toBeNull();
    });

    it('re-adds a previously deleted product without a unique-constraint error', function () {
        $product = Product::factory()->create([
            'product_type_id' => $this->toppingType->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);

        // Add → delete → re-add
        $addResponse = $this->actingAs($this->user)
            ->postJson($this->base, ['product_id' => $product->id]);
        $addResponse->assertCreated();

        $itemId = $addResponse->json('data.id');

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$itemId}")
            ->assertNoContent();

        $this->actingAs($this->user)
            ->postJson($this->base, ['product_id' => $product->id])
            ->assertCreated();

        // Restored item must have its sku recreated
        $newItemId = ToppingGroupItem::where('topping_group_id', $this->group->id)
            ->where('product_id', $product->id)
            ->value('id');

        expect(ToppingGroupItemSku::where('topping_group_item_id', $newItemId)->count())->toBe(1);
    });
});
