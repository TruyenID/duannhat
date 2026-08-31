<?php

declare(strict_types=1);

/**
 * #962 · 7a-7 — đường GHI đơn thôi cầm bộ máy THUẾ của Pricing và bảng menu của Catalog.
 *
 * Nút này là một nút DUY NHẤT. `WritesCustomerOrders` import `TaxResolver` + `TaxType`
 * (Pricing) và `MenuProduct` + `Product` + `ToppingGroupItem` (Catalog) chỉ vì đúng một
 * lý do: nạp đối số cho `TaxResolver::resolveForLine(Product, ?TaxType, …)`. Gỡ lẻ một
 * cái là vô nghĩa — nên PR đổi cả cụm sang ba cổng, và bài này ghim HAI thứ khác nhau:
 *
 *  1. **Ranh giới** — bốn file Ordering không được import lại đám model/service đó.
 *  2. **Thuế** — lối vào-bằng-id phải ra ĐÚNG kết quả của lối vào-bằng-model, trên
 *     CHÍNH bộ case golden mà workstation (Go) cũng gate. Đây là phần dễ mất nhất:
 *     một PR "dọn dẹp" sau này viết lại chuỗi tầng trong adapter thì ranh giới vẫn
 *     sạch mà hoá đơn 適格請求書 thì sai — và sai lặng lẽ, vì tỉ lệ được đóng dấu
 *     BẤT BIẾN lên từng dòng đơn nên không có gì đối chiếu lại về sau.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Services\Customer\TaxResolver;
use App\Services\Order\Contracts\OrderLineTaxPricing;
use App\Services\Order\Contracts\OrderMenuLineDirectory;
use App\Services\Order\Contracts\OrderMenuLineTaxContext;
use App\Services\Order\Contracts\ToppingSelectionExistence;
use Illuminate\Support\Str;

/**
 * Bộ case golden của `TaxResolutionGoldenParityTest` — đọc lại bằng tên riêng để bài
 * này không phụ thuộc thứ tự nạp file của Pest.
 *
 * @return array<string, mixed>
 */
