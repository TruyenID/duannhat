<?php

declare(strict_types=1);

use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\PrintJobConfig;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\Renderer\PrintLabels;
use App\Services\Print\Renderer\PrintRenderContext;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\PrintRenderItem;
use App\Services\Print\Renderer\PrintRenderOrder;
use App\Services\Print\Renderer\PrintRenderSlip;
use App\Services\Print\Renderer\TaxLabels;
use Carbon\CarbonImmutable;

/*
 * plan-053 T5.1d (#1923) — cụm 1: issued_at · subtotal · service_charge.
 */

function emitBill(string $block, PrintRenderData $data, bool $suppress = false): string
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
    $ctx->suppressOrderRows = $suppress;

    $emitter = app(PrintKindRegistry::class)->planFor('receipt')->emitters[$block];
    $emitter($ctx, ['id' => $block]);

    return str_replace("\x1b@", '', $encoder->bytes());
}

function billData(array $order = [], ?CarbonImmutable $now = null): PrintRenderData
{
    return new PrintRenderData(
        kind: 'receipt',
        config: new PrintJobConfig(currency: '¥'),
        now: $now,
        order: $order === [] ? null : new PrintRenderOrder(...$order),
    );
}

it('#1923 issued_at dùng `now` của NGƯỜI GỌI', function () {
    $out = emitBill('issued_at', billData(now: CarbonImmutable::parse('2026-08-06 14:05:00')));

    expect(trim($out))->toBe('2026/08/06 14:05');
});

it('#1923 KHÔNG có now thì không in — thà thiếu dòng hơn đóng dấu sai giờ', function () {
    // Renderer lấp bằng đồng hồ máy là đúng cách một phiếu Tokyo bị đóng dấu
    // theo giờ máy chủ UTC (#1091).
    expect(emitBill('issued_at', billData()))->toBe('');
});

it('#1923 subtotal và service_charge in khi > 0', function () {
    $data = billData(['orderCode' => 'A-1', 'orderType' => 'dine_in', 'subtotal' => 1500, 'serviceCharge' => 150]);

    // So SỐ, không so ký hiệu tiền: encoder chuyển sang Shift_JIS cho locale ja,
    // và trong Shift_JIS ký hiệu ¥ LÀ byte 0x5C — đúng cho đầu in nhiệt Nhật,
    // nhưng trông như dấu `\` khi đọc bằng UTF-8.
    //
    // Bản đầu của test này so với '¥1,500' UTF-8 và đỏ. Suýt nữa tôi đi sửa
    // `money()` cho vừa một phép đo đọc sai bảng mã.
    expect(trim(emitBill('subtotal', $data)))->toContain('1,500')
        ->and(trim(emitBill('service_charge', $data)))->toContain('150');
});

it('#1923 ký hiệu tiền ra Shift_JIS 0x5C cho locale ja', function () {
    // Ghim riêng, vì nó dễ bị "sửa" thành 0xA5 (¥ trong Latin-1) bởi người thấy
    // 0x5C và nghĩ là backslash lọt vào.
    $data = billData(['orderCode' => 'A', 'orderType' => 'dine_in', 'subtotal' => 1500]);

    expect(emitBill('subtotal', $data))->toContain("\x5C1,500");
});

it('#1923 số ≤ 0 thì KHÔNG in', function () {
    // Âm là dữ liệu hỏng, và in ra dưới dạng "-¥500" trông như một khoản giảm
    // giá hợp lệ.
    foreach ([0, -500] as $v) {
        $data = billData(['orderCode' => 'A', 'orderType' => 'dine_in', 'subtotal' => $v, 'serviceCharge' => $v]);
        expect(emitBill('subtotal', $data))->toBe('')
            ->and(emitBill('service_charge', $data))->toBe('');
    }
});

