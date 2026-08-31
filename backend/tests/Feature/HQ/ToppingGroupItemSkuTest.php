<?php

/**
 * Plan 013 — ToppingGroupItemSkuTest
 *
 * Covers:
 *   Happy H7  — POST /skus with product_sku_id + extra_price → 201
 *   Happy     — PUT /skus/{sku} update price → 200
 *   Validation V6 — POST with product_sku_id=null when null row exists → 422
 *   Validation V7 — extra_price < 0 → 422
 *   Error ERR4 — POST with product_sku_id belonging to different product → 422
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
        'slug' => 'acme-tgis-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $toppingType = ProductType::factory()->create([
        'code' => 'topping',
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->product = Product::factory()->create([
        'product_type_id' => $toppingType->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->group = ToppingGroup::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->group->id,
        'product_id' => $this->product->id,
    ]);

    $this->base = "/api/v1/hq/{$this->brand->slug}/topping-groups/{$this->group->id}/items/{$this->item->id}/skus";
});

describe('happy path', function () {
    it('creates a sku price override with a product_sku_id and returns 201', function () {
        $sku = ProductSku::factory()->create(['product_id' => $this->product->id]);

        $response = $this->actingAs($this->user)->postJson($this->base, [
            'product_sku_id' => $sku->id,
            'extra_price' => 50,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.extra_price', '50.00');
    });

    it('updates the extra_price of an existing sku row', function () {
        $itemSku = ToppingGroupItemSku::factory()->noVariant()->create([
            'topping_group_item_id' => $this->item->id,
            'extra_price' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("{$this->base}/{$itemSku->id}", [
                'extra_price' => 120,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.extra_price', '120.00');
    });
});

describe('validation', function () {
    it('returns 422 when a null-sku row already exists for the item', function () {
        // Pre-existing null row
        ToppingGroupItemSku::factory()->noVariant()->create([
            'topping_group_item_id' => $this->item->id,
        ]);

        $this->actingAs($this->user)
            ->postJson($this->base, [
                'product_sku_id' => null,
                'extra_price' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_sku_id']);
    });

    it('allows negative extra_price (discount topping)', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'product_sku_id' => null,
                'extra_price' => -500,
            ])
            ->assertCreated()
            ->assertJsonPath('data.extra_price', '-500.00');
    });

    it('returns 422 when product_sku_id belongs to a different product', function () {
        $otherProduct = Product::factory()->create([
            'product_type_id' => $this->product->product_type_id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $foreignSku = ProductSku::factory()->create(['product_id' => $otherProduct->id]);

        $this->actingAs($this->user)
            ->postJson($this->base, [
                'product_sku_id' => $foreignSku->id,
                'extra_price' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_sku_id']);
    });
});
