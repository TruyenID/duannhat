<?php

use App\Models\Branch;
use App\Models\File;
use App\Models\FloatingSection;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuProductToppingItemOverride;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\TaxType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Models\Zone;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->branch = Branch::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $this->zone = Zone::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
    ]);

    $this->table = Table::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'menu-test-token',
        'is_active' => true,
    ]);
});

// =========================================================================
//  Happy path
// =========================================================================

it('returns menu with categories, items, and prices', function () {
    $menu = Menu::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'status' => 'Active',
        'priority' => 1,
    ]);

    $section = MenuSection::factory()->create([
        'name' => 'Drinks',
    ]);

    $menu->menuSections()->attach($section);

    $product = Product::factory()->active()->create([
        'name' => 'Matcha Latte',
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => 550,
    ]);

    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);

    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'selling_price' => 550,
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/customer/tables/menu-test-token/menu');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['categories' => [['id', 'name', 'items']]]]);

    $categories = $response->json('data.categories');
    expect($categories)->toHaveCount(1);
    expect($categories[0]['items'])->toHaveCount(1);
    expect($categories[0]['items'][0]['name'])->toBe('Matcha Latte');
    expect((float) $categories[0]['items'][0]['price'])->toEqual(550.0);
    // Single-SKU products expose the sku_id field
    expect($categories[0]['items'][0]['sku_id'])->toBe($sku->id);
});

it('returns the active Floating Section price and promotion metadata', function () {
    $menu = Menu::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'status' => 'Active',
    ]);
    $menuSection = MenuSection::factory()->create(['name' => 'Deals']);
    $menu->menuSections()->attach($menuSection);
    $product = Product::factory()->active()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 1200]);
    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => $menuSection->id,
        'is_active' => true,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);
    $floating = FloatingSection::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $product->brand_id,
        'branch_id' => $this->branch->id,
        'name' => 'Dinner deal',
        'is_active' => true,
        'start_date' => null,
        'end_date' => null,
    ]);
    $floating->schedules()->create([
        'start_time' => '00:00:00',
        'end_time' => '23:59:59',
        'days_of_week' => 127,
        'is_active' => true,
        'priority' => 0,
    ]);
    $floatingProduct = $floating->products()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    $floatingProduct->skus()->create([
        'product_sku_id' => $sku->id,
        'selling_price' => 650,
        'is_active' => true,
        'is_price_overridden' => true,
    ]);

    $categories = $this->getJson('/api/v1/customer/tables/menu-test-token/menu')
        ->assertOk()
        ->json('data.categories');

    // categories[0] = floating spotlight (built independently at the floating
    // price). categories[1] = the normal menu section, where the same product's
    // menu price is lowered to the floating price with base_price = original.
    $spotlight = $categories[0]['items'][0];
    expect((float) $spotlight['price'])->toBe(650.0)
        ->and($spotlight['active_floating_section']['floating_section_id'])->toBe($floating->id)
        ->and($categories[0]['is_floating_section'])->toBeTrue();

    $menuItem = $categories[1]['items'][0];
    expect((float) $menuItem['price'])->toBe(650.0)
        ->and((float) $menuItem['base_price'])->toBe(1000.0)
        ->and($menuItem['active_floating_section']['floating_section_id'])->toBe($floating->id);
});

it('exposes per-item effective tax rates for 総額表示 previews', function () {
    // plan-043 T5.4 — the menu payload carries the resolved tax type's two
    // rates (店内/持ち帰り) so customer-web can render tax-included prices.
    // MenuProduct override wins over the Product fallback; inherit → null.
    $menu = Menu::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'status' => 'Active',
        'priority' => 1,
    ]);
    $section = MenuSection::factory()->create(['name' => 'Food']);
    $menu->menuSections()->attach($section);

    $brandId = $this->branch->console_brand_id ?? $this->branch->id;
    $reduced = TaxType::factory()->reduced()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $brandId,
    ]);

    // Product carries the reduced type (10/8). No MenuProduct override.
    $product = Product::factory()->active()->create([
        'name' => 'Bento',
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'tax_type_id' => $reduced->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 1000]);
    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    // Second product inherits (no tax type anywhere) → null rates.
    $bare = Product::factory()->active()->create([
        'name' => 'Plain',
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);
    $bareSku = ProductSku::factory()->create(['product_id' => $bare->id, 'selling_price' => 300]);
    $bareMp = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $bare->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $bareMp->id,
        'product_sku_id' => $bareSku->id,
        'selling_price' => 300,
        'is_active' => true,
    ]);

    $items = collect($this->getJson('/api/v1/customer/tables/menu-test-token/menu')->json('data.categories.0.items'))
        ->keyBy('name');

    expect((float) $items['Bento']['tax_rate'])->toBe(8.0)
        ->and($items['Bento']['tax_type_id'])->toBe($reduced->id)
        ->and($items['Plain']['tax_rate'])->toBeNull();
});

