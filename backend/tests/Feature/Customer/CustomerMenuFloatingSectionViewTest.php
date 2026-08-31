<?php

use App\Models\Branch;
use App\Models\FloatingSection;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSchedule;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\Zone;
use Illuminate\Support\Carbon;

/**
 * #1185 — the customer menu must present an active Floating Section as its own
 * section at the top of the view, and order the menu's own sections by the
 * curator's `menu_menu_sections.display_order`.
 */
const ORG = '00000000-0000-0000-0000-000000000001';

beforeEach(function () {
    // #1732 — ĐÓNG BĂNG ĐỒNG HỒ. File này dựng menu có khung giờ (`お持ち帰り` mở
    // 00:00–21:30) rồi khẳng định món spotlight neo vào ĐÚNG menu đó. Với đồng
    // hồ thật, sau 21:30 giờ chi nhánh (factory ghim `Asia/Tokyo`) menu ấy đã
    // đóng, resolver chọn menu khác, và test đỏ vì một lý do không liên quan gì
    // tới thứ nó đang kiểm.
    //
    // Đã xảy ra: full suite chạy lúc 23:24 JST đỏ đúng một test — chính nó —
    // trên cây mã mà 30 phút trước còn xanh sạch. Một session làm buổi tối sẽ
    // mất thời gian truy một lỗi không tồn tại, và tệ hơn là quen dần với việc
    // "cái đó đỏ sẵn", lúc đó nó che mất lỗi thật.
    //
    // 03:00 UTC = 12:00 JST — nằm trong MỌI khung giờ mà file này dựng, nên cả
    // bảy test giữ nguyên ý nghĩa. Đây là luật đã ghi ở CLAUDE.md (#1091):
    // *"Time-dependent tests MUST freeze the clock"*.
    Carbon::setTestNow('2026-08-03 03:00:00');

    $this->branch = Branch::factory()->create(['console_organization_id' => ORG]);
    $this->zone = Zone::factory()->create(['organization_id' => ORG, 'branch_id' => $this->branch->id]);
    Table::factory()->create([
        'organization_id' => ORG,
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'fs-view-token',
        'is_active' => true,
    ]);

    $this->menu = Menu::factory()->create([
        'organization_id' => ORG,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'status' => 'Active',
        'priority' => 1,
    ]);

    /** Attach a section to the menu at a given display_order and put one product in it. */
    $this->addSection = function (string $name, int $displayOrder, float $price = 1000): array {
        $section = MenuSection::factory()->create(['name' => $name]);
        $this->menu->menuSections()->attach($section, ['display_order' => $displayOrder]);

        $product = Product::factory()->active()->create([
            'name' => $name.' item',
            'organization_id' => ORG,
        ]);
        $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => $price]);

        $menuProduct = MenuProduct::factory()->create([
            'menu_id' => $this->menu->id,
            'product_id' => $product->id,
            'menu_section_id' => $section->id,
            'is_active' => true,
            'display_order' => 0,
        ]);
        MenuProductSku::factory()->create([
            'menu_product_id' => $menuProduct->id,
            'product_sku_id' => $sku->id,
            'selling_price' => $price,
            'is_active' => true,
        ]);

        return ['section' => $section, 'product' => $product, 'sku' => $sku];
    };

    /** An always-on Floating Section discounting one SKU. */
    $this->addFloating = function (string $name, int $priority, Product $product, ProductSku $sku, float $price): FloatingSection {
        $floating = FloatingSection::factory()->create([
            'organization_id' => ORG,
            'brand_id' => $product->brand_id,
            'branch_id' => $this->branch->id,
            'name' => $name,
            'priority' => $priority,
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
            'selling_price' => $price,
            'is_active' => true,
            'is_price_overridden' => true,
        ]);

        return $floating;
    };
});

function menuCategories(): array
{
    return test()->getJson('/api/v1/customer/tables/fs-view-token/menu')
        ->assertOk()
        ->json('data.categories');
}

