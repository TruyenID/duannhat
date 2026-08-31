<?php

/**
 * Plan 015 — Shop menu read with toppings
 *
 * Covers:
 *   Happy H1 — GET .../menus/{menu} returns topping_groups[] per product
 *   Happy H2 — Product without topping groups gets `topping_groups: []`
 *   Happy H3 — pivot.min_select_override beats group.min_select in
 *              `effective_min_select`
 *   Happy H4 — pivot.max_select_override beats group.max_select in
 *              `effective_max_select`
 *
 * Endpoints under test:
 *   GET /api/v1/shops/{shopSlug}/menus/{menu}
 *   GET /api/v1/shops/{shopSlug}/menus/{menu}/products
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'sjk-tp-'.Str::random(4),
        'is_active' => true,
        'name' => 'Test Topping Shop',
    ]);
    DB::table('branches')->where('id', $this->shop->id)->update(['timezone' => 'Asia/Tokyo']);
    $this->shop->refresh();

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    // Active branch menu (branch_id set, master_menu_id non-null since
    // shop-side menu queries restrict to cloned-from-master).
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'status' => 'Active',
    ]);
    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'master_menu_id' => $masterMenu->id,
        'is_master' => false,
        'status' => 'Active',
    ]);

    // A product on the menu (gets a default sku via factory).
    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->productSku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
        'selling_price' => 10000,
    ]);
    MenuProduct::factory()->create([
        'menu_id' => $this->menu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $this->showUrl = "/api/v1/shops/{$this->shop->slug}/menus/{$this->menu->id}";
    $this->productsUrl = "/api/v1/shops/{$this->shop->slug}/menus/{$this->menu->id}/products";
});

/**
 * Helper: build a topping group attached to $this->product, with one item
 * + one item-SKU at $extraPrice.
 */
function makeToppingGroup(array $groupAttrs = [], float $extraPrice = 50.0, ?array $pivotAttrs = null): ToppingGroup
{
    /** @var TestCase $t */
    $t = test();

    $group = ToppingGroup::factory()->create(array_merge([
        'organization_id' => $t->orgId,
        'brand_id' => $t->brand->id,
        'is_active' => true,
        'min_select' => 0,
        'max_select' => null,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
        'price_strategy' => 'flat',
    ], $groupAttrs));

    $toppingProduct = Product::factory()->create([
        'organization_id' => $t->orgId,
        'brand_id' => $t->brand->id,
    ]);
    $toppingSku = ProductSku::factory()->create([
        'product_id' => $toppingProduct->id,
        'is_active' => true,
        'selling_price' => $extraPrice,
    ]);

    $item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $group->id,
        'product_id' => $toppingProduct->id,
        'is_default' => false,
        'sort_order' => 0,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $item->id,
        'product_sku_id' => $toppingSku->id,
        'extra_price' => $extraPrice,
    ]);

    $t->product->toppingGroups()->attach($group->id, $pivotAttrs ?? ['sort_order' => 0]);

    return $group;
}

// =========================================================================
//  Happy path
// =========================================================================

