<?php

declare(strict_types=1);

use App\Services\Print\Renderer\PrintJobConfig;
use App\Services\Print\Renderer\PrintKindPlan;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\TaxLabels;
use PHPUnit\Framework\Assert;

/**
 * plan-053 T5.1d slice 0 (#1897) — cổng parity cho HỢP ĐỒNG renderer.
 *
 * Fixture: `workstation/internal/service/testdata/print_contract_golden.json`,
 * do Go sinh (`go test ./internal/service/ -run Contract_Golden -args
 * -update-print-contract`). PHP ĐỌC, không nhân bản kỳ vọng — cùng khuôn với
 * `RendererPrimitivesParityTest` (T5.2a) và `PrintLabelsParityTest` (#1876).
 *
 * Nó ghim thứ mà `print_golden.json` KHÔNG ghim được: file kia render bằng
 * renderer **Go** từ định nghĩa cloud export, nên nó vẫn xanh khi phía PHP
 * thiếu nguyên một ô dữ liệu hay nguyên một kind.
 */

/** @return array<string, mixed> */
function printContractGolden(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $path = base_path('../workstation/internal/service/testdata/print_contract_golden.json');
    expect(file_exists($path))->toBeTrue("thiếu fixture hợp đồng: {$path}");

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $cache = $decoded;
}

it('#1897 PrintJobConfig phủ ĐÚNG tập trường của Go', function () {
    $go = printContractGolden()['config_fields'];
    $php = array_keys((new PrintJobConfig)->toGoFieldMap());

    sort($go);
    sort($php);

    expect($php)->toBe($go);
});

it('#1897 PrintRenderData phủ ĐÚNG tập Ô của Go', function () {
    // Ô thiếu ở PHP là lỗi IM LẶNG: emitter đọc phải null và trên giấy nó hiện
    // ra thành một dòng vắng mặt, không phải một ngoại lệ.
    $go = printContractGolden()['data_fields'];
    sort($go);

    expect(PrintRenderData::goFieldNames())->toBe($go);
});

it('#1897 TaxLabels khớp Go từng chuỗi', function (string $locale) {
    $expected = printContractGolden()['tax_labels'][$locale] ?? null;

    expect($expected)->not->toBeNull("fixture không có locale {$locale}");
    expect(TaxLabels::forLocale($locale)->toGoFieldMap())->toBe($expected);
})->with(['ja', 'en', 'vi']);

it('#1897 locale lạ rơi về ja, giống taxLabelsFor bên Go', function () {
    // Fixture chỉ chứa ba locale thật; hành vi fallback vẫn phải được ghim, nếu
    // không một locale lạ có thể lặng lẽ ra chuỗi rỗng.
    expect(TaxLabels::forLocale('xx')->toGoFieldMap())
        ->toBe(TaxLabels::forLocale('ja')->toGoFieldMap());
});

