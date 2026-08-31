<?php

declare(strict_types=1);

use App\Services\Print\Renderer\DebtSlipInfo;
use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\PrintJobConfig;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\Renderer\PrintLabels;
use App\Services\Print\Renderer\PrintRenderContext;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\PrintRenderItem;
use App\Services\Print\Renderer\PrintRenderOrder;
use App\Services\Print\Renderer\PrintRenderTopping;
use App\Services\Print\Renderer\TablePaidInfo;
use App\Services\Print\Renderer\TaxLabels;
use App\Services\Print\Renderer\VatInvoiceInfo;
use App\Services\Print\Renderer\VatInvoiceLine;
use App\Services\Print\Renderer\VatInvoiceTaxLine;
use App\Services\Print\Renderer\VatInvoiceTopping;
use Carbon\CarbonImmutable;

/*
 * plan-053 T5.1d slice 2 (#1932) — thân emitter họ **docs**.
 *
 * ── Vì sao mọi khẳng định ở đây so BYTE đã mã hoá, không so chuỗi UTF-8 ───
 *
 * Encoder chuyển sang Shift_JIS. Ký hiệu ¥ trong Shift_JIS là byte **0x5C**
 * (chính là vị trí của dấu `\` trong ASCII), nên `expect($out)->toContain('¥')`
 * KHÔNG BAO GIỜ đúng dù phiếu in ra hoàn toàn đúng — và một rào không bao giờ
 * đúng thì hoặc bị xoá, hoặc bị "sửa" thành một rào yếu hơn.
 *
 * `sjis()` mã hoá kỳ vọng bằng chính hàm mà encoder dùng, nên bài test đọc
 * được bằng tiếng người mà vẫn so đúng byte trên dây.
 */

/** Mã hoá kỳ vọng bằng đúng bộ mã encoder ghi ra — xem doc đầu file. */
function sjis(string $s): string
{
    return Escpos::encodeShiftJis($s);
}

/** Bỏ mọi chuỗi điều khiển ESC/POS, chỉ còn chữ + xuống dòng. */
function docsText(string $bytes): string
{
    return (string) preg_replace('/\x1b\x1d\x61.|\x1b\x45.|\x1b\x69..|\x1b\x64.|\x1b\x40/s', '', $bytes);
}

/** @return list<string> */
function docsLines(string $bytes): array
{
    return explode("\n", docsText($bytes));
}

/**
 * Chạy MỘT emitter của một kind và trả byte nó sinh ra.
 *
 * @param  array{locale?: string, width?: int, definition?: array<string, mixed>, block?: array<string, mixed>}  $opts
 */
function docsEmit(string $kind, string $blockId, PrintRenderData $data, array $opts = []): string
{
    $locale = $opts['locale'] ?? 'vi';
    $plan = app(PrintKindRegistry::class)->planFor($kind);

    expect($plan)->not->toBeNull();

    $encoder = new Escpos;
    $ctx = new PrintRenderContext(
        encoder: $encoder,
        definition: $opts['definition'] ?? ['blocks' => [['id' => $blockId]]],
        data: $data,
        config: $data->config,
        locale: $locale,
        width: $opts['width'] ?? 48,
        japaneseDoc: $plan->japaneseDoc,
        labels: PrintLabels::forLocale($locale),
        tax: TaxLabels::forLocale($locale),
    );

    $plan->emitters[$blockId]($ctx, $opts['block'] ?? ['id' => $blockId]);

    return $encoder->bytes();
}

function docsEpilogue(string $kind): string
{
    $plan = app(PrintKindRegistry::class)->planFor($kind);
    $encoder = new Escpos;

    ($plan->epilogue)(new PrintRenderContext(
        encoder: $encoder,
        definition: [],
        data: new PrintRenderData(kind: $kind, config: new PrintJobConfig),
        config: new PrintJobConfig,
        locale: 'vi',
        width: 48,
        japaneseDoc: $plan->japaneseDoc,
        labels: PrintLabels::forLocale('vi'),
        tax: TaxLabels::forLocale('vi'),
    ));

    return $encoder->bytes();
}

function docsVat(array $fields = []): VatInvoiceInfo
{
    return new VatInvoiceInfo(...($fields + ['invoiceNo' => 'HN1-202606-00042']));
}

function docsData(string $kind, array $fields = []): PrintRenderData
{
    return new PrintRenderData(...($fields + [
        'kind' => $kind,
        'config' => new PrintJobConfig(storeName: 'Quan Pho', currency: '¥'),
    ]));
}

// ─── header dùng chung ────────────────────────────────────────────────────

