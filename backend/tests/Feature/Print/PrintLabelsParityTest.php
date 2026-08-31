<?php

/**
 * plan-053 T5.1d (#1876) — catalog nhãn PHP phải khớp Go từng byte.
 *
 * Mọi emitter phiếu in lấy nhãn từ `printLabelsFor()` (Go) / `PrintLabels`
 * (PHP), KHÔNG lấy từ block definition. Nên một dấu cách thừa ở `NotePrefix`
 * làm lệch mọi dòng ghi chú của mọi kind — và lệch theo kiểu chỉ lộ ra dưới
 * dạng "hash khác" ở tầng parity slip, nơi rất khó khoanh.
 *
 * Khoá nó ở đây, sớm nhất có thể, bằng đúng khuôn T5.2a đã dùng cho primitives:
 * **Go sinh, PHP đọc**, không nhân bản kỳ vọng.
 *
 * Fixture: `workstation/internal/service/testdata/print_labels_golden.json`
 * Sinh lại: `go test ./internal/service/ -run Labels_Golden -args -update-print-labels`
 */

use App\Services\Print\Renderer\PrintLabels;

/** @return array<string, array<string, string>> */
function labelsGolden(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $path = base_path('../workstation/internal/service/testdata/print_labels_golden.json');
    expect(file_exists($path))->toBeTrue("thiếu fixture nhãn: {$path}");

    /** @var array<string, array<string, string>> $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $cache = $decoded;
}

it('#1876 khớp catalog nhãn của Go từng trường', function (string $locale) {
    $expected = labelsGolden()[$locale] ?? null;

    expect($expected)->not->toBeNull("fixture không có locale {$locale}");
    expect(PrintLabels::forLocale($locale)->toGoFieldMap())->toBe($expected);
})->with(['ja', 'en', 'vi']);

it('#1876 phủ ĐỦ tập trường, không thiếu không thừa', function () {
    // Ca này bắt loại lỗi mà ca trên bỏ sót: nếu PHP thiếu hẳn một trường thì
    // `toBe()` đã đỏ — nhưng nếu Go THÊM một trường mới và fixture được sinh
    // lại trong khi PHP chưa theo, thông báo lỗi sẽ là một diff 36 dòng khó
    // đọc. So tập khoá trước cho ra câu trả lời gọn: thiếu đúng cái nào.
    $php = array_keys(PrintLabels::forLocale('ja')->toGoFieldMap());
    $go = array_keys(labelsGolden()['ja']);

    sort($php);
    sort($go);

    expect($php)->toBe($go);
});

it('#1876 locale lạ rơi về ja, giống default của Go', function () {
    // Go: `switch { case "en" … case "vi" … default: ja }`. Fallback không nằm
    // trong fixture (một khoá "xx" ở đó sẽ trông như một locale thật), nên nó
    // được ghim riêng ở CẢ HAI repo — bên Go là
    // `TestLabels_UnknownLocaleFallsBackToJapanese`.
    $ja = PrintLabels::forLocale('ja')->toGoFieldMap();

    expect(PrintLabels::forLocale('xx')->toGoFieldMap())->toBe($ja)
        ->and(PrintLabels::forLocale(null)->toGoFieldMap())->toBe($ja)
        ->and(PrintLabels::forLocale('  JA  ')->toGoFieldMap())->toBe($ja);
});

it('#1876 không nhãn nào rỗng ở bất kỳ locale nào', function (string $locale) {
    // Một chuỗi rỗng in ra thành dòng cụt trên phiếu thật, và nếu nó lọt vào
    // fixture thì ca parity ở trên sẽ coi nó là chủ đích.
    foreach (PrintLabels::forLocale($locale)->toGoFieldMap() as $name => $value) {
        expect($value)->not->toBe('', "{$locale}.{$name} rỗng");
    }
})->with(['ja', 'en', 'vi']);
