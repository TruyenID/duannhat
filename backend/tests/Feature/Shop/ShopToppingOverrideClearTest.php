<?php

declare(strict_types=1);

/**
 * #1272 — clearing the LAST topping override in a group was impossible from the
 * shop UI.
 *
 * Both shop override endpoints are full-replace syncs: the client sends the
 * whole list and the service deletes-then-recreates. So `overrides: []` is the
 * legitimate way to say "no overrides left" — and it is the payload the UI
 * produces when you un-hide the last hidden variant (the row carries no
 * meaningful content once `is_hidden` is false and there is no price, so the
 * client drops it).
 *
 * Both controllers validated `['required', 'array']`, and Laravel's `required`
 * treats `[]` as EMPTY. Result: hiding a variant worked (payload had one row)
 * but un-hiding it again 422'd with "The overrides field is required" — the
 * group's last override could never be cleared. The HQ twin has used `present`
 * (and covered `[]` explicitly) since it shipped; only the two shop copies
 * diverged, with no coverage of this payload.
 *
 * Guarded here at the HTTP layer on purpose: the bug lived entirely in the
 * controller's validation rules, so a service-level test cannot see it.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\FloatingSection;
use App\Models\FloatingSectionProduct;
use App\Models\FloatingSectionProductToppingItemOverride;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductToppingItemOverride;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Role;
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
        'is_active' => true,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'ovr-clear-'.Str::random(4),
        'is_active' => true,
    ]);

    // The product the toppings hang off.
    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
        'selling_price' => 10000,
    ]);

    // A topping group with ONE item carrying ONE variant SKU — the shape in the
    // bug report ("1 ghi đè", a single hidden variant).
    $this->group = ToppingGroup::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_active' => true,
        'min_select' => 0,
        'max_select' => null,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
        'price_strategy' => 'flat',
    ]);

    $toppingProduct = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->toppingSku = ProductSku::factory()->create([
        'product_id' => $toppingProduct->id,
        'is_active' => true,
        'selling_price' => 500,
    ]);
    $this->item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->group->id,
        'product_id' => $toppingProduct->id,
        'sort_order' => 0,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->toppingSku->id,
        'extra_price' => 500,
    ]);
    $this->product->toppingGroups()->attach($this->group->id, ['sort_order' => 0]);

    // ---- menu path -------------------------------------------------------
    $masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Active',
        'master_menu_id' => null,
    ]);
    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'master_menu_id' => $masterMenu->id,
        'status' => 'Active',
    ]);
    $this->menuProduct = MenuProduct::factory()->create([
        'menu_id' => $this->menu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    // ---- floating-section path -------------------------------------------
    $this->section = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_active' => true,
    ]);
    $this->sectionProduct = FloatingSectionProduct::factory()->create([
        'floating_section_id' => $this->section->id,
        'product_id' => $this->product->id,
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($role, $this->orgId);

    $this->menuUrl = "/api/v1/shops/{$this->shop->slug}/menus/{$this->menu->id}"
        ."/products/{$this->menuProduct->id}/topping-groups/{$this->group->id}/overrides";
    $this->sectionUrl = "/api/v1/shops/{$this->shop->slug}/floating-sections/{$this->section->id}"
        ."/products/{$this->sectionProduct->id}/topping-groups/{$this->group->id}/overrides";
});

// =============================================================================
//  Shop menu overrides
// =============================================================================

it('un-hides the last hidden variant on a shop menu when the client sends []', function () {
    // Exactly the reported state: one override, hiding one variant.
    MenuProductToppingItemOverride::create([
        'menu_product_id' => $this->menuProduct->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->toppingSku->id,
        'is_hidden' => true,
        'override_price' => null,
    ]);

    // Toggling "show" again drops the row client-side → payload is [].
    $this->actingAs($this->manager)
        ->putJson($this->menuUrl, ['overrides' => []])
        ->assertOk()
        ->assertJsonPath('data', []);

    expect(MenuProductToppingItemOverride::where('menu_product_id', $this->menuProduct->id)
        ->where('topping_group_id', $this->group->id)
        ->count())->toBe(0);
});

it('clears the last price override on a shop menu when the client sends []', function () {
    MenuProductToppingItemOverride::create([
        'menu_product_id' => $this->menuProduct->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->toppingSku->id,
        'is_hidden' => false,
        'override_price' => 1200,
    ]);

    $this->actingAs($this->manager)
        ->putJson($this->menuUrl, ['overrides' => []])
        ->assertOk()
        ->assertJsonPath('data', []);

    expect(MenuProductToppingItemOverride::where('menu_product_id', $this->menuProduct->id)
        ->count())->toBe(0);
});

it('still rejects a shop menu sync with the overrides key missing entirely', function () {
    // `present` must not become a hole: a client that forgets the key is a bug,
    // and silently treating it as "clear everything" would wipe overrides.
    $this->actingAs($this->manager)
        ->putJson($this->menuUrl, [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('overrides');
});

// =============================================================================
//  Floating-section overrides — same controller shape, same bug
// =============================================================================

it('un-hides the last hidden variant on a floating section when the client sends []', function () {
    FloatingSectionProductToppingItemOverride::create([
        'floating_section_product_id' => $this->sectionProduct->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->toppingSku->id,
        'is_hidden' => true,
        'override_price' => null,
    ]);

    $this->actingAs($this->manager)
        ->putJson($this->sectionUrl, ['overrides' => []])
        ->assertOk()
        ->assertJsonPath('data', []);

    expect(FloatingSectionProductToppingItemOverride::where('floating_section_product_id', $this->sectionProduct->id)
        ->where('topping_group_id', $this->group->id)
        ->count())->toBe(0);
});

it('still rejects a floating section sync with the overrides key missing entirely', function () {
    $this->actingAs($this->manager)
        ->putJson($this->sectionUrl, [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('overrides');
});