it('#1923 phiếu con của lần chia bill BỎ QUA cả hai dòng', function () {
    // `suppressOrderRows` tính MỘT lần ở prologue. Kiểm lại điều kiện trong
    // emitter thay vì đọc cờ là cách dòng tạm tính và khối thuế nói khác nhau.
    $data = billData(['orderCode' => 'A', 'orderType' => 'dine_in', 'subtotal' => 1500, 'serviceCharge' => 150]);

    expect(emitBill('subtotal', $data, suppress: true))->toBe('')
        ->and(emitBill('service_charge', $data, suppress: true))->toBe('');
});

/*
 * Cụm 2 — payments · change_due · remaining.
 */

function billSlipData(array $slip, int $paidShown = 0, int $remaining = 0): PrintRenderData
{
    return new PrintRenderData(
        kind: 'receipt',
        config: new PrintJobConfig(currency: '¥'),
        paidShown: $paidShown,
        remaining: $remaining,
        slip: new PrintRenderSlip(...$slip),
    );
}

it('#1923 payments đọc paidShown của NGƯỜI GỌI, không phải slip->amountPaid', function () {
    // Hai đường khác nhau ở phiếu chia bill. Đọc nhầm ô làm phiếu của MỘT
    // người in ra số tiền của cả đơn.
    $data = billSlipData(['amountPaid' => 9999, 'paymentMethod' => 'cash'], paidShown: 1500);

    $out = emitBill('payments', $data);

    expect($out)->toContain('1,500')
        ->and($out)->not->toContain('9,999');
});

it('#1923 phương thức thanh toán in NGUYÊN VĂN', function () {
    // Chuỗi đến từ cấu hình của quán; dịch ở đây làm phiếu khác báo cáo ca.
    expect(emitBill('payments', billSlipData(['paymentMethod' => 'paypay'], paidShown: 100)))
        ->toContain('paypay');
});

it('#1923 phương thức rỗng thì không in dòng đó', function () {
    $out = emitBill('payments', billSlipData(['paymentMethod' => '   '], paidShown: 100));

    expect(substr_count($out, "\n"))->toBe(1);
});

it('#1923 change_due in cả khi tiền thối = 0', function () {
    // Điều kiện là `tendered <= 0`, KHÔNG phải `change > 0`: trả đúng số thì
    // thối bằng 0 nhưng dòng "khách đưa" vẫn là bằng chứng khách đưa bao nhiêu.
    $out = emitBill('change_due', billSlipData(['tendered' => 1500, 'change' => 0]));

    expect($out)->toContain('1,500')
        ->and(substr_count($out, "\n"))->toBe(2);
});

it('#1923 không có tendered thì bỏ qua cả khối', function () {
    expect(emitBill('change_due', billSlipData(['tendered' => 0, 'change' => 500])))->toBe('');
});

it('#1923 remaining đọc data->remaining, không phải slip->remaining', function () {
    // Hai ô khác nhau có chủ đích: một là số người gọi quyết cho tờ giấy, một
    // là số của phiếu con.
    $out = emitBill('remaining', billSlipData(['remaining' => 7777], remaining: 300));

    expect($out)->toContain('300')
        ->and($out)->not->toContain('7,777');
});

/*
 * Cụm 3 — order_note · tax_legend · registration_number.
 */

it('#1923 order_note ngắt dòng theo bề rộng giấy', function () {
    // Ghi chú là chỗ DUY NHẤT trên phiếu có độ dài không giới hạn — chữ do
    // người dùng gõ. Không ngắt thì nó tràn và đẩy vỡ mọi thứ bên dưới.
    $long = str_repeat('rat dai ', 20);
    $data = billData(['orderCode' => 'A', 'orderType' => 'dine_in', 'note' => $long]);

    $out = emitBill('order_note', $data);

    expect(substr_count($out, "\n"))->toBeGreaterThan(1);
    foreach (array_filter(explode("\n", $out)) as $line) {
        expect(mb_strlen($line))->toBeLessThanOrEqual(48);
    }
});

