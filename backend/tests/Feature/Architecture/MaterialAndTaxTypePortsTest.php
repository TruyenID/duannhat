<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Models\Recipe;
use App\Models\TaxType;
use App\Services\Inventory\Contracts\MaterialDirectory;
use App\Services\Inventory\Contracts\MaterialSnapshot;
use App\Services\Product\Contracts\MaterialAllergenPropagation;
use App\Services\Product\Contracts\RecipeGraph;
use App\Services\Tax\Contracts\TaxTypeDirectory;
use App\Services\Tax\Contracts\TaxTypeIdentity;
use Illuminate\Support\Str;

/**
 * #962 — bốn cổng mở khoá cụm nợ "consumer không phải Ordering".
 *
 * Hai chiều, cùng một epic:
 *
 *   Pricing  → Catalog   `TaxTypeDirectory`        gỡ TRỌN 7 cạnh Catalog → Pricing
 *   Inventory→ Catalog   `MaterialDirectory`       gỡ phần Catalog tra cứu nguyên liệu
 *   Catalog  → Inventory `RecipeGraph`             điều kiện để `MaterialService` về Inventory
 *   Catalog  → Inventory `MaterialAllergenPropagation`
 *
 * Bài test này ghim HAI thứ, và sự phân biệt giữa chúng là chủ ý:
 *
 *  - **ranh giới** (P*): cổng resolve được, và bốn class tiêu thụ không còn
 *    import model của module khác. Deptrac đã đo điều đó ở tầng đồ thị; ở đây
 *    nó chỉ thẳng FILE, và chạy được mà không cần regen `deptrac.yaml`.
 *  - **bất biến nghiệp vụ** (B*): những luật mà một cổng "đúng hình dạng" vẫn
 *    có thể làm hỏng — và chính vì thế chúng phải được ghim RIÊNG, không dựa
 *    vào việc deptrac xanh.
 */
it('P1: bốn cổng resolve được từ container', function (string $port) {
    expect(app()->make($port))->toBeInstanceOf($port);
})->with([
    TaxTypeDirectory::class,
    MaterialDirectory::class,
    RecipeGraph::class,
    MaterialAllergenPropagation::class,
]);

it('P2: consumer không còn import model của module khác', function (string $relativePath, string $forbidden) {
    $source = (string) file_get_contents(app_path($relativePath));

    expect($source)->not->toContain("use App\\Models\\{$forbidden};", sprintf(
        'app/%s vẫn import App\Models\%s — cạnh này đã được trả bằng cổng, đừng mở lại.',
        $relativePath,
        $forbidden,
    ));
})->with([
    ['Services/Product/MenuService.php', 'TaxType'],
    ['Services/Product/FloatingSectionService.php', 'TaxType'],
    ['Services/Product/Internal/EloquentProductPersistence.php', 'TaxType'],
    ['Services/Import/ProductImporter.php', 'TaxType'],
    ['Services/Product/RecipeService.php', 'Material'],
    ['Services/Import/RecipeImporter.php', 'Material'],
    ['Services/Product/AllergenRollupService.php', 'Material'],
    // Chiều ngược: `MaterialService` giờ thuộc Inventory, nên nó không được
    // import model của Catalog nữa.
    ['Services/Product/MaterialService.php', 'Recipe'],
]);

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
});

/**
 * B1 — cổng thuế KHÔNG mang mức thuế.
 *
 * plan-043: mức thuế được `TaxResolver` phân giải rồi **snapshot bất biến** lên
 * từng dòng đơn, làm tròn MỘT LẦN theo nhóm cùng mức. Một mức thuế đọc rời ở
 * tầng danh mục không có ngữ cảnh làm tròn nào, nên nó chỉ có thể sai. Ghim
 * bằng phản chiếu chứ không bằng lời hứa trong docblock.
 */
it('B1: TaxTypeIdentity không phơi `rate` ra cho Catalog', function () {
    $props = array_map(
        fn (ReflectionProperty $p) => $p->getName(),
        (new ReflectionClass(TaxTypeIdentity::class))->getProperties(),
    );

    expect($props)->toEqualCanonicalizing(['id', 'code'])
        ->and($props)->not->toContain('rate');
});

/**
 * B2 — `findAssignable` chỉ trả loại ĐANG BẬT, và tôn trọng phạm vi brand.
 *
 * Đây là luật "gán một tier" (#1218 D): tắt một loại thuế CHẶN gán mới, nhưng
 * không đụng vào dòng đã trỏ vào nó.
 */
it('B2: findAssignable lọc is_active và brand', function () {
    $directory = app(TaxTypeDirectory::class);

    $active = TaxType::factory()->standard()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'is_active' => true,
    ]);
    $retired = TaxType::factory()->reduced()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'is_active' => false,
    ]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $foreign = TaxType::factory()->standard()->create([
        'organization_id' => $this->orgId, 'brand_id' => $otherBrand->id, 'is_active' => true,
    ]);

    expect($directory->findAssignable((string) $active->id, (string) $this->brand->id)?->id)->toBe((string) $active->id)
        ->and($directory->findAssignable((string) $retired->id, (string) $this->brand->id))->toBeNull()
        ->and($directory->findAssignable((string) $foreign->id, (string) $this->brand->id))->toBeNull()
        // brand null = KHÔNG lọc theo brand, giữ nguyên hành vi `when($brandId)`
        // của bốn chỗ gọi cũ.
        ->and($directory->findAssignable((string) $foreign->id)?->id)->toBe((string) $foreign->id);
});