it('#1897 mọi kind ĐÃ đăng ký ở PHP phải khớp Go từng chi tiết', function () {
    /** @var array<string, array{default_width: int, japanese_doc: bool, blocks: list<string>}> $goKinds */
    $goKinds = printContractGolden()['kinds'];
    $php = app(PrintKindRegistry::class)->toContractShape();

    foreach ($php as $kind => $shape) {
        // `toHaveKey($key, $value)` của Pest: tham số thứ hai là GIÁ TRỊ mong
        // đợi, KHÔNG phải thông điệp. Bản đầu truyền một câu tiếng Việt vào đó,
        // nên assertion so một mảng với một chuỗi và luôn trượt.
        //
        // Nó không lộ ra suốt slice 0 vì lúc đó PHP đăng ký 0 kind ⇒ vòng lặp
        // rỗng ⇒ dòng này KHÔNG BAO GIỜ chạy. Cổng xanh vì chưa ai đi qua nó,
        // không phải vì nó đúng. Lộ ra ở slice 1a (#1908) khi có 5 kind thật.
        // Dùng assertion của PHPUnit vì nó là thứ DUY NHẤT ở đây nhận thông
        // điệp thật. Cả `toHaveKey` lẫn `toContain` của Pest đều coi tham số
        // thứ hai là GIÁ TRỊ, nên truyền câu giải thích vào đó biến assertion
        // thành một phép so sai — tôi mắc đúng lỗi đó hai lần liên tiếp khi sửa
        // dòng này, lần thứ hai ngay sau khi vừa chẩn đoán lần thứ nhất.
        Assert::assertArrayHasKey(
            $kind,
            $goKinds,
            "PHP đăng ký kind {$kind} mà Go không có — bảng dispatch đã trôi",
        );
        expect($shape)->toBe($goKinds[$kind], "kind {$kind} lệch khỏi Go");
    }

    // Chiều còn lại KHÔNG được khẳng định là bằng nhau, và đó là sự thật của
    // slice 0: emitter chưa port, nên PHP đăng ký ÍT hơn Go. Điều bắt buộc là
    // PHP không được đăng ký thứ Go không có, và mỗi cái nó có đăng ký thì
    // phải khớp tuyệt đối.
    //
    // Ngày hai tập bằng nhau là ngày T5.1d xong — đó là phép đo, không phải
    // lời hứa.
    expect(array_diff(array_keys($php), array_keys($goKinds)))->toBe([]);
});

it('#1897 fixture Go phải CÓ kind — fixture rỗng là fixture hỏng, không phải port xong', function () {
    // Nếu bên Go bảng dispatch rỗng (build tag, thứ tự nạp) thì lượt `-update`
    // ghi ra một bảng rỗng rồi so với chính nó và VẪN XANH — bên Go có
    // `TestContract_DispatchTableIsNotEmpty` chặn đúng ca đó. Ca này là bản
    // sao ở phía đọc: một fixture rỗng sẽ làm mọi khẳng định "PHP khớp Go"
    // bên trên trở nên đúng một cách vô nghĩa.
    expect(printContractGolden()['kinds'])->not->toBe([]);
    expect(printContractGolden()['data_fields'])->not->toBe([]);
    expect(printContractGolden()['config_fields'])->not->toBe([]);
});

it('#1897 cổng kind CẮN ĐƯỢC — một plan sai lệch bị bắt ngay hôm nay', function () {
    // Registry của app đang RỖNG ở slice 0 (chưa port emitter nào), nên hai ca
    // trên chạy qua một vòng lặp không thân. Nếu dừng ở đó thì cổng chỉ chứng
    // minh được điều gì đó vào ngày slice 1 hạ cánh — tức nó chưa được kiểm
    // bao giờ, đúng lúc người ta tin nó nhất.
    //
    // Ca này dựng một registry riêng, đăng ký một kind CÓ THẬT bên Go nhưng
    // khai sai khổ giấy, rồi khẳng định phép so bắt được.
    $goKinds = printContractGolden()['kinds'];
    $kind = array_key_first($goKinds);
    $goShape = $goKinds[$kind];

    $registry = new PrintKindRegistry;
    $registry->register($kind, new PrintKindPlan(
        defaultWidth: $goShape['default_width'] + 1,
        emitters: array_fill_keys($goShape['blocks'], static function (): void {}),
        japaneseDoc: $goShape['japanese_doc'],
    ));

    expect($registry->toContractShape()[$kind])->not->toBe($goShape);

    // …và khớp khi khai đúng, để ca trên không xanh vì một lý do khác.
    $ok = new PrintKindRegistry;
    $ok->register($kind, new PrintKindPlan(
        defaultWidth: $goShape['default_width'],
        emitters: array_fill_keys($goShape['blocks'], static function (): void {}),
        japaneseDoc: $goShape['japanese_doc'],
    ));

    expect($ok->toContractShape()[$kind])->toBe($goShape);
});