it('#1923 ghi chú rỗng thì không in gì', function () {
    foreach (['', '   '] as $note) {
        expect(emitBill('order_note', billData(['orderCode' => 'A', 'orderType' => 'dine_in', 'note' => $note])))->toBe('');
    }
});

it('#1923 tax_legend cần CẢ HAI điều kiện', function () {
    // Một chú thích cho ký hiệu không xuất hiện ở đâu còn tệ hơn không có chú
    // thích — nó làm người đọc đi tìm dấu ※ không tồn tại.
    $data = billData(['orderCode' => 'A', 'orderType' => 'dine_in']);

    $render = function (bool $show, bool $hasReduced) use ($data): string {
        $encoder = new Escpos;
        $ctx = new PrintRenderContext(
            encoder: $encoder, definition: ['blocks' => []], data: $data, config: $data->config,
            locale: 'ja', width: 48, japaneseDoc: false,
            labels: PrintLabels::forLocale('ja'), tax: TaxLabels::forLocale('ja'),
        );
        $ctx->showTaxBreakdown = $show;
        $ctx->taxSummary = ['has_reduced' => $hasReduced];

        (app(PrintKindRegistry::class)
            ->planFor('receipt')->emitters['tax_legend'])($ctx, ['id' => 'tax_legend']);

        return str_replace("\x1b@", '', $encoder->bytes());
    };

    expect($render(true, true))->not->toBe('')
        ->and($render(false, true))->toBe('')   // phiếu con chia bill
        ->and($render(true, false))->toBe('');  // đơn không có dòng thuế giảm
});

it('#2064 registration_number VẪN in trên phiếu con chia bill', function () {
    // 登録番号 là danh tính NGƯỜI BÁN (trường ① của 適格簡易請求書) — không phụ
    // thuộc phiếu là con hay nguyên đơn. Cờ `showTaxBreakdown` (tắt trên phiếu
    // con, Q13) từng kéo nó tắt theo, tức mỗi phiếu con của một bàn chia bill
    // mất một trường bắt buộc — khách B2B cầm nó không khấu trừ được.
    $config = new PrintJobConfig(currency: '¥', sellerRegistrationNumber: 'T1234567890123');
    $data = new PrintRenderData(kind: 'receipt', config: $config);

    $encoder = new Escpos;
    $ctx = new PrintRenderContext(
        encoder: $encoder, definition: ['blocks' => []], data: $data, config: $config,
        locale: 'ja', width: 48, japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'), tax: TaxLabels::forLocale('ja'),
    );
    // Trạng thái của một phiếu con sau prologue: khối thuế theo mức bị ẩn.
    $ctx->showTaxBreakdown = false;

    (app(PrintKindRegistry::class)
        ->planFor('receipt')->emitters['registration_number'])($ctx, ['id' => 'registration_number']);

    expect(str_replace("\x1b@", '', $encoder->bytes()))
        ->toContain(Escpos::encodeShiftJis('登録番号: T1234567890123'));
});

it('#1923 registration_number vắng mặt IM LẶNG khi quán chưa đăng ký', function () {
    // 免税事業者 không có số này là HỢP PHÁP — vắng mặt là cố ý, không kèm cảnh
    // báo nào (ruling #1152).
    $data = new PrintRenderData(kind: 'receipt', config: new PrintJobConfig(currency: '¥'));

    $encoder = new Escpos;
    $ctx = new PrintRenderContext(
        encoder: $encoder, definition: ['blocks' => []], data: $data, config: $data->config,
        locale: 'ja', width: 48, japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'), tax: TaxLabels::forLocale('ja'),
    );
    $ctx->showTaxBreakdown = true;

    (app(PrintKindRegistry::class)
        ->planFor('receipt')->emitters['registration_number'])($ctx, ['id' => 'registration_number']);

    expect(str_replace("\x1b@", '', $encoder->bytes()))->toBe('');
});

