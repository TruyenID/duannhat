<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * #2488 — "tạo 2 biến thể nhưng chỉ hiện 1, không tạo lại được".
 *
 * Tái dựng đúng sự cố của Betoya trên sản phẩm Rượu Gin, bằng cùng hình dạng dữ
 * liệu đọc được từ production (`019f6ed5-58ec-71fe-93d4-b7c5ed840120`):
 *
 *   10:27:41  giá trị `翠ジン` + SKU ¥450 — HAI biến thể sống, đúng như khách nói
 *   10:55:31  SKU bị xoá. **Giá trị vẫn sống.**
 *   10:57:38  khách gõ lại, phải đổi tên thành `翠ジン -`
 *
 * Ba bài dưới ghim ba tầng đã biến một lần xoá nhầm thành bế tắc. Chúng mô tả
 * hành vi ĐANG CÓ — không phải hành vi mong muốn — nên bản vá nào sửa chúng
 * cũng phải sửa bài test cùng lúc, và đó chính là điểm: hiện trạng không được
 * đổi trong im lặng.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'betoya-2488',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/products";
});

/**
 * Sản phẩm một thuộc tính, HAI giá trị, mỗi giá trị một SKU — trạng thái mà
 * khách đã đạt được lúc 10:27:41 và mô tả là "tạo 2 biến thể".
 *
 * @return array{0: Product, 1: ProductOptionValue, 2: ProductOptionValue}
 */
function makeGinProduct(Brand $brand): array
{
    $product = Product::factory()->forBrand($brand)->create();

    $option = ProductOption::factory()->create([
        'product_id' => $product->id,
        'position' => 1,
        'key' => 'kich_thuoc',
        'name' => 'サイズ',
    ]);

    // Nhãn tiếng Nhật + slug băm là chi tiết QUAN TRỌNG, không phải màu mè:
    // `slugifyAscii` trả chuỗi rỗng cho kanji/katakana, nên `toOptionSlug` rơi
    // về `value_<hash nhãn>`. Ba slug này là giá trị THẬT trên production.
    $mega = ProductOptionValue::factory()->create([
        'option_id' => $option->id,
        'value' => 'value_1deglb5',
        'label' => 'メガ翠ジン',
        'position' => 1,
        'is_active' => true,
    ]);
    $sui = ProductOptionValue::factory()->create([
        'option_id' => $option->id,
        'value' => 'value_i51sxu',
        'label' => '翠ジン',
        'position' => 2,
        'is_active' => true,
    ]);

    foreach ([[$mega, 990.0], [$sui, 450.0]] as [$value, $price]) {
        ProductSku::factory()->create([
            'product_id' => $product->id,
            'option_value1_id' => $value->id,
            'option_signature' => ProductSku::computeOptionSignature($value->id, null, null),
            'selling_price' => $price,
            'is_active' => true,
        ]);
    }

    return [$product->refresh(), $mega, $sui];
}

it('#2488 — xoá SKU bỏ lại giá trị mồ côi: 2 giá trị nhưng 1 biến thể', function () {
    [$product, , $sui] = makeGinProduct($this->brand);

    $skuOfSui = ProductSku::where('product_id', $product->id)
        ->where('option_value1_id', $sui->id)
        ->firstOrFail();

    $this->actingAs($this->user)
        ->deleteJson("/api/v1/hq/{$this->brand->slug}/skus/{$skuOfSui->id}")
        ->assertSuccessful();

    // Đây là toàn bộ sự cố, trong hai dòng: giá trị SỐNG, SKU thì không.
    expect($sui->fresh()->deleted_at)->toBeNull()
        ->and(ProductSku::where('product_id', $product->id)->count())->toBe(1)
        ->and(ProductOptionValue::where('option_id', $sui->option_id)->count())->toBe(2);

    // Màn hình đọc hai con số này từ hai chỗ khác nhau và không đối chiếu:
    // ô thuộc tính hiện 2 chip, danh sách biến thể hiện 1 dòng. Khách thấy
    // thứ mình vừa tạo biến mất mà không có lời giải thích nào.
});

it('#2488 — lỗi trùng khi gõ lại nhãn cũ phải nói bằng NHÃN, không phải slug nội bộ', function () {
    [$product, $mega, $sui] = makeGinProduct($this->brand);

    $skuOfSui = ProductSku::where('product_id', $product->id)
        ->where('option_value1_id', $sui->id)
        ->firstOrFail();
    $this->actingAs($this->user)
        ->deleteJson("/api/v1/hq/{$this->brand->slug}/skus/{$skuOfSui->id}")
        ->assertSuccessful();

    // Khách thấy `翠ジン` không còn trong danh sách biến thể nên thêm lại nó —
    // đúng việc bất kỳ ai cũng làm. Giá trị vẫn đang sống, nên:
    $response = $this->actingAs($this->user)
        ->putJson("/api/v1/hq/{$this->brand->slug}/product-options/{$sui->option_id}/sync-values", [
            'values' => [
                ['id' => $mega->id, 'label' => 'メガ翠ジン'],
                ['id' => $sui->id, 'label' => '翠ジン'],
                ['value' => 'value_i51sxu', 'label' => '翠ジン'],
            ],
        ]);

    $response->assertStatus(422);

    $message = json_encode($response->json(), JSON_UNESCAPED_UNICODE);

    // Trước #2488 thông điệp mang `value_i51sxu` — chuỗi người dùng CHƯA TỪNG
    // gõ, cho một nhãn họ nhìn thấy rõ là đang thiếu. Không có đường nào từ câu
    // đó tới hành động đúng, nên khách đổi tên thành `翠ジン -` cho qua: một giá
    // trị rác + một SKU ¥0 trên production là cái giá của nó. Giờ thông điệp
    // phải nói bằng NHÃN — thứ duy nhất người dùng nhận ra được.
    expect($message)->toContain('翠ジン');
});

it('#2488 — generate-combinations CHỮA ĐƯỢC, và trả lại đúng giá cũ', function () {
    [$product, , $sui] = makeGinProduct($this->brand);

    $skuOfSui = ProductSku::where('product_id', $product->id)
        ->where('option_value1_id', $sui->id)
        ->firstOrFail();
    $this->actingAs($this->user)
        ->deleteJson("/api/v1/hq/{$this->brand->slug}/skus/{$skuOfSui->id}")
        ->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$product->id}/skus/generate-combinations")
        ->assertStatus(201)
        ->assertJsonPath('created_count', 1);

    $restored = ProductSku::where('product_id', $product->id)
        ->where('option_value1_id', $sui->id)
        ->firstOrFail();

    // Khôi phục ĐÚNG hàng cũ, không phải tạo hàng mới: id giữ nguyên và giá
    // ¥450 khách đã nhập vẫn còn. Đó là lý do bản vá đúng là NỐI NÚT cho
    // endpoint này chứ không phải bắt khách gõ lại từ đầu.
    expect($restored->id)->toBe($skuOfSui->id)
        ->and((float) $restored->selling_price)->toBe(450.0)
        ->and($restored->is_active)->toBeTrue()
        ->and(ProductSku::where('product_id', $product->id)->count())->toBe(2);
})->note('Endpoint này KHÔNG có component nào gọi — xem option-slug-and-orphan-recovery.test.ts');
