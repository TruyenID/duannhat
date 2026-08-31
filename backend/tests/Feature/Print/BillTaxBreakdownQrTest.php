<?php

declare(strict_types=1);

use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\PrintJobConfig;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\Renderer\PrintLabels;
use App\Services\Print\Renderer\PrintRenderContext;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\PrintRenderer;
use App\Services\Print\Renderer\PrintRenderItem;
use App\Services\Print\Renderer\PrintRenderOrder;
use App\Services\Print\Renderer\PrintRenderProfile;
use App\Services\Print\Renderer\PrintRenderSlip;
use App\Services\Print\Renderer\ReceiptTaxSummary;
use App\Services\Print\Renderer\TaxLabels;

/*
 * plan-053 T5.1d (#1937) — hai emitter CUỐI của họ bill: `tax_breakdown` và
 * `qr_block`. Cả hai trước đó no-op vì THIẾU DỮ LIỆU, không phải vì chưa viết.
 *
 * ── Bảng mã: đừng so với '¥' UTF-8 ────────────────────────────────────────
 *
 * Encoder chuyển sang Shift_JIS cho locale `ja`, và trong Shift_JIS ký hiệu ¥
 * LÀ byte 0x5C. Mọi khẳng định về tiền dưới đây so phần SỐ, hoặc so "\x5C1,000".
 */

/**
 * Dựng context rồi chạy MỘT emitter của họ bill.
 *
 * Chạy `prologue` thật (không set cờ bằng tay) vì `tax_breakdown` đọc
 * `showTaxBreakdown` — thứ mà `prepareBillTax` tính. Set tay là kiểm một trạng
 * thái mà renderer thật không bao giờ ở trong.
 *
 * @param  array<string, mixed>  $block
 */
function emitBillTaxQr(
    array $block,
    PrintRenderData $data,
    ?ReceiptTaxSummary $snapshot = null,
    int $width = 48,
    string $locale = 'ja',
): string {
    $encoder = new Escpos;

    $ctx = new PrintRenderContext(
        encoder: $encoder,
        definition: ['blocks' => [$block]],
        data: $data,
        config: $data->config,
        locale: $locale,
        width: $width,
        japaneseDoc: false,
        labels: PrintLabels::forLocale($locale),
        tax: TaxLabels::forLocale($locale),
        taxBreakdown: $snapshot,
    );

    $plan = app(PrintKindRegistry::class)->planFor($data->kind);
    ($plan->prologue)($ctx);

    // Byte của prologue là TIỀN ĐỀ, không phải sản phẩm của block đang đo, nên
    // cắt theo ĐỘ DÀI sau prologue thay vì gỡ một hằng đã biết.
    //
    // Bản cũ `str_replace("\x1b@", '')` chỉ gỡ đúng lệnh khởi tạo. Ngày prologue
    // họ bill được bổ sung `ESC GS a 0` cho khớp Go (#1171), bốn phép `toBe('')`
    // ở dưới đỏ — không phải vì emitter phát thừa gì, mà vì helper tính 4 byte
    // của prologue vào output của block. Đo bằng độ dài thì mọi lệnh prologue
    // thêm về sau đều tự động nằm ngoài phép đo.
    $prologueLength = $encoder->length();

    $plan->emitters[$block['id']]($ctx, $block);

    return substr($encoder->bytes(), $prologueLength);
}

/** @param list<array<string, mixed>> $items */
function taxQrData(array $overrides = [], array $items = []): PrintRenderData
{
    return new PrintRenderData(
        kind: $overrides['kind'] ?? 'receipt',
        config: $overrides['config'] ?? new PrintJobConfig(currency: '¥'),
        order: array_key_exists('order', $overrides)
            ? $overrides['order']
            : new PrintRenderOrder(orderCode: 'HCM-2026-A1B2', orderType: 'dine_in'),
        items: array_map(static fn (array $i) => new PrintRenderItem(...$i), $items),
        total: $overrides['total'] ?? 0,
        deltaBill: $overrides['deltaBill'] ?? false,
    );
}

/** Snapshot thật, hình dạng của `OrderTaxBreakdownReads::forOrders()`. */
function taxSnapshot(array $byRate): ReceiptTaxSummary
{
    return ReceiptTaxSummary::fromBreakdown(['by_rate' => $byRate]);
}