it('#1932 store_info và title vẽ MỘT dòng, không phải hai', function () {
    $data = docsData('vat_invoice', ['vat' => docsVat()]);
    $definition = ['blocks' => [
        ['id' => 'store_info'],
        ['id' => 'title', 'i18n' => ['vi' => 'HOA DON GTGT']],
    ]];

    $plan = app(PrintKindRegistry::class)->planFor('vat_invoice');
    $encoder = new Escpos;
    $ctx = new PrintRenderContext(
        encoder: $encoder,
        definition: $definition,
        data: $data,
        config: $data->config,
        locale: 'vi',
        width: 48,
        japaneseDoc: false,
        labels: PrintLabels::forLocale('vi'),
        tax: TaxLabels::forLocale('vi'),
    );

    // Brand bật CẢ HAI block. Cờ `headerDrawn` phải làm cái thứ hai thành
    // no-op — không có cờ thì phiếu in ra hai dòng tiêu đề.
    $plan->emitters['store_info']($ctx, ['id' => 'store_info']);
    $plan->emitters['title']($ctx, $definition['blocks'][1]);

    $lines = array_values(array_filter(docsLines($encoder->bytes()), fn ($l) => trim($l) !== ''));

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toBe('Quan Pho'.str_repeat(' ', 48 - 8 - 12).'HOA DON GTGT');
});

it('#1932 tiêu đề rỗng thì KHÔNG đệm khoảng trắng tới hết dòng', function () {
    // #1734 — nhánh dưới tạo một hàng dài đầy khoảng trắng đuôi, vô hình trên
    // màn hình và lộ ra trên giấy nhiệt.
    $out = docsEmit('vat_invoice', 'store_info', docsData('vat_invoice', ['vat' => docsVat()]));

    expect(docsLines($out)[0])->toBe('Quan Pho');
});

it('#1932 tiêu đề quá dài thì xuống dòng riêng, căn phải', function () {
    $data = new PrintRenderData(
        kind: 'vat_invoice',
        config: new PrintJobConfig(storeName: str_repeat('X', 40)),
        vat: docsVat(),
    );

    // Chữ tiêu đề luôn lấy từ block `title` của DEFINITION, kể cả khi emitter
    // đang được gọi cho block `store_info` — hai block vẽ chung một dòng.
    $lines = docsLines(docsEmit('vat_invoice', 'title', $data, [
        'definition' => ['blocks' => [['id' => 'title', 'i18n' => ['vi' => 'HOA DON GIA TRI GIA TANG']]]],
    ]));

    expect($lines[0])->toBe(str_repeat('X', 40))
        ->and($lines[1])->toBe(str_repeat(' ', 48 - 24).'HOA DON GIA TRI GIA TANG');
});

it('#1932 chứng từ Nhật ghi đè nhãn brand soạn — và CHỈ khi có 登録番号', function () {
    // #1734 — nhãn 適格簡易請求書 là một TUYÊN BỐ PHÁP LÝ. Brand không được phép
    // dán nó lên một tờ giấy không đủ điều kiện.
    $authored = ['blocks' => [['id' => 'title', 'i18n' => ['ja' => 'ブランドが書いた見出し']]]];

    $withReg = docsEmit('qualified_simplified_invoice', 'title',
        docsData('qualified_simplified_invoice', ['vat' => docsVat(['sellerRegistrationNumber' => 'T1234567890123'])]),
        ['locale' => 'ja', 'definition' => $authored],
    );

    $withoutReg = docsEmit('qualified_simplified_invoice', 'title',
        docsData('qualified_simplified_invoice', ['vat' => docsVat()]),
        ['locale' => 'ja', 'definition' => $authored],
    );

    expect($withReg)->toContain(sjis('適格簡易請求書'))
        ->and($withReg)->not->toContain(sjis('ブランドが書いた見出し'))
        // Không có số ⇒ nhãn biến mất hẳn, tờ giấy chỉ là 領収書.
        ->and($withoutReg)->not->toContain(sjis('適格簡易請求書'))
        ->and($withoutReg)->toContain(sjis('領収書'));
});

// ─── dấu bản sao ──────────────────────────────────────────────────────────

it('#1932 bản in ĐẦU không mang dấu bản sao', function () {
    // Bản nào cũng mang dấu thì cái dấu chẳng nói gì — và khách không phân biệt
    // được bản gốc với bản sao.
    $out = docsEmit('vat_invoice', 'reprint_marker',
        docsData('vat_invoice', ['vat' => docsVat(['reprintNumber' => 1])]));

    expect(docsText($out))->toBe('');
});

it('#1932 số bản in đi trong PAYLOAD chứng từ, không chỉ ở ô chung', function () {
    // Đây là thang ba bậc của `reprintNumber()`: `LANPrintVatInvoice` /
    // `LANPrintDebtSlip` cấp số vào chính payload. Đọc thiếu hai bậc sau ⇒ bản
    // sao thứ hai ra giấy KHÔNG mang dấu, tức trông y hệt bản gốc.
    // Chữ trên dấu theo locale của CẤU HÌNH QUÁN, không theo locale lượt render.
    $config = new PrintJobConfig(storeName: 'Quan Pho', currency: '¥', locale: 'vi');

    $fromVat = docsEmit('vat_invoice', 'reprint_marker', new PrintRenderData(
        kind: 'vat_invoice', config: $config, vat: docsVat(['reprintNumber' => 2]),
    ));

    $fromDebt = docsEmit('debt_slip', 'reprint_marker', new PrintRenderData(
        kind: 'debt_slip', config: $config, debt: new DebtSlipInfo(reprintNumber: 3),
    ));

    expect(trim(docsText($fromVat)))->toBe('BAN IN #2')
        ->and(trim(docsText($fromDebt)))->toBe('BAN IN #3');
});

