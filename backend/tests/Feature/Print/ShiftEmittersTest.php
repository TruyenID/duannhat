<?php

declare(strict_types=1);

use App\Services\Print\Renderer\ChainShiftLine;
use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\PaymentMethodLabels;
use App\Services\Print\Renderer\PrintJobConfig;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\Renderer\PrintLabels;
use App\Services\Print\Renderer\PrintRenderContext;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\ShiftDenominationLine;
use App\Services\Print\Renderer\ShiftDiscountLine;
use App\Services\Print\Renderer\ShiftOpenReportInfo;
use App\Services\Print\Renderer\ShiftPaymentLine;
use App\Services\Print\Renderer\ShiftReportInfo;
use App\Services\Print\Renderer\ShiftTaxRateLine;
use App\Services\Print\Renderer\TaxLabels;

/*
 * plan-053 T5.1d slice 3 (#1934) — 22 ô emitter của họ SHIFT.
 *
 * ── Vì sao mọi phép so ở đây đều đi qua `shiftSjis()` ─────────────────────────
 *
 * Encoder chuyển chuỗi sang Shift_JIS cho locale `ja`, nên output KHÔNG phải
 * UTF-8 và so trực tiếp với chữ Nhật viết trong file test này sẽ luôn đỏ. Bản
 * đầu của tôi so `toContain('総売上')` và đỏ hết — suýt nữa đi sửa emitter cho
 * vừa một phép đo đọc sai bảng mã.
 *
 * `shiftSjis()` mã hoá kỳ vọng bằng CHÍNH hàm encoder dùng, nên nó vẫn là một phép
 * so thật (nội dung, thứ tự, khoảng trắng) chứ không phải một phép so tự thoả
 * mãn — nếu emitter in sai chữ, chuỗi mã hoá cũng khác.
 */

function shiftSjis(string $s): string
{
    return Escpos::encodeShiftJis($s);
}

/**
 * Chạy MỘT block của một kind, trả về byte thô.
 *
 * `definition` mặc định chứa đúng block đang chạy, để `def.has()` /
 * `def.block()` thấy nó — giống lúc render thật.
 *
 * @param  array<string, mixed>  $block
 * @param  list<array<string, mixed>>|null  $definitionBlocks
 */
function emitShift(
    string $kind,
    string $blockId,
    PrintRenderData $data,
    array $block = [],
    ?array $definitionBlocks = null,
    string $locale = 'ja',
    int $width = 42,
): string {
    $encoder = new Escpos;

    $ctx = new PrintRenderContext(
        encoder: $encoder,
        definition: ['blocks' => $definitionBlocks ?? [['id' => $blockId]]],
        data: $data,
        config: $data->config,
        locale: $locale,
        width: $width,
        japaneseDoc: false,
        labels: PrintLabels::forLocale($locale),
        tax: TaxLabels::forLocale($locale),
    );

    $emitter = app(PrintKindRegistry::class)->planFor($kind)->emitters[$blockId];
    $emitter($ctx, ['id' => $blockId] + $block);

    return str_replace(Escpos::INIT, '', $encoder->bytes());
}

/** @param array<string, mixed> $overrides */
function shiftReportData(array $overrides = []): PrintRenderData
{
    return new PrintRenderData(
        kind: 'shift_report',
        config: new PrintJobConfig(currency: '¥', storeName: 'テスト店'),
        shift: new ShiftReportInfo(...$overrides),
    );
}

/** @param array<string, mixed> $overrides */
function shiftOpenData(array $overrides = []): PrintRenderData
{
    return new PrintRenderData(
        kind: 'shift_open',
        config: new PrintJobConfig(currency: '¥', storeName: 'テスト店'),
        shiftOpen: new ShiftOpenReportInfo(...$overrides),
    );
}

/*
 * ── Tiền: hậu tố, KHÔNG phải ký hiệu đứng trước ────────────────────────
 */

it('#1934 tiền của họ shift là HẬU TỐ 円, không phải ¥ đứng trước', function () {
    // Đây là khác biệt dễ lẫn nhất giữa `$ctx->money()` (`¥1,500`) và
    // `shiftMoney` (`1,500円`). Dùng nhầm hàm thì phiếu vẫn in ra một con số
    // đúng — chỉ sai đơn vị, tức không có gì đỏ ở tầng nào khác.
    $out = emitShift('shift_report', 'sales_summary', shiftReportData(['grossSales' => 1500]));

    expect($out)->toContain(shiftSjis('1,500円'))
        ->and($out)->not->toContain(shiftSjis('¥1,500'));
});