/*
 * ── tax_breakdown, nhánh THEO MỨC ────────────────────────────────────────
 */

it('#1937 tax_breakdown in một dòng cho MỖI mức, lấy số từ snapshot', function () {
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData(),
        taxSnapshot([
            ['rate' => 10.0, 'taxable' => 2915, 'tax' => 265],
            ['rate' => 8.0, 'taxable' => 300, 'tax' => 22],
        ]),
    );

    $lines = array_values(array_filter(explode("\n", $out), static fn ($l) => trim($l) !== ''));

    expect($lines)->toHaveCount(2);

    // Sắp theo mức TĂNG DẦN — `fromBreakdown` usort, không phải thứ tự đầu vào.
    expect($lines[0])->toContain('8%')->toContain('300')->toContain('22')
        ->and($lines[1])->toContain('10%')->toContain('2,915')->toContain('265');
});

it('#1937 số in ra là số của SNAPSHOT, không phải số tính lại từ mức × cơ sở', function () {
    // Đây là điểm chính của cả emitter. 10% của 2,915 là 291.5 — bất kỳ phép
    // tính lại nào cũng ra 291 hoặc 292. Snapshot nói 265 (làm tròn MỘT lần cho
    // mỗi nhóm mức ở Cloud rồi mới phân bổ), và 265 là con số trên hoá đơn đã
    // phát hành. In ra số khác nghĩa là báo cáo thuế lệch.
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData(),
        taxSnapshot([['rate' => 10.0, 'taxable' => 2915, 'tax' => 265]]),
    );

    expect($out)->toContain('265')
        ->and($out)->not->toContain('291')
        ->and($out)->not->toContain('292');
});

it('#1937 khối thuế thụt 3 cột — nó là 内税 NẰM DƯỚI tổng, không phải khoản cộng thêm', function () {
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData(),
        taxSnapshot([['rate' => 10.0, 'taxable' => 1000, 'tax' => 90]]),
    );

    $line = rtrim(explode("\n", $out)[0], "\r");

    expect($line)->toStartWith('   ')
        ->and($line[3] ?? '')->not->toBe(' ');

    // …và bề rộng CÒN LẠI vẫn đúng khổ: cột tiền phải chạm mép phải của 48 cột,
    // không phải 45 rồi thừa 3. Sai chỗ này thì khối thuế lệch khỏi dòng tổng.
    expect(strlen($line))->toBe(48);
});

it('#1937 dòng gộp và dòng theo mức thẳng cùng một mép phải', function () {
    // Hai nhánh của cùng một block: nếu chúng lệch mép thì một quán vừa nâng
    // cấp dữ liệu sẽ thấy khối thuế nhảy 3 cột mà không ai đổi template.
    $perRate = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData(),
        taxSnapshot([['rate' => 10.0, 'taxable' => 1000, 'tax' => 90]]),
    );

    $legacy = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData(['order' => new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in', taxAmount: 90)]),
    );

    expect(strlen(rtrim(explode("\n", $legacy)[0], "\r")))
        ->toBe(strlen(rtrim(explode("\n", $perRate)[0], "\r")));
});

it('#1937 khối theo mức KHÔNG mang dấu ※ — ※ thuộc dòng món và chú thích', function () {
    // Thêm ※ ở đây là chỗ thứ BA nói cùng một việc, và nó sẽ lệch khi ai đó
    // sửa hai chỗ kia.
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData(),
        taxSnapshot([
            ['rate' => 8.0, 'taxable' => 300, 'tax' => 22],
            ['rate' => 10.0, 'taxable' => 1000, 'tax' => 90],
        ]),
    );

    expect($out)->not->toContain(TaxLabels::REDUCED_MARKER)
        // …và mã hoá Shift_JIS của ※ cũng không có mặt (0x81 0x9F).
        ->and($out)->not->toContain("\x81\x9F");
});

