<?php

/**
 * Issue #151 P1.2 — ToppingGroupItem service-layer guards
 *
 * Covers: brand isolation and duplicate-item invariants.
 * Note: product type restriction was removed — any product type may be added.
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ToppingGroup;
use App\Services\Topping\ToppingGroupItemService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->productType = ProductType::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->group = ToppingGroup::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->service = app(ToppingGroupItemService::class);
});

it('accepts any product regardless of type', function () {
    $product = Product::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
    ]);

    $item = $this->service->addItem($this->group, ['product_id' => $product->id]);

    expect($item->product_id)->toBe($product->id)
        ->and($item->topping_group_id)->toBe($this->group->id);
});

it('rejects a product from a different brand', function () {
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    $otherType = ProductType::factory()->create([
        'brand_id' => $otherBrand->id,
        'organization_id' => $this->orgId,
    ]);
    $product = Product::factory()->create([
        'brand_id' => $otherBrand->id,
        'organization_id' => $this->orgId,
        'product_type_id' => $otherType->id,
    ]);

    expect(fn () => $this->service->addItem($this->group, ['product_id' => $product->id]))
        ->toThrow(InvalidArgumentException::class, 'same brand');
});

// =============================================================================
// syncItems — full panel sync (add + reorder + remove) in one save
// =============================================================================

it('syncs the group to exactly the given products, in order', function () {
    $make = fn () => Product::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
    ]);
    $a = $make();
    $b = $make();
    $c = $make();

    // Seed: a, b already in the group.
    $this->service->addItem($this->group, ['product_id' => $a->id]);
    $this->service->addItem($this->group, ['product_id' => $b->id]);

    // Save the panel snapshot: drop b, add c, order = [c, a].
    $result = $this->service->syncItems($this->group, [$c->id, $a->id]);

    expect($result->pluck('product_id')->all())->toBe([$c->id, $a->id])
        ->and($result->pluck('sort_order')->all())->toBe([0, 1]);
});

it('removes every item when synced with an empty list', function () {
    $product = Product::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
    ]);
    $this->service->addItem($this->group, ['product_id' => $product->id]);

    $result = $this->service->syncItems($this->group, []);

    expect($result)->toHaveCount(0);
});

it('is idempotent when the product set is unchanged', function () {
    $a = Product::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'product_type_id' => $this->productType->id,
    ]);
    $this->service->addItem($this->group, ['product_id' => $a->id]);

    $first = $this->service->syncItems($this->group, [$a->id]);
    $second = $this->service->syncItems($this->group, [$a->id]);

    expect($second->pluck('product_id')->all())->toBe([$a->id])
        ->and($second->first()->id)->toBe($first->first()->id);
});