it('returns the full ordered gallery for each menu item', function () {
    $menu = Menu::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'status' => 'Active',
        'priority' => 1,
    ]);

    $section = MenuSection::factory()->create(['name' => 'Drinks']);
    $menu->menuSections()->attach($section);

    $product = Product::factory()->active()->create([
        'name' => 'Matcha Latte',
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    // Three gallery photos, intentionally created out of order to prove the
    // response respects sort_order.
    foreach ([2, 0, 1] as $order) {
        File::factory()->permanent()->create([
            'fileable_type' => $product->getMorphClass(),
            'fileable_id' => $product->id,
            'collection' => 'gallery',
            'sort_order' => $order,
            'disk' => 'public',
            'path' => "uploads/gallery/photo-{$order}.jpg",
        ]);
    }

    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => 550,
    ]);

    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);

    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'selling_price' => 550,
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/customer/tables/menu-test-token/menu')->assertOk();

    $item = $response->json('data.categories.0.items.0');

    expect($item['images'])->toHaveCount(3);
    // Ordered by sort_order (0, 1, 2), not insertion order.
    expect($item['images'][0])->toContain('photo-0.jpg');
    expect($item['images'][1])->toContain('photo-1.jpg');
    expect($item['images'][2])->toContain('photo-2.jpg');
    // `image` stays the first gallery photo for backwards compatibility.
    expect($item['image'])->toContain('photo-0.jpg');
});

it('returns an empty images array when a product has no gallery', function () {
    $menu = Menu::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'status' => 'Active',
        'priority' => 1,
    ]);

    $section = MenuSection::factory()->create(['name' => 'Drinks']);
    $menu->menuSections()->attach($section);

    $product = Product::factory()->active()->create([
        'name' => 'Plain Tea',
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => 300,
    ]);

    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);

    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'selling_price' => 300,
        'is_active' => true,
    ]);

    $item = $this->getJson('/api/v1/customer/tables/menu-test-token/menu')
        ->assertOk()
        ->json('data.categories.0.items.0');

    expect($item['images'])->toBe([]);
    expect($item['image'])->toBeNull();
});

// =========================================================================
//  Edge cases
// =========================================================================

it('returns 404 when no active menu exists', function () {
    $this->getJson('/api/v1/customer/tables/menu-test-token/menu')
        ->assertNotFound()
        ->assertJson([
            'message' => 'No menu is currently available for online ordering.',
            'code' => 'menu_unavailable',
        ]);
});

it('explains when online ordering is outside service hours', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-22 06:30:00', 'Asia/Tokyo'));
    $this->branch->update([
        'name' => 'Ningyocho',
        'slug' => 'ningyocho',
        'timezone' => 'Asia/Tokyo',
    ]);

    $menu = Menu::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'name' => 'Breakfast menu',
        'status' => 'Active',
        'service_type' => 'Both',
    ]);
    $menu->activeSchedules()->create([
        'start_time' => '07:00:00',
        'end_time' => '22:00:00',
        'days_of_week' => 127,
        'is_active' => true,
        'priority' => 1,
    ]);

    $this->getJson('/api/v1/customer/branches/ningyocho/menu')
        ->assertNotFound()
        ->assertJson([
            'message' => 'Online ordering is currently outside service hours.',
            'code' => 'menu_outside_service_hours',
            'availability' => [
                'branch_name' => 'Ningyocho',
                'menu_name' => 'Breakfast menu',
                'timezone' => 'Asia/Tokyo',
                'next_opens_at' => '2026-07-22T07:00:00+09:00',
                'next_closes_at' => '2026-07-22T22:00:00+09:00',
            ],
        ]);
});