it('#1937 snapshot dẫn dắt CẢ dấu ※, không để items nói khác', function () {
    // Cặp bất biến: dấu trên dòng món và khối thuế phải đến từ CÙNG một bản
    // tổng hợp. Ở đây snapshot có hai mức trong khi các dòng món chỉ mang một —
    // `has_reduced` phải theo snapshot.
    $data = taxQrData(items: [
        ['menuItemName' => 'Bia', 'quantity' => 1, 'unitPrice' => 500, 'taxRate' => 10.0],
    ]);

    $encoder = new Escpos;
    $ctx = new PrintRenderContext(
        encoder: $encoder, definition: ['blocks' => []], data: $data, config: $data->config,
        locale: 'ja', width: 48, japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'), tax: TaxLabels::forLocale('ja'),
        taxBreakdown: taxSnapshot([
            ['rate' => 8.0, 'taxable' => 300, 'tax' => 22],
            ['rate' => 10.0, 'taxable' => 500, 'tax' => 45],
        ]),
    );

    ($plan = app(PrintKindRegistry::class)->planFor('receipt'))->prologue !== null
        && ($plan->prologue)($ctx);

    expect($ctx->taxSummary)->toBe(['max_rate' => 10.0, 'has_reduced' => true]);
});

it('#1937 phiếu con của lần chia bill KHÔNG in khối thuế', function () {
    // `showTaxBreakdown` false. Khối thuế trên một phiếu con là thuế của CẢ đơn
    // in cạnh số tiền của một người.
    $data = new PrintRenderData(
        kind: 'receipt',
        config: new PrintJobConfig(currency: '¥'),
        order: new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in', taxAmount: 90),
        slip: new PrintRenderSlip(splitCount: 3, billTotal: 1000),
    );

    expect(emitBillTaxQr(['id' => 'tax_breakdown'], $data, taxSnapshot([
        ['rate' => 10.0, 'taxable' => 1000, 'tax' => 90],
    ])))->toBe('');
});

/*
 * ── #2064 — 登録番号 KHÔNG đi chung cờ với khối thuế ──────────────────────
 *
 * Cả BỐN thứ dưới đây từng nằm sau đúng một cờ `showTaxBreakdown`: khối thuế
 * theo mức (④⑤), chú thích ※ (③), dấu ※ trên dòng món (③), và 登録番号 (①).
 * Ba cái đầu là chuyện của SỐ TIỀN — chúng ẩn trên phiếu con vì phần chia chưa
 * mang ảnh chụp phân bổ theo mức (#2677). Cái thứ tư là DANH TÍNH NGƯỜI BÁN,
 * không phải thuộc tính của tờ giấy, nên nó đi cùng ba cái kia là TAI NẠN.
 *
 * Rào phải chứng minh CẢ HAI CHIỀU trong CÙNG một lượt render: nếu chỉ khẳng
 * định "số có mặt", thì một bản vá bật hết mọi khối lên phiếu con — đúng thứ
 * bị cấm cho tới khi giải xong bài toán làm tròn — vẫn xanh.
 *
 * Ba ca dựng qua `PrintRenderer::render()` (entry point thật, có prologue thật)
 * chứ không set `showTaxBreakdown` bằng tay: set tay thì cờ không còn được suy
 * ra từ `slip`, và ngày `$isSplitSubBill` tính sai sẽ không có gì đỏ.
 */

/** @param list<array<string, mixed>> $byRate */
function renderSubBillProof(?PrintRenderSlip $slip, string $reg, array $byRate): string
{
    $data = new PrintRenderData(
        kind: 'receipt',
        config: new PrintJobConfig(currency: '¥', sellerRegistrationNumber: $reg),
        order: new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in', taxAmount: 69),
        items: [
            new PrintRenderItem(menuItemName: 'Bento', quantity: 1, unitPrice: 300, taxRate: 8.0),
            new PrintRenderItem(menuItemName: 'Bia', quantity: 1, unitPrice: 500, taxRate: 10.0),
        ],
        slip: $slip,
    );

    return app(PrintRenderer::class)->render(
        ['kind' => 'receipt', 'blocks' => [
            ['id' => 'items'], ['id' => 'tax_breakdown'],
            ['id' => 'tax_legend'], ['id' => 'registration_number'],
        ]],
        $data,
        new PrintRenderProfile(columns: 48),
        'ja',
        taxSnapshot($byRate),
    )->bytes();
}