it('#1932 dấu bản sao căn phải theo CỘT, không theo mã điểm', function () {
    // 「再印刷 #2」 là 7 mã điểm nhưng 9 CỘT. Căn theo mã điểm đẩy dấu ra khỏi
    // mép giấy ở mọi phiếu tiếng Nhật.
    $data = new PrintRenderData(
        kind: 'vat_invoice',
        config: new PrintJobConfig(locale: 'ja'),
        vat: docsVat(['reprintNumber' => 2]),
    );

    $line = docsLines(docsEmit('vat_invoice', 'reprint_marker', $data, ['locale' => 'ja']))[0];

    // 48 cột − 9 cột của dấu = 39 khoảng trắng.
    expect($line)->toBe(str_repeat(' ', 39).sjis('再印刷 #2'));
});

// ─── hoá đơn GTGT (nhánh Việt) ────────────────────────────────────────────

it('#1932 số hoá đơn + thời điểm chia MỘT dòng', function () {
    $data = docsData('vat_invoice', [
        'vat' => docsVat(['issuedAt' => CarbonImmutable::parse('2026-06-20 10:21:00')]),
    ]);

    $line = docsLines(docsEmit('vat_invoice', 'invoice_number', $data))[0];

    expect($line)->toBe('So HD: HN1-202606-00042'.str_repeat(' ', 48 - 23 - 16).'2026/06/20 10:21');
});

it('#1932 mốc đóng băng trên hoá đơn THẮNG `now` của người gọi', function () {
    // In lại hoá đơn tháng trước phải giữ ngày phát hành của nó.
    $data = docsData('vat_invoice', [
        'now' => CarbonImmutable::parse('2026-08-06 09:00:00'),
        'vat' => docsVat(['issuedAt' => CarbonImmutable::parse('2026-06-20 10:21:00')]),
    ]);

    expect(docsText(docsEmit('vat_invoice', 'invoice_number', $data)))->toContain('2026/06/20 10:21');
});

it('#1932 không có mốc nào thì VẪN in số hoá đơn — nó là định danh chứng từ', function () {
    $data = docsData('vat_invoice', ['vat' => docsVat()]);

    expect(trim(docsText(docsEmit('vat_invoice', 'invoice_number', $data))))
        ->toBe('So HD: HN1-202606-00042');
});

it('#1932 khối hai bên in đủ người mua VÀ người bán', function () {
    $data = docsData('vat_invoice', ['vat' => docsVat([
        'companyName' => 'ABC Foods',
        'taxCode' => '0312345678',
        'billingAddress' => '123 Le Loi',
        'sellerRegistrationNumber' => '0999888777',
    ])]);

    $lines = array_values(array_filter(docsLines(docsEmit('vat_invoice', 'customer_header', $data)), fn ($l) => $l !== ''));

    expect($lines)->toBe([
        'NGUOI MUA',
        'Cty:  ABC Foods',
        'MST:  0312345678',
        'DC:   123 Le Loi',
        'NGUOI BAN',
        'Quan Pho',
        // #1224 — MST người bán cạnh tên người bán, đối xứng với khối người mua.
        'MST:  0999888777',
    ]);
});

it('#1932 definition chọn được TRƯỜNG nào của người mua được in', function () {
    $data = docsData('vat_invoice', ['vat' => docsVat([
        'companyName' => 'ABC Foods',
        'taxCode' => '0312345678',
        'billingAddress' => '123 Le Loi',
    ])]);

    $out = docsText(docsEmit('vat_invoice', 'customer_header', $data, [
        'block' => ['id' => 'customer_header', 'fields' => ['customer_tax_code']],
    ]));

    expect($out)->toContain('MST:  0312345678')
        ->and($out)->not->toContain('ABC Foods')
        ->and($out)->not->toContain('123 Le Loi');
});

it('#1932 bảng hàng căn ba cột số về bên phải', function () {
    $data = docsData('vat_invoice', ['vat' => docsVat([
        'items' => [new VatInvoiceLine(name: 'Pho bo', quantity: 2, unitPrice: 65000, lineTotal: 130000)],
    ])]);

    $header = docsLines(docsEmit('vat_invoice', 'column_header', $data));
    $items = docsLines(docsEmit('vat_invoice', 'items', $data));

    // Cột tên = 48 − 25. "SL" đệm trái tới 3, "Don gia" tới 11, "Thanh tien" tới 11.
    expect($header[3])->toBe('San pham'.str_repeat(' ', 23 - 8).' SL    Don gia Thanh tien')
        ->and($items[0])->toBe('Pho bo'.str_repeat(' ', 23 - 6).'  2     65,000    130,000');
});