it('reports the earliest opening across menus available for the requested service', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-22 06:30:00', 'Asia/Tokyo'));
    $this->branch->update(['slug' => 'ningyocho', 'timezone' => 'Asia/Tokyo']);

    foreach ([
        ['name' => 'Takeaway', 'service_type' => 'Takeaway', 'start_time' => '11:00:00'],
        ['name' => 'All-day menu', 'service_type' => 'Both', 'start_time' => '07:00:00'],
        ['name' => 'Dine-in only', 'service_type' => 'DineIn', 'start_time' => '06:45:00'],
    ] as $definition) {
        $menu = Menu::factory()->create([
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'branch_id' => $this->branch->id,
            'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
            'name' => $definition['name'],
            'status' => 'Active',
            'service_type' => $definition['service_type'],
        ]);
        $menu->activeSchedules()->create([
            'start_time' => $definition['start_time'],
            'end_time' => '22:00:00',
            'days_of_week' => 127,
            'is_active' => true,
            'priority' => 1,
        ]);
    }

    $this->getJson('/api/v1/customer/branches/ningyocho/menu')
        ->assertNotFound()
        ->assertJsonPath('availability.menu_name', 'All-day menu')
        ->assertJsonPath('availability.next_opens_at', '2026-07-22T07:00:00+09:00');
});

// =========================================================================
//  Error handling
// =========================================================================

it('returns 404 for invalid qr_token on menu', function () {
    $this->getJson('/api/v1/customer/tables/nonexistent/menu')->assertNotFound();
});

// =========================================================================
//  Topping items expose product_sku_id
// =========================================================================

it('returns sku_id for non-variant topping items so they can be ordered', function () {
    // Setup: a product with one attached topping group whose single item has
    // no product options (non-variant). The customer menu response must
    // expose the underlying product_sku_id, otherwise checkout cannot send
    // product_sku_id in the toppings payload and the order endpoint rejects
    // mandatory groups with toppings_below_min.
    $menu = Menu::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'status' => 'Active',
        'priority' => 1,
    ]);

    $section = MenuSection::factory()->create(['name' => 'Main']);
    $menu->menuSections()->attach($section);

    $product = Product::factory()->active()->create([
        'name' => 'Pho Combo',
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => 1000,
    ]);

    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);

    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    // Topping product (non-variant: single SKU, no product options).
    $toppingProduct = Product::factory()->active()->create([
        'name' => 'Extra Egg',
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);
    $toppingSku = ProductSku::factory()->create(['product_id' => $toppingProduct->id]);

    $group = ToppingGroup::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'min_select' => 0,
        'max_select' => 1,
        'is_active' => true,
    ]);

    $groupItem = ToppingGroupItem::factory()->create([
        'topping_group_id' => $group->id,
        'product_id' => $toppingProduct->id,
    ]);

    ToppingGroupItemSku::factory()->noVariant()->create([
        'topping_group_item_id' => $groupItem->id,
        'product_sku_id' => $toppingSku->id,
        'extra_price' => 100,
    ]);

    $product->toppingGroups()->attach($group->id, ['sort_order' => 0]);

    $response = $this->getJson('/api/v1/customer/tables/menu-test-token/menu')->assertOk();

    $items = collect($response->json('data.categories'))
        ->flatMap(fn ($c) => $c['items'])
        ->firstWhere('name', 'Pho Combo');

    expect($items)->not->toBeNull();
    expect($items['toppingGroups'])->toHaveCount(1);
    expect($items['toppingGroups'][0]['items'])->toHaveCount(1);
    expect($items['toppingGroups'][0]['items'][0])->toHaveKey('sku_id');
    expect($items['toppingGroups'][0]['items'][0]['sku_id'])->toBe($toppingSku->id);
});