it('leads the view with the floating section without removing the item from its own section', function () {
    $drinks = ($this->addSection)('Drinks', 1);
    $floating = ($this->addFloating)('Happy hour', 0, $drinks['product'], $drinks['sku'], 650);

    $categories = menuCategories();

    expect($categories)->toHaveCount(2);

    // Promo leads.
    // The spotlight is built from the floating_section_* tables and prefixes
    // its id so it can never collide with a menu_section_id.
    expect($categories[0]['id'])->toBe('floating-section-'.$floating->id)
        ->and($categories[0]['name'])->toBe('Happy hour')
        ->and($categories[0]['is_floating_section'])->toBeTrue()
        ->and($categories[0]['items'])->toHaveCount(1)
        ->and((float) $categories[0]['items'][0]['price'])->toBe(650.0)
        ->and((float) $categories[0]['items'][0]['base_price'])->toBe(1000.0);

    // …and the item is still browsable under its own menu section, at the same
    // discounted price, so a customer navigating by category does not lose it.
    expect($categories[1]['id'])->toBe($drinks['section']->id)
        ->and($categories[1])->not->toHaveKey('is_floating_section')
        ->and($categories[1]['items'])->toHaveCount(1)
        ->and((float) $categories[1]['items'][0]['price'])->toBe(650.0);
});

it('orders several floating sections by priority ascending, smallest first', function () {
    $food = ($this->addSection)('Food', 1);
    $drinks = ($this->addSection)('Drinks', 2, 800);

    // Deliberately created in the "wrong" order so a stable sort cannot pass by luck.
    ($this->addFloating)('Late deal', 9, $food['product'], $food['sku'], 700);
    ($this->addFloating)('Early bird', 1, $drinks['product'], $drinks['sku'], 500);

    $categories = menuCategories();

    $promos = array_values(array_filter($categories, fn ($c) => $c['is_floating_section'] ?? false));

    expect($promos)->toHaveCount(2)
        ->and($promos[0]['name'])->toBe('Early bird')
        ->and($promos[1]['name'])->toBe('Late deal');

    // Both promos still precede every ordinary menu section.
    expect($categories[0]['name'])->toBe('Early bird')
        ->and($categories[1]['name'])->toBe('Late deal')
        ->and($categories[2]['is_floating_section'] ?? false)->toBeFalse();
});

it('orders the menu own sections by the display_order pivot', function () {
    // Created out of order on purpose: insertion order must not decide the view.
    ($this->addSection)('Desserts', 3);
    ($this->addSection)('Starters', 1);
    ($this->addSection)('Mains', 2);

    expect(array_column(menuCategories(), 'name'))->toBe(['Starters', 'Mains', 'Desserts']);
});

