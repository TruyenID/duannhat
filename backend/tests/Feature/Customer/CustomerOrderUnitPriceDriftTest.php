<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\Zone;

/**
 * #1715 — chốt chặn "khách thấy một giá, đơn tính một giá khác".
 *
 * customer-web chụp giá vào giỏ lúc bấm card rồi giữ nguyên; server LUÔN định giá
 * lại lúc tạo đơn. Khung giờ ưu đãi đóng giữa chừng ⇒ hai con số rời nhau, và
 * không màn nào của client hỏi server trước khi commit. `expected_unit_price` cho
 * server chặn đúng lúc đó — nhưng **chỉ theo một chiều**: xem ca "rẻ hơn" bên dưới.
 */
const DRIFT_ORG = '00000000-0000-0000-0000-000000000001';

beforeEach(function () {
    $this->brand = Brand::factory()->create(['console_organization_id' => DRIFT_ORG]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => DRIFT_ORG,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->zone = Zone::factory()->create([
        'organization_id' => DRIFT_ORG,
        'branch_id' => $this->branch->id,
    ]);
    $this->table = Table::factory()->create([
        'organization_id' => DRIFT_ORG,
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'drift-token',
        'is_active' => true,
        'status' => 'free',
    ]);

    // Không nằm trong menu nào ⇒ server tính theo giá SKU. Đủ để dựng độ lệch mà
    // không phải dàn cả một khung giờ ưu đãi.
    $this->sku = ProductSku::factory()->create(['selling_price' => 1000]);
});

// =========================================================================
//  Động cơ LEGACY — đơn dine-in đầu tiên (và cùng đường với takeaway)
// =========================================================================

it('từ chối 409 khi server tính CAO hơn giá client đang hiển thị', function () {
    $response = $this->postJson('/api/v1/customer/tables/drift-token/orders', [
        'items' => [
            // Khách thấy ¥800 (giá khung giờ ưu đãi đã đóng), server tính ¥1,000.
            ['product_sku_id' => $this->sku->id, 'quantity' => 1, 'expected_unit_price' => 800],
        ],
    ]);

    // Chi nhánh không khai tiền tệ ⇒ JPY, mà yên không có phần lẻ: thân lỗi phải
    // in "800"/"1000" chứ không phải "800.00" — client hiển thị lại đúng cái nó nhận.
    $response->assertStatus(409)
        ->assertJsonPath('code', 'line_unit_price_drift')
        ->assertJsonPath('items.0.product_sku_id', $this->sku->id)
        ->assertJsonPath('items.0.expected_unit_price', '800')
        ->assertJsonPath('items.0.actual_unit_price', '1000')
        ->assertJsonPath('items.0.currency', 'JPY');
});

it('KHÔNG tạo đơn nào khi đã từ chối', function () {
    $this->postJson('/api/v1/customer/tables/drift-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1, 'expected_unit_price' => 800],
        ],
    ])->assertStatus(409);

    // Rollback phải sạch: không đơn, và bàn không bị chuyển sang "có khách".
    expect(CustomerOrder::count())->toBe(0);
    $this->table->refresh();
    expect($this->table->current_order_id)->toBeNull()
        ->and($this->table->status->value)->toBe('free');
});

it('liệt kê MỌI dòng lệch, không dừng ở dòng đầu tiên', function () {
    $second = ProductSku::factory()->create(['selling_price' => 2000]);

    $this->postJson('/api/v1/customer/tables/drift-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1, 'expected_unit_price' => 800],
            ['product_sku_id' => $second->id, 'quantity' => 1, 'expected_unit_price' => 1500],
        ],
    ])
        ->assertStatus(409)
        ->assertJsonCount(2, 'items');

    // Client cập nhật cả giỏ trong một lượt thay vì bị chặn nhiều lần liên tiếp —
    // đây là lý do gom cả lô rồi mới ném.
});

it('NHẬN đơn khi server tính RẺ hơn giá client hiển thị', function () {
    // Bất đối xứng có chủ đích. Luật #514 cho phép server hợp lệ tính rẻ hơn card
    // khách bấm (nó lấy dòng menu rẻ nhất toàn chi nhánh cho một SKU), nên so đối
    // xứng sẽ biến chuyện bình thường đó thành 409 giả hàng loạt. Khách trả ít hơn
    // cái đã thấy không phải là lỗi cần chặn.
    $this->postJson('/api/v1/customer/tables/drift-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1, 'expected_unit_price' => 1200],
        ],
    ])->assertStatus(201);
});

it('nhận đơn khi khớp đúng giá', function () {
    $this->postJson('/api/v1/customer/tables/drift-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1, 'expected_unit_price' => 1000],
        ],
    ])->assertStatus(201);
});

it('client CŨ không gửi trường này thì đặt đơn y như trước', function () {
    $this->postJson('/api/v1/customer/tables/drift-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
    ])->assertStatus(201);
});