it('hides a simple topping the shop marked is_hidden from the customer menu', function () {
    // Shop bấm "Ẩn" một topping (Ớt ngọt) → menu_product_topping_item_overrides
    // .is_hidden = true. The order gate rejects a hidden topping, so the
    // customer menu must not offer it (previously it still showed at ¥0).
    $menu = Menu::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'status' => 'Active',
        'priority' => 1,
    ]);
    $section = MenuSection::factory()->create(['name' => 'Main']);
    $menu->menuSections()->attach($section);

    $product = Product::factory()->active()->create([
        'name' => 'Goi Cuon',
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 400]);
    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'selling_price' => 400,
        'is_active' => true,
    ]);

    // A sauce group with two simple toppings.
    $group = ToppingGroup::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'min_select' => 0,
        'max_select' => 1,
        'is_active' => true,
    ]);
    $makeTopping = function (string $name) use ($group) {
        $tp = Product::factory()->active()->create([
            'name' => $name,
            'organization_id' => '00000000-0000-0000-0000-000000000001',
        ]);
        $tsku = ProductSku::factory()->create(['product_id' => $tp->id]);
        $gi = ToppingGroupItem::factory()->create([
            'topping_group_id' => $group->id,
            'product_id' => $tp->id,
        ]);
        ToppingGroupItemSku::factory()->create([
            'topping_group_item_id' => $gi->id,
            'product_sku_id' => $tsku->id,
            'extra_price' => 0,
        ]);

        return [$gi, $tsku];
    };
    [$keepItem, $keepSku] = $makeTopping('Nuoc mam');
    [$hiddenItem, $hiddenSku] = $makeTopping('Ot ngot');

    $product->toppingGroups()->attach($group->id, ['sort_order' => 0]);

    // Shop hides "Ot ngot" on this menu line.
    MenuProductToppingItemOverride::create([
        'menu_product_id' => $menuProduct->id,
        'topping_group_id' => $group->id,
        'topping_group_item_id' => $hiddenItem->id,
        'product_sku_id' => $hiddenSku->id,
        'is_hidden' => true,
        'override_price' => null,
    ]);

    $item = collect(
        $this->getJson("/api/v1/customer/branches/{$this->branch->slug}/menu")
            ->assertOk()
            ->json('data.categories')
    )
        ->flatMap(fn ($c) => $c['items'])
        ->firstWhere('name', 'Goi Cuon');

    $toppingNames = collect($item['toppingGroups'][0]['items'])->pluck('name');
    expect($toppingNames)->toContain('Nuoc mam')
        ->and($toppingNames)->not->toContain('Ot ngot');
});

it('falls back to the topping product sku when the wildcard row has no product_sku_id', function () {
    // ToppingGroupItemService::createItem stores a wildcard
    // topping_group_item_skus row (product_sku_id = NULL) for every
    // non-variant topping added through admin-web — that row carries the
    // price, not the SKU identity. Emitting its NULL as sku_id left the menu
    // with an unorderable topping: customer-web drops selections it cannot
    // map to a SKU, so a mandatory group arrived empty and the order failed
    // with 422 toppings_below_min "no selection was provided".
    $menu = Menu::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'status' => 'Active',
        'priority' => 1,
    ]);

    $section = MenuSection::factory()->create(['name' => 'Main']);
    $menu->menuSections()->attach($section);

    $product = Product::factory()->active()->create([
        'name' => 'Pho Wildcard',
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => 1000,
    ]);

    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);

    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    $toppingProduct = Product::factory()->active()->create([
        'name' => 'Extra Egg',
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);
    $toppingSku = ProductSku::factory()->create(['product_id' => $toppingProduct->id]);

    // Mandatory group — exactly the shape that produced the 422.
    $group = ToppingGroup::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'min_select' => 1,
        'max_select' => 1,
        'is_active' => true,
    ]);

    $groupItem = ToppingGroupItem::factory()->create([
        'topping_group_id' => $group->id,
        'product_id' => $toppingProduct->id,
    ]);

    ToppingGroupItemSku::factory()->noVariant()->create([
        'topping_group_item_id' => $groupItem->id,
        'extra_price' => 0,
    ]);

    $product->toppingGroups()->attach($group->id, ['sort_order' => 0]);

    $response = $this->getJson('/api/v1/customer/tables/menu-test-token/menu')->assertOk();

    $items = collect($response->json('data.categories'))
        ->flatMap(fn ($c) => $c['items'])
        ->firstWhere('name', 'Pho Wildcard');

    expect($items)->not->toBeNull();
    expect($items['toppingGroups'][0]['items'][0]['sku_id'])->toBe($toppingSku->id);
});

// =========================================================================
//  #463 — service_type gating on the customer-facing resolve endpoints
//
//  Dine-in flow  (GET /customer/tables/{qrToken}/menu)  → DineIn + Both only.
//  Takeaway flow (GET /customer/branches/{slug}/menu)   → Takeaway + Both only.
//  Legacy "Both" menus show in both flows (back-compat).
// =========================================================================

/**
 * Build an active branch menu carrying exactly one visible product so the
 * resolved menu can be identified by its single item name.
 */