it('#2064 phiếu con chia bill GIỮ 登録番号 nhưng VẪN ẩn khối thuế theo mức + ※', function () {
    // Tiền đề của ruling Q13 — "インボイス chỉ cần trên chứng từ chính" — đòi
    // TỒN TẠI một chứng từ chính. #1779 gỡ toàn bộ đường phát hành/lưu hoá đơn,
    // nên với một bàn chia bill, phiếu con là tờ giấy DUY NHẤT khách cầm về.
    // Thiếu trường ① ⇒ khách B2B không khấu trừ được thuế đầu vào.
    $out = renderSubBillProof(
        new PrintRenderSlip(amountPaid: 400, slipIndex: 1, splitCount: 2, billTotal: 800),
        'T1234567890123',
        [['rate' => 8.0, 'taxable' => 300, 'tax' => 22], ['rate' => 10.0, 'taxable' => 500, 'tax' => 45]],
    );

    expect($out)
        // ① danh tính người bán — PHẢI có.
        ->toContain(Escpos::encodeShiftJis('登録番号: T1234567890123'))
        // ④⑤ khối theo mức — VẪN ẩn (phần chia chưa có ảnh chụp theo mức, #2677).
        ->not->toContain(Escpos::encodeShiftJis('対象'))
        // ③ dấu ※ trên dòng món + chú thích — VẪN ẩn, cùng lý do.
        ->not->toContain(Escpos::encodeShiftJis('※'));
});

it('#2064 đơn NGUYÊN không đổi một dòng — số CÓ và khối thuế CÓ', function () {
    // Ca "phải IM": tờ `receipt` của đơn nguyên hôm nay đã đủ 5 nhóm trường của
    // 適格簡易請求書. Việc gỡ gate cho phiếu con không được chạm tới nó.
    $out = renderSubBillProof(
        null,
        'T1234567890123',
        [['rate' => 8.0, 'taxable' => 300, 'tax' => 22], ['rate' => 10.0, 'taxable' => 500, 'tax' => 45]],
    );

    expect($out)
        ->toContain(Escpos::encodeShiftJis('登録番号: T1234567890123'))
        ->toContain(Escpos::encodeShiftJis('8%対象'))
        ->toContain(Escpos::encodeShiftJis('10%対象'))
        ->toContain(Escpos::encodeShiftJis('※'));
});

it('#2064 quán 免税事業者 trên phiếu con: KHÔNG dòng trống, KHÔNG nhãn cụt', function () {
    // Không có số đăng ký là HỢP PHÁP (#1152) — vắng mặt phải im lặng. Rào này
    // bắt cái bẫy hiển nhiên của việc gỡ gate: in nhãn `登録番号: ` với vế phải
    // rỗng, hoặc phát một dòng trắng vào chỗ nó từng đứng.
    $out = renderSubBillProof(
        new PrintRenderSlip(amountPaid: 400, slipIndex: 1, splitCount: 2, billTotal: 800),
        '',
        [['rate' => 10.0, 'taxable' => 800, 'tax' => 72]],
    );

    expect($out)->not->toContain(Escpos::encodeShiftJis('登録番号'));

    // "Sạch" phải đo được, không phải cảm nhận: phiếu không được kết thúc bằng
    // dòng trắng mà block đã tắt bỏ lại.
    $lines = explode("\n", $out);
    expect(rtrim(end($lines), "\r"))->not->toBe('');
});

/*
 * ── tax_breakdown, nhánh GỘP (legacy) ────────────────────────────────────
 */

it('#1937 không có snapshot thì rơi về MỘT dòng thuế gộp, không phải khối 0', function () {
    // Cạm bẫy đã trả giá: một bản trước dựng `ReceiptTaxSummary` với
    // taxable/tax = 0 để lấy dấu ※, và vì nhánh này đọc `blocks` trước tiên,
    // phiếu in ra những dòng thuế BỊA đúng số 0 — trông hoàn toàn hợp lệ.
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData(
            ['order' => new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in', taxAmount: 265)],
            [['menuItemName' => 'Bia', 'quantity' => 1, 'unitPrice' => 500, 'taxRate' => 10.0]],
        ),
    );

    $lines = array_values(array_filter(explode("\n", $out), static fn ($l) => trim($l) !== ''));

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toContain('265')
        ->and($lines[0])->not->toContain('対象');
});

