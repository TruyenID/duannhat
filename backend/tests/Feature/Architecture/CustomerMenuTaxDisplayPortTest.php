<?php

declare(strict_types=1);

/**
 * #1596 — `CustomerMenuService` + `MenuLocalizationIntegrityReporter` về đúng
 * module của chúng (Catalog), và cạnh thuế còn lại đi qua cổng công bố.
 *
 * Hai class đó dựng THỰC ĐƠN: toàn bộ từ vựng của chúng là `Menu`, `MenuProduct`,
 * `Product`, `ToppingGroupItem`, `FloatingSection` — bảng của Catalog. Khai lại
 * chủ sở hữu gỡ 14/16 vi phạm cấp class mà không đổi một dòng hành vi nào. Hai
 * cạnh còn lại thì KHÔNG được gỡ bằng cách khai lại, nên chúng được TRẢ:
 *
 *   · `new TaxResolver` (Pricing)  ⇒ cổng `MenuDisplayTaxRates`
 *   · `ProductReviewService::recommendPercent()` (CustomerEngagement)
 *     ⇒ `Product::recommendPercent()`, nơi hai cột `review_*` vốn đã nằm
 *
 * Deptrac đã đo phần RANH GIỚI. Bài này ghim phần deptrac KHÔNG đo được: chuyển
 * bộ giải thuế ra sau một interface làm đồ thị xanh **kể cả khi kết quả sai**, và
 * tỉ lệ sai ở đây không kêu — khách thấy 総額表示 một đằng, hoá đơn in một nẻo.
 *
 * Vì sao cổng RIÊNG chứ không dùng lại `OrderLineTaxPricing`: cổng kia cố ý phát
 * cảnh báo "không tầng nào giải ra tỉ lệ" (= sắp thu thiếu thuế). Endpoint thực
 * đơn chạy ở MỌI lượt xem trang, nên dùng chung sẽ chôn những lần xảy ra thật
 * dưới lưu lượng hiển thị. Đó là một QUYẾT ĐỊNH, nên nó có bài test riêng bên
 * dưới — nếu không, người sau "gộp cho gọn" sẽ không thấy mình vừa phá cái gì.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Services\Customer\TaxResolver;
use App\Services\Tax\Contracts\MenuDisplayTaxRateBatch;
use App\Services\Tax\Contracts\MenuDisplayTaxRates;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Bộ case golden của `TaxResolutionGoldenParityTest` — hợp đồng liên-ngôn-ngữ với
 * workstation (Go). Đọc lại bằng tên riêng để bài này không phụ thuộc thứ tự nạp
 * file của Pest.
 *
 * @return array<string, mixed>
 */
