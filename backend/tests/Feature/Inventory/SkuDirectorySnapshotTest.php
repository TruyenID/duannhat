<?php

declare(strict_types=1);

/**
 * #1567 — `SkuSnapshot` không được trộn ba nhãn khác nhau vào nhau.
 *
 * Tôi suýt ship đúng lỗi đó: khi dời `ProductionCalculatorService` sang cổng,
 * bản nháp đổi `$variant->product?->code` (nhãn NHÓM sản phẩm) thành
 * `$variant->sku` (mã BIẾN THỂ). Cả hai là `?string`, màn hình kế hoạch sản
 * xuất vẫn hiện bình thường — chỉ là nhãn nhóm in sai giá trị.
 *
 * Kiểm tiếp thì lộ ra thứ lớn hơn: bảng `products` **không có cột `code`**, nên
 * `$variant->product?->code` ĐÃ LUÔN trả `null`. Xem #1614.
 */

use App\Models\Product;
use App\Models\ProductSku;
use App\Services\Product\Contracts\SkuDirectory;

it('sku là của BIẾN THỂ, name sản phẩm cha đi riêng — không lẫn nhau', function () {
    $product = Product::factory()->create(['name' => 'Cà phê sữa']);
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'sku' => 'SKU-VARIANT-L',
        'name' => 'Ly lớn',
    ]);

    $snap = app(SkuDirectory::class)->findWithRecipe((string) $sku->id);

    expect($snap)->not->toBeNull()
        ->and($snap->sku)->toBe('SKU-VARIANT-L')
        ->and($snap->name)->toBe('Ly lớn')
        ->and($snap->productName)->toBe('Cà phê sữa')
        // Ba giá trị PHẢI khác nhau, nếu không bài test không chứng minh gì.
        ->and($snap->sku)->not->toBe($snap->name)
        ->and($snap->name)->not->toBe($snap->productName);
});

it('SKU không có công thức trả recipe = null, KHÔNG phải lỗi', function () {
    // ProductionOrderService dựa vào đúng ca này: items rỗng, và submit() mới
    // là chỗ báo "phải có ít nhất một dòng".
    $product = Product::factory()->create();
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'recipe_id' => null]);

    $snap = app(SkuDirectory::class)->findWithRecipe((string) $sku->id);

    expect($snap)->not->toBeNull()
        ->and($snap->recipe)->toBeNull();
});

it('id không tồn tại trả null', function () {
    expect(app(SkuDirectory::class)->findWithRecipe('00000000-0000-4000-8000-000000000000'))->toBeNull();
});