it('#1934 tiền tệ lấy từ ẢNH CHỤP của ca, không từ config', function () {
    // plan-046 đóng băng currency lên TillSession lúc mở ca đúng để một lần
    // admin đổi tiền tệ giữa chừng không viết lại phiếu của ca đã đóng.
    $out = emitShift('shift_report', 'sales_summary', shiftReportData([
        'currency' => 'VND',
        'grossSales' => 1500,
    ]));

    expect($out)->toContain(shiftSjis('1,500 VND'))
        ->and($out)->not->toContain(shiftSjis('1,500円'));
});

it('#1934 số âm mang dấu trừ TRƯỚC toàn bộ, kể cả đơn vị', function () {
    // 過不足 có thể âm, và đó là con số người ta đọc phiếu này để tìm.
    $out = emitShift('shift_report', 'variance', shiftReportData([
        'showDrawerCheck' => true,
        'cashVariance' => -300,
    ]));

    expect($out)->toContain(shiftSjis('-300円'));
});

it('#1934 quá tiền mới được dấu +, thiếu tiền thì không', function () {
    $over = emitShift('shift_report', 'variance', shiftReportData(['showDrawerCheck' => true, 'cashVariance' => 300]));
    $short = emitShift('shift_report', 'variance', shiftReportData(['showDrawerCheck' => true, 'cashVariance' => -300]));

    expect($over)->toContain(shiftSjis('+300円'))
        ->and($short)->not->toContain(shiftSjis('+'));
});

/*
 * ── Phần đầu phiếu ─────────────────────────────────────────────────────
 */

it('#1934 header vẽ MỘT lần dù brand bật cả store_info lẫn title', function () {
    // Không có `headerDrawn` thì brand bật cả hai in ra HAI lần tên quán.
    $encoder = new Escpos;
    $data = shiftReportData();

    $ctx = new PrintRenderContext(
        encoder: $encoder,
        definition: ['blocks' => [['id' => 'store_info'], ['id' => 'title']]],
        data: $data,
        config: $data->config,
        locale: 'ja',
        width: 42,
        japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'),
        tax: TaxLabels::forLocale('ja'),
    );

    $plan = app(PrintKindRegistry::class)->planFor('shift_report');
    $plan->emitters['store_info']($ctx, ['id' => 'store_info']);
    $plan->emitters['title']($ctx, ['id' => 'title']);

    expect(substr_count($encoder->bytes(), shiftSjis('テスト店')))->toBe(1);
});

it('#1934 store_info bị TẮT thì không in tên quán, nhưng vẫn in tiêu đề', function () {
    // `def.has()` là "có block VÀ đang bật" — khác `def.block()`.
    $out = emitShift(
        'shift_report',
        'title',
        shiftReportData(),
        definitionBlocks: [['id' => 'store_info', 'enabled' => false], ['id' => 'title']],
    );

    expect($out)->not->toContain(shiftSjis('テスト店'))
        ->and($out)->toContain(shiftSjis('精算'));
});

it('#1934 tên quán quá dài HẠ CỠ thay vì tràn giấy', function () {
    // Cỡ đôi chiếm gấp đôi số cột; không vừa thì rơi về chỉ gấp đôi chiều cao.
    $data = new PrintRenderData(
        kind: 'shift_report',
        config: new PrintJobConfig(currency: '¥', storeName: str_repeat('あ', 12)),
        shift: new ShiftReportInfo,
    );

    // 12 kanji = 24 cột; ×2 = 48 > 42 ⇒ DOUBLE_HEIGHT.
    expect(emitShift('shift_report', 'store_info', $data))->toContain(Escpos::DOUBLE_HEIGHT);

    $short = shiftReportData();
    // 「テスト店」 = 10 cột; ×2 = 20 ≤ 42 ⇒ DOUBLE_SIZE.
    expect(emitShift('shift_report', 'store_info', $short))->toContain(Escpos::DOUBLE_SIZE);
});