function makeServiceTypeMenu(Branch $branch, ?string $serviceType, int $priority, string $productName, ?string $masterMenuId = null): Menu
{
    $orgId = '00000000-0000-0000-0000-000000000001';

    $menu = Menu::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
        'brand_id' => $branch->console_brand_id ?? $branch->id,
        'status' => 'Active',
        'priority' => $priority,
        'service_type' => $serviceType,
        'master_menu_id' => $masterMenuId,
    ]);

    $section = MenuSection::factory()->create(['name' => "Section {$productName}"]);
    $menu->menuSections()->attach($section);

    $product = Product::factory()->active()->create([
        'name' => $productName,
        'organization_id' => $orgId,
    ]);
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => 500,
    ]);
    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'selling_price' => 500,
        'is_active' => true,
    ]);

    return $menu;
}

function resolvedItemNames(array $json): array
{
    return collect($json['data']['categories'] ?? [])
        ->flatMap(fn ($c) => $c['items'])
        ->pluck('name')
        ->all();
}

it('dine-in flow returns DineIn menus and hides Takeaway-only menus', function () {
    // Takeaway menu at the higher-resolved priority — must be skipped so the
    // dine-in flow falls through to the DineIn menu (proves gating, not order).
    makeServiceTypeMenu($this->branch, 'Takeaway', 20, 'Takeaway Coffee');
    makeServiceTypeMenu($this->branch, 'DineIn', 10, 'DineIn Ramen');

    $names = resolvedItemNames(
        $this->getJson('/api/v1/customer/tables/menu-test-token/menu')->assertOk()->json()
    );

    expect($names)->toContain('DineIn Ramen')
        ->not->toContain('Takeaway Coffee');
});

it('takeaway flow returns Takeaway menus and hides DineIn-only menus', function () {
    makeServiceTypeMenu($this->branch, 'DineIn', 20, 'DineIn Ramen');
    makeServiceTypeMenu($this->branch, 'Takeaway', 10, 'Takeaway Coffee');

    $names = resolvedItemNames(
        $this->getJson("/api/v1/customer/branches/{$this->branch->slug}/menu")->assertOk()->json()
    );

    expect($names)->toContain('Takeaway Coffee')
        ->not->toContain('DineIn Ramen');
});

it('a "Both" menu shows in both the dine-in and takeaway flows', function () {
    makeServiceTypeMenu($this->branch, 'Both', 10, 'Shared Tea');

    $dineIn = resolvedItemNames(
        $this->getJson('/api/v1/customer/tables/menu-test-token/menu')->assertOk()->json()
    );
    $takeaway = resolvedItemNames(
        $this->getJson("/api/v1/customer/branches/{$this->branch->slug}/menu")->assertOk()->json()
    );

    expect($dineIn)->toContain('Shared Tea');
    expect($takeaway)->toContain('Shared Tea');
});

it('a branch menu with NULL service_type inherits its master menu type on the customer resolve', function () {
    // Master pinned to Takeaway; branch menu leaves service_type NULL → inherits Takeaway.
    $master = Menu::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => null,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'is_master' => true,
        'service_type' => 'Takeaway',
        'status' => 'Active',
        'priority' => 1,
    ]);

    makeServiceTypeMenu($this->branch, null, 10, 'Inherited Bento', $master->id);

    // Takeaway flow: inherited Takeaway → visible.
    $takeaway = resolvedItemNames(
        $this->getJson("/api/v1/customer/branches/{$this->branch->slug}/menu")->assertOk()->json()
    );
    expect($takeaway)->toContain('Inherited Bento');

    // Dine-in flow: inherited Takeaway → hidden (no active menu at all → 404).
    $this->getJson('/api/v1/customer/tables/menu-test-token/menu')->assertNotFound();
});

// =========================================================================
//  Merge multiple active menus into one view (#1185)
// =========================================================================

