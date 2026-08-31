<?php

declare(strict_types=1);

use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\PrintJobConfig;
use App\Services\Print\Renderer\PrintLabels;
use App\Services\Print\Renderer\PrintRenderContext;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\TaxLabels;

/*
 * plan-053 T5.1d (#1923) — hai helper mà cả 18 emitter họ bill đều gọi.
 *
 * `row()` là chỗ port dễ hỏng IM LẶNG nhất trong cả slice: đếm cột bằng
 * `mb_strlen` thay vì `displayWidth` vẫn cho ra một dòng trông đúng trên phiếu
 * tiếng Việt, và chỉ lệch trên phiếu tiếng Nhật — tức lộ ra ở quán, không ở máy
 * người viết code.
 */

function billCtx(int $width = 48, string $currency = '¥'): PrintRenderContext
{
    $config = new PrintJobConfig(currency: $currency);

    return new PrintRenderContext(
        encoder: new Escpos,
        definition: ['blocks' => []],
        data: new PrintRenderData(kind: 'receipt', config: $config),
        config: $config,
        locale: 'ja',
        width: $width,
        japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'),
        tax: TaxLabels::forLocale('ja'),
    );
}

it('#1923 row căng nhãn và giá trị ra đúng bề rộng', function () {
    $ctx = billCtx(20);
    $ctx->row('Subtotal', '1,000');

    // Cắt chuỗi khởi tạo `ESC @` trước khi đo. Bản đầu của test này dùng
    // regex `[^\x20-\x7E]` để lọc — nó bỏ `\x1B` nhưng GIỮ `@`, nên đếm ra
    // 21 và tôi suýt sửa `row()` cho vừa một phép đo hỏng.
    $line = trim(str_replace("\x1b@", '', $ctx->encoder->bytes()), "\n\r\0");

    // 8 + 5 = 13 cột nội dung, nên phải có 7 khoảng trắng ở giữa.
    expect($line)->toBe('Subtotal       1,000')
        ->and(mb_strlen($line))->toBe(20);
});

it('#1923 row đếm KANJI là hai cột, không phải một', function () {
    // Đây là bất biến của cả slice. `小計` là 2 ký tự nhưng chiếm 4 cột trên
    // đầu in nhiệt; đếm bằng mb_strlen sẽ thừa 2 khoảng trắng và cột tiền lệch
    // dần theo độ dài tên món.
    $narrow = billCtx(20);
    $narrow->row('AB', '100');
    $ascii = trim(str_replace("\x1b@", '', $narrow->encoder->bytes()), "\n\r\0");

    $wide = billCtx(20);
    $wide->row('小計', '100');
    $kanji = trim(str_replace("\x1b@", '', $wide->encoder->bytes()), "\n\r\0");

    // Cùng số KÝ TỰ nhãn (2), nhưng kanji chiếm gấp đôi cột ⇒ ít khoảng trắng
    // hơn đúng 2. Nếu hai bên bằng nhau thì phép đo đang dùng mb_strlen.
    $gapAscii = substr_count($ascii, ' ');
    $gapKanji = substr_count($kanji, ' ');

    expect($gapAscii - $gapKanji)->toBe(2);
});

it('#1923 nhãn quá dài thì dính sát, KHÔNG tràn dòng', function () {
    // Tràn dòng đẩy giá trị xuống dòng dưới và cột tiền vỡ hẳn; dính sát thì
    // xấu nhưng vẫn đọc được đúng con số.
    $ctx = billCtx(10);
    $ctx->row('MotNhanRatDaiQuaKhoGiay', '999,999');

    $line = trim(str_replace("\x1b@", '', $ctx->encoder->bytes()), "\n\r\0");

    expect(substr_count($line, "\n"))->toBe(0)
        ->and($line)->toContain('999,999');
});

it('#1923 money lấy ký hiệu từ CẤU HÌNH, không hard-code', function () {
    // `¥` in trên phiếu tiếng Việt là lỗi không ai báo cáo vì nó trông "chỉ hơi
    // lạ" — nhưng nó sai với mọi quán VN.
    expect(billCtx(48, '¥')->money(1234))->toBe('¥1,234')
        ->and(billCtx(48, '₫')->money(1234))->toBe('₫1,234');
});

it('#1923 money nhóm hàng nghìn giống formatPrice bên Go', function () {
    $ctx = billCtx();

    expect($ctx->money(0))->toBe('¥0')
        ->and($ctx->money(999))->toBe('¥999')
        ->and($ctx->money(1000))->toBe('¥1,000')
        ->and($ctx->money(1234567))->toBe('¥1,234,567');
});
