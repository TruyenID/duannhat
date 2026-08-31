<?php

declare(strict_types=1);

/**
 * #962 — hai cạnh cuối mà bộ máy thuế và đường replay OFFLINE còn cầm bảng của Catalog.
 *
 * Trước bản vá này:
 *
 *  · `TaxResolver` (Pricing) tự đọc `menus` (tầng 3) và pivot `menu_menu_sections`
 *    (tầng 2) — hai model Catalog nằm ngay giữa động cơ thuế.
 *  · `OfflineOrderEvidenceVerifier` (Ordering) tự đọc `menu_product_skus` để phục
 *    hồi định danh dòng menu của một đơn đã bán offline.
 *
 * Cả hai giờ đi qua cổng: `MenuTaxTypeAnchors` (Pricing KHAI, Catalog HIỆN THỰC) và
 * `OrderLineCatalogAnchors::menuLine()` (cổng sẵn có, thêm một phép tra KHÔNG phạm vi).
 *
 * Bài này ghim BA thứ, và chỉ thứ nhất là "kiến trúc":
 *
 *  1. **Ranh giới** — hai file đó không được import lại đám model Catalog, và cổng
 *     không được rò model ra chữ ký.
 *  2. **Ngữ nghĩa của cổng** — `taxType` phải đọc qua QUAN HỆ (loại thuế xoá mềm ⇒
 *     tầng RỖNG, chuỗi đi tiếp), tầng 2 phải theo CẶP (menu, mục) chứ không phải
 *     thuộc tính toàn cục của mục, và `menuLine()` phải KHÔNG lọc `is_active`.
 *  3. **Chuỗi tầng không đổi** — `TaxResolver` sau khi đi qua cổng vẫn phải ra đúng
 *     thứ tự #1218. Đây là phần dễ mất nhất: ranh giới sạch mà hoá đơn sai thì
 *     không có gì đỏ lên, vì tỉ lệ được đóng dấu BẤT BIẾN lên từng dòng đơn.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\TaxType;
use App\Services\Customer\TaxResolver;
use App\Services\Order\Contracts\OrderLineCatalogAnchors;
use App\Services\Tax\Contracts\MenuTaxTypeAnchors;
use Illuminate\Support\Str;

// =============================================================================
//  1. Ranh giới
// =============================================================================

it('cổng bind được — cổng không có hiện thực là cổng trang trí', function () {
    expect(app(MenuTaxTypeAnchors::class))->toBeInstanceOf(MenuTaxTypeAnchors::class);
});

it('hai file tiêu thụ KHÔNG còn import model Catalog', function (string $relative, array $forbidden) {
    $src = (string) file_get_contents(app_path($relative));

    foreach ($forbidden as $import) {
        expect($src)->not->toContain("use {$import};", "{$relative} nhận lại cạnh {$import}");
    }
})->with([
    'động cơ thuế' => [
        'Services/Customer/TaxResolver.php',
        ['App\Models\Menu', 'App\Models\MenuMenuSection'],
    ],
    'replay đơn offline' => [
        'Services/Order/Internal/OfflineOrderEvidenceVerifier.php',
        ['App\Models\MenuProductSku'],
    ],
]);

it('cổng KHÔNG được nhận hay trả model — port rò model là cạnh cũ khoác áo interface', function () {
    // `PublishedContracts` chỉ được phụ thuộc hai kernel, nên một chữ ký mang
    // `TaxType` hay `Menu` sẽ đỏ ở deptrac. Ghim luôn bằng chữ để lý do đọc được
    // ngay tại chỗ, không phải suy ra từ một baseline mới.
    $src = (string) file_get_contents(app_path('Services/Tax/Contracts/MenuTaxTypeAnchors.php'));

    // Bỏ chú thích trước khi soi: docblock của cổng này CÓ nhắc `App\Models\TaxType`
    // đúng để giải thích vì sao nó không được xuất hiện trong chữ ký. Soi cả file
    // thì lời giải thích tự làm bài test đỏ.
    $code = implode('', array_map(
        static fn (array|string $t): string => is_string($t) ? $t
            : (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]),
        token_get_all($src),
    ));

    expect($code)->not->toMatch('/\bApp\\\\Models\\\\/')
        // `{@see}` bị `pint` kéo thành `use` thật, và một PublishedContracts phụ
        // thuộc PublishedContracts khác cũng đỏ. Đã trả giá đúng ở file này.
        ->and($code)->not->toContain('use App\\');
});

// =============================================================================
//  2. Ngữ nghĩa của cổng
// =============================================================================

describe('MenuTaxTypeAnchors', function () {
    beforeEach(function () {
        $this->orgId = (string) Str::uuid();
        $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        $this->branch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
        ]);

        $this->std = TaxType::factory()->standard()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        ]);
        $this->red = TaxType::factory()->reduced()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        ]);

        $this->section = MenuSection::factory()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        ]);

        $this->makeMenu = fn (?string $taxTypeId = null): Menu => Menu::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'tax_type_id' => $taxTypeId,
        ]);

        $this->anchors = app(MenuTaxTypeAnchors::class);
    });

    it('tầng 3 trả id loại thuế của cả menu; menu không khai thì null', function () {
        expect($this->anchors->taxTypeIdForMenu((string) ($this->makeMenu)($this->red->id)->id))
            ->toBe((string) $this->red->id)
            ->and($this->anchors->taxTypeIdForMenu((string) ($this->makeMenu)()->id))->toBeNull()
            ->and($this->anchors->taxTypeIdForMenu((string) Str::uuid()))->toBeNull();
    });

    it('tầng 2 đọc PIVOT (menu, mục) — cùng mục trong menu khác KHÔNG dính', function () {
        // Giá trị nằm trên `menu_menu_sections`, không nằm trên `menu_sections`:
        // một mục được bày trong nhiều menu, nên một cột đặt trên chính mục đó sẽ
        // rò tỉ lệ của menu này sang mọi menu khác đang bày nó (#1218).
        $withRate = ($this->makeMenu)();
        $withRate->menuSections()->attach($this->section->id, [
            'tax_type_id' => $this->std->id, 'display_order' => 0,
        ]);
        $without = ($this->makeMenu)();
        $without->menuSections()->attach($this->section->id, ['display_order' => 0]);

        expect($this->anchors->taxTypeIdForMenuSection((string) $withRate->id, (string) $this->section->id))
            ->toBe((string) $this->std->id)
            ->and($this->anchors->taxTypeIdForMenuSection((string) $without->id, (string) $this->section->id))
            ->toBeNull()
            // Không có hàng pivot nào ⇒ null, không phải lỗi.
            ->and($this->anchors->taxTypeIdForMenuSection((string) ($this->makeMenu)()->id, (string) $this->section->id))
            ->toBeNull();
    });

    it('TaxType đã xoá mềm ⇒ CẢ HAI tầng rỗng, chuỗi tầng đi tiếp', function () {
        // Lối cũ đọc quan hệ `taxType` (có `SoftDeletingScope`), không đọc cột. Một
        // hiện thực "tối ưu" thành `value('tax_type_id')` sẽ đóng dấu một tỉ lệ chết
        // lên đơn — hỏng lúc chạy, và tỉ lệ là snapshot bất biến nên không có gì
        // đối chiếu lại về sau.
        $menu = ($this->makeMenu)($this->red->id);
        $menu->menuSections()->attach($this->section->id, [
            'tax_type_id' => $this->std->id, 'display_order' => 0,
        ]);

        $this->red->delete();
        $this->std->delete();

        expect($this->anchors->taxTypeIdForMenu((string) $menu->id))->toBeNull()
            ->and($this->anchors->taxTypeIdForMenuSection((string) $menu->id, (string) $this->section->id))
            ->toBeNull();
    });
});

describe('OrderLineCatalogAnchors::menuLine', function () {
    beforeEach(function () {
        $this->orgId = (string) Str::uuid();
        $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        $this->branch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
        ]);
        $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $this->product = Product::factory()->active()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
        ]);
        $this->sku = ProductSku::factory()->create(['product_id' => $this->product->id, 'is_active' => true]);

        $this->menu = Menu::factory()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id, 'status' => 'Active',
        ]);
        $this->menuProduct = MenuProduct::factory()->create([
            'menu_id' => $this->menu->id, 'product_id' => $this->product->id, 'is_active' => true,
        ]);

        $this->anchors = app(OrderLineCatalogAnchors::class);
    });

    it('KHÔNG lọc is_active và KHÔNG phạm vi chi nhánh — đơn offline đã bán rồi', function () {
        // Giá đã chốt trong `catalog_revisions` từ lúc bán. Thêm bất kỳ bộ lọc nào
        // vào đây là từ chối một đơn ngay tình chỉ vì cửa hàng đã tắt món đó SAU
        // khi khách trả tiền.
        $off = MenuProductSku::factory()->create([
            'menu_product_id' => $this->menuProduct->id,
            'product_sku_id' => $this->sku->id,
            'selling_price' => 420,
            'is_active' => false,
        ]);

        $anchor = $this->anchors->menuLine((string) $off->id);

        expect($anchor)->not->toBeNull()
            ->and($anchor->menuProductSkuId)->toBe((string) $off->id)
            ->and($anchor->productSkuId)->toBe((string) $this->sku->id)
            ->and($anchor->menuProductId)->toBe((string) $this->menuProduct->id)
            ->and($anchor->menuId)->toBe((string) $this->menu->id)
            ->and($anchor->brandId)->toBe((string) $this->brand->id);
    });

    it('hàng không còn ⇒ null, để chỗ gọi phân biệt "đã xoá" với "trỏ sang SKU khác"', function () {
        // `offline_menu_line_deleted` và `offline_menu_line_repointed` là hai
        // reason_code khác nhau; gộp chúng làm một là mất thông tin điều tra.
        expect($this->anchors->menuLine((string) Str::uuid()))->toBeNull();
    });
});

// =============================================================================
//  3. Chuỗi tầng #1218 không đổi sau khi đi qua cổng
// =============================================================================

it('TaxResolver vẫn đi ĐÚNG thứ tự tầng mục → menu → sản phẩm sau khi qua cổng', function () {
    $orgId = (string) Str::uuid();
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
    ]);
    $pt = ProductType::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);

    $std = TaxType::factory()->standard()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $red = TaxType::factory()->reduced()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $exempt = TaxType::factory()->exempt()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);

    $product = Product::factory()->create([
        'organization_id' => $orgId, 'brand_id' => $brand->id,
        'product_type_id' => $pt->id, 'tax_type_id' => $exempt->id,
    ])->load('taxType');

    $section = MenuSection::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $menu = Menu::factory()->create([
        'organization_id' => $orgId, 'brand_id' => $brand->id,
        'branch_id' => $branch->id, 'tax_type_id' => $red->id,
    ]);

    $resolve = fn (?MenuSection $s) => (new TaxResolver)->resolveForLine(
        $product, null, (string) $branch->id, (string) $brand->id, (string) $menu->id, $s?->id,
    )->rate;

    // Không mục ⇒ tầng 3 (cả menu) thắng sản phẩm 非課税. Đây là nửa GÂY BẤT NGỜ của
    // phán quyết #1218 và là chỗ người đọc sau dễ "sửa" nhất.
    expect($resolve(null))->toBe(8.0);

    $menu->menuSections()->attach($section->id, ['tax_type_id' => $std->id, 'display_order' => 0]);

    // Có mục ⇒ tầng 2 thắng tầng 3.
    expect($resolve($section))->toBe(10.0);
});