it('lists a discounted product once even when it sits in several menu sections', function () {
    // Caught on screen: the same product placed in both "Recommended" and
    // "Mains" was mirrored into the promo twice, so the promo section showed
    // six cards for three products.
    $mains = ($this->addSection)('Mains', 1);

    $recommended = MenuSection::factory()->create(['name' => 'Recommended']);
    $this->menu->menuSections()->attach($recommended, ['display_order' => 2]);
    $secondPlacement = MenuProduct::factory()->create([
        'menu_id' => $this->menu->id,
        'product_id' => $mains['product']->id,
        'menu_section_id' => $recommended->id,
        'is_active' => true,
        'display_order' => 0,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $secondPlacement->id,
        'product_sku_id' => $mains['sku']->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    ($this->addFloating)('Happy hour', 0, $mains['product'], $mains['sku'], 650);

    $categories = menuCategories();

    expect($categories[0]['is_floating_section'])->toBeTrue()
        ->and($categories[0]['items'])->toHaveCount(1);

    // The product still appears under each of its two menu sections.
    expect($categories[1]['items'])->toHaveCount(1)
        ->and($categories[2]['items'])->toHaveCount(1);
});

it('leaves the view untouched when no floating section is active', function () {
    ($this->addSection)('Drinks', 1);

    $categories = menuCategories();

    expect($categories)->toHaveCount(1)
        ->and($categories[0]['is_floating_section'] ?? false)->toBeFalse()
        ->and((float) $categories[0]['items'][0]['price'])->toBe(1000.0);
});

// =========================================================================
//  #1702 — item spotlight lấy ngữ cảnh menu của menu SỞ HỮU nó, không phải head
// =========================================================================

it('stamps the spotlight item with the menu that actually owns the SKU, not the head menu', function () {
    // Head menu (priority 1) đóng muộn; menu thứ hai (priority 2) đóng SỚM hơn.
    // Món được giảm giá nằm ở menu thứ hai. Trước #1702 item spotlight mượn hạn
    // giỏ của head ⇒ khách thêm vào giỏ vẫn đặt được sau khi menu của nó đã đóng.
    ($this->addSection)('Head section', 1);

    $late = MenuSchedule::factory()->create([
        'menu_id' => $this->menu->id,
        'start_time' => '00:00:00',
        'end_time' => '23:59:59',
        'days_of_week' => 127,
        'is_active' => true,
        'priority' => 1,
    ]);
    expect($late->exists)->toBeTrue();

    $takeaway = Menu::factory()->create([
        'organization_id' => ORG,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'name' => 'お持ち帰り',
        'status' => 'Active',
        'priority' => 2,
    ]);
    MenuSchedule::factory()->create([
        'menu_id' => $takeaway->id,
        'start_time' => '00:00:00',
        'end_time' => '21:30:00',
        'days_of_week' => 127,
        'is_active' => true,
        'priority' => 1,
    ]);

    $section = MenuSection::factory()->create(['name' => 'Takeaway section']);
    $takeaway->menuSections()->attach($section, ['display_order' => 1]);
    $product = Product::factory()->active()->create(['name' => 'Phở rau', 'organization_id' => ORG]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 1100]);
    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $takeaway->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'selling_price' => 1100,
        'is_active' => true,
    ]);

    ($this->addFloating)('Khung giờ ưu đãi', 0, $product, $sku, 800);

    $categories = menuCategories();
    $spotlight = $categories[0];

    expect($spotlight['is_floating_section'])->toBeTrue()
        ->and($spotlight['items'][0]['menu_id'])->toBe($takeaway->id)
        ->and($spotlight['items'][0]['menu_end_time'])->toBe('21:30:00')
        // Tên hiển thị vẫn là tên khung giờ ưu đãi — đó là cái khách thấy.
        ->and($spotlight['items'][0]['menu_name'])->toBe('Khung giờ ưu đãi');

    // Bản trong menu của chính nó mang cùng ngữ cảnh, nên hai đường vào giỏ
    // (bấm card spotlight vs bấm card trong section) khoá cùng một hạn.
    $ownSection = collect($categories)->firstWhere('name', 'Takeaway section');
    expect($ownSection['items'][0]['menu_id'])->toBe($takeaway->id)
        ->and($ownSection['items'][0]['item_deadline'])->toBe($spotlight['items'][0]['item_deadline']);
});

it('falls back to the head menu for a spotlight product that is in no menu at all', function () {
    $head = ($this->addSection)('Head section', 1);

    // Sản phẩm CHỈ nằm trong khung giờ ưu đãi — đúng mục đích của spotlight.
    $offMenu = Product::factory()->active()->create(['name' => 'Off-menu combo', 'organization_id' => ORG]);
    $offMenuSku = ProductSku::factory()->create(['product_id' => $offMenu->id, 'selling_price' => 900]);
    ($this->addFloating)('Khung giờ ưu đãi', 0, $offMenu, $offMenuSku, 700);

    $categories = menuCategories();
    $data = test()->getJson('/api/v1/customer/tables/fs-view-token/menu')->json('data');

    expect($categories[0]['is_floating_section'])->toBeTrue()
        ->and($categories[0]['items'][0]['menu_id'])->toBe($data['menu_id'])
        ->and($head['product']->exists)->toBeTrue();
});
