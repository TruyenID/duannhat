<?php

declare(strict_types=1);

use App\Models\Coupon;
use App\Services\Promotion\CouponService;

/**
 * #2083 — hợp đồng CHUNG cho phép tính tiền giảm của coupon, Cloud ↔ máy trạm.
 *
 * ## Vì sao chỗ này, và vì sao nó im lặng
 *
 * Lệch ở đây nghĩa là **tiền in trên phiếu khác tiền vào sổ**, và nó xuất hiện
 * đúng lúc **offline** — lúc không ai đối chiếu được. Khách cầm tờ giấy ghi một
 * số, kế toán thấy một số khác, và không có gì đỏ lên ở giữa.
 *
 * Đã lệch thật: bản Go chia **số nguyên** (cắt cụt) trong khi Cloud `round()`,
 * nên giỏ ¥1.005 với coupon 15% ra **150** ở máy trạm và **151** trên Cloud.
 * Comment của chính bản Go khai *"integer division mirrors Cloud's round"* —
 * không đúng, và cái sai nằm trong lời tự trấn an ấy suốt từ đó.
 *
 * ## Cổng có sẵn không phủ được chỗ này
 *
 * `coupon_parity_test.go` phía Go chỉ soi **trạng thái vòng đời** (đã dùng
 * chưa, hết hạn chưa, đúng chi nhánh chưa). Không một đồng nào được so.
 *
 * ## Cổng này KHÔNG được tự bỏ qua
 *
 * Submodule vắng là **lỗi cấu hình** của lượt chạy, không phải hoàn cảnh để im
 * lặng đi qua — chính cách im lặng ấy đã cho hai bản sửa của Cloud trôi mất
 * khỏi Go (#2089).
 */
function couponMathGoldenCases(): array
{
    $path = base_path('tests/Fixtures/coupon_math_golden.json');
    expect(file_exists($path))->toBeTrue("thiếu fixture: {$path}");

    /** @var array{cases: list<array<string, mixed>>} $doc */
    $doc = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $doc['cases'];
}

it('computeDiscount khớp fixture chung ở từng ca', function () {
    $service = app(CouponService::class);
    $checked = 0;

    foreach (couponMathGoldenCases() as $case) {
        $checked++;

        // #2118/#2186 — `value_x100` (nullable): giá trị CHÍNH XÁC nhân 100
        // như Cloud phát trên dây (12,5% → 1250). Phía Cloud cột là
        // decimal(12,2) nên bản chính xác chính là value_x100/100; phía Go
        // dùng cặp (value, value_x100) đúng như replica SQLite nhận từ feed.
        // Khoá vắng hoặc null = feed cũ ⇒ cả hai bên dùng `value` đã tròn.
        $exact = $case['value_x100'] ?? null;
        $value = $exact !== null ? $exact / 100 : $case['value'];

        // Model KHÔNG lưu DB: phép tính là hàm thuần trên các trường của coupon,
        // và dựng bản ghi thật chỉ thêm nhiễu (brand, tổ chức, ràng buộc FK)
        // vào một bài test về số học.
        $coupon = new Coupon([
            'discount_type' => $case['type'],
            'discount_value' => $value,
            'max_discount_cap' => $case['cap'],
        ]);

        // JPY — tiền tệ không có phần lẻ, khớp mô hình SỐ NGUYÊN phía Go.
        // Mọi ca trong fixture cố ý dùng tiền tệ như vậy; xem ghi chú giới hạn
        // trong chính file fixture.
        $got = $service->computeDiscount($coupon, (float) $case['subtotal'], 'JPY');

        expect($got)->toBe((float) $case['expected'], sprintf(
            '%s: computeDiscount(%s %s, subtotal=%s, cap=%s) — %s',
            $case['id'],
            $case['type'],
            $case['value'],
            $case['subtotal'],
            var_export($case['cap'], true),
            $case['why'] ?: 'khác kỳ vọng',
        ));
    }

    expect($checked)->toBeGreaterThan(0, 'fixture không có ca nào — bộ đọc hỏng');
});

// #2180 (bản thứ ba, sót lại — dọn trong PR #2190/#2200) — cổng "hai repo dùng
// ĐÚNG MỘT file" từng nằm ở đây dưới dạng hard-fail khi submodule vắng. Nó đã
// được `SharedFixturesAgreeTest` phủ cho MỌI fixture trong `tests/Fixtures`
// (gồm `coupon_math_golden.json`; skip ở local khi thiếu checkout, THROW trên
// CI) — bản ở đây chỉ còn tác dụng nhuộm đỏ `pest tests/Feature/Promotion/`
// trong worktree chưa init submodule, đúng kiểu chẩn đoán nhầm #1329.