it('#1937 snapshot RỖNG cũng rơi về dòng gộp — đơn cũ không có mức nào', function () {
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData(['order' => new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in', taxAmount: 90)]),
        ReceiptTaxSummary::empty(),
    );

    expect(trim($out))->toContain('90')->not->toContain('対象');
});

it('#1937 tổng thuế đã chốt của đơn THẮNG phép trích từ tổng', function () {
    // Đảo thứ tự là in ra một con số do tầng in nghĩ ra đè lên con số backend
    // đã ghi sổ. 1,100 @10% trích ra 100; đơn nói 265 thì in 265.
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData([
            'order' => new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in', taxAmount: 265),
            'total' => 1100,
        ]),
    );

    expect($out)->toContain('265')->not->toContain('100');
});

it('#2067 dòng thuế gộp là `order.tax_amount`, in NGUYÊN VĂN', function () {
    // Con số duy nhất tầng in được phép đưa lên giấy. 88 trên tổng 1,100 không
    // phải một tỉ lệ tròn nào — đó là điểm: nó đến từ đơn, không từ phép chia.
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData([
            'order' => new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in', taxAmount: 88),
            'total' => 1100,
        ]),
    );

    expect($out)->toContain('88')->not->toContain('100');
});

it('#2067 KHÔNG có dữ kiện thuế thì KHÔNG in dòng nào — không bịa 10%', function () {
    // Đây là ca đảo chiều so với #1937. Trước đây test này TÊN LÀ "quán chưa
    // cấu hình thuế suất vẫn ra khối hợp lý, không phải ¥0" và đòi `toContain('100')`
    // — tức con số bịa là một YÊU CẦU CÓ TEST. 1,100 trích 10% ra đúng 100.
    //
    // Vì sao đảo: `taxRate` không có đường nào được điền trong production, nên
    // `effectiveTaxRate()` luôn trả 10.0 và nhánh "dự phòng" là nhánh DUY NHẤT
    // từng chạy — mọi quán, mọi quốc gia, kể cả hàng 軽減税率 8% và 非課税.
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData([
            'config' => new PrintJobConfig(currency: '¥', taxRate: 0.0),
            'order' => new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in'),
            'total' => 1100,
        ]),
    );

    expect($out)->toBe('');
});

it('#2067 cấu hình taxRate KHÁC 0 cũng không cứu được — tầng in không tính thuế', function () {
    // 1,080 @8% từng ra 80. Giờ không có `order.tax_amount` thì vẫn không in gì:
    // luật là "không tính", không phải "tính bằng tỉ lệ đúng hơn".
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData([
            'config' => new PrintJobConfig(currency: '¥', taxRate: 8.0),
            'order' => new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in'),
            'total' => 1080,
        ]),
    );

    expect($out)->toBe('');
});

it('#1937/#2067 phiếu HIỂN THỊ MỘT PHẦN không mượn tổng thuế của cả đơn', function () {
    // `kitchen` · phiếu delta · không có đơn: cả ba in một PHẦN của đơn, nên
    // tổng thuế cả đơn sẽ lớn hơn số tiền trên chính tờ giấy đó. Trước #2067
    // chúng trích từ tổng của riêng tờ giấy; giờ chúng in RỖNG — nhưng điều phải
    // giữ nguyên là chúng không bao giờ mượn 9,999 của cả đơn.
    $order = new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in', taxAmount: 9999);

    $delta = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData(['order' => $order, 'total' => 1100, 'deltaBill' => true]),
    );

    expect($delta)->toBe('');
});

it('#1937 không thuế thì khối VẮNG MẶT, không in "Thuế 0"', function () {
    // In ¥0 nói với khách rằng đơn có thuế bằng không — khác hẳn "đơn này không
    // có khối thuế".
    expect(emitBillTaxQr(['id' => 'tax_breakdown'], taxQrData(['total' => 0])))->toBe('');
});

it('#1937 nhãn khối theo mức đổi theo locale', function () {
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData(),
        taxSnapshot([['rate' => 8.0, 'taxable' => 300, 'tax' => 22]]),
        locale: 'vi',
    );

    // Tiếng Việt gấp về ASCII không dấu (xem PrintLabels).
    expect($out)->toContain('Chiu thue 8%')->toContain('thue trong');
});