it('#1932 biến thể, topping và ghi chú in thụt vào dưới dòng món', function () {
    $data = docsData('vat_invoice', ['vat' => docsVat([
        'items' => [new VatInvoiceLine(
            name: 'Pho bo',
            quantity: 1,
            unitPrice: 65000,
            lineTotal: 75000,
            variantName: 'To lon',
            note: 'it hanh',
            toppings: [new VatInvoiceTopping(name: 'Trung', quantity: 2, unitPrice: 5000)],
        )],
    ])]);

    $lines = docsLines(docsEmit('vat_invoice', 'items', $data));

    expect($lines[1])->toBe('   -- To lon')
        // Nhân số lượng: 2 × 5.000.
        //
        // 15 = bề rộng CỘT của "   —— Trung ×2", không phải số ký tự (13): `×`
        // (U+00D7) đi ra Shift_JIS hai byte nên nó chiếm HAI cột. Số này từng là
        // 14 — đo bằng bảng bề rộng cũ, cái tính `×` một cột — và nó là một
        // trong hai chỗ tính tay đã đóng cứng phép đo sai ấy.
        ->and($lines[2])->toBe(sjis('   —— Trung ×2').str_repeat(' ', 48 - 15 - 6).'10,000')
        ->and($lines[3])->toBe('   Ghi chu: it hanh');
});

it('#1932 phiếu chia theo tiền đổi CẢ BA dòng tổng và bỏ khối thuế', function () {
    // #1169 — phiếu chia vẫn liệt kê cả đơn, nên "Tạm tính" sẽ nói dối. Khối
    // thuế theo mức (cơ sở là phần chia) sẽ là một con số thứ BA mâu thuẫn.
    $vat = docsVat([
        'subtotal' => 40000,
        'total' => 44000,
        'isAmountSplit' => true,
        'taxBreakdown' => [new VatInvoiceTaxLine(rate: 10.0, taxable: 40000, tax: 4000)],
        'items' => [new VatInvoiceLine(name: 'Pho bo', quantity: 2, unitPrice: 60000, lineTotal: 120000)],
    ]);

    $data = docsData('vat_invoice', ['vat' => $vat]);

    expect(docsText(docsEmit('vat_invoice', 'subtotal', $data)))->toContain('Tong mon')
        // Σ dòng hàng (120.000), KHÔNG phải tạm tính phần chia (40.000).
        ->and(docsText(docsEmit('vat_invoice', 'subtotal', $data)))->toContain('120,000')
        ->and(docsText(docsEmit('vat_invoice', 'tax_breakdown', $data)))->toBe('')
        ->and(docsText(docsEmit('vat_invoice', 'grand_total', $data)))
        ->toContain('Khach thanh toan (phan chia)')
        ->and(docsText(docsEmit('vat_invoice', 'grand_total', $data)))->toContain('(Hoa don phan chia)');
});

it('#1932 khối thuế theo mức dùng nhãn của LOCALE HOÁ ĐƠN, không của lượt render', function () {
    // Hoá đơn đã phát hành mang ngôn ngữ nó được phát hành; in lại ở một máy
    // đặt locale khác không được đổi chữ trên chứng từ.
    $data = docsData('vat_invoice', ['vat' => docsVat([
        'locale' => 'vi',
        'taxBreakdown' => [new VatInvoiceTaxLine(rate: 8.0, taxable: 10000, tax: 800)],
    ])]);

    $out = docsText(docsEmit('vat_invoice', 'tax_breakdown', $data, ['locale' => 'ja']));

    expect($out)->toContain('Chiu thue 8%')
        ->and($out)->toContain('thue trong')
        ->and($out)->not->toContain(sjis('対象'));
});

it('#1932 hoá đơn cũ không có breakdown thì rơi về dòng thuế đơn mức', function () {
    $data = docsData('vat_invoice', ['vat' => docsVat(['taxAmount' => 2000, 'taxRatePercent' => 8])]);

    expect(docsText(docsEmit('vat_invoice', 'tax_breakdown', $data)))->toContain('Thue 8 %');

    // Không có thuế thì không in dòng nào — một dòng "Thue 0" trên hoá đơn là
    // một khẳng định về thuế mà chứng từ không có căn cứ để nói.
    $noTax = docsData('vat_invoice', ['vat' => docsVat()]);

    expect(docsText(docsEmit('vat_invoice', 'tax_breakdown', $noTax)))->toBe('');
});

it('#1932 block registration_number CỐ Ý không in gì (#1224)', function () {
    // Số đã in trong khối NGƯỜI BÁN. Phát ở đây nữa là in hai lần cùng một số.
    $data = docsData('vat_invoice', ['vat' => docsVat(['sellerRegistrationNumber' => 'T1234567890123'])]);

    expect(docsEmit('vat_invoice', 'registration_number', $data))->toBe(Escpos::INIT);
});

it('#1932 chân hoá đơn Việt mang cột chữ ký + câu miễn trừ', function () {
    $out = docsText(docsEmit('vat_invoice', 'footer_text', docsData('vat_invoice', ['vat' => docsVat()])));

    expect($out)->toContain('Ben mua')
        ->and($out)->toContain('Ben ban')
        // v1 KHÔNG phải hoá đơn điện tử có chữ ký CQT — đây là lý do block này
        // không thể là chữ do brand soạn.
        ->and($out)->toContain('KHONG THAY THE HDDT CUA CO QUAN THUE');
});

