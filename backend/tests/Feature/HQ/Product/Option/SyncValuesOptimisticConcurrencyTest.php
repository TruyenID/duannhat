<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * #2488 — `sync-values` phải từ chối bản nháp hydrate từ dữ liệu cũ.
 *
 * `values` là danh sách TOÀN QUYỀN: giá trị nào vắng mặt là bị xoá mềm. Nên một
 * form mở sẵn từ trước, bấm Lưu sau khi tab khác đã thêm giá trị, sẽ xoá giá
 * trị nó chưa từng nhìn thấy — hai tab admin cùng mở là kịch bản thật trên
 * production (ảnh chụp của khách trong #2488).
 *
 * Client khai `known_value_ids` — tập id nó đã thấy lúc hydrate. Lệch với tập
 * đang sống → 409 `OPTION_VALUES_CHANGED`, KHÔNG ghi gì. Cố ý không merge hộ:
 * người dùng phải nhìn thấy dữ liệu mới trước khi quyết lưu gì.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'betoya-occ',
        'is_active' => true,
    ]);
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);

    $product = Product::factory()->forBrand($this->brand)->create();
    $this->option = ProductOption::factory()->create([
        'product_id' => $product->id,
        'position' => 1,
        'key' => 'kich_thuoc',
        'name' => 'サイズ',
    ]);
    $this->mega = ProductOptionValue::factory()->create([
        'option_id' => $this->option->id, 'value' => 'value_1deglb5', 'label' => 'メガ翠ジン', 'position' => 1, 'is_active' => true,
    ]);

    $this->sync = fn (array $body) => $this->actingAs($this->user)->putJson(
        "/api/v1/hq/{$this->brand->slug}/product-options/{$this->option->id}/sync-values",
        $body,
    );
});

it('409 khi tập id đã biết LỆCH tập đang sống — và KHÔNG xoá gì', function () {
    // Tab B thêm 翠ジン sau khi tab A đã hydrate (A chỉ biết メガ翠ジン).
    $sui = ProductOptionValue::factory()->create([
        'option_id' => $this->option->id, 'value' => 'value_i51sxu', 'label' => '翠ジン', 'position' => 2, 'is_active' => true,
    ]);

    // Tab A lưu: danh sách toàn quyền KHÔNG chứa 翠ジン — trước đây điều này
    // xoá mềm nó trong im lặng.
    $response = ($this->sync)([
        'values' => [['id' => $this->mega->id, 'label' => 'メガ翠ジン']],
        'known_value_ids' => [$this->mega->id],
    ]);

    $response->assertStatus(409)->assertJsonPath('error', 'OPTION_VALUES_CHANGED');
    // Giá trị của tab B còn nguyên — đây là toàn bộ mục đích của cái rào.
    expect($sui->fresh()->deleted_at)->toBeNull()
        ->and(ProductOptionValue::where('option_id', $this->option->id)->count())->toBe(2);
});

it('đi qua khi tập id khớp — thứ tự không quan trọng', function () {
    $sui = ProductOptionValue::factory()->create([
        'option_id' => $this->option->id, 'value' => 'value_i51sxu', 'label' => '翠ジン', 'position' => 2, 'is_active' => true,
    ]);

    ($this->sync)([
        'values' => [
            ['id' => $this->mega->id, 'label' => 'メガ翠ジン'],
            ['id' => $sui->id, 'label' => '翠ジン'],
        ],
        // đảo thứ tự có chủ đích — so sánh phải là so TẬP, không phải so mảng
        'known_value_ids' => [$sui->id, $this->mega->id],
    ])->assertSuccessful();
});

it('khách cũ không gửi known_value_ids vẫn chạy như trước — trường là nullable', function () {
    ($this->sync)([
        'values' => [['id' => $this->mega->id, 'label' => 'メガ翠ジン改']],
    ])->assertSuccessful();

    expect($this->mega->fresh()->label)->toBe('メガ翠ジン改');
});

it('xoá CÓ CHỦ ĐÍCH vẫn đi qua: biết đủ tập hiện tại rồi bỏ bớt là hợp lệ', function () {
    // Phân biệt then chốt với ca 409: người dùng NHÌN THẤY cả hai giá trị và
    // chủ động bỏ một — known khớp alive nên không phải bản nháp nguội.
    $sui = ProductOptionValue::factory()->create([
        'option_id' => $this->option->id, 'value' => 'value_i51sxu', 'label' => '翠ジン', 'position' => 2, 'is_active' => true,
    ]);

    ($this->sync)([
        'values' => [['id' => $this->mega->id, 'label' => 'メガ翠ジン']],
        'known_value_ids' => [$this->mega->id, $sui->id],
    ])->assertSuccessful();

    expect($sui->fresh()->deleted_at)->not->toBeNull();
});