it('#1937 mức lẻ giữ chữ số thập phân, mức nguyên thì không', function () {
    // Giống `rateKey` của engine, để 8 và 8.0 không tách hai khối.
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData(),
        taxSnapshot([
            ['rate' => 8.0, 'taxable' => 100, 'tax' => 7],
            ['rate' => 8.5, 'taxable' => 200, 'tax' => 15],
        ]),
    );

    expect($out)->toContain('8%')->toContain('8.5%');
});

/*
 * ── qr_block ─────────────────────────────────────────────────────────────
 */

/** Chuỗi dữ liệu nằm giữa header StarPRNT `ESC GS y D 1 NUL <len>` và lệnh in. */
function qrPayloadOf(string $bytes): string
{
    $start = strpos($bytes, "\x1B\x1D\x79\x44\x31\x00");
    expect($start)->not->toBeFalse('không tìm thấy header dữ liệu QR');

    $len = ord($bytes[$start + 6]) | (ord($bytes[$start + 7]) << 8);

    return substr($bytes, $start + 8, $len);
}

it('#1937 qr_block mặc định mang JSON {orderId,orderCode,type}', function () {
    // Payload phải khớp `kioskQRPayload` bên Go byte-for-byte: kiosk đọc
    // `orderCode` thẳng từ JSON đã parse. Payload cũ là UUID trần và mọi lượt
    // quét 404 (#1190) — lệch ở đây không làm gì đỏ, chỉ làm máy quét im lặng.
    $order = new PrintRenderOrder(
        orderCode: 'HCM-2026-A1B2',
        orderType: 'takeaway',
        id: '019e5f2a-1c3d-7a10-9c2b-0f1e2d3c4b5a',
    );

    $out = emitBillTaxQr(['id' => 'qr_block'], taxQrData(['order' => $order]));

    expect(qrPayloadOf($out))->toBe(
        '{"orderId":"019e5f2a-1c3d-7a10-9c2b-0f1e2d3c4b5a","orderCode":"HCM-2026-A1B2","type":"takeaway"}',
    );
});

it('#1937 thứ tự khoá là orderId → orderCode → type, khớp thứ tự trường struct Go', function () {
    $out = emitBillTaxQr(['id' => 'qr_block'], taxQrData([
        'order' => new PrintRenderOrder(orderCode: 'B-2', orderType: 'dine_in', id: 'ID-1'),
    ]));

    $payload = qrPayloadOf($out);

    expect(strpos($payload, 'orderId'))->toBeLessThan(strpos($payload, 'orderCode'))
        ->and(strpos($payload, 'orderCode'))->toBeLessThan(strpos($payload, '"type"'));
});

it('#1937 gạch chéo trong mã đơn KHÔNG bị escape — Go không escape nó', function () {
    // Mặc định `json_encode` của PHP đổi `/` thành `\/`; `encoding/json` thì
    // không. Đây là chỗ hai bộ mã hoá lệch nhau thật sự có thể xảy ra.
    $out = emitBillTaxQr(['id' => 'qr_block'], taxQrData([
        'order' => new PrintRenderOrder(orderCode: 'A/1', orderType: 'dine_in', id: 'X'),
    ]));

    expect(qrPayloadOf($out))->toContain('"orderCode":"A/1"')->not->toContain('\\/');
});

it('#1937 source: order_code in mã TRẦN — opt-in, không phải mặc định', function () {
    $order = new PrintRenderOrder(orderCode: 'HCM-2026-A1B2', orderType: 'dine_in', id: 'X');

    expect(qrPayloadOf(emitBillTaxQr(
        ['id' => 'qr_block', 'source' => 'order_code'],
        taxQrData(['order' => $order]),
    )))->toBe('HCM-2026-A1B2');

    // `order_url` là thứ `config/print_templates.php` khai cho cả ba kind bật
    // QR, và nó phải đi vào nhánh JSON — không phải nhánh mã trần.
    expect(qrPayloadOf(emitBillTaxQr(
        ['id' => 'qr_block', 'source' => 'order_url'],
        taxQrData(['order' => $order]),
    )))->toStartWith('{');
});

it('#1937 qr_block căn giữa rồi TRẢ LẠI căn trái', function () {
    // Không trả lại thì mọi block sau nó (footer_text, greeting) in ra giữa
    // dòng — và nó chỉ lộ ra ở template nào đặt chữ SAU mã QR.
    $out = emitBillTaxQr(['id' => 'qr_block'], taxQrData([
        'order' => new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in', id: 'X'),
    ]));

    expect($out)->toContain(Escpos::ALIGN_CENTER)
        ->and(strrpos($out, Escpos::ALIGN_LEFT))->toBeGreaterThan(strrpos($out, Escpos::ALIGN_CENTER));
});