it('#1932 tiền tệ ĐÓNG BĂNG trên hoá đơn thắng cấu hình đang sống', function () {
    // In lại hoá đơn tháng trước mà lấy tiền tệ hiện tại là âm thầm đổi mệnh
    // giá một chứng từ đã nộp thuế.
    $data = new PrintRenderData(
        kind: 'vat_invoice',
        config: new PrintJobConfig(currency: '¥'),
        vat: docsVat(['subtotal' => 1000, 'currencyPrefix' => 'd']),
    );

    expect(trim(docsText(docsEmit('vat_invoice', 'subtotal', $data))))->toEndWith('d1,000');
});

// ─── hoá đơn Nhật (適格簡易請求書) ─────────────────────────────────────────

it('#1932 nhánh Nhật đo cột bằng CỘT — 合計 phải căn sát mép phải', function () {
    $data = new PrintRenderData(
        kind: 'qualified_simplified_invoice',
        config: new PrintJobConfig(currency: '¥'),
        vat: docsVat(['total' => 2200, 'currencyPrefix' => '¥']),
    );

    $line = docsLines(docsEmit('qualified_simplified_invoice', 'grand_total', $data, ['locale' => 'ja']))[0];

    // " 合計" = 1 + 4 cột; "¥2,200" = 6 cột ⇒ đệm 48 − 5 − 6 = 37.
    expect($line)->toBe(sjis(' 合計').str_repeat(' ', 37).sjis('¥2,200'));
});

it('#1932 khối tiền Nhật DẪN XUẤT phí phục vụ để các cột cộng đúng', function () {
    // 小計 + サービス料 + 消費税 = 合計. Không dẫn xuất thì phí phục vụ biến mất
    // im lặng và tờ giấy không cộng lại thành tổng.
    $data = new PrintRenderData(
        kind: 'qualified_simplified_invoice',
        config: new PrintJobConfig(currency: '¥'),
        vat: docsVat(['subtotal' => 2000, 'taxAmount' => 200, 'total' => 2500, 'currencyPrefix' => '¥']),
    );

    $out = docsText(docsEmit('qualified_simplified_invoice', 'subtotal', $data, ['locale' => 'ja']));

    expect($out)->toContain(sjis('サービス料'))
        ->and($out)->toContain(sjis('¥300'));
});

it('#1932 giảm giá đơn (điều chỉnh ÂM) in 値引き, không phải サービス料', function () {
    $data = new PrintRenderData(
        kind: 'qualified_simplified_invoice',
        config: new PrintJobConfig(currency: '¥'),
        vat: docsVat(['subtotal' => 2000, 'taxAmount' => 200, 'total' => 2000, 'currencyPrefix' => '¥']),
    );

    $out = docsText(docsEmit('qualified_simplified_invoice', 'subtotal', $data, ['locale' => 'ja']));

    expect($out)->toContain(sjis('値引き'))
        ->and($out)->toContain(sjis('-¥200'))
        ->and($out)->not->toContain(sjis('サービス料'));
});

it('#1932 câu miễn trừ Nhật CHỈ in khi tờ giấy chưa đủ điều kiện (#1734)', function () {
    // Trước #1734 nó in vô điều kiện ngay dưới nhãn 適格簡易請求書: tờ giấy tự
    // nhận đủ điều kiện ở trên rồi tự phủ nhận ở dưới, và kế toán của khách từ
    // chối dùng nó để khấu trừ thuế đầu vào.
    $qualified = docsEmit('qualified_simplified_invoice', 'footer_text',
        docsData('qualified_simplified_invoice', ['vat' => docsVat(['sellerRegistrationNumber' => 'T1'])]),
        ['locale' => 'ja']);

    $unqualified = docsEmit('qualified_simplified_invoice', 'footer_text',
        docsData('qualified_simplified_invoice', ['vat' => docsVat()]),
        ['locale' => 'ja']);

    expect($qualified)->not->toContain(sjis('※適格請求書等の代替ではありません'))
        ->and($qualified)->toContain(sjis('登録番号 T1'))
        ->and($unqualified)->toContain(sjis('※適格請求書等の代替ではありません'));
});

it('#1932 khách lẻ không gõ gì thì khối 【お客様】 in dòng gạch để viết tay', function () {
    $blank = docsText(docsEmit('qualified_simplified_invoice', 'customer_header',
        docsData('qualified_simplified_invoice', ['vat' => docsVat()]), ['locale' => 'ja']));

    $named = docsText(docsEmit('qualified_simplified_invoice', 'customer_header',
        docsData('qualified_simplified_invoice', ['vat' => docsVat(['buyerName' => sjis('山田') === '' ? '' : '山田'])]),
        ['locale' => 'ja']));

    expect($blank)->toContain('____')
        ->and($named)->not->toContain('____')
        ->and($named)->toContain(sjis('山田'));
});

