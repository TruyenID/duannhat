<?php

/**
 * plan-040 Cluster H — ProductionCalculatorService math (TH.1 C1, TH.2 NEW-BP-3).
 *
 *  - C1:        the calculator reads the canonical ingredient keys
 *               (`material_id`/`variant_id` + `quantity`), so required totals
 *               are non-zero and availability/feasibility are populated.
 *  - C1:        the variant lookup is org-scoped — another tenant's SKU name
 *               never leaks into the breakdown.
 *  - NEW-BP-3:  required need divides by recipe.output_quantity, matching the
 *               production explosion.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Services\Inventory\ProductionCalculatorService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $this->service = app(ProductionCalculatorService::class);
});

/**
 * Build a recipe-backed sellable SKU with a single material ingredient and a
 * stocked material in the warehouse. Returns [sku, material].
 *
 * @return array{0: ProductSku, 1: Material}
 */
function makeCalculatorFixture(
    string $orgId,
    string $brandId,
    string $warehouseId,
    float $ingredientQty,
    float $outputQty,
    float $materialStock,
): array {
    $material = Material::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'name' => 'Calc-Rice',
    ]);
    StockLevel::create([
        'warehouse_id' => $warehouseId,
        'material_id' => $material->id,
        'quantity' => $materialStock,
        'unit' => 'g',
        'alert_enabled' => false,
    ]);

    $recipe = Recipe::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => $outputQty,
        'output_unit' => 'serving',
        'ingredients' => [
            ['type' => 'material', 'material_id' => $material->id, 'quantity' => $ingredientQty, 'unit' => 'g'],
        ],
    ]);

    $product = Product::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
    ]);
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'recipe_id' => $recipe->id,
        'recipe_multiplier' => 1.0,
    ]);

    return [$sku, $material];
}

it('reads quantity + material_id so required_total is non-zero and feasibility is populated (C1)', function () {
    [$sku, $material] = makeCalculatorFixture(
        $this->orgId,
        $this->brand->id,
        $this->warehouse->id,
        ingredientQty: 10,
        outputQty: 1,
        materialStock: 100,
    );

    $result = $this->service->calculateShortage($this->orgId, $this->warehouse->id, $sku->id, 2);

    expect($result['ingredients'])->toHaveCount(1);
    $row = $result['ingredients'][0];

    // 10 × multiplier(1) / output_quantity(1) × desired(2) = 20.
    expect((float) $row['required_total'])->toBe(20.0)
        ->and((float) $row['available_stock'])->toBe(100.0)
        ->and($row['is_sufficient'])->toBeTrue()
        ->and($result['is_feasible'])->toBeTrue()
        ->and($row['name'])->toBe('Calc-Rice');
});

it('flags shortage when material stock is below the required total (C1)', function () {
    [$sku] = makeCalculatorFixture(
        $this->orgId,
        $this->brand->id,
        $this->warehouse->id,
        ingredientQty: 10,
        outputQty: 1,
        materialStock: 5,
    );

    $result = $this->service->calculateShortage($this->orgId, $this->warehouse->id, $sku->id, 2);
    $row = $result['ingredients'][0];

    expect((float) $row['required_total'])->toBe(20.0)
        ->and((float) $row['shortage'])->toBe(15.0)
        ->and($row['is_sufficient'])->toBeFalse()
        ->and($result['is_feasible'])->toBeFalse();
});

it('divides the required need by recipe.output_quantity (NEW-BP-3)', function () {
    [$sku] = makeCalculatorFixture(
        $this->orgId,
        $this->brand->id,
        $this->warehouse->id,
        ingredientQty: 10,
        outputQty: 2, // recipe yields 2 servings per batch
        materialStock: 100,
    );

    // 10 / output_quantity(2) × multiplier(1) × desired(3) = 15.
    $result = $this->service->calculateShortage($this->orgId, $this->warehouse->id, $sku->id, 3);

    expect((float) $result['ingredients'][0]['required_total'])->toBe(15.0);
});

it('org-scopes the variant lookup so another tenant SKU name never leaks (C1)', function () {
    // Foreign-org component variant with a distinctive (would-be-leaked) name.
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherProduct = Product::factory()->create([
        'organization_id' => $otherOrgId,
        'brand_id' => $otherBrand->id,
    ]);
    $foreignVariant = ProductSku::factory()->create([
        'product_id' => $otherProduct->id,
        'name' => 'SECRET-ORGB-VARIANT',
    ]);

    // Our org's recipe references the foreign-org variant as a component.
    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 1,
        'output_unit' => 'serving',
        'ingredients' => [
            ['type' => 'variant', 'variant_id' => $foreignVariant->id, 'quantity' => 1, 'unit' => 'pcs'],
        ],
    ]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'recipe_id' => $recipe->id,
        'recipe_multiplier' => 1.0,
    ]);

    $result = $this->service->calculateShortage($this->orgId, $this->warehouse->id, $sku->id, 1);
    $row = $result['ingredients'][0];

    // The foreign-org variant name must NOT be resolved/leaked — it falls back
    // to the ingredient placeholder instead.
    expect($row['name'])->not->toBe('SECRET-ORGB-VARIANT')
        ->and((float) $row['required_total'])->toBe(1.0);
});

/**
 * #1614 — payload kế hoạch sản xuất KHÔNG còn `product.sku`.
 *
 * Trường đó đọc `$variant->product?->code`, mà `products` **chưa bao giờ** có
 * cột `code` — không migration nào tạo nó. Eloquent trả `null` cho thuộc tính
 * không tồn tại thay vì báo lỗi, nên nó im lặng là `null` kể từ ngày viết ra, và
 * đọc lên như *"dữ liệu còn thiếu"* chứ không như *"cột không tồn tại"* — đúng
 * kiểu nhầm mà #1301 (登録番号 rỗng vì `select()` thiếu cột) đã trả giá.
 *
 * Bỏ hẳn thay vì dựng cột: mỗi variant bên dưới đã mang `sku` THẬT, nên trường ở
 * tầng product là khái niệm trùng. Bài test ghim cả hai vế — khoá chết đã đi VÀ
 * cái thay thế nó vẫn có dữ liệu thật — vì bỏ nhầm cả hai thì payload mất hẳn
 * mã biến thể mà không ai nhận ra.
 */
it('#1614 — product không còn khoá sku chết, variant vẫn có sku thật', function () {
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Calc-1614',
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $material->id,
        'quantity' => 100,
        'unit' => 'g',
        'alert_enabled' => false,
    ]);
    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 1,
        'output_unit' => 'serving',
        'ingredients' => [
            ['type' => 'material', 'material_id' => $material->id, 'quantity' => 1, 'unit' => 'g'],
        ],
    ]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'recipe_id' => $recipe->id,
        'recipe_multiplier' => 1.0,
        'sku' => 'VAR-REAL-001',
    ]);

    $result = $this->service->calculate($this->orgId, $this->warehouse->id, [$sku->id]);

    expect($result['products'])->not->toBeEmpty();
    $bucket = $result['products'][0];

    expect(array_key_exists('sku', $bucket['product']))->toBeFalse(
        'product.sku quay lại rồi — nó đọc một cột KHÔNG TỒN TẠI (products.code) '
        .'nên luôn null, và null đọc lên như dữ liệu thiếu chứ không như cột thiếu.'
    );
    expect($bucket['product']['id'])->toBe((string) $product->id);

    // Vế thứ hai: mã THẬT vẫn ở đúng chỗ của nó.
    expect($bucket['variants'][0]['variant']['sku'])->toBe('VAR-REAL-001');
});