it('merges multiple active menus into one view, ordered by priority, each item carrying its own menu context', function () {
    $orgId = '00000000-0000-0000-0000-000000000001';
    $brandId = $this->branch->console_brand_id ?? $this->branch->id;

    $makeMenu = function (string $name, int $priority, string $productName, float $price) use ($orgId, $brandId) {
        $menu = Menu::factory()->create([
            'organization_id' => $orgId,
            'branch_id' => $this->branch->id,
            'brand_id' => $brandId,
            'name' => $name,
            'status' => 'Active',
            'priority' => $priority,
        ]);
        $section = MenuSection::factory()->create(['name' => "{$name} section"]);
        $menu->menuSections()->attach($section);
        $product = Product::factory()->active()->create(['name' => $productName, 'organization_id' => $orgId]);
        $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => $price]);
        $mp = MenuProduct::factory()->create([
            'menu_id' => $menu->id,
            'product_id' => $product->id,
            'menu_section_id' => $section->id,
            'is_active' => true,
        ]);
        MenuProductSku::factory()->create([
            'menu_product_id' => $mp->id,
            'product_sku_id' => $sku->id,
            'selling_price' => $price,
            'is_active' => true,
        ]);

        return $menu;
    };

    // priority 2 created first to prove ordering is by priority, not insert order.
    $menuB = $makeMenu('Menu B', 2, 'Bun Bo', 800);
    $menuA = $makeMenu('Menu A', 1, 'Pho', 600);

    $data = $this->getJson('/api/v1/customer/tables/menu-test-token/menu')
        ->assertOk()
        ->json('data');

    // Both menus surface as their own sections.
    $categories = collect($data['categories']);
    expect($categories)->toHaveCount(2);

    // Ordered by priority: Menu A (1) before Menu B (2).
    expect($categories[0]['name'])->toBe('Menu A section')
        ->and($categories[1]['name'])->toBe('Menu B section');

    // Each item carries the menu_id/menu_name of the menu it belongs to.
    expect($categories[0]['items'][0]['menu_id'])->toBe($menuA->id)
        ->and($categories[0]['items'][0]['menu_name'])->toBe('Menu A')
        ->and($categories[1]['items'][0]['menu_id'])->toBe($menuB->id)
        ->and($categories[1]['items'][0]['menu_name'])->toBe('Menu B');

    // #1702 — view khai đủ MỌI menu được gộp, theo thứ tự priority. Top-level
    // vẫn là menu head (client cũ đọc nó), nhưng dưới merge nó chỉ mô tả MỘT
    // menu chứ không mô tả cả view — nên client cần danh sách này để biết menu
    // của một món trong giỏ còn mở hay đã đóng.
    expect(array_column($data['menus'], 'menu_id'))->toBe([$menuA->id, $menuB->id])
        ->and(array_column($data['menus'], 'menu_name'))->toBe(['Menu A', 'Menu B'])
        ->and($data['menus'][0])->toHaveKeys([
            'menu_id', 'menu_name', 'schedule_start_time', 'schedule_end_time',
            'cart_timeout_minutes', 'cart_deadline_iso',
        ])
        ->and($data['menu_id'])->toBe($menuA->id);
});

it('still lists a single active menu in menus[] so clients need no special case', function () {
    $orgId = '00000000-0000-0000-0000-000000000001';
    $brandId = $this->branch->console_brand_id ?? $this->branch->id;

    $menu = Menu::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $brandId,
        'name' => 'Only menu',
        'status' => 'Active',
        'priority' => 1,
    ]);
    $section = MenuSection::factory()->create(['name' => 'Only section']);
    $menu->menuSections()->attach($section);
    $product = Product::factory()->active()->create(['name' => 'Com tam', 'organization_id' => $orgId]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 700]);
    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'selling_price' => 700,
        'is_active' => true,
    ]);

    $data = $this->getJson('/api/v1/customer/tables/menu-test-token/menu')
        ->assertOk()
        ->json('data');

    expect($data['menus'])->toHaveCount(1)
        ->and($data['menus'][0]['menu_id'])->toBe($menu->id)
        ->and($data['menus'][0]['cart_deadline_iso'])->toBe($data['cart_deadline_iso']);
});