function taxGolden962(): array
{
    $path = __DIR__.'/../../Fixtures/tax_resolution_golden.json';

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

// =============================================================================
//  1. Ranh giới
// =============================================================================

it('cả ba cổng đều bind được — cổng không có hiện thực là cổng trang trí', function () {
    // #1544 đã trả giá đúng chỗ này: `OrderQueryPort` từng tồn tại mà không ai bind,
    // nên mọi caller tin nó đều ăn container exception rồi quay về import model.
    expect(app(OrderLineTaxPricing::class))->toBeInstanceOf(OrderLineTaxPricing::class)
        ->and(app(OrderMenuLineDirectory::class))->toBeInstanceOf(OrderMenuLineDirectory::class)
        ->and(app(ToppingSelectionExistence::class))->toBeInstanceOf(ToppingSelectionExistence::class);
});

it('bốn file Ordering KHÔNG còn import bộ máy thuế hay bảng menu', function (string $relative, array $forbidden) {
    $src = (string) file_get_contents(app_path($relative));

    foreach ($forbidden as $import) {
        expect($src)->not->toContain("use {$import};", "{$relative} nhận lại cạnh {$import}");
    }
})->with([
    'trait ghi đơn' => [
        'Services/Order/Internal/Concerns/WritesCustomerOrders.php',
        [
            'App\Services\Customer\TaxResolver',
            'App\Models\TaxType',
            'App\Models\MenuProduct',
            'App\Models\Product',
            'App\Models\ToppingGroupItem',
        ],
    ],
    // Trait bị deptrac tính MỘT LẦN CHO MỖI class dùng nó, nên file này thừa hưởng
    // cạnh của trait mà không tự import gì. Ghim luôn để ai thêm import ở đây thì
    // biết mình đang mọc lại cạnh vừa gỡ.
    'persistence dùng trait' => [
        'Services/Order/Internal/EloquentOrderPersistence.php',
        ['App\Services\Customer\TaxResolver', 'App\Models\TaxType', 'App\Models\MenuProduct', 'App\Models\Product', 'App\Models\ToppingGroupItem'],
    ],
    'resolver giá typed' => [
        'Services/Order/Internal/CustomerOrderPricingResolution.php',
        ['App\Services\Customer\TaxResolver'],
    ],
    'verifier đơn offline' => [
        'Services/Order/Internal/OfflineOrderEvidenceVerifier.php',
        ['App\Services\Customer\TaxResolver'],
    ],
]);

it('mỗi beginBatch() là một lô MỚI — memo không được sống xuyên thao tác', function () {
    // Docblock của `TaxResolver` nói thẳng: "create a fresh resolver per order
    // operation so the memo can't go stale mid-request". Bind adapter thành
    // `singleton` sẽ phá đúng luật đó mà không test nào khác thấy — một sửa thuế
    // giữa request sẽ bị memo cũ che mất.
    $pricing = app(OrderLineTaxPricing::class);

    expect($pricing->beginBatch())->not->toBe($pricing->beginBatch());
});

// =============================================================================
//  2. Thuế — lối vào bằng id phải KHỚP TUYỆT ĐỐI lối vào bằng model
// =============================================================================

it('giải thuế bằng id ra đúng kết quả của lối bằng model, trên mọi case golden', function (array $case) {
    // Bộ case này là hợp đồng liên-ngôn-ngữ với workstation (Go). Dùng lại nó ở đây
    // nghĩa là: cổng mới không thể lệch khỏi chuỗi tầng mà máy tính tiền offline
    // đang dùng, chứ không chỉ "không lệch khỏi chính nó".
    $golden = taxGolden962();

    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    $catalog = ($case['no_brand_default'] ?? false)
        ? []
        : array_merge($golden['tax_types'], $case['extra_tax_types'] ?? []);

    foreach ($catalog as $row) {
        TaxType::factory()->create([
            'id' => $row['id'],
            'organization_id' => $orgId,
            'brand_id' => $brand->id,
            'code' => $row['code'],
            'rate' => $row['rate'],
            'is_default' => $row['is_default'],
            'is_active' => $row['is_active'],
        ]);
    }

    $exists = fn (?string $id): bool => $id !== null
        && collect($catalog)->contains(fn (array $r): bool => $r['id'] === $id);

    ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'default_tax_type_id' => $exists($case['branch_default_tax_type_id']) ? $case['branch_default_tax_type_id'] : null,
        'prices_include_tax' => false,
        'currency_code' => 'JPY',
    ]);

    $productType = ProductType::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $product = Product::factory()->active()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'product_type_id' => $productType->id,
        'tax_type_id' => $exists($case['product_tax_type_id']) ? $case['product_tax_type_id'] : null,
    ]);

    $menuTypeId = $case['menu_tax_type_id'];
    $menuTaxType = $menuTypeId === null ? null : TaxType::find($menuTypeId);

    // Lối CŨ: model vào, model ra.
    $byModel = (new TaxResolver)->resolveForLine(
        $product->fresh(['taxType']),
        $menuTaxType,
        (string) $branch->id,
        (string) $brand->id,
    );

    // Lối MỚI: qua cổng, toàn scalar. Chú ý tầng 1 truyền `$menuTypeId` THÔ — kể cả
    // khi nó trỏ vào một type không giải ra được. Đó chính là chỗ hai lối có thể
    // lệch nếu hiện thực tự "kiểm tra tồn tại" thay vì để chuỗi tầng đi tiếp.
    $byId = app(OrderLineTaxPricing::class)->beginBatch()->resolveForLine(
        (string) $product->id,
        $product->tax_type_id,
        $menuTypeId,
        (string) $branch->id,
        (string) $brand->id,
    );

    expect($byId->taxTypeId)->toBe($byModel->taxTypeId, "lệch TYPE so với lối model. {$case['why']}")
        ->and($byId->rate)->toBe($byModel->rate, "lệch RATE so với lối model. {$case['why']}")
        // Và cả hai phải khớp cái mà hợp đồng golden tuyên bố — nếu chỉ so hai lối
        // với nhau thì một thay đổi làm HỎNG CẢ HAI vẫn xanh.
        ->and($byId->taxTypeId)->toBe($case['expect']['tax_type_id'], "lệch hợp đồng golden. {$case['why']}")
        ->and($byId->rate)->toBe((float) $case['expect']['rate'], "lệch hợp đồng golden. {$case['why']}");
})->with(fn () => collect(taxGolden962()['cases'])
    ->mapWithKeys(fn (array $case): array => [$case['name'] => [$case]])
    ->all());