/*
 * Cụm 4 — header (store_info + title dùng CHUNG một emitter).
 */

function emitHeaderWith(array $blocks, string $kind = 'receipt', ?PrintRenderOrder $order = null, string $store = 'Quán A'): string
{
    $config = new PrintJobConfig(currency: '¥', storeName: $store);
    $encoder = new Escpos;
    $ctx = new PrintRenderContext(
        encoder: $encoder,
        definition: ['blocks' => $blocks],
        data: new PrintRenderData(kind: $kind, config: $config, order: $order),
        config: $config,
        locale: 'ja',
        width: 48,
        japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'),
        tax: TaxLabels::forLocale('ja'),
    );

    $emitters = app(PrintKindRegistry::class)->planFor($kind)->emitters;

    // Duyệt ĐÚNG thứ tự definition, như renderer thật — đó là điều kiện để
    // `headerDrawn` có nghĩa.
    foreach ($blocks as $b) {
        if (isset($emitters[$b['id']])) {
            $emitters[$b['id']]($ctx, $b);
        }
    }

    return str_replace("\x1b@", '', $encoder->bytes());
}

it('#1923 bật CẢ HAI block vẫn chỉ ra MỘT dòng', function () {
    // Đây là lý do `headerDrawn` tồn tại. Bỏ cờ thì brand bật cả hai in HAI
    // dòng — và nó chỉ lộ ra ở quán nào bật cả hai, tức không lộ trong cấu hình
    // mặc định.
    $out = emitHeaderWith([
        ['id' => 'store_info'],
        ['id' => 'title', 'i18n' => ['ja' => 'RECEIPT']],
    ]);

    // Đếm '\n', KHÔNG dùng trim(): `bold(false)` phát byte SAU dấu xuống
    // dòng, nên trim() không chạm tới nó và phép đếm ra 1 thay vì 0. Một dòng
    // = một '\n'.
    expect(substr_count($out, "\n"))->toBe(1)
        ->and($out)->toContain('RECEIPT');
});

it('#1923 chỉ bật title thì in tiêu đề căn phải, KHÔNG in tên quán', function () {
    $out = emitHeaderWith([['id' => 'title', 'i18n' => ['ja' => 'RECEIPT']]]);

    expect($out)->toContain('RECEIPT')
        ->and($out)->not->toContain('Quán A');
});

it('#1923 tên quán rỗng rơi về "Store", không để trống', function () {
    // Phiếu không tên quán trông như phiếu của máy chưa cấu hình.
    expect(emitHeaderWith([['id' => 'store_info']], store: ''))->toContain('Store');
});

it('#1923 quá dài thì XUỐNG DÒNG, không thu nhỏ', function () {
    // Tên quán bị cắt còn tệ hơn phiếu dài thêm một dòng.
    $out = emitHeaderWith([
        ['id' => 'store_info'],
        ['id' => 'title', 'i18n' => ['ja' => 'TIEU DE RAT DAI QUA KHO GIAY NAY']],
    ], store: str_repeat('TenQuanRatDai', 3));

    expect(substr_count($out, "\n"))->toBe(2);
});

it('#1923 delta_qr + mang đi đổi tiêu đề — nhánh DUY NHẤT phụ thuộc dữ liệu', function () {
    // Đơn mang đi không có vòng chạy bàn, nên phiếu xác định một lượt LẤY HÀNG
    // chứ không phải "món vừa thêm".
    $takeaway = new PrintRenderOrder(orderCode: 'A', orderType: 'takeaway');
    $dineIn = new PrintRenderOrder(orderCode: 'A', orderType: 'dine_in');

    $blocks = [['id' => 'title', 'i18n' => ['ja' => 'DELTA']]];

    expect(emitHeaderWith($blocks, 'delta_qr', $takeaway))->not->toContain('DELTA')
        ->and(emitHeaderWith($blocks, 'delta_qr', $dineIn))->toContain('DELTA');
});