it('#1932 phiếu chia theo tiền VẪN in khối thuế ở nhánh Nhật', function () {
    // Khác hẳn nhánh Việt (bỏ hẳn khối). Phiếu chia Nhật mang breakdown đã phân
    // bổ của chính khách đó, nên phần thuế vốn thiếu giờ có in ra.
    $data = new PrintRenderData(
        kind: 'qualified_simplified_invoice',
        config: new PrintJobConfig(currency: '¥'),
        vat: docsVat([
            'isAmountSplit' => true,
            'currencyPrefix' => '¥',
            'taxBreakdown' => [new VatInvoiceTaxLine(rate: 10.0, taxable: 1000, tax: 100)],
        ]),
    );

    expect(docsText(docsEmit('qualified_simplified_invoice', 'tax_breakdown', $data, ['locale' => 'ja'])))
        ->toContain(sjis('10%対象'));
});

// ─── biên bản huỷ hoá đơn ─────────────────────────────────────────────────

it('#1932 biên bản huỷ luôn tự xưng là biên bản huỷ (TR-18)', function () {
    // Một biên bản huỷ không nói rằng nó là biên bản huỷ thì nó là một cái
    // biên lai — khách cầm về tưởng đã mua hàng.
    $out = docsEmit('void_notice', 'void_marker', docsData('void_notice'));

    expect(docsText($out))->toContain('BIEN BAN HUY HOA DON')
        // Canh giữa rồi TRẢ VỀ canh trái, nếu không mọi dòng sau đều lệch.
        ->and($out)->toEndWith(Escpos::ALIGN_LEFT);
});

it('#1932 biên bản huỷ in số hoá đơn bị huỷ, thời điểm và lý do', function () {
    $data = docsData('void_notice', [
        'vat' => docsVat(),
        'voidedAt' => CarbonImmutable::parse('2026-06-21 08:30:00'),
        'voidReason' => 'Sai ma so thue',
    ]);

    expect(trim(docsText(docsEmit('void_notice', 'invoice_number', $data))))
        ->toBe('So HD bi huy: HN1-202606-00042')
        ->and(trim(docsText(docsEmit('void_notice', 'issued_at', $data))))
        ->toBe('Thoi diem huy: 2026/06/21 08:30')
        ->and(trim(docsText(docsEmit('void_notice', 'order_meta', $data))))
        ->toBe('Ly do: Sai ma so thue');

    // Lý do rỗng ⇒ không in dòng cụt "Ly do:".
    expect(docsText(docsEmit('void_notice', 'order_meta', docsData('void_notice'))))->toBe('');
});

// ─── phiếu ghi nợ ─────────────────────────────────────────────────────────

it('#1932 phiếu nợ in ngày căn trái + mã đơn căn phải', function () {
    $data = docsData('debt_slip', [
        'now' => CarbonImmutable::parse('2026-06-20 10:21:00'),
        'order' => new PrintRenderOrder(orderCode: 'WS-019e-20260608-004'),
    ]);

    expect(docsLines(docsEmit('debt_slip', 'issued_at', $data))[0])
        ->toBe('2026/06/20 10:21'.str_repeat(' ', 48 - 16 - 4).'#004');
});

it('#1932 không có `now` thì phiếu nợ VẪN in mã đơn', function () {
    // Mã đơn là thứ đối chiếu được công nợ; một mốc thời gian do renderer bịa
    // ra là lỗi #1091.
    $data = docsData('debt_slip', ['order' => new PrintRenderOrder(orderCode: 'WS-019e-20260608-004')]);

    expect(trim(docsText(docsEmit('debt_slip', 'issued_at', $data))))->toEndWith('#004');
});

it('#1932 khối khách nợ để "-" khi chưa có tên, và bỏ hẳn SDT/MST rỗng', function () {
    $named = docsText(docsEmit('debt_slip', 'customer_header',
        docsData('debt_slip', ['debt' => new DebtSlipInfo(customerName: 'Anh Ba', customerPhone: '0909')])));

    $blank = docsText(docsEmit('debt_slip', 'customer_header',
        docsData('debt_slip', ['debt' => new DebtSlipInfo])));

    expect($named)->toContain('Anh Ba')
        ->and($named)->toContain('SDT')
        ->and($blank)->toContain('Khach hang')
        // Ô trống phải HIỆN RA để thu ngân điền tay, không được biến mất.
        ->and($blank)->toContain('-')
        ->and($blank)->not->toContain('SDT')
        ->and($blank)->not->toContain('MST');
});

it('#1932 phiếu nợ KHÔNG đóng dấu ※ thuế suất giảm', function () {
    // PHIẾU GHI NỢ là bản ghi công nợ, không phải biên lai インボイス.
    $data = docsData('debt_slip', [
        'items' => [new PrintRenderItem(
            menuItemName: 'Banh mi',
            quantity: 1,
            unitPrice: 20000,
            taxRate: 8.0,
        )],
    ]);

    expect(docsText(docsEmit('debt_slip', 'items', $data)))->not->toContain(sjis('※'));
});

