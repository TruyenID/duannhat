<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Menu;
use App\Models\MenuMenuSection;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Support\Str;

/*
 * RULING (chủ dự án, 2026-08-11): **軽減税率 8% CHỈ đến từ MENU MANG VỀ. Sản
 * phẩm mặc định 10%. Lệch khỏi đó là BUG.**
 *
 * `SingleRateTaxContractTest` đã ghim phần HÀNH VI: cùng một hộp bentō đi qua
 * menu tại quán trả ¥100 thuế, đi qua menu mang về trả ¥80. File này ghim phần
 * còn lại — **8% được phép chảy vào từ ĐÂU** — vì chuỗi phân giải có tận sáu
 * tầng và năm tầng trong đó KHÔNG phải menu:
 *
 *   1. MenuProduct  ← hợp lệ (dòng menu mang về)
 *   2. MenuMenuSection ← hợp lệ (nhóm món trong menu mang về)
 *   3. Menu         ← hợp lệ (cả menu mang về)
 *   4. Product      ← BUG nếu là REDUCED: khách ăn TẠI QUÁN cũng thành 8%
 *   5. branch default ← BUG nếu là REDUCED: MỌI món không có menu override
 *   6. brand default  ← BUG nếu là REDUCED: cả thương hiệu
 *
 * Ba tầng dưới không có cổng nào chặn — `TaxResolver` cố ý không biết order
 * type (#1099), nên nó KHÔNG thể tự phát hiện "8% này đến từ chỗ sai". Cái
 * chặn phải nằm ở dữ liệu, và đó là file này.
 *
 * Đây không phải lo xa: ảnh chụp catalog Betoya từng mang 13 sản phẩm gán
 * REDUCED thẳng trên `products` (#2320 gọi nó là lỗi THU VƯỢT khi nó bị san
 * phẳng ngược lại). Theo ruling trên, gán 8% thẳng lên sản phẩm là sai NGAY TỪ
 * ĐẦU — đúng chiều là để sản phẩm ở 10% và cho menu mang về mang override.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->standard = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'code' => 'STANDARD', 'rate' => 10, 'is_default' => true,
    ]);
    $this->reduced = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'code' => 'REDUCED', 'rate' => 8,
    ]);

    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'default_tax_type_id' => $this->standard->id,
        'prices_include_tax' => false,
        'currency_code' => 'JPY',
    ]);

    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    // Phở ¥1,000 — món ăn thật của quán, mặc định 10% theo ruling.
    $this->pho = Product::factory()->active()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id, 'tax_type_id' => $this->standard->id,
    ]);
    $this->phoSku = ProductSku::factory()->create([
        'product_id' => $this->pho->id, 'selling_price' => 1000, 'is_active' => true,
    ]);
});

/** Dựng một menu + dòng menu cho phở, trả về MenuProductSku để đặt hàng. */
function takeawayRulingMenuLine(?string $menuTaxTypeId, ?string $lineTaxTypeId = null): MenuProductSku
{
    $menu = Menu::factory()->create([
        'organization_id' => test()->orgId, 'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id, 'status' => 'Active',
        'tax_type_id' => $menuTaxTypeId,
    ]);
    $line = MenuProduct::factory()->create([
        'menu_id' => $menu->id, 'product_id' => test()->pho->id,
        'is_active' => true, 'tax_type_id' => $lineTaxTypeId,
    ]);

    return MenuProductSku::factory()->create([
        'menu_product_id' => $line->id, 'product_sku_id' => test()->phoSku->id,
        'selling_price' => 1000, 'is_price_overridden' => true, 'is_active' => true,
    ]);
}

