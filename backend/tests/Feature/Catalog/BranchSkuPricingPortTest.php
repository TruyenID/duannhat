<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Services\Product\Contracts\BranchSkuPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * #1597 — cổng tra giá bán mà Catalog công bố cho Ordering.
 *
 * Ghim đúng những thứ mà một cổng dựng sai sẽ làm hỏng LẶNG LẼ: giá menu theo
 * chi nhánh (không phải theo `is_active` toàn cục), fallback ngoài menu, và
 * phân biệt "không tồn tại" với "tồn tại nhưng cấm bán" — hai ca ra hai thông
 * điệp lỗi khác nhau ở đường sync workstation.
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
    $this->otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->pricing = app(BranchSkuPricing::class);

    $this->onMenuOf = function (Branch $branch, ProductSku $sku, float $price): void {
        $menu = Menu::factory()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
            'branch_id' => $branch->id, 'status' => 'Active',
        ]);
        $menuProduct = MenuProduct::factory()->create(['menu_id' => $menu->id, 'is_active' => true]);
        MenuProductSku::factory()->create([
            'menu_product_id' => $menuProduct->id,
            'product_sku_id' => $sku->id,
            'is_active' => true,
            'selling_price' => $price,
        ]);
    };
});

it('có binding thật, không phải interface rỗng', function () {
    expect($this->pricing)->toBeInstanceOf(BranchSkuPricing::class);
});

it('trả giá MENU của chi nhánh, không phải giá gốc của SKU', function () {
    $sku = ProductSku::factory()->create(['selling_price' => 999]);
    ($this->onMenuOf)($this->branch, $sku, 800);

    $price = $this->pricing->forBranch((string) $this->branch->id, (string) $sku->id);

    expect($price->baseSellingPrice)->toBe(999.0)
        ->and($price->branchMenuPrice)->toBe(800.0)
        ->and($price->effectivePrice())->toBe(800.0);
});

it('SKU ngoài menu chi nhánh → branchMenuPrice null, effectivePrice là giá gốc', function () {
    $sku = ProductSku::factory()->create(['selling_price' => 999]);

    $price = $this->pricing->forBranch((string) $this->branch->id, (string) $sku->id);

    expect($price->branchMenuPrice)->toBeNull()
        ->and($price->effectivePrice())->toBe(999.0);
});

/**
 * Đây là ca mà một cổng "tra giá theo SKU" viết ẩu sẽ sai: cùng một SKU nằm
 * trên menu của chi nhánh KHÁC. Bỏ phạm vi chi nhánh thì chi nhánh này bán theo
 * giá của chi nhánh kia — sai tiền, không ai báo.
 */
it('KHÔNG lấy giá menu của chi nhánh khác', function () {
    $sku = ProductSku::factory()->create(['selling_price' => 999]);
    ($this->onMenuOf)($this->otherBranch, $sku, 100);

    $price = $this->pricing->forBranch((string) $this->branch->id, (string) $sku->id);

    expect($price->branchMenuPrice)->toBeNull()
        ->and($price->effectivePrice())->toBe(999.0);
});

it('id ma → null, phân biệt được với "có nhưng cấm bán"', function () {
    expect($this->pricing->forBranch((string) $this->branch->id, (string) Str::uuid()))->toBeNull();
});

it('mang nguyên định nghĩa sellable của ProductSku (sản phẩm cha không Active)', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'status' => 'inactive',
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 500, 'is_active' => true]);

    $price = $this->pricing->forBranch((string) $this->branch->id, (string) $sku->id);

    expect($price)->not->toBeNull()
        ->and($price->isSellable)->toBeFalse();
});

it('mang categoryIds — dữ liệu Catalog mà Ordering từng đọc bằng DB::table thô', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'status' => 'active',
    ]);
    $category = Category::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    // `product_category.id` là auto-increment bigint, KHÔNG phải uuid như hai
    // cột khoá ngoài bên cạnh — nhét uuid vào là `datatype mismatch`.
    DB::table('product_category')->insert([
        'product_id' => $product->id,
        'category_id' => $category->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 500, 'is_active' => true]);

    $price = $this->pricing->forBranch((string) $this->branch->id, (string) $sku->id);

    expect($price->categoryIds)->toBe([(string) $category->id])
        ->and($price->isSellable)->toBeTrue();
});