it('#1932 dòng món phiếu nợ mang biến thể, topping và ghi chú', function () {
    $data = docsData('debt_slip', [
        'items' => [new PrintRenderItem(
            menuItemName: 'Pho bo',
            quantity: 2,
            unitPrice: 65000,
            skuVariantName: 'To lon',
            note: 'it hanh',
            toppings: [new PrintRenderTopping(name: 'Trung', quantity: 1, unitPrice: 5000)],
        )],
    ]);

    $lines = docsLines(docsEmit('debt_slip', 'items', $data));

    expect($lines[0])->toBe('2  Pho bo'.str_repeat(' ', 48 - 3 - 6 - 8).sjis('¥130,000'))
        ->and($lines[1])->toBe('   -- To lon')
        // Hai thay đổi chồng lên nhau ở đúng dòng này, và cả hai đều đúng:
        //
        //   · #2812 — món qty 2, topping qty 1 ⇒ số tiền là của CẢ DÒNG
        //     (¥10.000), không phải một suất (¥5.000). Kỳ vọng cũ ghim hành vi
        //     sai; golden liên repo là nguồn và `SlipByteParityTest` so hash
        //     byte với Go xác nhận chiều này.
        //   · #2757 — mọi số tiền đệm tới bề rộng của giá RỘNG NHẤT trên tờ
        //     (`¥130,000`, 8 cột) rồi mới canh phải, nên dấu ¥ thẳng một cột.
        //     `¥10,000` rộng 7 ⇒ thừa ra MỘT khoảng trắng đuôi.
        ->and($lines[2])->toBe('   -- 2 x Trung'.str_repeat(' ', 48 - 3 - 3 - 9 - 8).sjis('¥10,000').' ')
        ->and($lines[3])->toBe('   Ghi chu: it hanh');
});

it('#1932 topping mã hoá trong ghi chú (app Handy) vẫn in ra', function () {
    // Một đơn từ Handy có `toppings` rỗng nhưng `note` đầy. Bỏ nhánh dự phòng
    // này thì topping khách đã gọi biến mất khỏi phiếu.
    $data = docsData('debt_slip', [
        'items' => [new PrintRenderItem(
            menuItemName: 'Pho bo',
            quantity: 1,
            unitPrice: 65000,
            note: "+ Trung ¥5,000\n- Hanh\nit cay",
        )],
    ]);

    $out = docsText(docsEmit('debt_slip', 'items', $data));

    expect($out)->toContain('-- Trung')
        ->and($out)->toContain('5,000')
        ->and($out)->toContain('-- Hanh')
        // Dòng KHÔNG có tiền tố +/- là ghi chú thật của khách, phải hiện dưới
        // nhãn "Ghi chu:" chứ không bị giấu thành một topping.
        ->and($out)->toContain('Ghi chu: it cay');
});

it('#1932 KHÔNG khai số ghi nợ ⇒ cả hoá đơn vào sổ nợ', function () {
    // Rơi về 0 sẽ in "GHI NO ¥0" trên một tờ phiếu nợ — một chứng từ nói rằng
    // khách không nợ gì.
    $whole = docsEmit('debt_slip', 'debt_summary',
        docsData('debt_slip', ['total' => 130000, 'debt' => new DebtSlipInfo]));

    $partial = docsEmit('debt_slip', 'debt_summary',
        docsData('debt_slip', ['total' => 130000, 'debt' => new DebtSlipInfo(debtAmount: 30000)]));

    expect(trim(docsText($whole)))->toEndWith(sjis('¥130,000'))
        ->and(trim(docsText($partial)))->toEndWith(sjis('¥30,000'));
});

it('#1932 phiếu nợ in tổng, đã trả và dòng chữ ký', function () {
    $data = docsData('debt_slip', ['total' => 130000, 'paidShown' => 100000]);

    expect(trim(docsText(docsEmit('debt_slip', 'grand_total', $data))))->toStartWith('Tong')
        ->and(trim(docsText(docsEmit('debt_slip', 'payments', $data))))->toStartWith('Da thanh toan')
        ->and(docsText(docsEmit('debt_slip', 'debt_signature', $data)))
        ->toContain('Khach hang xac nhan da nhan no');
});

it('#1932 tiêu đề cột phiếu nợ căng lại theo bề rộng giấy THẬT', function () {
    // Chuỗi lưu sẵn khoảng trắng (cách người ta gõ vào một ô form), nhưng một
    // tiêu đề soạn cho 32 cột sẽ dính hoặc trôi trên 48.
    // Dải "trắng / gạch / trắng" đứng trên tiêu đề, nên dòng chữ là thứ TƯ.
    $line = docsLines(docsEmit('debt_slip', 'column_header', docsData('debt_slip'), [
        'block' => ['id' => 'column_header', 'i18n' => ['vi' => 'San pham   Thanh tien']],
    ]))[3];

    expect($line)->toBe('San pham'.str_repeat(' ', 48 - 8 - 10).'Thanh tien');
});