/** Đặt 1 phở qua đúng dòng menu chỉ định, trả về thuế đã snapshot lên dòng đơn. */
function taxOnLineVia(MenuProductSku $menuSku, string $orderType = 'dine_in'): array
{
    $order = app(CustomerOrderService::class)->create([
        'order_type' => $orderType,
        'status' => 'open',
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
    app(CustomerOrderService::class)->addItems($order, ['items' => [[
        'product_sku_id' => test()->phoSku->id,
        'menu_product_sku_id' => $menuSku->id,
        'quantity' => 1,
    ]]]);

    $line = CustomerOrder::find($order->id)->items()->first();

    return ['rate' => (float) $line->tax_rate, 'amount' => (float) $line->tax_amount];
}

// =========================================================================
//  Ba tầng ĐƯỢC PHÉP phát ra 8% — cả ba đều là menu
// =========================================================================

it('menu MANG VỀ mang REDUCED thì phở ra 8% (tầng 3 — cả menu)', function () {
    expect(taxOnLineVia(takeawayRulingMenuLine($this->reduced->id)))
        ->toBe(['rate' => 8.0, 'amount' => 80.0]);
});

it('dòng menu mang REDUCED thì ra 8% dù menu không khai gì (tầng 1)', function () {
    expect(taxOnLineVia(takeawayRulingMenuLine(null, $this->reduced->id)))
        ->toBe(['rate' => 8.0, 'amount' => 80.0]);
});

it('nhóm món trong menu mang REDUCED thì ra 8% (tầng 2 — pivot menu_menu_sections)', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id, 'status' => 'Active', 'tax_type_id' => null,
    ]);
    $section = MenuSection::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
    ]);
    MenuMenuSection::factory()->create([
        'menu_id' => $menu->id, 'menu_section_id' => $section->id,
        'tax_type_id' => $this->reduced->id,
    ]);
    $line = MenuProduct::factory()->create([
        'menu_id' => $menu->id, 'product_id' => $this->pho->id,
        'menu_section_id' => $section->id, 'is_active' => true, 'tax_type_id' => null,
    ]);
    $menuSku = MenuProductSku::factory()->create([
        'menu_product_id' => $line->id, 'product_sku_id' => $this->phoSku->id,
        'selling_price' => 1000, 'is_price_overridden' => true, 'is_active' => true,
    ]);

    expect(taxOnLineVia($menuSku))->toBe(['rate' => 8.0, 'amount' => 80.0]);
})->skip(fn () => ! class_exists(MenuMenuSection::class), 'pivot model chưa tồn tại ở nhánh này');

// =========================================================================
//  Không có menu nào nói 8% ⇒ 10%. Đây là mặc định phải giữ.
// =========================================================================

it('menu TẠI QUÁN không khai gì thì phở ra 10% — thừa kế sản phẩm', function () {
    expect(taxOnLineVia(takeawayRulingMenuLine(null)))
        ->toBe(['rate' => 10.0, 'amount' => 100.0]);
});

it('đơn MANG ĐI qua menu tại quán vẫn 10% — loại đơn KHÔNG quyết định thuế', function () {
    // #1099: đổi loại đơn không bao giờ re-price. 8% phải đến từ menu khách
    // thực sự đặt, không phải từ chữ "takeaway" trên header đơn.
    expect(taxOnLineVia(takeawayRulingMenuLine(null), 'takeaway'))
        ->toBe(['rate' => 10.0, 'amount' => 100.0]);
});

it('dòng menu mang STANDARD thắng menu REDUCED — một món trong menu mang về vẫn 10% được', function () {
    // Ví dụ đời thật: bia trong menu mang về. 酒類 không được hưởng 軽減税率.
    expect(taxOnLineVia(takeawayRulingMenuLine($this->reduced->id, $this->standard->id)))
        ->toBe(['rate' => 10.0, 'amount' => 100.0]);
});

// =========================================================================
//  Ba tầng KHÔNG được phép phát ra 8% — ghim hậu quả để nói rõ vì sao
// =========================================================================

it('BUG SHAPE: REDUCED gán thẳng lên SẢN PHẨM làm khách ăn TẠI QUÁN cũng chỉ chịu 8%', function () {
    // Không có menu nào nói gì; 8% rò lên từ tầng 4.
    $this->pho->update(['tax_type_id' => $this->reduced->id]);

    expect(taxOnLineVia(takeawayRulingMenuLine(null)))
        ->toBe(['rate' => 8.0, 'amount' => 80.0]);

    // Đúng cái mà bất biến dữ liệu bên dưới tồn tại để chặn: `TaxResolver` cố ý
    // không biết order type nên KHÔNG thể tự phát hiện chỗ này sai.
})->group('bug-shape');