it('surfaces the floating section as its own spotlight section on top', function () {
    $orgId = '00000000-0000-0000-0000-000000000001';
    $brandId = $this->branch->console_brand_id ?? $this->branch->id;

    $menu = Menu::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $brandId,
        'name' => 'Main',
        'status' => 'Active',
        'priority' => 1,
    ]);
    $section = MenuSection::factory()->create(['name' => 'Drinks']);
    $menu->menuSections()->attach($section);
    $product = Product::factory()->active()->create(['name' => 'Bia 333', 'organization_id' => $orgId]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 1000]);
    $mp = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $mp->id,
        'product_sku_id' => $sku->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    $floating = FloatingSection::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $product->brand_id,
        'branch_id' => $this->branch->id,
        'name' => 'Happy Hour',
        'is_active' => true,
        'priority' => 0,
        // Factory default is a random start/end date that may exclude today —
        // null = unbounded window (matches the older floating-section test).
        'start_date' => null,
        'end_date' => null,
    ]);
    $floating->schedules()->create([
        'start_time' => '00:00:00',
        'end_time' => '23:59:59',
        'days_of_week' => 127,
        'is_active' => true,
        'priority' => 0,
    ]);
    $fp = $floating->products()->create(['product_id' => $product->id, 'is_active' => true, 'display_order' => 1]);
    $fp->skus()->create([
        'product_sku_id' => $sku->id,
        'selling_price' => 600,
        'is_active' => true,
        'is_price_overridden' => true,
    ]);

    $categories = $this->getJson('/api/v1/customer/tables/menu-test-token/menu')
        ->assertOk()
        ->json('data.categories');

    // First category is the floating-section spotlight (id = floating-section-<uuid>),
    // flagged + named after the floating section.
    expect($categories[0]['id'])->toBe('floating-section-'.$floating->id)
        ->and($categories[0]['name'])->toBe('Happy Hour')
        ->and($categories[0]['is_floating_section'])->toBeTrue();

    // The promo item sits in the spotlight at the floating price…
    $spotlightItem = $categories[0]['items'][0];
    expect((float) $spotlightItem['price'])->toBe(600.0)
        ->and($spotlightItem['active_floating_section']['floating_section_id'])->toBe($floating->id);

    // …and still appears in its normal menu section (spotlight = duplicate),
    // with the menu price lowered to the floating price.
    expect($categories[1]['name'])->toBe('Drinks')
        ->and($categories[1]['items'][0]['name'])->toBe('Bia 333');
});

it('surfaces floating-section products that are NOT in any menu (independent spotlight)', function () {
    $orgId = '00000000-0000-0000-0000-000000000001';
    $brandId = $this->branch->console_brand_id ?? $this->branch->id;

    // A normal menu with an unrelated product.
    $menu = Menu::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $brandId,
        'name' => 'Main',
        'status' => 'Active',
        'priority' => 1,
    ]);
    $section = MenuSection::factory()->create(['name' => 'Drinks']);
    $menu->menuSections()->attach($section);
    $menuProd = Product::factory()->active()->create(['name' => 'Latte', 'organization_id' => $orgId]);
    $menuSku = ProductSku::factory()->create(['product_id' => $menuProd->id, 'selling_price' => 500]);
    $mp = MenuProduct::factory()->create([
        'menu_id' => $menu->id, 'product_id' => $menuProd->id,
        'menu_section_id' => $section->id, 'is_active' => true,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $mp->id, 'product_sku_id' => $menuSku->id,
        'selling_price' => 500, 'is_active' => true,
    ]);

    // A floating-section product that is NOT in the menu at all.
    $offMenuProduct = Product::factory()->active()->create(['name' => 'Bia 333', 'organization_id' => $orgId]);
    $offMenuSku = ProductSku::factory()->create(['product_id' => $offMenuProduct->id, 'selling_price' => 400]);
    $floating = FloatingSection::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $offMenuProduct->brand_id,
        'branch_id' => $this->branch->id,
        'name' => 'Khung giờ ưu đãi',
        'is_active' => true,
        'priority' => 0,
        'start_date' => null,
        'end_date' => null,
    ]);
    $floating->schedules()->create([
        'start_time' => '00:00:00', 'end_time' => '23:59:59',
        'days_of_week' => 127, 'is_active' => true, 'priority' => 0,
    ]);
    $fp = $floating->products()->create(['product_id' => $offMenuProduct->id, 'is_active' => true, 'display_order' => 1]);
    $fp->skus()->create([
        'product_sku_id' => $offMenuSku->id, 'selling_price' => 320,
        'is_active' => true, 'is_price_overridden' => true,
    ]);

    $categories = $this->getJson('/api/v1/customer/tables/menu-test-token/menu')
        ->assertOk()
        ->json('data.categories');

    // Spotlight on top, with the off-menu product (never appears in the menu section).
    expect($categories[0]['is_floating_section'])->toBeTrue()
        ->and($categories[0]['items'][0]['name'])->toBe('Bia 333')
        ->and((float) $categories[0]['items'][0]['price'])->toBe(320.0);

    // The menu section still only has its own (unrelated) product.
    expect($categories[1]['name'])->toBe('Drinks')
        ->and($categories[1]['items'][0]['name'])->toBe('Latte');
});