// ─── giấy báo bàn đã thanh toán ───────────────────────────────────────────

it('#1932 giấy báo bàn có tiêu đề cao gấp đôi cho người chạy bàn liếc qua', function () {
    $data = docsData('table_paid', ['tablePaid' => new TablePaidInfo(tableNumber: 'A-3')]);

    $out = docsEmit('table_paid', 'paid_summary', $data, ['width' => 42]);

    expect(docsText($out))->toContain('DA THANH TOAN BAN A-3')
        // Bật CAO GẤP ĐÔI rồi trả về cỡ thường — thiếu vế sau thì cả phần còn
        // lại của phiếu in ra khổng lồ.
        ->and($out)->toContain(Escpos::DOUBLE_HEIGHT)
        ->and($out)->toContain(Escpos::NORMAL_SIZE);
});

it('#1932 chưa biết số bàn thì in "-", không bỏ trống dòng tiêu đề', function () {
    $out = docsText(docsEmit('table_paid', 'paid_summary',
        docsData('table_paid', ['tablePaid' => new TablePaidInfo]), ['width' => 42]));

    expect($out)->toContain('DA THANH TOAN BAN -');
});

it('#1932 giấy báo bàn dùng nhãn mã đơn TRÙNG KHÍT phiếu bán hàng', function () {
    // Chạy bàn phải đối chiếu mẩu giấy này với biên lai của khách bằng CÙNG một
    // con số, nên hai nhãn phải là một chuỗi.
    $data = docsData('table_paid', ['tablePaid' => new TablePaidInfo(orderCode: 'WS-019e-20260608-004')]);

    expect(trim(docsText(docsEmit('table_paid', 'order_meta', $data, ['width' => 42]))))
        ->toBe('So HD:'.str_repeat(' ', 42 - 6 - 3).'004')
        ->and(PrintLabels::forLocale('vi')->orderNo)->toBe('So phieu');
});

it('#1932 giấy báo bàn KHÔNG bịa tên quán khi cấu hình bỏ trống', function () {
    // Khác header hoá đơn (rơi về "Store"): đây là giấy nội bộ, một dòng "Store"
    // bịa ra không giúp ai.
    $data = new PrintRenderData(kind: 'table_paid', config: new PrintJobConfig);

    expect(docsText(docsEmit('table_paid', 'store_info', $data, ['width' => 42])))->toBe('');
});

it('#1932 giờ thanh toán in NGUYÊN chuỗi đã định dạng theo múi giờ chi nhánh', function () {
    // #1091 — chuỗi đã định dạng ở nơi biết múi giờ chi nhánh; tầng in không
    // được định dạng lại bằng đồng hồ app.
    $data = docsData('table_paid', ['tablePaid' => new TablePaidInfo(paidAt: '2026/06/20 10:21')]);

    expect(trim(docsText(docsEmit('table_paid', 'issued_at', $data, ['width' => 42]))))
        ->toBe('2026/06/20 10:21');
});

// ─── chữ do brand soạn ────────────────────────────────────────────────────

it('#1932 footer_text của phiếu nợ / giấy báo bàn là chữ brand, canh theo block', function () {
    foreach (['debt_slip' => 48, 'table_paid' => 42] as $kind => $width) {
        $out = docsLines(docsEmit($kind, 'footer_text', docsData($kind), [
            'width' => $width,
            'block' => ['id' => 'footer_text', 'align' => 'center', 'i18n' => ['vi' => 'Cam on quy khach']],
        ]));

        expect($out[0])->toBe(str_repeat(' ', intdiv($width - 16, 2)).'Cam on quy khach');
    }
});

// ─── epilogue ─────────────────────────────────────────────────────────────

it('#1932 MỌI kind họ docs đều kết thúc bằng lệnh CẮT giấy', function () {
    // Một phiếu không cắt là tờ giấy dính liền tờ sau — nhân viên xé tay, và
    // chứng từ của hai khách nằm trên một dải giấy.
    foreach (['vat_invoice', 'qualified_simplified_invoice', 'void_notice', 'debt_slip', 'table_paid'] as $kind) {
        expect(docsEpilogue($kind))->toEndWith(Escpos::CUT);
    }
});

it('#1932 lề đuôi trước khi cắt khác nhau theo kind, và đó là đo được', function () {
    // Hoá đơn GTGT KHÔNG feed (chân phiếu tự để lại 3 dòng trắng); phiếu nợ và
    // biên bản huỷ feed 3; giấy báo bàn feed 1. Gộp làm một là hoặc cắt sát
    // chữ, hoặc phí giấy trên mỗi phiếu.
    $feeds = fn (string $kind): int => substr_count(docsEpilogue($kind), Escpos::LINE_FEED);

    expect($feeds('vat_invoice'))->toBe(0)
        ->and($feeds('qualified_simplified_invoice'))->toBe(0)
        ->and($feeds('void_notice'))->toBe(3)
        ->and($feeds('debt_slip'))->toBe(3)
        ->and($feeds('table_paid'))->toBe(1);
});