it('#1934 tiêu đề: DỮ LIỆU thắng chữ brand soạn ở ca bàn giao và chuỗi', function () {
    // 引き継ぎ / 精算（チェーン） nói tờ giấy này ghi nhận HÀNH VI KẾ TOÁN nào.
    // Một ca bàn giao in chữ 精算 là nói sai việc đã xảy ra.
    $authored = [['id' => 'title', 'i18n' => ['ja' => 'ブランド名']]];

    $handover = emitShift(
        'shift_report',
        'title',
        shiftReportData(['reportKind' => 'handover']),
        definitionBlocks: $authored,
    );
    $chain = emitShift(
        'shift_report',
        'title',
        shiftReportData(['isChain' => true]),
        definitionBlocks: $authored,
    );
    $plain = emitShift('shift_report', 'title', shiftReportData(), definitionBlocks: $authored);

    expect($handover)->toContain(shiftSjis('引き継ぎ'))
        ->and($chain)->toContain(shiftSjis('精算（チェーン）'))
        // Ca thường thì chữ brand soạn MỚI thắng — đó là nửa kia của quy tắc.
        ->and($plain)->toContain(shiftSjis('ブランド名'));
});

it('#1934 phiếu mở ca: chữ brand soạn thắng, không có mới rơi về nhãn mặc định', function () {
    $authored = emitShift(
        'shift_open',
        'title',
        shiftOpenData(),
        definitionBlocks: [['id' => 'title', 'i18n' => ['ja' => 'レジ開け']]],
    );
    $fallback = emitShift('shift_open', 'title', shiftOpenData());

    expect($authored)->toContain(shiftSjis('レジ開け'))
        ->and($fallback)->toContain(shiftSjis('開始'));
});

/*
 * ── HAI plan, cùng block id, KHÁC emitter ──────────────────────────────
 */

it('#1934 shift_meta và denomination_table là HAI emitter khác nhau ở hai kind', function () {
    // Chỗ dễ port sai nhất của cả họ: một bảng `blockId => emitter` dùng chung
    // sẽ làm phiếu mở ca in ra khối 精算 rỗng — không có gì đỏ, chỉ là một tờ
    // giấy thiếu bảng kiểm đếm tiền.
    $openPlan = app(PrintKindRegistry::class)->planFor('shift_open');
    $reportPlan = app(PrintKindRegistry::class)->planFor('shift_report');

    foreach (['shift_meta', 'denomination_table'] as $blockId) {
        expect($openPlan->emitters[$blockId])->not->toBe($reportPlan->emitters[$blockId]);
    }

    // Và chứng minh bằng HÀNH VI, không chỉ bằng danh tính hàm: phiếu mở ca đọc
    // `shiftOpen`, phiếu 精算 đọc `shift`.
    $open = emitShift('shift_open', 'shift_meta', shiftOpenData(['deviceName' => 'POS-1']));
    $report = emitShift('shift_report', 'shift_meta', shiftReportData(['tillCode' => '0001']));

    expect($open)->toContain(shiftSjis('POS-1'))
        ->and($report)->toContain(shiftSjis('レジ0001'));
});

it('#1934 chain_report DÙNG LẠI đúng plan của shift_report', function () {
    $registry = app(PrintKindRegistry::class);

    expect($registry->planFor('chain_report'))->toBe($registry->planFor('shift_report'));
});

/*
 * ── Phiếu mở ca ────────────────────────────────────────────────────────
 */

it('#1934 shift_meta mở ca theo thứ tự `fields` brand khai', function () {
    $data = shiftOpenData(['deviceName' => 'POS-1', 'operator' => '田中', 'openedAt' => '2026/08/06 09:00']);

    $out = emitShift('shift_open', 'shift_meta', $data, block: ['fields' => ['opened_at', 'device_name']]);

    expect(strpos($out, shiftSjis('2026/08/06 09:00')))->toBeLessThan(strpos($out, shiftSjis('POS-1')))
        // `cashier_name` không được khai ⇒ không in.
        ->and($out)->not->toContain(shiftSjis('田中'));
});

