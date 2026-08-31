<?php

use App\Models\PaymentGatewayOption;
use App\Models\PaymentMethod;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use Database\Seeders\PaymentGatewayCatalogSeeder;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

/**
 * `display_name_i18n` phải là JSON **OBJECT**, kể cả khi rỗng.
 *
 * ## Vì sao một dấu ngoặc lại đáng một bài test riêng
 *
 * Máy trạm giải mã trường này vào `map[string]string`
 * (`workstation/internal/service/sync_pull_pos.go`). `json_encode([])` của PHP
 * cho ra `[]`, và Go từ chối mảng vào map — nên MỘT trường rỗng làm hỏng TOÀN BỘ
 * lượt giải mã của feed:
 *
 *     json: cannot unmarshal array into Go struct field
 *     optionPull.data.branch.options.display_name_i18n of type map[string]string
 *
 * Chuỗi hậu quả đã đo được trên máy dev, đầu này sang đầu kia:
 *
 *   1. `PullEffectivePaymentOptions` hỏng ở MỖI vòng 5 giây
 *   2. bảng `effective_payment_options` của máy trạm ở lại **0 dòng**
 *   3. `GET /api/v1/pos/effective-payment-options` qua LAN trả danh sách rỗng
 *   4. POS hiện "Chưa cấu hình phương thức thanh toán tại quầy" — **quán không
 *      thu được tiền**
 *
 * Và nó **im lặng**: lỗi chỉ đi vào một `slog.Warn` trong `pullPosReplicas`,
 * nên mọi feed khác vẫn chạy và không có gì đỏ ở đâu cả.
 *
 * ## Ca kích hoạt là ca MẶC ĐỊNH
 *
 * Nó nổ đúng khi tuỳ chọn không có bản dịch nào — trạng thái hợp lệ theo hợp
 * đồng ("mirror falls back to display_name"), và là trạng thái của mọi DB
 * `migrate:fresh --seed`: `PaymentGatewayCatalogSeeder` CÓ gán
 * `translateOrNew(...)`, nhưng Astrotomic ghi bản dịch qua model event, còn
 * `DatabaseSeeder` chạy trong `WithoutModelEvents` — nên
 * `payment_gateway_option_translations` về **0 dòng** sau mỗi lượt seed đầy đủ.
 *
 * Vì vậy bài test này KHÔNG seed bản dịch: nó dựng đúng ca mặc định đó.
 */
uses()->group('payment', 'pos');

beforeEach(function () {
    $this->fixtures = new PaymentPolicyApiFixtures;
    $this->fixtures->bind();

    PaymentMethod::factory()->create([
        'organization_id' => $this->fixtures->organization->id,
        'branch_id' => null,
        'code' => 'cash',
        'type' => 'cash',
        'is_active' => true,
        'is_auto_confirm' => true,
        'requires_tendered' => true,
    ]);

    app(PaymentGatewayCatalogSeeder::class)->seedInternal();

    // Ca mặc định của một DB seed đầy đủ: catalog có, bản dịch KHÔNG.
    PaymentGatewayOption::query()
        ->where('code', PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE)
        ->firstOrFail()
        ->translations()
        ->delete();
});

function cashOptionJson(): array
{
    $device = test()->fixtures->seedDevice('pos');

    $body = test()->withHeaders([
        'Authorization' => 'Bearer '.$device->device_token,
        'X-Shop-Slug' => test()->fixtures->shop->slug,
    ])->getJson('/api/v1/pos/effective-payment-options')
        ->assertOk()
        ->getContent();

    $option = collect(json_decode($body, true)['data']['options'])
        ->firstWhere(
            'id',
            (string) PaymentGatewayOption::query()
                ->where('code', PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE)
                ->whereHas('provider', fn ($q) => $q->where('code', PaymentGatewayProviderCodeEnum::Internal->value))
                ->firstOrFail()->id,
        );

    expect($option)->not->toBeNull();

    // Trả về ĐOẠN JSON THÔ của tuỳ chọn — `json()` của Laravel đã giải mã, và
    // phép giải mã đó xoá sạch khác biệt `[]` với `{}`, tức xoá đúng thứ cần đo.
    return ['decoded' => $option, 'raw' => $body];
}

it('phát `{}` chứ KHÔNG phải `[]` khi tuỳ chọn không có bản dịch nào', function () {
    $raw = cashOptionJson()['raw'];

    // Đo trên chuỗi thô: đây là hình dạng byte mà Go nhìn thấy.
    expect($raw)->toContain('"display_name_i18n":{}')
        ->and($raw)->not->toContain('"display_name_i18n":[]');
});

it('vẫn phát object khi CÓ bản dịch — bản sửa không đổi ca bình thường', function () {
    PaymentGatewayOption::query()
        ->where('code', PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE)
        ->firstOrFail()
        ->translateOrNew('vi')
        ->fill(['name' => 'Tiền mặt (sổ nội bộ)'])
        ->save();

    $out = cashOptionJson();

    expect($out['raw'])->not->toContain('"display_name_i18n":[]')
        ->and($out['decoded']['display_name_i18n']['vi'])->toBe('Tiền mặt (sổ nội bộ)');
});