it('BUG SHAPE: REDUCED làm mặc định của CHI NHÁNH làm mọi món không có menu override thành 8%', function () {
    $this->pho->update(['tax_type_id' => null]);
    ShopOrderSetting::where('branch_id', $this->branch->id)
        ->update(['default_tax_type_id' => $this->reduced->id]);

    expect(taxOnLineVia(takeawayRulingMenuLine(null)))
        ->toBe(['rate' => 8.0, 'amount' => 80.0]);
})->group('bug-shape');

it('BUG SHAPE: REDUCED là is_default của THƯƠNG HIỆU làm cả brand thành 8%', function () {
    $this->pho->update(['tax_type_id' => null]);
    ShopOrderSetting::where('branch_id', $this->branch->id)
        ->update(['default_tax_type_id' => null]);
    $this->standard->update(['is_default' => false]);
    $this->reduced->update(['is_default' => true]);

    expect(taxOnLineVia(takeawayRulingMenuLine(null)))
        ->toBe(['rate' => 8.0, 'amount' => 80.0]);
})->group('bug-shape');

// =========================================================================
//  BẤT BIẾN DỮ LIỆU — cái thật sự chặn ba hình dạng lỗi trên
// =========================================================================

it('ảnh chụp catalog KHÔNG gán 軽減税率 thẳng lên sản phẩm nào', function () {
    $products = json_decode(
        (string) file_get_contents(__DIR__.'/../../../database/seeders/fixtures/catalog/products.json'),
        true, flags: JSON_THROW_ON_ERROR,
    );
    $taxTypes = json_decode(
        (string) file_get_contents(__DIR__.'/../../../database/seeders/fixtures/catalog/tax_types.json'),
        true, flags: JSON_THROW_ON_ERROR,
    );

    $reducedIds = array_column(
        array_filter($taxTypes, fn (array $t) => ($t['code'] ?? '') === 'REDUCED'),
        'id',
    );
    expect($reducedIds)->not->toBeEmpty('fixture phải khai REDUCED, nếu không bài test này chẳng ghim gì');

    $offenders = array_values(array_filter(
        $products,
        fn (array $p) => in_array($p['tax_type_id'] ?? null, $reducedIds, true),
    ));

    expect($offenders)->toBe([], sprintf(
        '%d sản phẩm gán 軽減税率 8%% thẳng trên products. 8%% chỉ được đến từ MENU MANG VỀ '
        .'(ruling 2026-08-11) — gán trên sản phẩm thì khách ăn tại quán cũng chỉ chịu 8%%. '
        .'Chuyển override sang menu mang về. Món: %s',
        count($offenders),
        implode(', ', array_map(fn (array $p) => (string) ($p['name'] ?? $p['id']), array_slice($offenders, 0, 10))),
    ));
});

it('ảnh chụp catalog KHÔNG lấy 軽減税率 làm mặc định của chi nhánh hay thương hiệu', function () {
    $taxTypes = json_decode(
        (string) file_get_contents(__DIR__.'/../../../database/seeders/fixtures/catalog/tax_types.json'),
        true, flags: JSON_THROW_ON_ERROR,
    );

    $reduced = array_values(array_filter($taxTypes, fn (array $t) => ($t['code'] ?? '') === 'REDUCED'));
    expect($reduced)->not->toBeEmpty();

    foreach ($reduced as $type) {
        expect((bool) ($type['is_default'] ?? false))->toBeFalse(
            'REDUCED không được là is_default của brand — mọi món không có menu override sẽ thành 8%.',
        );
    }

    $reducedIds = array_column($reduced, 'id');
    $settingsPath = __DIR__.'/../../../database/seeders/fixtures/catalog/shop_order_settings.json';
    if (! file_exists($settingsPath)) {
        return; // fixture không chụp bảng này ở nhánh hiện tại
    }

    foreach (json_decode((string) file_get_contents($settingsPath), true, flags: JSON_THROW_ON_ERROR) as $row) {
        expect(in_array($row['default_tax_type_id'] ?? null, $reducedIds, true))->toBeFalse(
            'default_tax_type_id của chi nhánh không được là REDUCED.',
        );
    }
});