it('#2188 `till_name` KHÔNG còn là bí danh của `device_name` — alias legacy đã xoá', function () {
    // #1934 từng nhận cả hai tên cho cùng một dòng. Ruling #2188: alias tên cũ
    // bị xoá, definition phải gọi đúng `device_name`; một template còn ghi
    // `till_name` mất dòng máy — cố ý, để cái tên chết không sống ngầm.
    $data = shiftOpenData(['deviceName' => 'POS-1']);

    expect(emitShift('shift_open', 'shift_meta', $data, block: ['fields' => ['till_name']]))
        ->not->toContain(shiftSjis('POS-1'))
        ->and(emitShift('shift_open', 'shift_meta', $data, block: ['fields' => ['device_name']]))
        ->toContain(shiftSjis('POS-1'));
});

it('#1934 người mở ca chưa đặt thì in 未設定, KHÔNG bỏ dòng', function () {
    // Một dòng vắng và một ca không ai nhận là hai chuyện khác nhau.
    $out = emitShift('shift_open', 'shift_meta', shiftOpenData(), block: ['fields' => ['cashier_name']]);

    expect($out)->toContain(shiftSjis('未設定'));
});

it('#1934 bảng mệnh giá mở ca CÓ tiêu đề cột, bảng 精算 thì không', function () {
    $open = emitShift('shift_open', 'denomination_table', shiftOpenData([
        'denominations' => [new ShiftDenominationLine(value: 1000, quantity: 3, subtotal: 3000)],
    ]));

    $report = emitShift('shift_report', 'denomination_table', shiftReportData([
        'showDenominations' => true,
        'denominations' => [new ShiftDenominationLine(value: 1000, quantity: 3, subtotal: 3000)],
    ]));

    expect($open)->toContain(shiftSjis('金種'))->toContain(shiftSjis('枚数'))->toContain(shiftSjis('金額'))
        // 枚 là đơn vị của phiếu 精算; phiếu mở ca dùng 枚 làm QtyUnit nên cả hai
        // đều có — điều phân biệt là ba TIÊU ĐỀ CỘT ở trên.
        ->and($report)->toContain(shiftSjis('金種'))
        ->and($report)->not->toContain(shiftSjis('枚数'));
});

it('#1934 ghi chú mở ca ngắt dòng theo bề rộng giấy và thụt 2 cột', function () {
    // Không ngắt dòng thì nó tràn và đẩy vỡ mọi thứ bên dưới.
    $out = emitShift('shift_open', 'order_note', shiftOpenData(['note' => str_repeat('a ', 40)]), width: 20);

    $lines = array_values(array_filter(explode("\n", $out), static fn ($l) => str_starts_with($l, '  a')));

    expect(count($lines))->toBeGreaterThan(1);

    foreach ($lines as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(20);
    }
});

it('#1934 ghi chú rỗng thì KHÔNG in cả nhãn 備考', function () {
    expect(emitShift('shift_open', 'order_note', shiftOpenData(['note' => '   '])))->toBe('');
});

/*
 * ── Phiếu 精算: khối định danh ─────────────────────────────────────────
 */

it('#1934 số Z đệm 0 thành 5 chữ số', function () {
    // Nó là số sổ liên tục và được đối soát bằng mắt theo cột.
    expect(emitShift('shift_report', 'shift_meta', shiftReportData(['zNumber' => 3])))
        ->toContain(shiftSjis('No.00003'));
});

it('#1934 phiếu chuỗi in mã chuỗi CẮT 8 ký tự + số ca, KHÔNG in số Z', function () {
    $out = emitShift('shift_report', 'shift_meta', shiftReportData([
        'isChain' => true,
        'chainId' => '01JQRSTUVWXYZ0123456789',
        'shiftCount' => 3,
        'zNumber' => 7,
    ]));

    // So NGUYÊN DÒNG, không `toContain`: bản đầu của ca này dùng `toContain` và
    // một đột biến BỎ HẲN phép cắt vẫn xanh — vì chuỗi đầy đủ
    // `01JQRSTUVWXYZ...` cũng CHỨA tiền tố `01JQRSTU`. Một rào chưa thấy đỏ thì
    // chưa phải rào.
    // Khối này căn phải, nên byte điều khiển ESC GS a 2 dính vào ĐẦU dòng đầu
    // tiên và `trim()` không với tới. Gỡ lệnh căn lề trước khi tách dòng.
    $lines = array_map(
        static fn ($l) => trim($l),
        explode("\n", str_replace([Escpos::ALIGN_RIGHT, Escpos::ALIGN_LEFT, Escpos::ALIGN_CENTER], '', $out))
    );

    expect($lines)->toContain(shiftSjis('チェーン 01JQRSTU'))
        ->and($lines)->toContain(shiftSjis('3 シフト'))
        ->and($out)->not->toContain(shiftSjis('No.00007'));
});

