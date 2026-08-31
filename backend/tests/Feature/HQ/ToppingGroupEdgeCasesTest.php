<?php

/**
 * Topping management — edge cases that the happy-path P-tier tests miss.
 * ============================================================================
 *
 * Each describe block covers ONE invariant the system needs to enforce but
 * isn't obvious from the schema alone. If a future change breaks one of
 * these, the customer-facing UX or the kitchen-ticket UX silently degrades —
 * we want fast loud failures here, not hand-debugging at 11pm on a Friday.
 *
 * Reference: see `docs/contributing/topping-ux-design.md` for the why behind
 * each invariant from a 30-year restaurant-manager perspective.
 *
 * ============================================================================
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroup;
use App\Models\ProductType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Models\User;
use App\Services\Topping\ProductToppingGroupService;
use App\Services\Topping\ToppingGroupItemService;
use App\Services\Topping\ToppingGroupItemSkuService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'edge-'.Str::random(4),
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

    $this->foodType = ProductType::factory()->create([
        'code' => 'food',
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->itemService = app(ToppingGroupItemService::class);
    $this->skuService = app(ToppingGroupItemSkuService::class);
    $this->ptgService = app(ProductToppingGroupService::class);
});

function makeToppingProduct(string $name, ProductType $type, Brand $brand, string $orgId): Product
{
    $product = Product::factory()->create([
        'brand_id' => $brand->id,
        'organization_id' => $orgId,
        'product_type_id' => $type->id,
        'name' => $name,
    ]);
    ProductSku::factory()->create(['product_id' => $product->id]);

    return $product;
}

// =============================================================================
// Edge case A — REMOVE modifier ⇒ extra_price MUST be 0
// =============================================================================

describe('REMOVE modifier pricing invariant', function () {
    it('rejects non-zero extra_price on a SKU when the parent group is modifier_type=remove', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'modifier_type' => 'remove',
        ]);
        $product = makeToppingProduct('Hành lá', $this->toppingType, $this->brand, $this->orgId);
        $item = ToppingGroupItem::create([
            'topping_group_id' => $group->id,
            'product_id' => $product->id,
        ]);

        expect(fn () => $this->skuService->create($item, [
            'product_sku_id' => null,
            'extra_price' => 5000,    // ❌ charging for absence
        ]))->toThrow(InvalidArgumentException::class, 'remove-modifier');
    });

    it('accepts extra_price=0 on a REMOVE group SKU (the legitimate case)', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'modifier_type' => 'remove',
        ]);
        $product = makeToppingProduct('Hành lá', $this->toppingType, $this->brand, $this->orgId);
        $item = ToppingGroupItem::create([
            'topping_group_id' => $group->id,
            'product_id' => $product->id,
        ]);

        $sku = $this->skuService->create($item, [
            'product_sku_id' => null,
            'extra_price' => 0,
        ]);
        expect((float) $sku->extra_price)->toEqual(0);
    });

    it('rejects updating an existing SKU to non-zero extra_price after the group flipped to remove', function () {
        // Realistic scenario: admin starts with an ADD group at extra_price=5000,
        // later flips the group to REMOVE for some reason. They try to bump the
        // price further — the guard catches them.
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'modifier_type' => 'remove',
        ]);
        $product = makeToppingProduct('Rau mùi', $this->toppingType, $this->brand, $this->orgId);
        $item = ToppingGroupItem::create([
            'topping_group_id' => $group->id,
            'product_id' => $product->id,
        ]);
        $sku = ToppingGroupItemSku::create([
            'topping_group_item_id' => $item->id,
            'extra_price' => 0,
        ]);

        expect(fn () => $this->skuService->update($sku, ['extra_price' => 1000]))
            ->toThrow(InvalidArgumentException::class, 'remove-modifier');
    });

    it('does NOT block non-zero extra_price on an ADD group (regression)', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'modifier_type' => 'add',
        ]);
        $product = makeToppingProduct('Phô mai', $this->toppingType, $this->brand, $this->orgId);
        $item = ToppingGroupItem::create([
            'topping_group_id' => $group->id,
            'product_id' => $product->id,
        ]);

        $sku = $this->skuService->create($item, [
            'product_sku_id' => null,
            'extra_price' => 15000,
        ]);
        expect((float) $sku->extra_price)->toEqual(15000);
    });
});

// =============================================================================
// Edge case B — single-select group ⇒ at most ONE is_default=true item
// =============================================================================

describe('single-select default uniqueness', function () {
    it('accepts the first is_default=true on a single-select group', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'selection_type' => 'single',
            'min_select' => 1,
            'max_select' => 1,
        ]);
        $product = makeToppingProduct('Có đá', $this->toppingType, $this->brand, $this->orgId);

        $item = $this->itemService->addItem($group, [
            'product_id' => $product->id,
            'is_default' => true,
        ]);
        expect((bool) $item->is_default)->toBeTrue();
    });

    it('rejects a SECOND is_default=true on the same single-select group', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'selection_type' => 'single',
            'min_select' => 1,
            'max_select' => 1,
        ]);
        $first = makeToppingProduct('Có đá', $this->toppingType, $this->brand, $this->orgId);
        $second = makeToppingProduct('Không đá', $this->toppingType, $this->brand, $this->orgId);

        $this->itemService->addItem($group, ['product_id' => $first->id, 'is_default' => true]);

        expect(fn () => $this->itemService->addItem($group, ['product_id' => $second->id, 'is_default' => true]))
            ->toThrow(InvalidArgumentException::class, 'at most one is_default');
    });

    it('allows multiple is_default=true items on a MULTIPLE-select group', function () {
        // Multi-select default = "comes with X and Y by default, customer can
        // untick either". E.g., burger comes with cheese + pickles by default.
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'selection_type' => 'multiple',
            'min_select' => 0,
            'max_select' => 5,
        ]);
        $cheese = makeToppingProduct('Phô mai', $this->toppingType, $this->brand, $this->orgId);
        $pickles = makeToppingProduct('Dưa chua', $this->toppingType, $this->brand, $this->orgId);

        $this->itemService->addItem($group, ['product_id' => $cheese->id, 'is_default' => true]);
        $this->itemService->addItem($group, ['product_id' => $pickles->id, 'is_default' => true]);

        $defaultCount = ToppingGroupItem::where('topping_group_id', $group->id)
            ->where('is_default', true)->count();
        expect($defaultCount)->toBe(2);
    });
});

// =============================================================================
// Edge case C — min_select_override > group.max_select
// =============================================================================

describe('override bounds vs group bounds', function () {
    it('rejects min_select_override that exceeds the group max_select', function () {
        // Group default 0..3, attachment overrides min=5 → impossible (group
        // doesn't allow more than 3 selections so customer can never satisfy).
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'min_select' => 0,
            'max_select' => 3,
        ]);
        $product = Product::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'product_type_id' => $this->foodType->id,
        ]);

        expect(fn () => $this->ptgService->syncForProduct(
            $product,
            [$group->id],
            [],
            [$group->id => ['min' => 5, 'max' => null]],
        ))->toThrow(InvalidArgumentException::class, 'min_select_override=5');
    });

    it('rejects max_select_override that drops below the group min_select', function () {
        // Group default min=2, attachment overrides max=1 → impossible (must
        // pick 2 but only 1 allowed).
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'min_select' => 2,
            'max_select' => 5,
        ]);
        $product = Product::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'product_type_id' => $this->foodType->id,
        ]);

        expect(fn () => $this->ptgService->syncForProduct(
            $product,
            [$group->id],
            [],
            [$group->id => ['min' => null, 'max' => 1]],
        ))->toThrow(InvalidArgumentException::class, 'max_select_override=1');
    });

    it('rejects min_select_override > max_select_override on same attachment', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'min_select' => 0,
            'max_select' => 5,
        ]);
        $product = Product::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'product_type_id' => $this->foodType->id,
        ]);

        expect(fn () => $this->ptgService->syncForProduct(
            $product,
            [$group->id],
            [],
            [$group->id => ['min' => 3, 'max' => 2]],
        ))->toThrow(InvalidArgumentException::class, 'cannot exceed');
    });

    it('accepts valid overrides within the group bounds', function () {
        // Group 0..5, override 1..2 — both within bounds.
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'min_select' => 0,
            'max_select' => 5,
        ]);
        $product = Product::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'product_type_id' => $this->foodType->id,
        ]);

        $this->ptgService->syncForProduct(
            $product,
            [$group->id],
            [],
            [$group->id => ['min' => 1, 'max' => 2]],
        );

        $row = ProductToppingGroup::where('product_id', $product->id)->first();
        expect($row->min_select_override)->toBe(1)
            ->and($row->max_select_override)->toBe(2);
    });

    it('accepts NULL overrides (inherit group default — the common case)', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'min_select' => 0,
            'max_select' => 3,
        ]);
        $product = Product::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'product_type_id' => $this->foodType->id,
        ]);

        $this->ptgService->syncForProduct($product, [$group->id]);  // no overrides

        $row = ProductToppingGroup::where('product_id', $product->id)->first();
        expect($row->min_select_override)->toBeNull()
            ->and($row->max_select_override)->toBeNull();
    });
});

// =============================================================================
// Edge case E — accepted behaviours we explicitly DO NOT block (documented)
// =============================================================================

describe('intentionally accepted edge cases', function () {
    it('allows free_quantity to exceed the number of items in the group (silent truncation at runtime)', function () {
        // Phase 2 customer-web computes effective free count as
        // `min(free_quantity, count(selected))`. So if admin sets free=10
        // on a 5-item group, every selection is free — fine, no error.
        // We don't reject this at the schema layer because the trade-off is
        // unclear: maybe admin plans to add more items later.
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'price_strategy' => 'free_up_to_n',
            'free_quantity' => 99,
        ]);
        expect($group->free_quantity)->toBe(99);
    });
});

// =============================================================================
// Edge case — adding a VARIANT topping must seed one price row per SKU
// =============================================================================

describe('variant topping price-row seeding', function () {
    it('seeds one topping_group_item_sku per active variant SKU when the product has options', function () {
        // Bug: addGroupItem created a wildcard row only for NON-variant products
        // and NOTHING for variant products, leaving topping_group_item_skus empty.
        // The shop/customer menu reads that table (not product.skus), so a 4-SKU
        // topping collapsed to a single fallback line.
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $product = Product::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'product_type_id' => $this->toppingType->id,
            'name' => 'Ngọt 0%',
        ]);
        // 3 variant SKUs (distinct option_value1_id) + 1 inactive variant that must be skipped.
        $active = collect(range(1, 3))->map(fn ($i) => ProductSku::factory()->create([
            'product_id' => $product->id,
            'option_value1_id' => (string) Str::uuid(),
            'option_signature' => 'sig-active-'.$i,
            'is_active' => true,
        ]));
        ProductSku::factory()->create([
            'product_id' => $product->id,
            'option_value1_id' => (string) Str::uuid(),
            'option_signature' => 'sig-inactive',
            'is_active' => false,
        ]);

        $item = $this->ptgService->addGroupItem($group, ['product_id' => $product->id]);

        $rows = ToppingGroupItemSku::where('topping_group_item_id', $item->id)->get();

        // One row per ACTIVE variant, none for the inactive one, no wildcard.
        expect($rows)->toHaveCount(3)
            ->and($rows->pluck('product_sku_id')->sort()->values()->all())
            ->toBe($active->pluck('id')->sort()->values()->all())
            ->and($rows->whereNull('product_sku_id'))->toHaveCount(0);
    });

    it('binds the product SKU (not a wildcard) for a non-variant topping', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $product = Product::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'product_type_id' => $this->toppingType->id,
            'name' => 'Trà Xả',
        ]);
        $sku = ProductSku::factory()->create(['product_id' => $product->id, 'option_value1_id' => null]);

        $item = $this->ptgService->addGroupItem($group, ['product_id' => $product->id]);

        $rows = ToppingGroupItemSku::where('topping_group_item_id', $item->id)->get();

        // #1708: a single-SKU topping binds its product's SKU so the POS picker
        // can select it — a NULL wildcard was the silent-unclickable bug.
        expect($rows)->toHaveCount(1)
            ->and((string) $rows->first()->product_sku_id)->toBe((string) $sku->id);
    });
});

/*
 * #1861 — id lặp trong MỘT lời gọi sync làm chết 500.
 *
 * Log production ghi 9 lần `Duplicate entry ... for key
 * product_topping_groups_product_id_topping_group_id_unique`. Không phải
 * soft-delete sót lại (bảng này xoá cứng — đã kiểm), mà là `syncForProduct`
 * lặp `create()` theo từng phần tử của mảng đầu vào mà không khử trùng.
 *
 * Gửi cùng một nhóm hai lần là chuyện bình thường ở tầng gọi: form gửi lặp,
 * người dùng bấm hai lần, hay một payload ghép từ hai nguồn. Đó KHÔNG phải
 * lỗi của người gọi — "gắn nhóm X" hai lần vẫn chỉ có nghĩa là gắn một lần.
 */
it('#1861 id lặp trong cùng một lời gọi chỉ tạo MỘT dòng, không nổ 500', function () {
    $group = ToppingGroup::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'min_select' => 0,
        'max_select' => 3,
    ]);
    $product = Product::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'product_type_id' => $this->foodType->id,
    ]);

    $this->ptgService->syncForProduct($product, [$group->id, $group->id], [$group->id => 1], []);

    expect(DB::table('product_topping_groups')
        ->where('product_id', $product->id)
        ->where('topping_group_id', $group->id)
        ->count())->toBe(1);
});
