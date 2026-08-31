<?php

use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuSection;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(IamSeeder::class);

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->adminRole = Role::firstOrCreate(
        ['slug' => 'org-admin'],
        ['name' => 'Org Admin', 'level' => 100],
    );

    $this->admin = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->admin->assignRole($this->adminRole, $this->orgId);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

// =============================================================================
//  CRUD
// =============================================================================

it('can list menu sections', function () {
    MenuSection::factory()->count(3)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson("{$this->baseUrl}/menu-sections");

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

it('can create a menu section', function () {
    $response = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/menu-sections", [
            'name' => '前菜',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', '前菜');

    $this->assertDatabaseHas('menu_sections', [
        'name' => '前菜',
    ]);
});

it('creates and reloads a menu section with ja en and vi names', function () {
    $payload = ['name' => '前菜', 'ja' => ['name' => '前菜'], 'en' => ['name' => 'Starters'], 'vi' => ['name' => 'Khai vị']];
    $sectionId = $this->actingAs($this->admin)->postJson("{$this->baseUrl}/menu-sections", $payload)
        ->assertCreated()->assertJsonPath('data.translations.en.name', 'Starters')->json('data.id');
    foreach (['ja', 'en', 'vi'] as $locale) {
        $this->assertDatabaseHas('menu_section_translations', [
            'menu_section_id' => $sectionId, 'locale' => $locale, 'name' => $payload[$locale]['name'],
        ]);
    }
    $this->actingAs($this->admin)->getJson("{$this->baseUrl}/menu-sections/{$sectionId}")->assertOk()
        ->assertJsonPath('data.translations.ja.name', '前菜')
        ->assertJsonPath('data.translations.en.name', 'Starters')
        ->assertJsonPath('data.translations.vi.name', 'Khai vị');
});

it('can show a menu section', function () {
    $section = MenuSection::factory()->create([
        'name' => 'メイン',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson("{$this->baseUrl}/menu-sections/{$section->id}");

    $response->assertOk()
        ->assertJsonPath('data.name', 'メイン');
});

it('can update a menu section', function () {
    $section = MenuSection::factory()->create([
        'name' => 'Old Name',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->putJson("{$this->baseUrl}/menu-sections/{$section->id}", [
            'name' => 'New Name',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'New Name');
});

it('edits every menu section locale and returns them after reload', function () {
    $section = MenuSection::factory()->create([
        'name' => 'メイン', 'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
    ]);
    $this->actingAs($this->admin)->putJson("{$this->baseUrl}/menu-sections/{$section->id}", [
        'name' => '主菜', 'ja' => ['name' => '主菜'],
        'en' => ['name' => 'Main dishes'], 'vi' => ['name' => 'Món chính'],
    ])->assertOk();
    $this->actingAs($this->admin)->getJson("{$this->baseUrl}/menu-sections/{$section->id}")->assertOk()
        ->assertJsonPath('data.translations.ja.name', '主菜')
        ->assertJsonPath('data.translations.en.name', 'Main dishes')
        ->assertJsonPath('data.translations.vi.name', 'Món chính');
});

it('can delete a menu section', function () {
    $section = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->deleteJson("{$this->baseUrl}/menu-sections/{$section->id}");

    $response->assertNoContent();
    $this->assertSoftDeleted('menu_sections', ['id' => $section->id]);
});

it('validates required name on store', function () {
    $response = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/menu-sections", []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

// =============================================================================
//  Menu <-> Section N:N
// =============================================================================

it('can sync sections to a menu', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_master' => true,
        'status' => 'Draft',
    ]);

    $section1 = MenuSection::factory()->create([
        'name' => '前菜',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $section2 = MenuSection::factory()->create([
        'name' => 'メイン',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->putJson("{$this->baseUrl}/menus/{$menu->id}/sections", [
            'sections' => [
                ['id' => $section1->id, 'display_order' => 1],
                ['id' => $section2->id, 'display_order' => 2],
            ],
        ]);

    $response->assertOk();

    $this->assertDatabaseHas('menu_menu_sections', [
        'menu_id' => $menu->id,
        'menu_section_id' => $section1->id,
        'display_order' => 1,
    ]);

    $this->assertDatabaseHas('menu_menu_sections', [
        'menu_id' => $menu->id,
        'menu_section_id' => $section2->id,
        'display_order' => 2,
    ]);
});

it('can replace sections on a menu via sync', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_master' => true,
        'status' => 'Draft',
    ]);

    $section1 = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $section2 = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    // First sync
    $menu->menuSections()->sync([
        $section1->id => ['display_order' => 1],
    ]);

    // Replace with section2 only
    $this->actingAs($this->admin)
        ->putJson("{$this->baseUrl}/menus/{$menu->id}/sections", [
            'sections' => [
                ['id' => $section2->id, 'display_order' => 1],
            ],
        ])
        ->assertOk();

    $this->assertDatabaseMissing('menu_menu_sections', [
        'menu_id' => $menu->id,
        'menu_section_id' => $section1->id,
    ]);

    $this->assertDatabaseHas('menu_menu_sections', [
        'menu_id' => $menu->id,
        'menu_section_id' => $section2->id,
    ]);
});

// =============================================================================
//  MenuProduct with section
// =============================================================================

it('can assign a menu product to a section', function () {
    $productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $productType->id,
    ]);

    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_master' => true,
        'status' => 'Draft',
    ]);

    $section = MenuSection::factory()->create([
        'name' => '前菜',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'display_order' => 1,
    ]);

    expect($menuProduct->menu_section_id)->toBe($section->id);
    expect($menuProduct->menuSection->name)->toBe('前菜');
});

it('allows menu product without a section (backward compatible)', function () {
    $productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $productType->id,
    ]);

    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_master' => true,
        'status' => 'Draft',
    ]);

    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => null,
        'display_order' => 1,
    ]);

    expect($menuProduct->menu_section_id)->toBeNull();
    expect($menuProduct->menuSection)->toBeNull();
});

it('returns menu with sections in show endpoint', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_master' => true,
        'status' => 'Draft',
    ]);

    $section = MenuSection::factory()->create([
        'name' => 'デザート',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $menu->menuSections()->attach($section->id, ['display_order' => 1]);

    $response = $this->actingAs($this->admin)
        ->getJson("{$this->baseUrl}/menus/{$menu->id}");

    $response->assertOk()
        ->assertJsonPath('data.menuSections.0.name', 'デザート');
});

// =============================================================================
//  Section reuse across menus
// =============================================================================

it('allows same section to be used in multiple menus', function () {
    $section = MenuSection::factory()->create([
        'name' => 'メイン',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $menu1 = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Lunch',
        'is_master' => true,
        'status' => 'Draft',
    ]);

    $menu2 = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Dinner',
        'is_master' => true,
        'status' => 'Draft',
        'priority' => 20,
    ]);

    $menu1->menuSections()->attach($section->id, ['display_order' => 1]);
    $menu2->menuSections()->attach($section->id, ['display_order' => 2]);

    $section->refresh()->load('menus');
    expect($section->menus)->toHaveCount(2);

    // Different display_order per menu
    $m1Pivot = $section->menus->where('id', $menu1->id)->first()->pivot;
    $m2Pivot = $section->menus->where('id', $menu2->id)->first()->pivot;

    expect($m1Pivot->display_order)->toBe(1);
    expect($m2Pivot->display_order)->toBe(2);
});