it('#1934 giờ đóng in TRẦN, không tiền tố', function () {
    // Nó nằm ngay dưới dòng 対象期間 và cùng căn phải, nên cặp mốc đọc như một
    // khoảng.
    $out = emitShift('shift_report', 'shift_meta', shiftReportData([
        'openedAt' => '2026/08/06 09:00',
        'closedAt' => '2026/08/06 17:00',
    ]));

    expect($out)->toContain(shiftSjis('対象期間 2026/08/06 09:00'))
        ->and($out)->not->toContain(shiftSjis('対象期間 2026/08/06 17:00'));
});

/*
 * ── Phiếu 精算: các khối số ────────────────────────────────────────────
 */

it('#1934 chain_summary chỉ in cho phiếu CHUỖI', function () {
    $line = new ChainShiftLine(sequence: 1, kind: 'handover', operator: '田中', gross: 5000, variance: -100);

    $chain = emitShift('shift_report', 'chain_summary', shiftReportData([
        'isChain' => true,
        'chainIndex' => [$line],
    ]));
    $plain = emitShift('shift_report', 'chain_summary', shiftReportData(['chainIndex' => [$line]]));

    expect($chain)->toContain(shiftSjis('シフト1 (引き継ぎ)'))
        ->and($chain)->toContain(shiftSjis('田中'))
        // Thiếu tiền cũng phải hiện — đó mới là trường hợp người ta đi tìm.
        ->and($chain)->toContain(shiftSjis('-100円'))
        ->and($plain)->toBe('');
});

it('#1934 sales_summary đặt số món/số khách trên DÒNG RIÊNG căn phải', function () {
    $out = emitShift('shift_report', 'sales_summary', shiftReportData([
        'grossSales' => 3775,
        'itemCount' => 5,
        'netSales' => 3460,
        'guestCount' => 4,
        'taxTotal' => 315,
    ]));

    $lines = explode("\n", $out);

    // Dòng "5個" phải là dòng riêng, căn phải, KHÔNG dính vào dòng 総売上.
    $itemLine = array_values(array_filter($lines, static fn ($l) => str_contains($l, shiftSjis('個'))));

    expect($itemLine)->toHaveCount(1)
        ->and(trim($itemLine[0]))->toBe(shiftSjis('5個'))
        ->and(str_contains($itemLine[0], shiftSjis('総売上')))->toBeFalse();
});

it('#1934 tax_breakdown: hai khối RẼ NHÁNH cùng nhau', function () {
    // Cho một khối theo mức còn khối kia gộp là ra một tờ giấy mà hai nửa không
    // cộng khớp nhau.
    $perRate = emitShift('shift_report', 'tax_breakdown', shiftReportData([
        'showTaxBreakdown' => true,
        'taxBreakdown' => [
            new ShiftTaxRateLine(rate: 8.0, taxableSales: 1000, tax: 80),
            new ShiftTaxRateLine(rate: 10.0, taxableSales: 2000, tax: 200),
        ],
        'netSales' => 3000,
        'taxTotal' => 280,
    ]));

    expect($perRate)->toContain(shiftSjis('8%対象'))->toContain(shiftSjis('10%対象'))
        ->and($perRate)->not->toContain(shiftSjis('課税売上'));

    // Cờ bật NHƯNG không có ảnh chụp theo dòng ⇒ vẫn gộp. Một ca cũ bật cờ mà
    // in ra khối rỗng dưới tiêu đề 消費税内訳 là tệ hơn gộp.
    $legacy = emitShift('shift_report', 'tax_breakdown', shiftReportData([
        'showTaxBreakdown' => true,
        'netSales' => 3000,
        'taxTotal' => 280,
    ]));

    expect($legacy)->toContain(shiftSjis('課税売上'))
        ->and($legacy)->not->toContain(shiftSjis('対象'));
});