it('#1937 không có đơn thì không có QR — mã rỗng vẫn là mã in ra được', function () {
    expect(emitBillTaxQr(['id' => 'qr_block'], taxQrData(['order' => null])))->toBe('');
});

/*
 * ── dây nối: snapshot phải đi hết đường từ NGƯỜI GỌI xuống emitter ───────
 */

it('#1937 PrintRenderer chuyển snapshot xuống tận emitter', function () {
    // Mọi ca trên dựng context bằng tay, nên chúng vẫn xanh kể cả khi
    // `PrintRenderer::render()` đánh rơi tham số — và đó là cách khối thuế biến
    // mất trên phiếu thật trong khi test xanh. Ca này đi qua entry point thật.
    $data = new PrintRenderData(
        kind: 'receipt',
        config: new PrintJobConfig(currency: '¥'),
        order: new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in', id: 'X'),
    );

    $result = app(PrintRenderer::class)->render(
        ['kind' => 'receipt', 'blocks' => [['id' => 'tax_breakdown']]],
        $data,
        new PrintRenderProfile(columns: 48),
        'ja',
        taxSnapshot([['rate' => 10.0, 'taxable' => 2915, 'tax' => 265]]),
    );

    expect($result->bytes())->toContain('265')->toContain('2,915');
});

/*
 * ── #2035 — khổ 58mm (32 cột) ────────────────────────────────────────────
 *
 * Khối 内税 hai mức KHÔNG vừa 32 cột ở bất kỳ locale nào, và `rateBlockLine`
 * kẹp khe xuống 1 rồi vẫn phát ra dòng dài hơn giấy ⇒ **máy in thật tràn**,
 * không riêng bản xem trước.
 *
 * Đo (3 cột thụt + nhãn + khe tối thiểu 1 + giá trị):
 *
 *     ja   10%対象        ¥2,915 (内消費税 ¥265)    = 33
 *     en   10% taxable    ¥2,915 (incl. tax ¥265)   = 38
 *     vi   Chiu thue 10%  ¥2,915 (thue trong ¥265)  = 41
 *                                          khổ 58mm = 32
 *
 * Thu nhỏ số tiền KHÔNG cứu được (vi vẫn 35 cột với `¥5 (thue trong ¥1)`), và
 * đây là chứng từ thuế (適格請求書) nên bỏ bớt mức hay cắt số liệu đều không
 * phải đường ra: thông tin thuế theo từng mức là trường bắt buộc pháp lý.
 * Đường ra là BỐ CỤC — xuống dòng.
 *
 * Đo bằng `strlen` chứ không phải `Layout::displayWidth`: đầu ra đã là
 * Shift_JIS, nơi mỗi ký tự CJK chiếm đúng 2 byte = 2 cột và ¥ là 0x5C = 1 byte
 * = 1 cột, nên byte và cột trùng nhau. Cùng phép đo với ca "thụt 3 cột" ở trên.
 */

/** @return list<string> dòng không rỗng của một lượt phát, đã bỏ CR. */
function taxBlockLines(string $out): array
{
    return array_values(array_filter(
        array_map(static fn (string $l): string => rtrim($l, "\r"), explode("\n", $out)),
        static fn (string $l): bool => $l !== '',
    ));
}

it('#2035 khối theo mức KHÔNG tràn khổ 58mm ở bất kỳ locale nào', function (string $locale) {
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData(),
        taxSnapshot([
            ['rate' => 10.0, 'taxable' => 2915, 'tax' => 265],
            ['rate' => 8.0, 'taxable' => 300, 'tax' => 22],
        ]),
        width: 32,
        locale: $locale,
    );

    foreach (taxBlockLines($out) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(
            32,
            sprintf('%s: "%s" rộng %d cột trên giấy 32', $locale, $line, strlen($line)),
        );
    }
})->with(['ja', 'en', 'vi']);

