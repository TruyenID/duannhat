<?php

/**
 * Plan 013 — ProductToppingGroupItemOverrideTest (Phase 10E)
 *
 * Covers TESTS.md Phase 2 Extensions — Overrides scenarios:
 *   Happy path (5), Validation (5), Edge (2)
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroup;
use App\Models\ProductToppingGroupItemOverride;
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
        'slug' => 'acme-ptgio-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $productType = ProductType::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->product = Product::factory()->create([
        'product_type_id' => $productType->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->group = ToppingGroup::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    // Attach group to product so override endpoints work
    ProductToppingGroup::create([
        'product_id' => $this->product->id,
        'topping_group_id' => $this->group->id,
    ]);

    $this->base = "/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/topping-groups/{$this->group->id}/overrides";

    // Helper: create a topping product scoped to this brand for use as group items.
    // ToppingGroupItem has UNIQUE(topping_group_id, product_id) so each item needs
    // its own product unless you explicitly want to test the duplicate constraint.
    $this->makeItemProduct = fn () => Product::factory()->create([
        'product_type_id' => ProductType::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ])->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
});

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

describe('happy path', function () {
    it('syncs a price override and GET returns the correct row', function () {
        $item = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => $this->product->id,
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/sync", [
                'overrides' => [[
                    'topping_group_item_id' => $item->id,
                    'product_sku_id' => null,
                    'is_hidden' => false,
                    'override_price' => 20000,
                ]],
            ])
            ->assertOk();

        $response = $this->actingAs($this->user)->getJson($this->base);
        $response->assertOk();
        $rows = $response->json('data');
        expect($rows)->toHaveCount(1);
        expect($rows[0]['topping_group_item_id'])->toBe($item->id);
        expect((float) $rows[0]['override_price'])->toBe(20000.0);
        expect($rows[0]['is_hidden'])->toBeFalse();
    });

    it('syncing with empty array removes all existing overrides', function () {
        $items = collect(range(1, 3))->map(fn () => ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => ($this->makeItemProduct)()->id,
        ]));

        $items->each(fn ($item, $i) => ProductToppingGroupItemOverride::create([
            'product_id' => $this->product->id,
            'topping_group_id' => $this->group->id,
            'topping_group_item_id' => $item->id,
            'product_sku_id' => null,
            'is_hidden' => false,
            'override_price' => 1000 * ($i + 1),
        ]));

        $this->actingAs($this->user)
            ->putJson("{$this->base}/sync", ['overrides' => []])
            ->assertOk()
            ->assertJsonPath('data', []);

        expect(ProductToppingGroupItemOverride::where('product_id', $this->product->id)
            ->where('topping_group_id', $this->group->id)->count())->toBe(0);
    });

    it('marks an item as hidden while others remain visible (blacklist model)', function () {
        $itemA = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => ($this->makeItemProduct)()->id,
        ]);
        $itemB = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => ($this->makeItemProduct)()->id,
        ]);
        $itemC = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => ($this->makeItemProduct)()->id,
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/sync", [
                'overrides' => [[
                    'topping_group_item_id' => $itemC->id,
                    'product_sku_id' => null,
                    'is_hidden' => true,
                    'override_price' => null,
                ]],
            ])
            ->assertOk();

        expect(ProductToppingGroupItemOverride::where('product_id', $this->product->id)
            ->where('topping_group_id', $this->group->id)->count())->toBe(1);
        expect(ProductToppingGroupItemOverride::where('topping_group_item_id', $itemC->id)
            ->where('is_hidden', true)->exists())->toBeTrue();
        // A and B have no override rows → visible
        expect(ProductToppingGroupItemOverride::where('topping_group_item_id', $itemA->id)->exists())->toBeFalse();
        expect(ProductToppingGroupItemOverride::where('topping_group_item_id', $itemB->id)->exists())->toBeFalse();
    });

    it('clearing overrides restores visibility of all items', function () {
        $itemA = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => ($this->makeItemProduct)()->id,
        ]);
        $itemB = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => ($this->makeItemProduct)()->id,
        ]);

        ProductToppingGroupItemOverride::create([
            'product_id' => $this->product->id,
            'topping_group_id' => $this->group->id,
            'topping_group_item_id' => $itemA->id,
            'is_hidden' => true,
            'override_price' => null,
        ]);
        ProductToppingGroupItemOverride::create([
            'product_id' => $this->product->id,
            'topping_group_id' => $this->group->id,
            'topping_group_item_id' => $itemB->id,
            'is_hidden' => true,
            'override_price' => null,
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/sync", ['overrides' => []])
            ->assertOk()
            ->assertJsonPath('data', []);

        expect(ProductToppingGroupItemOverride::where('product_id', $this->product->id)->count())->toBe(0);
    });

    it('stores a variant-SKU price override that differs from the group default', function () {
        $itemProduct = Product::factory()->create([
            'product_type_id' => ProductType::factory()->create([
                'brand_id' => $this->brand->id,
                'organization_id' => $this->orgId,
            ])->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $sku = ProductSku::factory()->create(['product_id' => $itemProduct->id]);

        $item = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => $itemProduct->id,
        ]);

        ToppingGroupItemSku::create([
            'topping_group_item_id' => $item->id,
            'product_sku_id' => $sku->id,
            'extra_price' => 5000,
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/sync", [
                'overrides' => [[
                    'topping_group_item_id' => $item->id,
                    'product_sku_id' => $sku->id,
                    'is_hidden' => false,
                    'override_price' => 9000,
                ]],
            ])
            ->assertOk();

        $row = ProductToppingGroupItemOverride::where('product_id', $this->product->id)
            ->where('topping_group_item_id', $item->id)
            ->where('product_sku_id', $sku->id)
            ->first();

        expect($row)->not->toBeNull();
        expect((float) $row->override_price)->toBe(9000.0);
        // Differs from group default (5000)
        expect((float) $row->override_price)->not->toBe(5000.0);
    });
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

describe('validation', function () {
    it('returns 422 when topping_group_item_id does not belong to the group', function () {
        $otherGroup = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $foreignItem = ToppingGroupItem::factory()->create([
            'topping_group_id' => $otherGroup->id,
            'product_id' => $this->product->id,
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/sync", [
                'overrides' => [[
                    'topping_group_item_id' => $foreignItem->id,
                    'product_sku_id' => null,
                    'is_hidden' => false,
                    'override_price' => null,
                ]],
            ])
            ->assertUnprocessable();
    });

    it('returns 422 when product_sku_id belongs to a different product than the item', function () {
        $itemProduct = Product::factory()->create([
            'product_type_id' => ProductType::factory()->create([
                'brand_id' => $this->brand->id,
                'organization_id' => $this->orgId,
            ])->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $item = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => $itemProduct->id,
        ]);

        $otherProduct = Product::factory()->create([
            'product_type_id' => ProductType::factory()->create([
                'brand_id' => $this->brand->id,
                'organization_id' => $this->orgId,
            ])->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $foreignSku = ProductSku::factory()->create(['product_id' => $otherProduct->id]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/sync", [
                'overrides' => [[
                    'topping_group_item_id' => $item->id,
                    'product_sku_id' => $foreignSku->id,
                    'is_hidden' => false,
                    'override_price' => null,
                ]],
            ])
            ->assertUnprocessable();
    });

    it('returns 422 when the product is not attached to the topping group', function () {
        $unattachedGroup = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $item = ToppingGroupItem::factory()->create([
            'topping_group_id' => $unattachedGroup->id,
            'product_id' => $this->product->id,
        ]);

        $url = "/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/topping-groups/{$unattachedGroup->id}/overrides";

        $this->actingAs($this->user)
            ->putJson("{$url}/sync", [
                'overrides' => [[
                    'topping_group_item_id' => $item->id,
                    'product_sku_id' => null,
                    'is_hidden' => false,
                    'override_price' => null,
                ]],
            ])
            ->assertUnprocessable();
    });

    it('returns 422 when is_hidden is true and override_price is non-null', function () {
        $item = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => $this->product->id,
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/sync", [
                'overrides' => [[
                    'topping_group_item_id' => $item->id,
                    'product_sku_id' => null,
                    'is_hidden' => true,
                    'override_price' => 5000,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['overrides.0.override_price']);
    });

    it('returns 422 for duplicate (topping_group_item_id, product_sku_id) in the payload', function () {
        $item = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => $this->product->id,
        ]);

        $this->actingAs($this->user)
            ->putJson("{$this->base}/sync", [
                'overrides' => [
                    [
                        'topping_group_item_id' => $item->id,
                        'product_sku_id' => null,
                        'is_hidden' => false,
                        'override_price' => 1000,
                    ],
                    [
                        'topping_group_item_id' => $item->id,
                        'product_sku_id' => null,
                        'is_hidden' => false,
                        'override_price' => 2000,
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['overrides.1.topping_group_item_id']);
    });
});

// ---------------------------------------------------------------------------
// Edge cases
// ---------------------------------------------------------------------------

describe('edge cases', function () {
    it('allows hiding all items in a group (empty visible set is valid)', function () {
        $items = collect(range(1, 3))->map(fn () => ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => ($this->makeItemProduct)()->id,
        ]));

        $overrides = $items->map(fn ($item) => [
            'topping_group_item_id' => $item->id,
            'is_hidden' => true,
            'override_price' => null,
        ])->values()->all();

        $this->actingAs($this->user)
            ->putJson("{$this->base}/sync", ['overrides' => $overrides])
            ->assertOk();

        expect(ProductToppingGroupItemOverride::where('product_id', $this->product->id)
            ->where('is_hidden', true)->count())->toBe(3);
    });

    it('preserves override rows (orphan rows) when the group is detached and re-attached', function () {
        $item = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->group->id,
            'product_id' => $this->product->id,
        ]);

        ProductToppingGroupItemOverride::create([
            'product_id' => $this->product->id,
            'topping_group_id' => $this->group->id,
            'topping_group_item_id' => $item->id,
            'product_sku_id' => null,
            'is_hidden' => false,
            'override_price' => 7000,
        ]);

        // Detach group
        $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/topping-groups/sync", [
                'topping_group_ids' => [],
            ])
            ->assertOk();

        // Override row preserved (orphan)
        expect(ProductToppingGroupItemOverride::where('product_id', $this->product->id)
            ->where('topping_group_id', $this->group->id)->count())->toBe(1);

        // Re-attach
        $this->actingAs($this->user)
            ->postJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/topping-groups/sync", [
                'topping_group_ids' => [$this->group->id],
            ])
            ->assertOk();

        $row = ProductToppingGroupItemOverride::where('product_id', $this->product->id)
            ->where('topping_group_id', $this->group->id)
            ->first();

        expect($row)->not->toBeNull();
        expect((float) $row->override_price)->toBe(7000.0);
    });
});