/*
 * Cụm 5 — split_banner · customer_header.
 */

it('#1923 split_banner chỉ in khi ĐÚNG là phiếu chia', function () {
    // Băng này là thứ duy nhất nói cho khách biết tờ giấy họ cầm chỉ là MỘT
    // PHẦN — thiếu nó, phiếu chia trông y hệt phiếu đầy đủ với số tiền nhỏ hơn.
    $split = emitBill('split_banner', billSlipData(['splitMode' => 'equal', 'splitCount' => 3, 'slipIndex' => 2]));
    $plain = emitBill('split_banner', billSlipData(['splitCount' => 1]));

    expect($split)->toContain('===')
        ->and($split)->toContain('2/3')
        ->and($plain)->toBe('');
});

it('#1923 customer_header: hoá đơn đỏ không tên thì để CHỖ TRỐNG ghi tay', function () {
    // Hoá đơn đỏ là chứng từ pháp lý; một tờ không có chỗ điền tên người mua
    // thì không dùng được — khác hẳn biên lai thường, nơi thiếu tên chỉ là
    // thiếu thông tin.
    $data = new PrintRenderData(
        kind: 'red_invoice',
        config: new PrintJobConfig(currency: '¥'),
        order: new PrintRenderOrder(orderCode: 'A', orderType: 'dine_in'),
        slip: new PrintRenderSlip(customerName: ''),
    );

    expect(emitBill('customer_header', $data))->toContain(str_repeat('_', 18));
});

it('#1923 biên lai thường không tên thì KHÔNG để chỗ trống', function () {
    $data = new PrintRenderData(
        kind: 'receipt',
        config: new PrintJobConfig(currency: '¥'),
        order: new PrintRenderOrder(orderCode: 'A', orderType: 'dine_in'),
        slip: new PrintRenderSlip(customerName: ''),
    );

    expect(emitBill('customer_header', $data))->not->toContain('____');
});

it('#1923 khối "khách số mấy" KHÔNG in trên phiếu chia', function () {
    // Băng ở trên đã nói điều đó rồi. In lại là hai chỗ nói cùng một việc, và
    // chúng sẽ lệch nhau khi ai đó sửa một chỗ.
    $data = new PrintRenderData(
        kind: 'receipt',
        config: new PrintJobConfig(currency: '¥'),
        order: new PrintRenderOrder(orderCode: 'A', orderType: 'dine_in'),
        slip: new PrintRenderSlip(
            splitCount: 3, slipIndex: 2, splitMode: 'equal', label: 'Tanaka',
        ),
    );

    expect(emitBill('customer_header', $data))->not->toContain('2/3');
});

/*
 * Cụm 6 — column_header · order_meta.
 */

it('#1923 column_header tách nhãn trái/phải từ MỘT chuỗi definition', function () {
    // Tách ở renderer chứ không bắt brand khai hai trường: một template chỉ
    // khai một dòng thì người sửa nó nhìn thấy đúng dòng sẽ in ra.
    $out = emitHeaderWith([['id' => 'column_header', 'i18n' => ['ja' => 'Mon Gia']]]);

    expect($out)->toContain('Mon')->toContain('Gia');
});

it('#1923 column_header ngăn cách bằng KHOẢNG TRẮNG, không kẻ gạch', function () {
    // Tờ phiếu của quán ngăn các khối bằng dòng trống. Gạch từng có ở đây và
    // đã gỡ — một dòng giấy mỗi khối, không nói thêm gì dòng trống chưa nói.
    $out = emitHeaderWith([['id' => 'column_header', 'i18n' => ['ja' => 'A B']]]);

    expect($out)->not->toContain('- - -')
        // feed(1) + line(nhãn) + feed(1) = 3 xuống dòng
        ->and(substr_count($out, "\n"))->toBe(3);
});