// =============================================================================
//  3. Cổng tra dòng menu — ba mảnh truy vấn đều load-bearing
// =============================================================================

describe('OrderMenuLineDirectory', function () {
    beforeEach(function () {
        $this->orgId = (string) Str::uuid();
        Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
        $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        $this->branch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'is_active' => true,
        ]);
        $this->otherBranch = Branch::factory()->create([
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

        $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $this->product = Product::factory()->active()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
            'product_type_id' => $pt->id,
        ]);

        $this->menuOn = fn (Branch $b, ?string $taxTypeId = null): Menu => Menu::factory()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
            'branch_id' => $b->id, 'status' => 'Active', 'tax_type_id' => $taxTypeId,
        ]);

        $this->directory = app(OrderMenuLineDirectory::class);
    });

    it('không có dòng menu nào thì trả none(), không phải lỗi', function () {
        // Đơn off-menu là chuyện thường (kiosk, dòng tự do). Biến cái này thành
        // exception là chặn bán hàng.
        $ctx = $this->directory->taxContextForBranchProduct((string) $this->branch->id, (string) $this->product->id);

        expect($ctx)->toBeInstanceOf(OrderMenuLineTaxContext::class)
            ->and($ctx->menuId)->toBeNull()
            ->and($ctx->menuSectionId)->toBeNull()
            ->and($ctx->taxTypeId)->toBeNull();
    });

    it('menuProductId null cũng trả none()', function () {
        expect($this->directory->taxContextForMenuProduct(null)->taxTypeId)->toBeNull();
    });

    it('trả override tầng 1 của dòng menu — #1420, chỗ mà nhánh dự phòng từng bỏ qua', function () {
        $menu = ($this->menuOn)($this->branch, $this->reduced->id);
        MenuProduct::factory()->create([
            'menu_id' => $menu->id, 'product_id' => $this->product->id,
            'is_active' => true, 'tax_type_id' => $this->standard->id,
        ]);

        $ctx = $this->directory->taxContextForBranchProduct((string) $this->branch->id, (string) $this->product->id);

        expect($ctx->taxTypeId)->toBe($this->standard->id)
            ->and($ctx->menuId)->toBe($menu->id);
    });

    it('BỎ QUA dòng menu đã tắt — is_active không phải trang trí', function () {
        $menu = ($this->menuOn)($this->branch);
        MenuProduct::factory()->create([
            'menu_id' => $menu->id, 'product_id' => $this->product->id,
            'is_active' => false, 'tax_type_id' => $this->standard->id,
        ]);

        expect($this->directory->taxContextForBranchProduct((string) $this->branch->id, (string) $this->product->id)->menuId)
            ->toBeNull();
    });

    it('BỎ QUA menu của chi nhánh khác — cùng một SKU nằm trên 16+ menu ở staging (#514)', function () {
        $foreign = ($this->menuOn)($this->otherBranch);
        MenuProduct::factory()->create([
            'menu_id' => $foreign->id, 'product_id' => $this->product->id,
            'is_active' => true, 'tax_type_id' => $this->standard->id,
        ]);

        expect($this->directory->taxContextForBranchProduct((string) $this->branch->id, (string) $this->product->id)->menuId)
            ->toBeNull();
    });

    it('nhiều dòng hợp lệ thì chọn theo id — không có orderBy là re-resolve ra hai tỉ lệ khác nhau', function () {
        $a = ($this->menuOn)($this->branch);
        $b = ($this->menuOn)($this->branch);
        $lines = collect([
            MenuProduct::factory()->create([
                'menu_id' => $a->id, 'product_id' => $this->product->id,
                'is_active' => true, 'tax_type_id' => $this->standard->id,
            ]),
            MenuProduct::factory()->create([
                'menu_id' => $b->id, 'product_id' => $this->product->id,
                'is_active' => true, 'tax_type_id' => $this->reduced->id,
            ]),
        ]);
        $expected = $lines->sortBy(fn (MenuProduct $l): string => (string) $l->id)->first();

        $ctx = $this->directory->taxContextForBranchProduct((string) $this->branch->id, (string) $this->product->id);

        expect($ctx->taxTypeId)->toBe($expected->tax_type_id)
            // Và ổn định: gọi lại phải ra y hệt.
            ->and($this->directory->taxContextForBranchProduct((string) $this->branch->id, (string) $this->product->id)->taxTypeId)
            ->toBe($ctx->taxTypeId);
    });

    it('tra ĐÍCH DANH một dòng menu trả menu + section + tầng 1 của chính dòng đó', function () {
        $menu = ($this->menuOn)($this->branch, $this->reduced->id);
        $line = MenuProduct::factory()->create([
            'menu_id' => $menu->id, 'product_id' => $this->product->id,
            'is_active' => true, 'tax_type_id' => $this->standard->id,
        ]);

        $ctx = $this->directory->taxContextForMenuProduct((string) $line->id);

        expect($ctx->menuId)->toBe($menu->id)
            ->and($ctx->menuSectionId)->toBe($line->menu_section_id)
            ->and($ctx->taxTypeId)->toBe($this->standard->id);
    });

    it('dòng menu trỏ vào TaxType đã xoá mềm ⇒ tầng 1 rỗng, chuỗi tầng đi tiếp', function () {
        // Lối cũ đọc `$menuLine->taxType` (quan hệ, có SoftDeletingScope) chứ không
        // đọc cột `tax_type_id`. Hiện thực nào "tối ưu" thành đọc cột thẳng sẽ giữ
        // lại một type đã xoá và đóng dấu tỉ lệ chết lên đơn.
        $menu = ($this->menuOn)($this->branch);
        $line = MenuProduct::factory()->create([
            'menu_id' => $menu->id, 'product_id' => $this->product->id,
            'is_active' => true, 'tax_type_id' => $this->standard->id,
        ]);
        $this->standard->delete();

        $ctx = $this->directory->taxContextForMenuProduct((string) $line->id);

        expect($ctx->taxTypeId)->toBeNull()
            ->and($ctx->menuId)->toBe($menu->id);
    });
});

// =============================================================================
//  4. Cổng kiểm tham chiếu topping
// =============================================================================

it('selectionExists đúng cho cặp có thật, và false khi thiếu một vế', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $pt = ProductType::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $product = Product::factory()->active()->create([
        'organization_id' => $orgId, 'brand_id' => $brand->id, 'product_type_id' => $pt->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true]);
    $group = ToppingGroup::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $item = ToppingGroupItem::factory()->create(['topping_group_id' => $group->id]);

    $port = app(ToppingSelectionExistence::class);
    $ghost = (string) Str::uuid();

    expect($port->selectionExists((string) $item->id, (string) $sku->id))->toBeTrue()
        ->and($port->selectionExists($ghost, (string) $sku->id))->toBeFalse()
        ->and($port->selectionExists((string) $item->id, $ghost))->toBeFalse();

    // Xoá mềm phải tính là KHÔNG tồn tại: topping đã gỡ khỏi catalog không được
    // ghi thành dòng đơn mới, kể cả trên đường replay của máy trạm.
    $item->delete();
    expect($port->selectionExists((string) $item->id, (string) $sku->id))->toBeFalse();
});