// =========================================================================
//  Độ chính xác khi so = đơn vị nhỏ nhất của tiền tệ CHI NHÁNH
// =========================================================================

it('JPY: so theo đơn vị, nhiễu float của phép nhân khuyến mãi không thành 409', function () {
    // JPY không có phần lẻ. Client hiển thị ¥1,000 (số nguyên như mọi giá yên),
    // server giải ra đúng ¥1,000 — nhưng nếu so bằng float thô thì một dòng đi
    // qua phép nhân phần trăm khuyến mãi có thể ra 999.9999999 hoặc 1000.0000001
    // và sinh 409 giả. Quy về minor unit rồi so số nguyên là hết.
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => DRIFT_ORG,
        'currency_code' => 'JPY',
    ]);

    $this->postJson('/api/v1/customer/tables/drift-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1, 'expected_unit_price' => 999.999],
        ],
    ])->assertStatus(201);
});

it('USD: lệch 1 CENT vẫn bị chặn — độ chính xác đi theo tiền tệ chi nhánh', function () {
    // Cùng một độ lệch tuyệt đối (0.01) là KHÔNG ĐÁNG KỂ với yên nhưng là một
    // cent thật với đô. Ngưỡng phải đi theo đồng tiền chi nhánh đang bán, không
    // phải một hằng số chung cho mọi chi nhánh.
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => DRIFT_ORG,
        'currency_code' => 'USD',
    ]);
    $usdSku = ProductSku::factory()->create(['selling_price' => 10.55]);

    $this->postJson('/api/v1/customer/tables/drift-token/orders', [
        'items' => [
            ['product_sku_id' => $usdSku->id, 'quantity' => 1, 'expected_unit_price' => 10.54],
        ],
    ])
        ->assertStatus(409)
        ->assertJsonPath('items.0.expected_unit_price', '10.54')
        ->assertJsonPath('items.0.actual_unit_price', '10.55')
        ->assertJsonPath('items.0.currency', 'USD');
});

it('IDR: tiền chỉ lưu hành đơn vị nguyên ⇒ chênh dưới 1 đơn vị KHÔNG bị chặn', function () {
    // IDR có minor unit 2 chữ số nhưng `RoundingMode::autoStep` xếp nó vào nhóm
    // lưu hành đơn vị nguyên (cùng LAK/MMK/COP), nên bước tiền của động cơ là 1.
    // So theo `CurrencyMinorUnit::exponent` sẽ mịn gấp 100 lần và 409 vì một khoản
    // chênh không bao giờ vào nổi hoá đơn. Phải so trên đúng lưới động cơ dùng.
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => DRIFT_ORG,
        'currency_code' => 'IDR',
    ]);
    $idrSku = ProductSku::factory()->create(['selling_price' => 10000.4]);

    $this->postJson('/api/v1/customer/tables/drift-token/orders', [
        'items' => [
            ['product_sku_id' => $idrSku->id, 'quantity' => 1, 'expected_unit_price' => 10000],
        ],
    ])->assertStatus(201);
});

it('từ chối giá âm ở tầng validation', function () {
    $this->postJson('/api/v1/customer/tables/drift-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1, 'expected_unit_price' => -1],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('items.0.expected_unit_price');
});

// =========================================================================
//  Động cơ TYPED — thêm món vào đơn dine-in đang mở
// =========================================================================

it('chặn cả ở đường THÊM MÓN vào đơn đang mở (động cơ typed)', function () {
    // Đơn đầu đi động cơ legacy…
    $this->postJson('/api/v1/customer/tables/drift-token/orders', [
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertStatus(201);

    expect(CustomerOrder::count())->toBe(1);
    $itemsBefore = CustomerOrder::first()->items()->count();

    // …còn lần POST thứ hai vào cùng bàn đi qua changeItems (typed). Hai động cơ
    // khác nhau, nên cổng phải cắm ở CẢ HAI — vá một chỗ là code chết với 2/3 đường.
    $this->postJson('/api/v1/customer/tables/drift-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1, 'expected_unit_price' => 800],
        ],
    ])->assertStatus(409)->assertJsonPath('code', 'line_unit_price_drift');

    // Không dòng nào được thêm vào đơn đang mở.
    expect(CustomerOrder::first()->items()->count())->toBe($itemsBefore);
});

it('đường thêm món vẫn nhận khi khớp giá', function () {
    $this->postJson('/api/v1/customer/tables/drift-token/orders', [
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertStatus(201);

    // 200, không phải 201: đơn đã tồn tại, lần này là THÊM món vào nó.
    $this->postJson('/api/v1/customer/tables/drift-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1, 'expected_unit_price' => 1000],
        ],
    ])->assertStatus(200);

    expect((float) CustomerOrder::first()->items()->sum('quantity'))->toBe(2.0);
});