it('#1923 order_meta cắt mã đơn còn phần sau dấu gạch cuối', function () {
    // Mã đầy đủ dài và có tiền tố chi nhánh; nhân viên đọc bốn ký tự cuối.
    $order = new PrintRenderOrder(orderCode: 'HCM-2026-A1B2', orderType: 'dine_in', tableNumber: '12');
    $out = emitHeaderWith([['id' => 'order_meta']], order: $order);

    expect($out)->toContain('A1B2')
        ->and($out)->not->toContain('HCM-2026');
});

it('#1923 mã KHÔNG có dấu gạch thì giữ nguyên', function () {
    // Cắt bừa một mã không theo quy ước sẽ tạo ra hai đơn trông giống nhau.
    $order = new PrintRenderOrder(orderCode: 'ABC123', orderType: 'dine_in');

    expect(emitHeaderWith([['id' => 'order_meta']], order: $order))->toContain('ABC123');
});

it('#1923 đơn MANG ĐI bỏ hẳn dòng bàn, không in "-"', function () {
    // Đơn mang đi không có bàn; in một ô trống cho nó làm nhân viên đi tìm bàn
    // không tồn tại.
    $takeaway = new PrintRenderOrder(orderCode: 'A-1', orderType: 'takeaway');
    $dineIn = new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in');

    expect(emitHeaderWith([['id' => 'order_meta']], order: $takeaway))->not->toContain('-')
        // Đơn tại chỗ PHẢI có bàn, nên ô trống là dữ liệu thiếu và dấu gạch
        // nói ra điều đó.
        ->and(emitHeaderWith([['id' => 'order_meta']], order: $dineIn))->toContain('-');
});

it('#1923 order_meta theo THỨ TỰ field trong definition', function () {
    // Definition quyết định in gì và theo thứ tự nào; renderer chỉ biết vẽ.
    $order = new PrintRenderOrder(orderCode: 'A-9', orderType: 'dine_in', tableNumber: '7');

    $out = emitHeaderWith([['id' => 'order_meta', 'fields' => ['table', 'order_no']]], order: $order);

    expect(mb_strpos($out, '7'))->toBeLessThan(mb_strpos($out, '9'));
});

/*
 * Cụm 7 — items (dấu ※ và băng ngăn cách).
 */

function emitItemsWith(array $items, bool $showBreakdown = true, string $kind = 'receipt'): string
{
    $config = new PrintJobConfig(currency: '¥');
    $encoder = new Escpos;
    // PHẢI có order: `prepareBillTax` thoát sớm khi `order === null` (giống
    // Go), nên thiếu nó thì `taxSummary` không bao giờ được đổ đầy và dấu ※
    // không bao giờ xuất hiện — test đầu của tôi đo đúng chỗ đó và tôi suýt
    // đi sửa emitter.
    $data = new PrintRenderData(
        kind: $kind,
        config: $config,
        order: new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in'),
        items: array_map(
            fn (array $i) => new PrintRenderItem(...$i),
            $items,
        ),
    );

    $ctx = new PrintRenderContext(
        encoder: $encoder, definition: ['blocks' => []], data: $data, config: $config,
        locale: 'ja', width: 48, japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'), tax: TaxLabels::forLocale('ja'),
    );

    $plan = app(PrintKindRegistry::class)->planFor('receipt');
    ($plan->prologue)($ctx);
    $ctx->showTaxBreakdown = $showBreakdown;

    $plan->emitters['items']($ctx, ['id' => 'items']);

    return str_replace("\x1b@", '', $encoder->bytes());
}

