<?php

/**
 * #2686 — cổng `legacy_alias_reliance`.
 *
 * #2609 ship bộ đếm rồi dừng ở đó: `legacy_field_alias_hits` được ghi mỗi lần
 * một tên trường payment-policy cũ thực sự cung cấp giá trị, nhưng KHÔNG ai
 * đọc bảng. Câu hỏi "còn client nào phụ thuộc tên cũ không?" vì thế không trả
 * lời được, dù dữ liệu đã nằm sẵn trong DB.
 *
 * Bài test này ghim đúng thứ khó của cổng: **MẪU SỐ**. Tử số bằng 0 là chuyện
 * dễ đo; cái quyết định đúng/sai là 0 đó đến từ "đã hỏi và không ai dùng" hay
 * "chưa hỏi ai". Cổng anh em `client_sends_gateway_option_id` đã trả giá đúng
 * chỗ này, và một bản tóm tắt dựa trên nó phải đính chính trong cùng ngày.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Services\Payment\Observation\LegacyRemovalReadiness;
use Illuminate\Support\Str;

function aliasGate(int $sinceDays = 7): array
{
    $report = app(LegacyRemovalReadiness::class)->report(sinceDays: $sinceDays);

    foreach ($report['gates'] as $gate) {
        if ($gate['key'] === 'legacy_alias_reliance') {
            return $gate;
        }
    }

    throw new RuntimeException('Cổng legacy_alias_reliance không được báo cáo');
}

beforeEach(function () {
    $organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $organizationId,
        'console_organization_id' => $organizationId,
    ]);
    $brand = Brand::factory()->create(['console_organization_id' => $organizationId]);
    Branch::factory()->create([
        'console_organization_id' => $organizationId,
        'console_brand_id' => $brand->console_brand_id,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
});

// ĐÃ GỠ #2410 — sáu bài đo bộ đếm `legacy_field_alias_hits` đã xoá cùng bảng.
//
// Chúng ghim phần khó và đúng của cổng cũ: mẫu số bằng 0 không được đọc thành
// ĐẠT, thiết bị ngoài hai họ route không tính vào mẫu số, hit cũ hơn cửa sổ
// không giữ cổng đóng mãi. Lý lẽ đó vẫn đúng — nó chỉ không còn ĐỐI TƯỢNG:
// writer duy nhất của bảng là lớp alias, và lớp alias đã xoá.
//
// Giữ chúng lại là ghim hành vi của một phép đo mà không ai còn nuôi số liệu.

it('#2410 cổng sống tiếp làm RATCHET sau khi lớp alias đã xoá', function () {
    // Lớp đã xoá nên `target` là một CHUỖI, không phải `::class` — cùng khuôn
    // `payment_status_compatibility`, cổng đầu tiên đóng theo kiểu này.
    //
    // `code_present` phải FALSE. Nếu nó quay lại true thì có người dựng lại lớp
    // nhận-cả-hai-tên, và đó chính là điều cổng này còn tồn tại để phát hiện.
    $gate = aliasGate();

    expect($gate['target'])->toBe('App\\Http\\Support\\LegacyPaymentPolicyFieldAliases')
        ->and($gate['kind'])->toBe(LegacyRemovalReadiness::KIND_WORK)
        ->and($gate['code_present'])->toBeFalse()
        ->and($gate['call_sites'])->toBe([]);
});