describe('happy path', function () {
    it('returns topping_groups on each menu product (show endpoint)', function () {
        $group = makeToppingGroup(['name' => 'Sauces']);

        $response = $this->actingAs($this->user)->getJson($this->showUrl);

        $response->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'menu_products' => [
                    '*' => [
                        'product' => [
                            'topping_groups' => [
                                '*' => [
                                    'id', 'name', 'effective_min_select',
                                    'effective_max_select', 'items',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $response->assertJsonPath('data.menu_products.0.product.topping_groups.0.id', $group->id);
    });

    it('exposes is_active on each topping sku so the badge matches the customer menu', function () {
        // A topping item with two variant SKUs: one ACTIVE (300), one disabled
        // in the HQ catalog (product_skus.is_active = false, 450). The customer
        // menu hides the disabled one; the shop badge must be able to show it
        // as inactive too, so the resource has to surface is_active per SKU.
        $group = ToppingGroup::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_active' => true,
            'min_select' => 0,
            'max_select' => 1,
            'modifier_type' => 'add',
            'selection_type' => 'multiple',
            'price_strategy' => 'flat',
        ]);

        $toppingProduct = Product::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);
        $item = ToppingGroupItem::factory()->create([
            'topping_group_id' => $group->id,
            'product_id' => $toppingProduct->id,
            'sort_order' => 0,
        ]);

        // Distinct option values → distinct option_signature (unique per product).
        $option = ProductOption::factory()->create([
            'product_id' => $toppingProduct->id,
            'key' => 'size',
            'position' => 0,
        ]);
        $activeValue = ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'active',
            'position' => 0,
        ]);
        $inactiveValue = ProductOptionValue::factory()->create([
            'option_id' => $option->id,
            'value' => 'inactive',
            'position' => 1,
        ]);

        $activeSku = ProductSku::factory()->create([
            'product_id' => $toppingProduct->id,
            'option_value1_id' => $activeValue->id,
            'is_active' => true,
        ]);
        $inactiveSku = ProductSku::factory()->create([
            'product_id' => $toppingProduct->id,
            'option_value1_id' => $inactiveValue->id,
            'is_active' => false,
        ]);
        ToppingGroupItemSku::factory()->create([
            'topping_group_item_id' => $item->id,
            'product_sku_id' => $activeSku->id,
            'extra_price' => 300,
        ]);
        ToppingGroupItemSku::factory()->create([
            'topping_group_item_id' => $item->id,
            'product_sku_id' => $inactiveSku->id,
            'extra_price' => 450,
        ]);

        $this->product->toppingGroups()->attach($group->id, ['sort_order' => 0]);

        $skus = collect(
            $this->actingAs($this->user)->getJson($this->showUrl)
                ->assertOk()
                ->json('data.menu_products.0.product.topping_groups.0.items.0.skus')
        )->keyBy('product_sku_id');

        expect($skus[$activeSku->id]['is_active'])->toBeTrue()
            ->and($skus[$inactiveSku->id]['is_active'])->toBeFalse();
    });

    it('returns empty topping_groups when product has none attached', function () {
        // No topping group attached.

        $response = $this->actingAs($this->user)->getJson($this->productsUrl);

        $response->assertOk();
        $response->assertJsonPath('data.0.product.topping_groups', []);
    });

    it('effective_min_select reads from pivot override when present', function () {
        makeToppingGroup(
            ['min_select' => 0, 'max_select' => 3],
            50.0,
            ['sort_order' => 0, 'min_select_override' => 2, 'max_select_override' => null],
        );

        $response = $this->actingAs($this->user)->getJson($this->productsUrl);

        $response->assertOk();
        $response->assertJsonPath('data.0.product.topping_groups.0.effective_min_select', 2);
    });

    it('effective_max_select reads from pivot override when present', function () {
        makeToppingGroup(
            ['min_select' => 0, 'max_select' => 3],
            50.0,
            ['sort_order' => 0, 'min_select_override' => null, 'max_select_override' => 1],
        );

        $response = $this->actingAs($this->user)->getJson($this->productsUrl);

        $response->assertOk();
        $response->assertJsonPath('data.0.product.topping_groups.0.effective_max_select', 1);
    });
});

// =========================================================================
//  Authorization — multi-tenant isolation (topping READ path)
//
//  TESTS.md Authorization: "pos_staff of branch A GET branchB menu → 403
//  (existing scoping; topping data not exposed)". The shop menu read carries
//  the topping_groups payload, so the tenant boundary MUST be exercised on
//  this endpoint specifically — a foreign-org caller must never receive
//  another shop's toppings.
// =========================================================================

describe('authorization — tenant isolation', function () {
    it('denies a foreign-org user reading a shop menu with toppings → 403', function () {
        // A group attached to org-A's product so the payload WOULD carry
        // toppings if the request were allowed to reach the serializer.
        makeToppingGroup(['name' => 'Sauces']);

        // Foreign org with its own user; no role pivot on org-A.
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherUser = User::factory()->create([
            'console_organization_id' => $otherOrgId,
        ]);
        grantOrgAccess($otherUser, $otherOrgId);

        // Foreign user hits org-A's shop URL → blocked at shop-context bind,
        // topping data never serialized.
        $this->actingAs($otherUser)->getJson($this->showUrl)->assertForbidden();
        $this->actingAs($otherUser)->getJson($this->productsUrl)->assertForbidden();
    });

    it('rejects an unauthenticated topping read → 401', function () {
        makeToppingGroup(['name' => 'Sauces']);

        $this->getJson($this->showUrl)->assertUnauthorized();
        $this->getJson($this->productsUrl)->assertUnauthorized();
    });
});