it('#1934 phí phục vụ được nêu RIÊNG trong khối theo mức', function () {
    // Các mức thuế chỉ phủ dòng món — thiếu nó thì cột không cộng ra 純売上.
    $out = emitShift('shift_report', 'tax_breakdown', shiftReportData([
        'showTaxBreakdown' => true,
        'taxBreakdown' => [new ShiftTaxRateLine(rate: 10.0, taxableSales: 2000, tax: 200)],
        'serviceCharge' => 300,
        'serviceChargeTax' => 30,
    ]));

    expect($out)->toContain(shiftSjis('サービス料'))->toContain(shiftSjis('300円'))
        ->and($out)->toContain(shiftSjis('サービス料消費税'))->toContain(shiftSjis('30円'));
});

it('#1934 tender_summary dịch mã tổng quát, giữ nguyên tên ví thương hiệu', function () {
    $out = emitShift('shift_report', 'tender_summary', shiftReportData([
        'showPaymentMethods' => true,
        'payments' => [
            new ShiftPaymentLine(code: 'cash', label: 'Cash', count: 12, amount: 45000),
            new ShiftPaymentLine(code: 'paypay', label: 'PayPay', count: 3, amount: 9000),
        ],
    ]));

    // `cash` có trong bảng ⇒ dịch. `paypay` là danh từ riêng ⇒ rơi về tên Cloud.
    expect($out)->toContain(shiftSjis('現金'))
        ->and($out)->toContain(shiftSjis('PayPay'))
        ->and($out)->toContain(shiftSjis('12件'));
});

it('#1934 tender_summary im khi quán TẮT hiển thị phương thức', function () {
    expect(emitShift('shift_report', 'tender_summary', shiftReportData([
        'payments' => [new ShiftPaymentLine(code: 'cash', label: 'Cash', count: 1, amount: 100)],
    ])))->toBe('');
});

it('#1934 non_cash_change và acct_correction LUÔN in, với số 0', function () {
    // Một mục vắng đọc như "quán này không có khoản đó", khác hẳn "khoản đó
    // bằng 0" — kiểm toán viên đặt phiếu hai quán cạnh nhau phải thấy cùng tập.
    expect(emitShift('shift_report', 'non_cash_change', shiftReportData()))
        ->toContain(shiftSjis('現金以外おつり'))->toContain(shiftSjis('0件'))
        ->and(emitShift('shift_report', 'acct_correction', shiftReportData()))
        ->toContain(shiftSjis('会計修正'));
});

it('#1934 discount_summary: danh sách tên THẮNG tổng gộp', function () {
    // In cả hai là đếm đôi.
    $named = emitShift('shift_report', 'discount_summary', shiftReportData([
        'discounts' => [new ShiftDiscountLine(label: '会員割', count: 2, amount: 500)],
        'discountTotalCount' => 9,
        'discountTotalAmount' => 9999,
    ]));

    expect($named)->toContain(shiftSjis('会員割'))
        ->and($named)->toContain(shiftSjis('▲500円'))
        ->and($named)->not->toContain(shiftSjis('9,999円'));

    $collapsed = emitShift('shift_report', 'discount_summary', shiftReportData([
        'discountTotalCount' => 9,
        'discountTotalAmount' => 9999,
    ]));

    expect($collapsed)->toContain(shiftSjis('▲9,999円'));
});

it('#1934 cash_movement in HIỆU thu-chi, không phải tổng cộng', function () {
    // Nó trả lời "ngăn kéo dày lên hay mỏng đi bao nhiêu ngoài doanh thu".
    $out = emitShift('shift_report', 'cash_movement', shiftReportData([
        'paidInCount' => 2, 'paidInAmount' => 5000,
        'paidOutCount' => 1, 'paidOutAmount' => 8000,
    ]));

    expect($out)->toContain(shiftSjis('-3,000円'))
        ->and($out)->not->toContain(shiftSjis('13,000円'));
});

it('#1934 check_count chỉ dùng cột ĐẾM, không ghép cột tiền', function () {
    // Ghép thêm một cột tiền rỗng sẽ đẩy con số sang trái và lệch khỏi mọi dòng
    // đếm khác.
    $out = trim(emitShift('shift_report', 'check_count', shiftReportData(['checkCount' => 42])));
    $lines = explode("\n", $out);
    $row = end($lines);

    expect(rtrim($row))->toBe($row)
        ->and($row)->toEndWith(shiftSjis('42件'));
});