it('#2035 xuống dòng KHÔNG được làm mất một con số thuế nào', function (string $locale) {
    // Điều ràng buộc của cả bài: đây là 適格請求書. Cắt bớt số liệu để vừa giấy
    // là đổi một lỗi dàn trang lấy một lỗi chứng từ. Cả bốn con số của hai mức
    // phải còn nguyên trên giấy 32 cột.
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData(),
        taxSnapshot([
            ['rate' => 10.0, 'taxable' => 2915, 'tax' => 265],
            ['rate' => 8.0, 'taxable' => 300, 'tax' => 22],
        ]),
        width: 32,
        locale: $locale,
    );

    $text = implode("\n", taxBlockLines($out));

    // `str_contains` chứ không phải `toContain`: `toContain` của Pest là
    // VARIADIC — tham số thứ hai là một needle NỮA, không phải thông điệp, nên
    // câu tiếng Việt kèm theo sẽ được đem đi tìm trong đầu ra và bài luôn đỏ.
    foreach (['8%', '10%', '300', '22', '2,915', '265'] as $needle) {
        expect(str_contains($text, $needle))->toBeTrue("{$locale}: thiếu «{$needle}»");
    }
})->with(['ja', 'en', 'vi']);

it('#2035 giấy 42 và 48 cột KHÔNG đổi — chỉ 32 cột mới phải xuống dòng', function (string $locale) {
    // Dòng một-hàng vẫn vừa ở 42 (vi là ca sát nhất: 41 ≤ 42), nên xuống dòng ở
    // đó là đổi tờ giấy của mọi quán đang chạy để chữa một khổ không ai dùng.
    foreach ([42, 48] as $width) {
        $out = emitBillTaxQr(
            ['id' => 'tax_breakdown'],
            taxQrData(),
            taxSnapshot([['rate' => 10.0, 'taxable' => 2915, 'tax' => 265]]),
            width: $width,
            locale: $locale,
        );

        $lines = taxBlockLines($out);

        expect($lines)->toHaveCount(1, "{$locale}/{$width}: khối một mức phải là MỘT dòng")
            ->and(strlen($lines[0]))->toBe($width);
    }
})->with(['ja', 'en', 'vi']);

it('#2059 hình dạng quyết MỘT LẦN cho cả khối, không theo từng mức', function (string $locale) {
    // Lỗ hổng do kiểm đột biến tìm ra (#2059): mọi bài #2035 đều dùng hai mức
    // mà CẢ HAI đều không vừa giấy 32, nên chúng không phân biệt được
    //   (a) quyết một lần cho cả khối  — đúng
    //   (b) quyết theo từng mức        — sai
    // Đổi sang (b) thì toàn bộ bộ test vẫn XANH. Bài này dựng đúng ca phân
    // biệt: mức 8% NGẮN (vừa 32 nếu đứng một mình) đi cùng mức 10% DÀI.
    //
    // Vì sao (b) sai, dù mỗi dòng vẫn <= 32: hai mức sẽ ra hai hình dạng khác
    // nhau — 8% inline một dòng, 10% xuống hai dòng — nên cột số của chúng
    // không còn chung một mép phải. Người đọc hoá đơn dò số theo mép ấy.
    $out = emitBillTaxQr(
        ['id' => 'tax_breakdown'],
        taxQrData(),
        taxSnapshot([
            ['rate' => 8.0, 'taxable' => 50, 'tax' => 4],
            ['rate' => 10.0, 'taxable' => 2915, 'tax' => 265],
        ]),
        width: 32,
        locale: $locale,
    );

    $lines = taxBlockLines($out);

    // Hai mức cùng xuống dòng ⇒ 4 dòng. Bản "theo từng mức" cho 3.
    expect($lines)->toHaveCount(
        4,
        sprintf("%s: khối phải xuống dòng ĐỒNG LOẠT (4 dòng), nhận %d:\n%s", $locale, count($lines), implode("\n", $lines)),
    );

    // Và không dòng nào được mang dạng inline `… (nhãn …)` — dấu hiệu của một
    // mức tự quyết không xuống dòng trong khi mức kia đã xuống.
    foreach ($lines as $line) {
        expect(str_contains($line, '('))->toBeFalse(
            sprintf('%s: "%s" còn ở dạng gộp trong khi khối đã xuống dòng', $locale, $line),
        );
    }
})->with(['ja', 'en', 'vi']);