it('#1923 dấu ※ chỉ lên dòng có mức THẤP HƠN mức cao nhất', function () {
    // Đây là vế còn lại của cặp bất biến: dấu trên dòng món và khối thuế ở chân
    // phiếu phải đến từ CÙNG MỘT bản tổng hợp, nếu không tờ giấy tự mâu thuẫn.
    $out = emitItemsWith([
        ['menuItemName' => 'Bia', 'quantity' => 1, 'unitPrice' => 500, 'taxRate' => 10.0],
        ['menuItemName' => 'Com', 'quantity' => 1, 'unitPrice' => 800, 'taxRate' => 8.0],
    ]);

    $lines = array_values(array_filter(explode("\n", $out)));
    $comLine = current(array_filter($lines, fn ($l) => str_contains($l, 'Com')));
    $biaLine = current(array_filter($lines, fn ($l) => str_contains($l, 'Bia')));

    expect($comLine)->not->toBe($biaLine);
});

it('#1923 đơn một mức thuế thì KHÔNG dòng nào có dấu ※', function () {
    // Không có mức nào thấp hơn ⇒ không có "mức giảm" ⇒ dấu ※ vô nghĩa.
    $same = emitItemsWith([
        ['menuItemName' => 'A', 'quantity' => 1, 'unitPrice' => 100, 'taxRate' => 10.0],
        ['menuItemName' => 'B', 'quantity' => 1, 'unitPrice' => 200, 'taxRate' => 10.0],
    ]);
    $mixed = emitItemsWith([
        ['menuItemName' => 'A', 'quantity' => 1, 'unitPrice' => 100, 'taxRate' => 10.0],
        ['menuItemName' => 'B', 'quantity' => 1, 'unitPrice' => 200, 'taxRate' => 8.0],
    ]);

    // Khẳng định ĐÚNG cái tên bài test nói. Bản trước so `mb_strlen` của hai
    // phiếu và đòi bản một-mức ngắn hơn — một phép thế, và là phép thế hỏng ở
    // hai tầng: chuỗi trả về là byte Shift_JIS nên `mb_strlen` không đếm ký tự
    // của nó, còn "ngắn hơn" thì đúng chỉ nhờ số khoảng trắng đệm chứ không nhờ
    // dấu ※. Khi ※ được đo lại thành HAI cột (nó ra Shift_JIS hai byte), phiếu
    // pha hai mức mất đúng một khoảng trắng đệm và hai con số bằng nhau — rào
    // đỏ lên trong khi bất biến nó canh vẫn nguyên vẹn.
    $mark = Escpos::encodeShiftJis('※');

    expect($same)->not->toContain($mark)
        ->and($mixed)->toContain($mark);
});

it('#1923 dòng THIẾU taxRate bị bỏ qua, không tính là 0%', function () {
    // `null` nghĩa là KHÔNG BIẾT. Coi nó là 0% sẽ biến mọi đơn có một dòng
    // thiếu dữ liệu thành đơn "có mức giảm" — dấu ※ mọc lên khắp phiếu.
    $out = emitItemsWith([
        ['menuItemName' => 'A', 'quantity' => 1, 'unitPrice' => 100, 'taxRate' => 10.0],
        ['menuItemName' => 'B', 'quantity' => 1, 'unitPrice' => 200],
    ]);

    $allSame = emitItemsWith([
        ['menuItemName' => 'A', 'quantity' => 1, 'unitPrice' => 100, 'taxRate' => 10.0],
        ['menuItemName' => 'B', 'quantity' => 1, 'unitPrice' => 200, 'taxRate' => 10.0],
    ]);

    expect(mb_strlen($out))->toBe(mb_strlen($allSame));
});

it('#1923 phiếu con chia bill KHÔNG mang dấu ※', function () {
    $out = emitItemsWith([
        ['menuItemName' => 'A', 'quantity' => 1, 'unitPrice' => 100, 'taxRate' => 10.0],
        ['menuItemName' => 'B', 'quantity' => 1, 'unitPrice' => 200, 'taxRate' => 8.0],
    ], showBreakdown: false);

    $noMark = emitItemsWith([
        ['menuItemName' => 'A', 'quantity' => 1, 'unitPrice' => 100, 'taxRate' => 10.0],
        ['menuItemName' => 'B', 'quantity' => 1, 'unitPrice' => 200, 'taxRate' => 10.0],
    ], showBreakdown: false);

    expect(mb_strlen($out))->toBe(mb_strlen($noMark));
});

