<?php

declare(strict_types=1);

/**
 * #1661 — mọi tầng thuế của #1218 phải làm TIẾN bản catalog của chi nhánh.
 *
 * ## Vì sao đây là lỗi TIỀN, không phải lỗi sổ sách
 *
 * `catalog_revisions.revision` **là số phiên bản của feed menu**:
 * `SyncManifestService` trả `'rev-'.$revision`, và #1175 dùng nó cho conditional
 * GET. Revision không tiến ⇒ workstation nhận **304** ⇒ nó giữ nguyên
 * `menu_items.tax_type_id` cũ ⇒ **in hoá đơn theo thuế suất cũ** trong khi Cloud
 * ghi sổ theo thuế suất mới.
 *
 * Và `menu_items.tax_type_id` là **bốn tầng gộp lại một cột**
 * (`CustomerMenuService`: menu-item → section-in-this-menu → whole menu →
 * product). Nên bất kỳ tầng nào trong bốn tầng đó đổi mà revision không tiến
 * đều là cùng một lỗi.
 *
 * ## Vì sao `markDirty` MỘT MÌNH không đủ — và đây là chỗ dễ vá hụt nhất
 *
 * `bumpFor()` chỉ mint bản mới khi **hash snapshot đổi** (BR-CR02). Trước #1661
 * snapshot chỉ mang tầng 1 (`menu_products.tax_type_id`), nên đổi tầng 2/3/4 để
 * lại hash Y HỆT và `markDirty` trở thành no-op — kể cả khi observer có chạy.
 *
 * Đó chính là ca tầng 3: `Menu` **có** được observe, `markDirty` **có** chạy, và
 * vẫn không có bản nào được mint. Một bản vá chỉ thêm nhánh observer cho tầng 2
 * sẽ vẫn xanh ở test "observer có gọi markDirty" mà **không sửa được gì cả**.
 * Nên các test dưới đây đo `revision`, không đo lời gọi.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Services\Catalog\CatalogRevisionService;
use App\Services\Product\MenuService;
use App\Services\Workstation\SyncManifestService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);
    $this->section = MenuSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->menu->menuSections()->attach([$this->section->id => ['display_order' => 1]]);

    $this->line = MenuProduct::factory()->create([
        'menu_id' => $this->menu->id,
        'product_id' => $this->product->id,
        'menu_section_id' => $this->section->id,
        'is_active' => true,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $this->line->id,
        'product_sku_id' => $this->sku->id,
        'selling_price' => 1000,
        'is_price_overridden' => true,
        'is_active' => true,
    ]);

    $this->taxA = TaxType::factory()->create([
        'brand_id' => $this->brand->id, 'code' => 'STD10', 'rate' => 10, 'is_active' => true,
    ]);
    $this->taxB = TaxType::factory()->create([
        'brand_id' => $this->brand->id, 'code' => 'RED8', 'rate' => 8, 'is_active' => true,
    ]);

    $this->revisions = app(CatalogRevisionService::class);
    $this->menus = app(MenuService::class);
});

function ttbRevision(): ?int
{
    return test()->revisions->currentFor(test()->branch->id)?->revision;
}

// =========================================================================
//  Bốn tầng gộp vào `menu_items.tax_type_id`
// =========================================================================

it('#1661 tầng 2 — đổi thuế của SECTION trong menu làm tiến bản catalog', function () {
    $before = ttbRevision();
    expect($before)->not->toBeNull();

    $this->menus->updateSectionTaxType($this->menu, (string) $this->section->id, (string) $this->taxB->id, (string) $this->brand->id);

    expect(ttbRevision())->toBe($before + 1);
});

it('#1661 tầng 3 — đổi thuế của CẢ MENU làm tiến bản catalog', function () {
    $before = ttbRevision();

    // `Menu` VỐN ĐÃ được observe, nên `markDirty` vẫn chạy trước #1661. Cái
    // thiếu là snapshot: hash không đổi ⇒ không mint. Test này đo bản mint, nên
    // nó bắt được đúng khoảng trống đó.
    $this->menu->update(['tax_type_id' => $this->taxB->id]);

    expect(ttbRevision())->toBe($before + 1);
});

it('#1661 tầng 4 — đổi thuế của SẢN PHẨM làm tiến bản catalog', function () {
    $before = ttbRevision();

    $this->product->update(['tax_type_id' => $this->taxB->id]);

    expect(ttbRevision())->toBe($before + 1);
});

it('#1661 tầng 1 vẫn tiến như trước — không hồi quy', function () {
    $before = ttbRevision();

    $this->line->update(['tax_type_id' => $this->taxB->id]);

    expect(ttbRevision())->toBe($before + 1);
});

// =========================================================================
//  Hai tầng dự phòng + thuế suất — đi vào `effective_tax_rate` của feed
// =========================================================================

it('#1661 tầng 5 — đổi thuế mặc định của CHI NHÁNH làm tiến bản catalog', function () {
    $before = ttbRevision();

    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->branch->id],
        ['organization_id' => $this->orgId, 'default_tax_type_id' => $this->taxB->id],
    );

    expect(ttbRevision())->toBe($before + 1);
});

it('#1661 tầng 6 — đổi loại thuế MẶC ĐỊNH của thương hiệu làm tiến bản catalog', function () {
    $before = ttbRevision();

    $this->taxB->update(['is_default' => true]);

    expect(ttbRevision())->toBe($before + 1);
});

it('#1661 đổi THUẾ SUẤT của một loại thuế làm tiến bản catalog', function () {
    $this->menu->update(['tax_type_id' => $this->taxA->id]);
    $before = ttbRevision();

    // Cùng loại thuế, khác con số. Feed chở `rate` trong `tax_types` và trong
    // `effective_tax_rate`, nên đây đổi đúng cái khách bị tính.
    $this->taxA->update(['rate' => 12]);

    expect(ttbRevision())->toBe($before + 1);
});

// =========================================================================
//  Hệ quả cuối: phiên bản FEED phải đổi theo — đó mới là thứ workstation thấy
// =========================================================================

it('#1661 phiên bản feed menu của workstation đổi theo tầng 2 — nếu không, thiết bị nhận 304', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    // Manifest được nhớ đệm trong một request; test đo hai lần nên phải xin bản
    // dựng mới, nếu không nó so một giá trị với chính nó và LUÔN xanh.
    $before = app(SyncManifestService::class)->manifestFor($device)['feeds']['menu'];

    $this->menus->updateSectionTaxType($this->menu, (string) $this->section->id, (string) $this->taxB->id, (string) $this->brand->id);

    app()->forgetInstance(SyncManifestService::class);
    $after = app(SyncManifestService::class)->manifestFor($device->fresh())['feeds']['menu'];

    expect($after)->not->toBe($before);
});

// =========================================================================
//  Sửa KHÔNG được đổi hành vi "sửa vặt thì không mint"
// =========================================================================

it('#1661 đổi thứ tự hiển thị của section KHÔNG mint bản mới — BR-CR02 giữ nguyên', function () {
    $before = ttbRevision();

    $this->menu->menuSections()->updateExistingPivot((string) $this->section->id, ['display_order' => 9]);

    expect(ttbRevision())->toBe($before);
});
