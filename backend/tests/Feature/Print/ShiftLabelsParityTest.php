<?php

declare(strict_types=1);

use App\Services\Print\Renderer\ShiftLabels;
use App\Services\Print\Renderer\ShiftOpenLabels;

/**
 * plan-053 T5.1d (#1934) — 48 + 11 nhãn họ shift, ghim theo GIÁ TRỊ.
 *
 * ── Vì sao ghim giá trị, không chỉ tên trường ────────────────────────────
 *
 * `payload_fields` (#1910) ghim TÊN trường và đủ cho các struct dữ liệu. Nhãn
 * thì khác: tên trường vẫn khớp trong khi chuỗi sai. Một nhãn sai **không làm
 * gì đỏ** — nó hiện ra dưới dạng MỘT DÒNG SAI CHỮ trên phiếu 精算, và người
 * phát hiện là thu ngân đang đếm két lúc cuối ca.
 *
 * Cùng lập luận `tax_labels` đã dùng cho `RateTarget` (#1876), nơi một dấu `%`
 * chép sai làm hỏng mọi khối thuế theo mức của locale đó.
 *
 * Hai class PHP được SINH từ chính fixture này, nên test không kiểm "tôi chép
 * có đúng không" mà kiểm **bảng đã sinh có còn khớp Go không** — nó đỏ khi Go
 * đổi nhãn mà chưa sinh lại.
 */
function shiftLabelGolden(string $key): array
{
    static $cache = [];

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $path = base_path('../workstation/internal/service/testdata/print_contract_golden.json');
    expect(file_exists($path))->toBeTrue("thiếu fixture hợp đồng: {$path}");

    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $cache[$key] = $decoded[$key] ?? [];
}

it('#1934 ShiftLabels khớp Go từng chuỗi', function (string $locale) {
    $expected = shiftLabelGolden('shift_labels')[$locale] ?? null;

    expect($expected)->not->toBeNull("fixture không có locale {$locale}");
    expect(ShiftLabels::forLocale($locale)->toGoFieldMap())->toBe($expected);
})->with(['ja', 'en', 'vi']);

it('#1934 ShiftOpenLabels khớp Go từng chuỗi', function (string $locale) {
    $expected = shiftLabelGolden('shift_open_labels')[$locale] ?? null;

    expect($expected)->not->toBeNull("fixture không có locale {$locale}");
    expect(ShiftOpenLabels::forLocale($locale)->toGoFieldMap())->toBe($expected);
})->with(['ja', 'en', 'vi']);

it('#1934 locale lạ rơi về ja, không rơi về en', function () {
    // `labelsFor` bên Go dùng `default:` là bảng ja. Rơi về en là lỗi im lặng:
    // phiếu vẫn in, chỉ là in sai tiếng ở một quán Nhật.
    expect(ShiftLabels::forLocale('xx')->toGoFieldMap())
        ->toBe(shiftLabelGolden('shift_labels')['ja']);

    expect(ShiftOpenLabels::forLocale('xx')->toGoFieldMap())
        ->toBe(shiftLabelGolden('shift_open_labels')['ja']);
});

it('#1934 fixture CÓ hai bảng nhãn — rỗng là fixture hỏng, không phải port xong', function () {
    // Không có ca này thì một fixture rỗng làm mọi assertion ở trên so hai mảng
    // rỗng với nhau và xanh vĩnh viễn — đúng kiểu cổng "chưa bao giờ chạy" đã
    // gặp ở `PrintContractParityTest` (94996994a).
    expect(shiftLabelGolden('shift_labels'))->not->toBe([]);
    expect(shiftLabelGolden('shift_open_labels'))->not->toBe([]);
    expect(shiftLabelGolden('shift_labels')['ja'] ?? [])->toHaveCount(48);
    expect(shiftLabelGolden('shift_open_labels')['ja'] ?? [])->toHaveCount(11);
});