it('#1934 void_summary tách chưa-thanh-toán và đã-thanh-toán', function () {
    $out = emitShift('shift_report', 'void_summary', shiftReportData([
        'voidUnpaidCount' => 2, 'voidUnpaidAmount' => 1000,
        'voidPaidCount' => 1, 'voidPaidAmount' => 3000,
    ]));

    expect($out)->toContain(shiftSjis('未会計'))->toContain(shiftSjis('1,000円'))
        ->and($out)->toContain(shiftSjis('会計済'))->toContain(shiftSjis('3,000円'));
});

it('#1934 ba khối theo CỜ đều im khi cờ tắt', function () {
    foreach (['tender_summary', 'service_charge', 'variance', 'denomination_table'] as $blockId) {
        expect(emitShift('shift_report', $blockId, shiftReportData()))->toBe('');
    }
});

it('#1934 mọi emitter 精算 im khi KHÔNG có dữ liệu ca', function () {
    // Phiếu vẽ dở còn tệ hơn phiếu không in ra.
    $empty = new PrintRenderData(kind: 'shift_report', config: new PrintJobConfig(currency: '¥'));

    foreach (app(PrintKindRegistry::class)->planFor('shift_report')->blockIds() as $blockId) {
        if ($blockId === 'store_info' || $blockId === 'title') {
            continue; // header không cần dữ liệu ca
        }

        expect(emitShift('shift_report', $blockId, $empty))->toBe('', "block {$blockId} phải im");
    }
});

/*
 * ── Cột: thứ giữ cả phiếu thẳng hàng ───────────────────────────────────
 */

it('#1934 cột đếm rộng ĐÚNG 6, cột tiền rộng ĐÚNG 12', function () {
    // Bản đầu của ca này chỉ kiểm mọi dòng {đếm}{tiền} CÙNG độ rộng — và một
    // đột biến đổi COUNT_COL 6→7 vẫn xanh, vì nó dịch tất cả các dòng như nhau.
    // Đo tính NHẤT QUÁN không đo được VỊ TRÍ; phải ghim hình học thật.
    $data = shiftReportData([
        'showPaymentMethods' => true,
        'payments' => [new ShiftPaymentLine(code: 'cash', label: 'Cash', count: 12, amount: 45000)],
    ]);

    // '12件' = 4 cột ⇒ đệm về 6 = '  12件'
    // '45,000円' = 8 cột ⇒ đệm về 12 = '    45,000円'
    // nhãn '  現金' = 6 cột; 42 - 6 - 18 = 18 khoảng đệm giữa.
    $expected = shiftSjis('  現金'.str_repeat(' ', 18).'  12件    45,000円');

    $lines = array_map(static fn ($l) => rtrim($l, "\r"), explode("\n", emitShift('shift_report', 'tender_summary', $data)));

    expect($lines)->toContain($expected);
});

it('#1934 giấy hẹp: khoảng đệm KẸP ở 1 và cột đếm lộ ra đúng 6', function () {
    // Phép đo đáng ghi lại: đột biến COUNT_COL 6→7 SỐNG SÓT qua mọi ca dùng
    // `row()`, và đó KHÔNG phải test yếu — nó là sự thật về hình học. `row()`
    // co giãn khoảng giữa, nên thêm một cột vào cột đếm thì khoảng giữa tự bớt
    // đúng một cột và chuỗi byte ra y hệt. COUNT_COL chỉ quan sát được khi
    // khoảng đệm KHÔNG còn co được nữa — tức khi nó chạm sàn `max(..., 1)`.
    //
    // Nên ca này vừa ghim hằng số, vừa là ca duy nhất phủ nhánh kẹp đó.
    //
    // width 20: 20 - dw(金種)=4 - 6 - 12 = -2 ⇒ kẹp về 1.
    $out = emitShift('shift_open', 'denomination_table', shiftOpenData(), width: 20);

    $header = explode("\n", $out)[1];

    expect($header)->toBe(shiftSjis('金種'.' '.'  枚数'.'        金額'));
});

