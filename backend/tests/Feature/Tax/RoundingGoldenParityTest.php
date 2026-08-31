<?php

declare(strict_types=1);

use App\Support\RoundingMode;

/**
 * #2082 — hợp đồng CHUNG cho tầng đáy làm tròn, Cloud ↔ máy trạm.
 *
 * ## Vì sao tầng này, và vì sao bây giờ
 *
 * `priceGroups`, `stampLineTaxAmounts`, `writeConditions` và công thức trích
 * thuế ngược của 総額表示 đều gọi xuống đây. Lệch ở đây nạp vào **mọi** con số
 * phía trên, và lệch âm thầm.
 *
 * Nó đã lệch thật, hai lần, theo cùng một cách: Cloud sửa (`e8275ad97` #821 E1,
 * `790e007e4` #821 E1f), bản port Go **không nghe thấy**, và không có cơ chế
 * nào để nghe. Hậu quả đo được: `tax_rounding_decimals = 0` là MẶC ĐỊNH của DB,
 * nên ở tiền tệ có xu thì Cloud dùng bước 0,01 còn máy trạm dùng bước 1 — dòng
 * $1.45 @10% thu **$0.15 trên Cloud, $0.00 trên máy trạm**, ở mọi đơn.
 *
 * ## Cổng này KHÔNG được tự bỏ qua
 *
 * `TaxAllocationGoldenParityTest` (bản cũ) làm `expect(true)->toBeTrue()` rồi
 * `return` khi submodule vắng mặt. Nó xanh, đếm vào tổng assertion, và trông
 * như đã đo — trong khi không đo gì. Đó là cách D1/D2 sống sót (#2089).
 *
 * Ở đây submodule vắng là **lỗi cấu hình**, và test nói thẳng ra thế.
 */
function roundingGoldenCases(): array
{
    $path = base_path('tests/Fixtures/rounding_golden.json');
    expect(file_exists($path))->toBeTrue("thiếu fixture: {$path}");

    /** @var array{cases: list<array<string, mixed>>} $doc */
    $doc = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $doc['cases'];
}

it('currencyStep: mã tiền tệ → bước làm tròn', function () {
    $checked = 0;

    foreach (roundingGoldenCases() as $case) {
        if ($case['kind'] !== 'currency_step') {
            continue;
        }
        $checked++;
        expect(RoundingMode::step('auto', $case['currency']))
            ->toBe((float) $case['expected'], sprintf(
                'currency %s: %s',
                var_export($case['currency'], true),
                $case['why'] ?: 'khác kỳ vọng',
            ));
    }

    expect($checked)->toBeGreaterThan(0, 'fixture không có ca currency_step nào — bộ quét hỏng');
});

it('taxStep: (decimals, bước tiền tệ) → bước thuế, có KẸP', function () {
    $checked = 0;

    foreach (roundingGoldenCases() as $case) {
        if ($case['kind'] !== 'tax_step') {
            continue;
        }
        $checked++;
        $decimals = $case['decimals'] === null ? null : (int) $case['decimals'];

        // PHP không có `taxStepFrom`; `taxStep(decimals, currency)` tự phân giải
        // bước tiền tệ, nên ca phải mang MÃ tiền tệ. Go thì nhận thẳng bước, nên
        // ca mang cả hai.
        //
        // Trước đây file này TỰ SUY mã từ bước (`1.0 => 'JPY'`, …), và như thế
        // nó tự làm yếu chính mình: nếu `step()` sai cho đúng cái mã đại diện ấy
        // thì phép so là sai-với-sai và vẫn XANH. Cầu nối giữa hai khoá giờ là
        // một khẳng định, không phải một phép đoán trong test.
        $currency = (string) $case['currency'];

        expect(RoundingMode::step('auto', $currency))->toBe((float) $case['currency_step'], sprintf(
            'ca khai %s có bước %s, nhưng step() nói khác — cầu nối mã↔bước hỏng thì mọi so sánh taxStep bên dưới vô nghĩa',
            $currency,
            var_export($case['currency_step'], true),
        ));

        expect(RoundingMode::taxStep($decimals, $currency))
            ->toBe((float) $case['expected'], sprintf(
                'decimals=%s currencyStep=%s: %s',
                var_export($case['decimals'], true),
                $case['currency_step'],
                $case['why'] ?: 'khác kỳ vọng',
            ));
    }

    expect($checked)->toBeGreaterThan(0, 'fixture không có ca tax_step nào');
});

it('roundHalfUpToStep: biên .xx5 không được THU THIẾU', function () {
    $checked = 0;

    foreach (roundingGoldenCases() as $case) {
        if ($case['kind'] !== 'round_half_up') {
            continue;
        }
        $checked++;
        expect(RoundingMode::roundHalfUpToStep((float) $case['value'], (float) $case['step']))
            ->toBe((float) $case['expected'], sprintf(
                'round(%s @ %s): %s',
                $case['value'],
                $case['step'],
                $case['why'] ?: 'khác kỳ vọng',
            ));
    }

    expect($checked)->toBeGreaterThan(0, 'fixture không có ca round_half_up nào');
});

// #2180 — cổng "hai repo dùng ĐÚNG MỘT file" từng nằm ở đây dưới dạng hard-fail
// khi submodule vắng. Nó đã được `SharedFixturesAgreeTest` phủ cho MỌI fixture
// trong `tests/Fixtures` (skip ở local khi thiếu checkout, THROW trên CI) — bản
// ở đây chỉ còn tác dụng nhuộm đỏ `pest tests/Feature/Tax/` trong worktree chưa
// init submodule, đúng kiểu chẩn đoán nhầm #1329 dựng ra để tránh.
