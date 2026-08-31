<?php

declare(strict_types=1);

use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\PrintJobConfig;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\Renderer\PrintLabels;
use App\Services\Print\Renderer\PrintRenderContext;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\TaxLabels;
use App\Services\Print\Renderer\VatInvoiceInfo;
use Carbon\CarbonImmutable;

/*
 * plan-053 T5.1d (#1932) — `void_notice`, kind ĐẦU TIÊN của họ docs port đủ thân.
 *
 * Chọn nó trước vì nó tự chứa: 5 emitter, không helper chia sẻ. Nghĩa là test
 * này dựng được một tờ giấy HOÀN CHỈNH và so cả tờ, chứ không chỉ so từng block
 * rời — thứ mà bốn kind dở dang kia chưa cho phép.
 */

function emitVoid(string $block, PrintRenderData $data): string
{
    $encoder = new Escpos;
    $ctx = new PrintRenderContext(
        encoder: $encoder,
        definition: ['blocks' => [['id' => $block]]],
        data: $data,
        config: $data->config,
        locale: 'ja',
        width: 48,
        japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'),
        tax: TaxLabels::forLocale('ja'),
    );

    $emitter = app(PrintKindRegistry::class)->planFor('void_notice')->emitters[$block];
    $emitter($ctx, ['id' => $block]);

    return str_replace("\x1b@", '', $encoder->bytes());
}

function voidData(
    ?VatInvoiceInfo $vat = null,
    string $reason = '',
    ?CarbonImmutable $voidedAt = null,
): PrintRenderData {
    return new PrintRenderData(
        kind: 'void_notice',
        config: new PrintJobConfig(currency: '¥'),
        vat: $vat,
        voidReason: $reason,
        voidedAt: $voidedAt,
    );
}

it('#1932 tên biên bản KHÔNG dịch, kể cả khi locale là ja', function () {
    // Rào `CatalogParityTest` G5 canh cùng luật: đây là chứng từ nộp cho cơ quan
    // thuế Việt Nam, nên tên nó cố định bất kể giao diện đang hiển thị tiếng gì.
    // Context ở trên đặt locale = 'ja' chính vì thế.
    expect(emitVoid('void_marker', voidData()))->toContain('BIEN BAN HUY HOA DON');
});

it('#1932 số hoá đơn bị huỷ đọc từ ô vat', function () {
    $out = emitVoid('invoice_number', voidData(vat: new VatInvoiceInfo(invoiceNo: 'HD-00042')));

    expect(trim($out))->toBe('So HD bi huy: HD-00042');
});

it('#1932 KHÔNG có vat thì không in dòng nào', function () {
    // Một biên bản huỷ không nêu được số hoá đơn nào bị huỷ thì vô nghĩa về mặt
    // chứng từ. Thà thiếu dòng còn hơn in một dòng trống trông như đã điền.
    expect(trim(emitVoid('invoice_number', voidData())))->toBe('');
});

it('#1932 thời điểm huỷ lấy từ NGƯỜI GỌI, không lấp bằng đồng hồ máy', function (
    ?CarbonImmutable $voidedAt,
    string $expected,
) {
    // Lấp bằng đồng hồ máy đúng là cách một chứng từ Tokyo bị đóng dấu theo giờ
    // máy chủ UTC (#1091).
    expect(trim(emitVoid('issued_at', voidData(voidedAt: $voidedAt))))->toBe($expected);
})->with([
    'có mốc' => [CarbonImmutable::parse('2026-08-06 14:05:00'), 'Thoi diem huy: 2026/08/06 14:05'],
    'thiếu mốc' => [null, ''],
]);

it('#1932 lý do chỉ-khoảng-trắng bị coi như KHÔNG có', function (string $reason, string $expected) {
    // `trim` rồi mới kiểm rỗng, đúng như `strings.TrimSpace` bên Go — nếu không
    // thì in ra "Ly do: " cụt đuôi.
    expect(trim(emitVoid('order_meta', voidData(reason: $reason))))->toBe($expected);
})->with([
    'lý do thật' => ['Khach doi y', 'Ly do: Khach doi y'],
    'chỉ khoảng trắng' => ['   ', ''],
    'rỗng' => ['', ''],
]);

it('#1932 câu cuối là nội dung BẮT BUỘC, không phải footer do brand soạn', function () {
    // Emitter cố ý không đọc `footer_text` của brand: đây là phần làm chứng từ
    // có hiệu lực, cùng lập luận với nhãn 適格簡易請求書 ở #1734.
    expect(emitVoid('footer_text', voidData()))->toContain('KHACH HANG NHAN BIET HOA DON DA HUY');
});

it('#1932 cả năm block đều đã port — không còn no-op nào trên void_notice', function () {
    // Ghim tính TRỌN VẸN của kind. Nếu ai đó thêm block vào VOID_BLOCKS mà quên
    // đưa vào `ported`, kind này lại thành dở dang và test đỏ ngay.
    $plan = app(PrintKindRegistry::class)->planFor('void_notice');

    $noop = [];
    foreach ($plan->emitters as $id => $emitter) {
        $r = new ReflectionFunction($emitter);

        // Emitter chưa port là closure rỗng khai ngay trong `plan()`; emitter đã
        // port là first-class callable trỏ tới một method có tên.
        if ($r->getName() === '{closure}' || str_contains($r->getName(), '{closure')) {
            $noop[] = $id;
        }
    }

    expect($noop)->toBe([], 'block còn no-op trên void_notice: '.implode(', ', $noop));
});
