<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\FloatingSection;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\Zone;
use Carbon\Carbon;

/**
 * #1715 — TÁI HIỆN lỗi, không phải kiểm chứng bản vá.
 *
 * Trước khi tin rằng "khách thấy một giá, đơn tính một giá khác" có thật, file
 * này dựng đúng kịch bản của quán: một khung giờ ưu đãi 18:00-20:00 hạ ¥1,100
 * xuống ¥800; khách mở menu lúc 19:59, đặt lúc 20:01.
 *
 * Ca đầu tiên KHÔNG gửi `expected_unit_price` — tức là hành vi y hệt customer-web
 * trước bản vá. Nó phải PASS với một đơn được tạo ở ¥1,100 trong khi menu vừa
 * hiện ¥800: đó chính là lỗi, viết ra thành khẳng định.
 */
const REPRO_ORG = '00000000-0000-0000-0000-000000000001';

beforeEach(function () {
    $this->brand = Brand::factory()->create(['console_organization_id' => REPRO_ORG]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => REPRO_ORG,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->zone = Zone::factory()->create([
        'organization_id' => REPRO_ORG,
        'branch_id' => $this->branch->id,
    ]);
    Table::factory()->create([
        'organization_id' => REPRO_ORG,
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'repro-token',
        'is_active' => true,
        'status' => 'free',
    ]);

    // Menu thường: Phở rau ¥1,100.
    $menu = Menu::factory()->create([
        'organization_id' => REPRO_ORG,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'name' => '人形町店 メニュー',
        'status' => 'Active',
        'priority' => 1,
    ]);
    $section = MenuSection::factory()->create(['name' => 'Món nước']);
    $menu->menuSections()->attach($section, ['display_order' => 1]);

    $this->product = Product::factory()->active()->create([
        'name' => 'Phở rau',
        'organization_id' => REPRO_ORG,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'selling_price' => 1100,
    ]);
    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $this->product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $this->sku->id,
        'selling_price' => 1100,
        'is_active' => true,
    ]);

    // Khung giờ ưu đãi 18:00-20:00, hạ còn ¥800.
    $floating = FloatingSection::factory()->create([
        'organization_id' => REPRO_ORG,
        'brand_id' => $this->product->brand_id,
        'branch_id' => $this->branch->id,
        'name' => 'Khung giờ ưu đãi',
        'priority' => 0,
        'is_active' => true,
        'start_date' => null,
        'end_date' => null,
    ]);
    $floating->schedules()->create([
        'start_time' => '18:00:00',
        'end_time' => '20:00:00',
        'days_of_week' => 127,
        'is_active' => true,
        'priority' => 0,
    ]);
    $floatingProduct = $floating->products()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    $floatingProduct->skus()->create([
        'product_sku_id' => $this->sku->id,
        'selling_price' => 800,
        'is_active' => true,
        'is_price_overridden' => true,
    ]);

    $this->menuPrice = function (): float {
        $data = test()->getJson('/api/v1/customer/tables/repro-token/menu')->assertOk()->json('data');
        foreach ($data['categories'] as $category) {
            foreach ($category['items'] as $item) {
                if (($item['sku_id'] ?? null) === (string) test()->sku->id) {
                    return (float) $item['price'];
                }
            }
        }
        throw new RuntimeException('Không tìm thấy món trong menu.');
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

it('LỖI CÓ THẬT: khách xem 19:59 thấy ¥800, đặt 20:01 thì đơn ghi ¥1,100 và không ai báo gì', function () {
    // 19:59 — khung giờ ưu đãi đang chạy, menu hiện giá đã giảm.
    Carbon::setTestNow(Carbon::parse('2026-08-03 19:59:00', 'Asia/Tokyo'));
    $seenPrice = ($this->menuPrice)();
    expect($seenPrice)->toBe(800.0);

    // 20:01 — cửa sổ đã đóng. Khách bấm đặt, gửi ĐÚNG payload mà customer-web
    // gửi trước bản vá: chỉ sku + số lượng, không kèm giá đang hiển thị.
    Carbon::setTestNow(Carbon::parse('2026-08-03 20:01:00', 'Asia/Tokyo'));

    $this->postJson('/api/v1/customer/tables/repro-token/orders', [
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertStatus(201);

    $line = CustomerOrder::first()->items()->first();

    // Đây là lỗi, viết thành khẳng định: đơn được tạo, không lỗi, không cảnh báo,
    // ở một mức giá KHÁC hẳn cái khách vừa nhìn thấy.
    expect((float) $line->unit_price)->toBe(1100.0)
        ->and((float) $line->unit_price)->not->toBe($seenPrice);
});

it('menu THẬT SỰ trả giá khác nhau hai bên ranh giới cửa sổ', function () {
    // Chốt mắt xích đầu tiên của chuỗi: nếu backend không hạ/nâng giá quanh
    // 20:00 thì cả issue này vô nghĩa.
    Carbon::setTestNow(Carbon::parse('2026-08-03 19:59:59', 'Asia/Tokyo'));
    expect(($this->menuPrice)())->toBe(800.0);

    Carbon::setTestNow(Carbon::parse('2026-08-03 20:00:01', 'Asia/Tokyo'));
    expect(($this->menuPrice)())->toBe(1100.0);
});

it('BẢN VÁ: cùng kịch bản đó, gửi kèm giá đang hiển thị thì bị chặn thay vì âm thầm tính khác', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-03 19:59:00', 'Asia/Tokyo'));
    $seenPrice = ($this->menuPrice)();

    Carbon::setTestNow(Carbon::parse('2026-08-03 20:01:00', 'Asia/Tokyo'));

    $this->postJson('/api/v1/customer/tables/repro-token/orders', [
        'items' => [[
            'product_sku_id' => $this->sku->id,
            'quantity' => 1,
            'expected_unit_price' => $seenPrice,
        ]],
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'line_unit_price_drift')
        ->assertJsonPath('items.0.expected_unit_price', '800')
        ->assertJsonPath('items.0.actual_unit_price', '1100');

    // Và không đơn nào được sinh ra ở giá sai.
    expect(CustomerOrder::count())->toBe(0);
});

it('BẢN VÁ không chặn nhầm khi khách đặt TRONG khung giờ', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-03 19:00:00', 'Asia/Tokyo'));

    $this->postJson('/api/v1/customer/tables/repro-token/orders', [
        'items' => [[
            'product_sku_id' => $this->sku->id,
            'quantity' => 1,
            'expected_unit_price' => 800,
        ]],
    ])->assertStatus(201);

    expect((float) CustomerOrder::first()->items()->first()->unit_price)->toBe(800.0);
});