it('#1923 CHỈ phiếu hall kẻ gạch đóng bảng món, kể cả khi danh sách RỖNG', function () {
    // Phiếu hall là tờ người chạy bàn liếc trong lúc đi: đường kẻ bảo mắt dừng
    // trước khối tiền. Biên lai đọc lúc đứng yên nên chỉ có dòng trống. Kiểm cả
    // hai chiều — bỏ vế phủ định thì "kẻ ở mọi phiếu" vẫn qua.
    expect(emitItemsWith([], true, 'runner'))->toContain('- - -');
    expect(emitItemsWith([]))->not->toContain('- - -');
});

/*
 * Cụm 8 — grand_total (ba nhánh).
 */

function emitGrandTotalWith(?array $slip, int $total): string
{
    $config = new PrintJobConfig(currency: '¥');
    $encoder = new Escpos;
    $data = new PrintRenderData(
        kind: 'receipt',
        config: $config,
        order: new PrintRenderOrder(orderCode: 'A-1', orderType: 'dine_in'),
        total: $total,
        slip: $slip === null ? null : new PrintRenderSlip(...$slip),
    );

    $ctx = new PrintRenderContext(
        encoder: $encoder, definition: ['blocks' => []], data: $data, config: $config,
        locale: 'ja', width: 48, japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'), tax: TaxLabels::forLocale('ja'),
    );

    (app(PrintKindRegistry::class)
        ->planFor('receipt')->emitters['grand_total'])($ctx, ['id' => 'grand_total']);

    return str_replace("\x1b@", '', $encoder->bytes());
}

it('#1923 không chia bill: MỘT dòng tổng', function () {
    $out = emitGrandTotalWith(null, 5000);

    expect($out)->toContain('5,000')
        ->and(substr_count($out, "\n"))->toBe(1);
});

it('#1923 chia theo MÓN: phần của người này trước, tổng đơn sau', function () {
    // Chia theo món thì con số đáng chú ý là phần của người này.
    $out = emitGrandTotalWith(
        ['splitMode' => 'by_items', 'splitCount' => 3, 'slipIndex' => 2, 'orderGrossTotal' => 9000],
        1500,
    );

    expect(substr_count($out, "\n"))->toBe(2)
        ->and(mb_strpos($out, '1,500'))->toBeLessThan(mb_strpos($out, '9,000'))
        ->and($out)->toContain('2/3');
});

it('#1923 chia ĐỀU: tổng đơn trước, phần của người này sau', function () {
    // Thứ tự ĐẢO so với by_items — không phải nhầm lẫn: chia đều thì người ta
    // nhìn tổng trước rồi mới thấy phần mình.
    $out = emitGrandTotalWith(
        ['splitMode' => 'equal', 'splitCount' => 3, 'slipIndex' => 2, 'amountPaid' => 3000],
        9000,
    );

    expect(mb_strpos($out, '9,000'))->toBeLessThan(mb_strpos($out, '3,000'));
});

it('#1923 by_items đọc data->total, equal đọc slip->amountPaid', function () {
    // Hai ô khác nhau — tráo chúng cho ra một con số trông hợp lý nhưng SAI.
    $byItems = emitGrandTotalWith(
        ['splitMode' => 'by_items', 'splitCount' => 2, 'slipIndex' => 1, 'orderGrossTotal' => 8000, 'amountPaid' => 7777],
        1111,
    );

    expect($byItems)->toContain('1,111')
        ->and($byItems)->not->toContain('7,777');

    $equal = emitGrandTotalWith(
        ['splitMode' => 'equal', 'splitCount' => 2, 'slipIndex' => 1, 'amountPaid' => 4444],
        8888,
    );

    expect($equal)->toContain('4,444')->toContain('8,888');
});