/**
 * B3 — `belongsToBrand` CỐ Ý bỏ qua `is_active`.
 *
 * Đây là điểm dễ "sửa cho gọn" nhất và cũng là điểm đắt nhất nếu sửa: sản phẩm
 * / danh mục cũ đang trỏ vào một loại thuế đã tắt, nên nếu phép kiểm này xét
 * `is_active` thì MỌI lần sửa các sản phẩm đó sẽ 422 dù người dùng không hề
 * chạm tới thuế.
 */
it('B3: belongsToBrand chấp nhận loại thuế ĐÃ TẮT của đúng brand', function () {
    $directory = app(TaxTypeDirectory::class);

    $retired = TaxType::factory()->standard()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'is_active' => false,
    ]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);

    expect($directory->belongsToBrand((string) $retired->id, $this->orgId, (string) $this->brand->id))->toBeTrue()
        ->and($directory->belongsToBrand((string) $retired->id, $this->orgId, (string) $otherBrand->id))->toBeFalse();
});

/**
 * B4 — `adoptRecipeYield` IDEMPOTENT theo `yield_unit`.
 *
 * plan-022 T18/A4: một công thức được duyệt LẦN ĐẦU nâng nguyên liệu từ RAW lên
 * PRODUCED. Lần duyệt sau KHÔNG được ghi đè — nếu không, mỗi lần duyệt lại một
 * công thức sẽ xoá giá trị người vận hành đã sửa tay trên trang Material.
 */
it('B4: adoptRecipeYield chỉ nâng cấp nguyên liệu CHƯA có sản lượng', function () {
    $directory = app(MaterialDirectory::class);

    $raw = Material::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'yield_unit' => null,
    ]);
    $directory->adoptRecipeYield((string) $raw->id, 'KG', 2.5);

    expect($raw->fresh()->yield_unit)->toBe('KG')
        ->and((float) $raw->fresh()->yield_quantity)->toBe(2.5)
        // đơn vị GỐC được đăng ký — điều kiện để MaterialBatchService::complete()
        // phân giải được đơn vị gốc lúc đúc lô sản xuất.
        ->and(MaterialUnit::where('material_id', $raw->id)->where('is_base', true)->value('unit'))->toBe('KG');

    // Lần thứ hai: no-op tuyệt đối.
    $directory->adoptRecipeYield((string) $raw->id, 'L', 99.0);

    expect($raw->fresh()->yield_unit)->toBe('KG')
        ->and((float) $raw->fresh()->yield_quantity)->toBe(2.5)
        ->and(MaterialUnit::where('material_id', $raw->id)->count())->toBe(1);
});

/**
 * B5 — `registeredUnits` rỗng phải là RỖNG THẬT.
 *
 * plan-022 B5 dùng "chưa đăng ký đơn vị nào" làm điều kiện BỎ QUA kiểm tra đơn
 * vị. Vì thế một snapshot trả rỗng giả sẽ âm thầm tắt một luật kiểm — đó là lý
 * do `MaterialSnapshot` không mang trường đơn vị.
 */
it('B5: MaterialSnapshot không mang danh sách đơn vị', function () {
    $props = array_map(
        fn (ReflectionProperty $p) => $p->getName(),
        (new ReflectionClass(MaterialSnapshot::class))->getProperties(),
    );

    expect($props)->toEqualCanonicalizing(['id', 'sku', 'brandId']);

    $material = Material::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
    ]);
    $directory = app(MaterialDirectory::class);

    expect($directory->registeredUnits((string) $material->id))->toBe([]);

    MaterialUnit::create(['material_id' => $material->id, 'unit' => 'G', 'ratio' => 1.0, 'is_base' => true]);

    expect($directory->registeredUnits((string) $material->id))->toBe(['G']);
});

/**
 * B6 — `producedMaterialIdsConsuming` KHÔNG trả về chính nó.
 *
 * `MaterialService::checkUsage` dùng kết quả này để chặn xoá ("nguyên liệu này
 * đang được dùng bởi…"). Nếu một nguyên liệu tự lọt vào danh sách của mình thì
 * nó không bao giờ xoá được nữa.
 */
it('B6: producedMaterialIdsConsuming loại chính nguyên liệu đó ra', function () {
    $graph = app(RecipeGraph::class);

    $consumed = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $parent = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

    Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $parent->id,
        'is_active' => true,
        'ingredients' => [['type' => 'material', 'material_id' => $consumed->id, 'quantity' => 1, 'unit' => 'g']],
    ]);
    // Công thức TỰ THAM CHIẾU (dữ liệu cũ có thật) — không được tự nhận là
    // "đang dùng chính mình".
    Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $consumed->id,
        'is_active' => true,
        'ingredients' => [['type' => 'material', 'material_id' => $consumed->id, 'quantity' => 1, 'unit' => 'g']],
    ]);

    expect($graph->producedMaterialIdsConsuming((string) $consumed->id))->toBe([(string) $parent->id]);
});