function taxGolden1596(): array
{
    $path = __DIR__.'/../../Fixtures/tax_resolution_golden.json';

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Dựng org/brand/branch/product cho MỘT case golden và trả về đủ thứ để gọi cả
 * hai lối vào.
 *
 * @param  array<string, mixed>  $case
 * @return array{0: Branch, 1: Brand, 2: Product}
 */
function displayRateWorld1596(array $case): array
{
    $golden = taxGolden1596();

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

    return [$branch, $brand, $product];
}

// =============================================================================
//  1. Cổng phải tồn tại thật
// =============================================================================

it('cổng hiển thị bind được — cổng không có hiện thực là cổng trang trí', function () {
    // #1544 đã trả giá đúng chỗ này: `OrderQueryPort` từng tồn tại mà không ai
    // bind, nên mọi caller tin nó đều ăn container exception rồi quay về model.
    expect(app(MenuDisplayTaxRates::class))->toBeInstanceOf(MenuDisplayTaxRates::class)
        ->and(app(MenuDisplayTaxRates::class)->beginBatch())->toBeInstanceOf(MenuDisplayTaxRateBatch::class);
});

it('mỗi beginBatch() là một lô MỚI — memo không được sống xuyên lần dựng thực đơn', function () {
    // `TaxResolver` memo hoá mặc định chi nhánh/brand/menu/mục theo INSTANCE, và
    // docblock của nó cấm dùng chung xuyên thao tác. Bind adapter thành
    // `singleton` sẽ phá đúng luật đó mà không test nào khác thấy.
    $rates = app(MenuDisplayTaxRates::class);

    expect($rates->beginBatch())->not->toBe($rates->beginBatch());
});

it('CustomerMenuService KHÔNG còn cầm bộ máy thuế hay bộ đếm đánh giá', function () {
    $src = (string) file_get_contents(app_path('Services/Customer/CustomerMenuService.php'));

    // Ranh giới thật do deptrac đo; hai dòng này chỉ chỉ đúng FILE cho người sau.
    expect($src)->not->toContain('new TaxResolver')
        ->and($src)->not->toContain('ProductReviewService::');
});

// =============================================================================
//  2. Tỉ lệ hiển thị — lối vào bằng id phải KHỚP TUYỆT ĐỐI lối vào bằng model
// =============================================================================

it('tỉ lệ hiển thị giải bằng id ra đúng lối bằng model, trên mọi case golden', function (array $case) {
    [$branch, $brand, $product] = displayRateWorld1596($case);

    $menuTypeId = $case['menu_tax_type_id'];

    // Lối CŨ: model vào (`CustomerMenuService` gọi đúng thế này trước #1596).
    $byModel = (new TaxResolver)->resolveRateForDisplay(
        $product->fresh(['taxType']),
        $menuTypeId === null ? null : TaxType::find($menuTypeId),
        (string) $branch->id,
        (string) $brand->id,
    );

    // Lối MỚI: qua cổng, toàn scalar. Tầng 1 truyền `$menuTypeId` THÔ — kể cả khi
    // nó trỏ vào một type không tra được; đó chính là chỗ hai lối lệch nhau nếu
    // hiện thực tự "kiểm tra tồn tại" thay vì để chuỗi tầng đi tiếp.
    $byId = app(MenuDisplayTaxRates::class)->beginBatch()->rateForMenuLine(
        $menuTypeId,
        $product->tax_type_id,
        (string) $branch->id,
        (string) $brand->id,
    );

    expect($byId)->toBe($byModel, "lệch lối model. {$case['why']}");

    // Và cả hai phải khớp cái hợp đồng golden tuyên bố — so hai lối với nhau thôi
    // thì một thay đổi làm HỎNG CẢ HAI vẫn xanh.
    //
    // Ngoại lệ DUY NHẤT của bài này: khi không tầng nào giải ra, đường tiền đóng
    // dấu 0% còn đường hiển thị trả `null`. Đó là hợp đồng của
    // `resolveRateForDisplay` (xem docblock), không phải sai lệch.
    $expected = $case['expect']['tax_type_id'] === null ? null : (float) $case['expect']['rate'];
    expect($byId)->toBe($expected, "lệch hợp đồng golden. {$case['why']}");
})->with(fn () => collect(taxGolden1596()['cases'])
    ->mapWithKeys(fn (array $case): array => [$case['name'] => [$case]])
    ->all());

// =============================================================================
//  3. Loại thuế XOÁ MỀM phải RƠI XUỐNG tầng sau
// =============================================================================

it('type đã xoá mềm ở tầng 1 rơi xuống tầng sau, y như lối đọc quan hệ', function () {
    /*
     * Đây là bài quan trọng nhất của phần id-hoá. Lối cũ đọc QUAN HỆ `taxType`,
     * đi qua `SoftDeletingScope`, nên một loại thuế đã xoá làm tầng đó RỖNG.
     * Truyền `tax_type_id` thô mà hiện thực nào đó "tối ưu" bằng cách đọc thẳng
     * cột sẽ giữ lại một type đã chết và quảng cáo tỉ lệ của nó — hỏng lúc chạy,
     * không hỏng lúc biên dịch. Cùng cạm bẫy `MenuTaxTypeAnchors` đã ghi.
     */
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    $standard = TaxType::factory()->create([
        'organization_id' => $orgId, 'brand_id' => $brand->id,
        'code' => 'STANDARD', 'rate' => 10, 'is_default' => true, 'is_active' => true,
    ]);
    $doomed = TaxType::factory()->create([
        'organization_id' => $orgId, 'brand_id' => $brand->id,
        'code' => 'REDUCED', 'rate' => 8, 'is_default' => false, 'is_active' => true,
    ]);

    $productType = ProductType::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $product = Product::factory()->active()->create([
        'organization_id' => $orgId, 'brand_id' => $brand->id,
        'product_type_id' => $productType->id, 'tax_type_id' => null,
    ]);

    $rateFor = fn (): ?float => app(MenuDisplayTaxRates::class)->beginBatch()->rateForMenuLine(
        (string) $doomed->id,
        $product->tax_type_id,
        (string) $branch->id,
        (string) $brand->id,
    );

    expect($rateFor())->toBe(8.0);

    $doomed->delete();

    // Tầng 1 rỗng ⇒ tầng 4 (sản phẩm) rỗng ⇒ tầng 5 (chi nhánh) không khai ⇒
    // tầng 6, mặc định của brand.
    expect($rateFor())->toBe(10.0)
        ->and($standard->fresh()->rate)->toEqual(10);
});

// =============================================================================
//  4. Đường HIỂN THỊ phải IM — đó là lý do nó là cổng riêng
// =============================================================================

it('không tầng nào giải ra: đường tiền CẢNH BÁO, đường hiển thị IM LẶNG', function () {
    /*
     * Cảnh báo đó nghĩa là "một lần bán sắp thu thiếu thuế" và ops dựa vào nó
     * (audit fix 1.2, 2026-07-14 — trước đó nó im lặng và một brand thiếu seed đã
     * thu thiếu trên mọi đơn mà không để lại vết). Endpoint thực đơn bị gọi ở mọi
     * lượt xem trang, nên gộp hai đường làm một sẽ chôn vùi những lần xảy ra thật.
     *
     * Bài này ghim CẢ HAI chiều: bỏ cảnh báo ở đường tiền cũng đỏ, thêm cảnh báo
     * vào đường hiển thị cũng đỏ.
     */
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);
    $productType = ProductType::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $product = Product::factory()->active()->create([
        'organization_id' => $orgId, 'brand_id' => $brand->id,
        'product_type_id' => $productType->id, 'tax_type_id' => null,
    ]);

    // Brand KHÔNG có loại thuế nào ⇒ chuỗi tầng đi hết mà không giải ra gì.
    expect(TaxType::query()->where('brand_id', $brand->id)->count())->toBe(0);

    Log::spy();

    $displayed = app(MenuDisplayTaxRates::class)->beginBatch()->rateForMenuLine(
        null,
        $product->tax_type_id,
        (string) $branch->id,
        (string) $brand->id,
    );

    expect($displayed)->toBeNull();
    Log::shouldNotHaveReceived('warning');

    // Cùng dữ liệu, đường TIỀN: phải kêu.
    (new TaxResolver)->resolveForLineByIds(
        (string) $product->id,
        $product->tax_type_id,
        null,
        (string) $branch->id,
        (string) $brand->id,
    );

    Log::shouldHaveReceived('warning')->once();
});

// =============================================================================
//  5. `Product::recommendPercent()` — cạnh CustomerEngagement đã trả
// =============================================================================

it('recommendPercent sống trên Product và giữ nguyên phép làm tròn', function () {
    // Hai cột này là cột của `products` (bảng Catalog), do Catalog ghi qua
    // `ProductReviewAggregates`. Phép suy ra tỉ lệ không cần rời module — và bài
    // làm tròn .5 (1/8 = 12.5% ⇒ 13, không phải 12) là hành vi cũ, không đổi.
    $product = Product::factory()->make(['review_up_count' => 1, 'review_total_count' => 8]);

    expect($product->recommendPercent())->toBe(13);

    // Chưa ai chấm ⇒ `null`, KHÔNG phải 0%: "0% khuyên dùng" và "chưa có đánh giá"
    // nói ngược nhau với khách.
    expect(Product::factory()->make(['review_up_count' => 0, 'review_total_count' => 0])->recommendPercent())
        ->toBeNull();
});