it('#1934 cột đếm giữ nguyên bề rộng ở dòng KHÔNG có cột tiền', function () {
    // 会計回数 dừng ở COUNT_COL. Ghim luôn ở đây để đổi hằng đó là đỏ ở hai chỗ.
    $out = emitShift('shift_report', 'check_count', shiftReportData(['checkCount' => 42]));

    // nhãn 会計回数 = 8 cột; '42件' = 4 cột ⇒ đệm về 6; 42 - 8 - 6 = 28.
    $expected = shiftSjis('会計回数'.str_repeat(' ', 28).'  42件');

    $lines = array_map(static fn ($l) => rtrim($l, "\r"), explode("\n", $out));

    expect($lines)->toContain($expected);
});

it('#1934 các khối {đếm}{tiền} thẳng hàng với nhau', function () {
    // Vẫn giữ phép đo nhất quán — nó bắt loại lỗi khác: một khối quên gọi
    // `stat()` và tự ghép cột.
    $data = shiftReportData([
        'showPaymentMethods' => true,
        'payments' => [new ShiftPaymentLine(code: 'cash', label: 'Cash', count: 12, amount: 45000)],
        'paidInCount' => 2, 'paidInAmount' => 5000,
        'paidOutCount' => 1, 'paidOutAmount' => 8000,
    ]);

    $widths = [];

    foreach (['tender_summary', 'cash_movement', 'void_summary', 'non_cash_change'] as $blockId) {
        foreach (explode("\n", emitShift('shift_report', $blockId, $data)) as $line) {
            if (str_contains($line, shiftSjis('件'))) {
                $widths[] = strlen(rtrim($line, "\r"));
            }
        }
    }

    expect($widths)->not->toBeEmpty()
        ->and(array_unique($widths))->toHaveCount(1);
});

/*
 * ── PaymentMethodLabels ────────────────────────────────────────────────
 */

it('#1934 nhãn phương thức: thang ba bậc, bậc cuối là chính MÃ', function () {
    // Một dòng tiền không nhãn trên phiếu đối soát là một khoản không ai truy
    // được; `on_account` in thô thì xấu nhưng vẫn truy được.
    expect(PaymentMethodLabels::localize('ja', 'cash', 'Cash'))->toBe('現金')
        ->and(PaymentMethodLabels::localize('vi', 'CASH  ', 'Cash'))->toBe('Tien mat')
        ->and(PaymentMethodLabels::localize('ja', 'paypay', 'PayPay'))->toBe('PayPay')
        ->and(PaymentMethodLabels::localize('ja', 'weird_code', ''))->toBe('weird_code');
});

it('#1934 locale lạ rơi về TÊN CLOUD, không rơi về ja', function () {
    // Khác `ShiftLabels::forLocale` ngay bên cạnh — chép đúng Go: tên quán cấu
    // hình là bản dự phòng tốt hơn một bản dịch tiếng Nhật người đọc không đọc
    // được.
    expect(PaymentMethodLabels::localize('th', 'cash', 'Ngan hang'))->toBe('Ngan hang');
});

it('#1934 bảng nhãn phương thức khớp Go từng mã, từng locale', function () {
    // Đọc THẲNG từ nguồn Go thay vì chép kỳ vọng: một bảng chép tay được ghim
    // bằng một bảng chép tay khác thì cả hai cùng sai mà không ai biết.
    $source = file_get_contents(
        base_path('../workstation/internal/service/print_shift_report_i18n.go')
    );

    expect($source)->not->toBeFalse();

    $block = null;
    if (preg_match('/paymentMethodLabels = map\[string\]map\[string\]string\{(.*?)\n\}/s', (string) $source, $m) === 1) {
        $block = $m[1];
    }

    expect($block)->not->toBeNull('không tìm thấy paymentMethodLabels trong nguồn Go — cấu trúc file đã đổi');

    $go = [];
    preg_match_all('/"([a-z_]+)":\s*\{([^}]*)\}/', (string) $block, $rows, PREG_SET_ORDER);

    foreach ($rows as $row) {
        $entry = [];
        preg_match_all('/"(\w+)":\s*"([^"]*)"/u', $row[2], $pairs, PREG_SET_ORDER);

        foreach ($pairs as $pair) {
            $entry[$pair[1]] = $pair[2];
        }

        $go[$row[1]] = $entry;
    }

    // Chốt chặn cho chính bộ phân tích trên: nó im lặng trả về mảng rỗng nếu
    // cú pháp Go đổi, và một phép so hai mảng rỗng thì luôn xanh.
    expect($go)->toHaveCount(16);

    expect(PaymentMethodLabels::all())->toBe($go);
});
