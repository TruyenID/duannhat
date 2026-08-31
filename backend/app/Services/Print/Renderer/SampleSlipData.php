<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use App\Services\Print\Enums\PrintTemplateKind;

/**
 * plan-053 M5 (#1171) TR-33 — the standard sample basket a preview is drawn
 * from.
 *
 * Deliberately NOT the simple case. A preview built from one item at one tax
 * rate hides every layout problem the editor exists to catch, so the sample
 * carries:
 *
 *  - TWO tax rates, so the ※ reduced-rate marker and the per-rate 内税 block
 *    both appear (and the brand sees how tall that footer really is);
 *  - a name long enough to wrap on 58mm paper but not on 80mm, which is where
 *    column layouts actually break;
 *  - a Japanese item beside Vietnamese ones, so fullwidth measurement is
 *    visible rather than theoretical;
 *  - a topping, a discount and a service charge, because each adds a row that
 *    a brand designing against a bare total will not have budgeted for.
 *
 * The figures mirror the workstation's golden fixture so a reviewer comparing
 * the preview with the parity fixture sees the same basket.
 *
 * ── Labels come from the PRINT catalogs, never from here (#2028) ───────────
 *
 * The FIGURES are illustrative; the LABELS are not. Every label on a `locked`
 * or `params` row is a system constant the brand can never author, so the
 * preview must show the very string the emitter will print — the R28 rule,
 * applied to the whole slip instead of to `reprint_marker` alone.
 *
 * This class therefore holds **no label table of its own**. It reads the same
 * catalogs the real emitters read — {@see PrintLabels}, {@see TaxLabels},
 * {@see ShiftLabels}, {@see ShiftOpenLabels}, {@see TablePaidLabels},
 * {@see PaymentMethodLabels} — and those are generated from the shared Go
 * fixtures. Before #2028 it carried 54 Japanese literals and took no locale at
 * all, so an `en`/`vi` preview came out almost entirely in Japanese AND
 * disagreed with the ja slip (`割り勘 1/2` where the printer emits a `===` bar,
 * 「分割会計 (1/2)」 and a mode line).
 *
 * Adding a literal back here is how that returns. If a row has no catalog
 * label, LEAVE it — inventing a Vietnamese word for a money document is a
 * product decision, not a rendering one. The rows still carrying a local
 * literal are marked `#2028-unmapped` below and pinned by
 * `RendererPreviewTest` R29/R30.
 *
 * ── The sample is per-KIND, not one basket (#2036) ────────────────────────
 *
 * A block id does not name one emitter: `grand_total` is
 * `BillKindPlans::emitGrandTotal` on a receipt, `emitVatGrandTotal` on a VAT
 * invoice and `emitDebtTotal` on a 「PHIEU GHI NO」, and they do not all print
 * the same word. So `locked` is assembled per kind — the shift family, the debt
 * slip, the two VAT kinds, the void notice, then the bill default — and a row
 * that is right for one kind is a promise the other kind's printer will not
 * keep. Check `PrintKindRegistry` before reusing a row across kinds.
 *
 * #2039 added the fourth and fifth branches, and the fifth is the one worth
 * remembering: `vat_invoice` and `qualified_simplified_invoice` share ONE plan
 * and differ only by the `japaneseDoc` flag, yet they print two entirely
 * different label sets ({@see DocsKindPlans} vs {@see VatInvoiceJa}). Same
 * blocks, same plan object, different words on paper — so "same kind family"
 * is not a reason to share a row either.
 */
final class SampleSlipData
{
    /**
     * Số liệu thuế mẫu — HAI mức, và chúng là số của golden fixture bên
     * workstation chứ không phải số bịa cho đẹp.
     *
     * Công khai vì bản xem trước phải chứng minh được là nó vẽ đúng cái
     * `BillKindPlans::emitTaxBreakdown` vẽ (#2045 mục 3), và phép chứng minh ấy
     * cần dựng một `ReceiptTaxSummary` từ CÙNG bộ số. Chép lại bộ số vào bài
     * test là để hai bên trôi khỏi nhau trong im lặng — đúng cái đã xảy ra với
     * chuỗi 「内税」 tự chế.
     *
     * @var list<array{rate: float, taxable: int, tax: int}>
     */
    public const TAX_BREAKDOWN = [
        ['rate' => 10.0, 'taxable' => 2915, 'tax' => 265],
        ['rate' => 8.0, 'taxable' => 300, 'tax' => 22],
    ];

    /**
     * @param  string  $locale  reader's locale — the SAME one {@see SlipComposer::compose()} was handed
     * @return array<string, mixed>
     */
    public static function forKind(PrintTemplateKind|string $kind, int $columns, string $locale): array
    {
        $kindValue = $kind instanceof PrintTemplateKind ? $kind->value : $kind;
        $cur = '¥';

        $normalized = Definition::normalizeLocale($locale);
        $labels = PrintLabels::forLocale($locale);
        $tax = TaxLabels::forLocale($locale);
        $shift = ShiftLabels::forLocale($normalized);
        $open = ShiftOpenLabels::forLocale($normalized);
        $paid = TablePaidLabels::forLocale($locale);

        $params = [
            // #2036 — 法人名. #2000 bước 6 khai `store_organization` vào
            // `store_info.fields` MẶC ĐỊNH, tức máy in đã in dòng này; sample
            // không có ô đó thì `SlipComposer::emitParams` bỏ qua field (không
            // tìm thấy khoá) và bản xem trước NUỐT một dòng có thật của tờ giấy
            // — im lặng, đúng bệnh #2000/#2028.
            //
            // Đây là dòng đứng ĐẦU header hoá đơn Nhật, và nó load-bearing:
            // 登録番号 T+13 (#1152) thuộc PHÁP NHÂN, nên tên in cạnh con số đó
            // phải là tên pháp nhân, không phải tên chi nhánh.
            'store_organization' => ['label' => '', 'value' => '株式会社ベト屋'],
            'store_name' => ['label' => '', 'value' => 'ベト屋'],
            'store_sub_name' => ['label' => '', 'value' => 'VIET ORIGIN'],
            'store_address' => ['label' => '', 'value' => 'Tokyo, Shinjuku 1-2-3'],
            // #2036 — KHÔNG nhãn `TEL`. `StoreInfoBlock::emit()` phát
            // `$ctx->encoder->line($value)` cho MỌI dòng cửa hàng: giá trị trần,
            // không cột nhãn, cả ba họ phiếu. Nhãn `TEL` là một cột mà tờ giấy
            // không có — và nó cũng đo sai bề rộng dòng, đúng khuyết tật R28
            // sinh ra để chặn. Bốn dòng cửa hàng còn lại vốn đã không nhãn.
            'store_phone' => ['label' => '', 'value' => '03-1234-5678'],
            'order_no' => ['label' => $labels->orderNo, 'value' => '004'],
            'order_type' => ['label' => $labels->orderMethod, 'value' => $labels->dineIn],
            'table' => ['label' => $labels->table, 'value' => 'A-1'],
            'guest' => ['label' => $labels->guest, 'value' => '2'],
            // #2028-unmapped: no emitter prints a staff line on a bill, so no
            // catalog owns this label.
            'staff_name' => ['label' => '担当', 'value' => '田中'],
            // #2028-unmapped: `guestUnit` is a UNIT ("人" / " khach"), not a
            // row label, and nothing else names the cover count.
            'cover_count' => ['label' => '人数', 'value' => '4'],
            'customer_name' => ['label' => $labels->customerLabel, 'value' => 'Cty TNHH ABC Foods'],
            'customer_phone' => ['label' => $labels->phone, 'value' => '0901234567'],
            // #2028-unmapped: the VAT-invoice party block writes these two
            // itself (`emitVatParties`); there is no catalog entry to borrow.
            'customer_tax_code' => ['label' => 'MST', 'value' => '0312345678'],
            'customer_address' => ['label' => 'DC', 'value' => '123 Le Loi, Q.1'],
            'pickup_time' => ['label' => $labels->pickupTime, 'value' => '18:30'],
            'cashier_name' => ['label' => $shift->operator, 'value' => '田中'],
            'device_name' => ['label' => $open->device, 'value' => 'POS-01'],
            // #2028-unmapped: 精算 prints the period, never a business date.
            'business_date' => ['label' => '営業日', 'value' => '2026/07/20'],
            'opened_at' => ['label' => $open->openedAt, 'value' => '2026/07/20 09:00'],
            // #2028-unmapped: the real 精算 meta prints the closing time BARE,
            // right under the period line, with no label at all.
            'closed_at' => ['label' => '終了', 'value' => '2026/07/20 17:09'],
            // Both sequence rows use the same catalog entry the shift meta uses
            // (`chainShift` + the number). `chainShift` carries a trailing space
            // in en/vi because the emitter concatenates it — the preview puts
            // the number in its own column, so trim it.
            'shift_sequence' => ['label' => trim($shift->chainShift), 'value' => '2'],
            'chain_sequence' => ['label' => trim($shift->chainShift), 'value' => '2'],
        ];

        $items = [
            [
                'name' => 'Bun bo Hue dac biet thap cam',
                'qty' => 2,
                'unit_price' => $cur.'980',
                'amount' => $cur.'1,960',
                'mark' => '',
            ],
            ['name' => '緑茶', 'qty' => 1, 'unit_price' => $cur.'300', 'amount' => $cur.'300', 'mark' => TaxLabels::REDUCED_MARKER],
            ['name' => 'Cafe sua da', 'qty' => 1, 'unit_price' => $cur.'890', 'amount' => $cur.'890', 'mark' => ''],
        ];

        $money = [
            'subtotal' => [['label' => $labels->subtotal, 'value' => $cur.'3,300']],
            // #2071 — khối `discounts` giờ CÓ emitter (`BillKindPlans::emitDiscounts`)
            // và nó in MỖI DÒNG SỔ một dòng giấy, kèm nhóm mức. Sample là đúng
            // phép chia mà sổ ghi cho giỏ chuẩn này: ¥100 pro-rata trên
            // {8%: 300, 10%: 3.000} của subtotal 3.300 → −9 / −91 (phần dư vào
            // mức cuối). Nhãn đọc từ catalog của CHÍNH emitter
            // ({@see PrintLabels::$discount}) — không còn mượn
            // `$shift->discountGeneric` như hồi block chưa ai vẽ, vì bản xem
            // trước không được hứa một chữ khác tờ giấy (R28/R42).
            'discounts' => [
                ['label' => sprintf('%s (8%%)', $labels->discount), 'value' => '-'.$cur.'9'],
                ['label' => sprintf('%s (10%%)', $labels->discount), 'value' => '-'.$cur.'91'],
            ],
            'service_charge' => [['label' => $labels->serviceCharge, 'value' => $cur.'330']],
            'grand_total' => [['label' => $labels->total, 'value' => $cur.'3,530', 'bold' => true]],
            // #1042: the per-rate 内税 sits UNDER the total, indented three
            // columns, because it is already inside it.
            //
            // #2045 mục 3 — dựng bằng CHÍNH hàm emitter dùng, không chép tay.
            // Bản chép tay trước đó mang chuỗi tự chế 「内税」 (thay cho
            // 「内消費税」 của catalog) và một dấu ※ mà `rateBlockLine` cố ý không
            // in; nó còn LỌT rào "không dòng nào rộng hơn giấy" của R2 chỉ vì
            // ngắn hơn dòng thật, tức nó che luôn lỗi tràn khổ 58mm ngay bên
            // cạnh (#2035). Một bản sao thứ hai của bố cục vừa nói dối brand
            // vừa giấu lỗi thật.
            //
            // Bố cục theo `$columns`: ở 32 cột khối này xuống hai dòng mỗi mức
            // (#2035), ở 42/48 vẫn một dòng — và bản xem trước phải theo, vì
            // chiều CAO của khối là thứ brand dựng phần còn lại của phiếu
            // quanh nó.
            'tax_breakdown' => BillKindPlans::taxBreakdownRows(
                $tax,
                $cur,
                ReceiptTaxSummary::fromBreakdown(['by_rate' => self::TAX_BREAKDOWN])->blocks,
                $columns,
            ),
            'tax_legend' => [['text' => $tax->reducedLegend]],
            'registration_number' => [['text' => $tax->registrationNumber.': T1234567890123']],
            'payments' => [
                ['label' => $labels->paidAmount, 'value' => $cur.'3,530', 'bold' => true],
                // The VALUE stays the raw code: the bill prints the payment
                // name the shop configured, untranslated, on purpose — only the
                // 精算 tender block localises it ({@see PaymentMethodLabels}).
                ['label' => $labels->paymentMethod, 'value' => 'cash'],
            ],
            'change_due' => [
                ['label' => $labels->tendered, 'value' => $cur.'4,000'],
                ['label' => $labels->change, 'value' => $cur.'470'],
            ],
            'remaining' => [['label' => $labels->remaining, 'value' => $cur.'1,200', 'bold' => true]],
            'issued_at' => [['text' => '2026/07/20 14:32']],
            // `invoice_number` KHÔNG có ở đây nữa (#2045 mục 2).
            //
            // #2039 đã tách hai kind VAT ra `$vatRows`/`$vatJaRows` vì emitter
            // của chúng CÓ vẽ block này, bằng những chữ khác nhau. Hai kind còn
            // lại đọc dòng chung ấy và nó sai cho cả hai:
            //
            //   - `void_notice` in "So HD bi huy: {n}"
            //     (`DocsKindPlans::emitVoidInvoiceNumber`) — ASCII ở MỌI locale,
            //     như mọi dòng khác của biên bản huỷ. 「請求書番号」 vừa sai chữ
            //     vừa sai bề rộng (4 chữ Nhật = 8 cột). → `$voidRows`.
            //   - họ bill in KHÔNG GÌ CẢ: `invoice_number` là block `locked`,
            //     mặc định BẬT, và chưa từng có emitter trong
            //     `BillKindPlans::BLOCKS` — nợ #1949, và nó bị vòng lọc ở cuối
            //     hàm gỡ cùng mọi khối khác đang nằm trong
            //     `print_blocks.renderable_debt`.
            //
            // Không kind nào còn cần dòng chung, nên nó biến mất thay vì ở lại
            // làm một literal tiếng Nhật chết mà R29 phải miễn trừ.
            // P-10b (#1166) — NOT an illustrative figure. Unlike the amounts and
            // dates around it, this literal is not authorable: `reprint_marker`
            // is a `locked` block with no editable props (config/print_blocks.php)
            // and the text is hard-coded by the renderer, right-aligned. It is
            // ASCII in en/vi because half these machines have no kanji ROM, and
            // 「再印刷」 in ja — {@see ReprintMarker} builds exactly this, so the
            // preview reads the same catalog rather than guessing which of the
            // two it will be.
            'reprint_marker' => [['text' => $labels->reprintMark.' #2', 'align' => SlipComposer::ALIGN_RIGHT]],
            // #2028-unmapped: no emitter draws a 赤伝 marker at all — the red
            // invoice says so in its `title`. Nothing to read from.
            'red_invoice_marker' => [['text' => '*** 赤伝 ***', 'align' => SlipComposer::ALIGN_CENTER, 'bold' => true]],
            // `void_marker` KHÔNG có hàng chung ở đây (#2045 đợt hai): block đó
            // chỉ thuộc kind `void_notice` (config/print_blocks.php), và hàng
            // của nó nằm trong `$voidRows` — đúng chuỗi `emitVoidMarker` in.
            // Hàng cũ 「*** 取消 ***」 là dòng preview vẽ mà không máy in nào vẽ,
            // ngay cạnh một comment đã ghi đúng chuỗi thật.
            // The real banner, not a one-line lookalike (#2028): a full-width
            // bar, the bold title with the (n/m) suffix, then the mode line —
            // `BillKindPlans::emitSplitBanner`. The old `割り勘 1/2` promised a
            // shape the printer never emits and mis-measured the block's height,
            // which is what a brand designs the rest of the slip around.
            'split_banner' => self::splitBanner($labels, $columns),
            // No emitter owns `batch_total` either; `subtotal` is the catalog
            // entry that matches what the row says.
            'batch_total' => [['label' => $labels->subtotal, 'value' => $cur.'3,150', 'bold' => true]],
            // #2028-unmapped: `emitDebtOwed` prints the ASCII literal "GHI NO"
            // in every locale (the debt slip is Vietnamese by construction).
            //
            // #2036: the word itself was "CON NO" — the comment already named
            // the emitter's literal and the row beside it said something else,
            // which is the same promise-a-different-word bug one level down.
            'debt_summary' => [['label' => 'GHI NO', 'value' => $cur.'2,530', 'bold' => true]],
            'paid_summary' => [['label' => $paid->title, 'value' => '2026/07/20 14:32']],
        ];

        $shiftRows = [
            // One row per shift in the chain, then the operator UNDER it,
            // indented — `emitChainIndex`. The operator is what stops a
            // shortfall defaulting onto whoever happened to close the chain, so
            // it gets its own labelled row rather than being packed into the
            // shift line.
            'chain_summary' => [
                ['text' => '--- '.$shift->chainLabel.' 2'.$shift->chainShiftUnit.' ---'],
                ['label' => trim($shift->chainShift).' 1 ('.$shift->chainHandover.')', 'value' => $cur.'1,500'],
                ['label' => $shift->operator, 'value' => '佐藤', 'indent' => 2],
                ['label' => trim($shift->chainShift).' 2 ('.$shift->chainFinal.')', 'value' => $cur.'2,275'],
                ['label' => $shift->operator, 'value' => '田中', 'indent' => 2],
            ],
            'sales_summary' => [
                ['label' => $shift->grossSales, 'value' => $cur.'3,775'],
                ['label' => $shift->netSales, 'value' => $cur.'3,460'],
                // Item and guest counts print on their OWN right-aligned line
                // with a unit suffix — `emitSalesSummary` does not label them,
                // because they are not money and a label column would read like
                // one.
                ['text' => '5'.$shift->itemUnit, 'align' => SlipComposer::ALIGN_RIGHT],
                ['text' => '4'.$shift->guestUnit, 'align' => SlipComposer::ALIGN_RIGHT],
            ],
            'tax_breakdown' => $money['tax_breakdown'],
            'tender_summary' => [
                ['label' => PaymentMethodLabels::localize($normalized, 'cash', '').' (2)', 'value' => $cur.'2,000'],
                // A branded wallet is a proper noun and stays untranslated in
                // every locale — the same ruling PaymentMethodLabels documents.
                ['label' => 'PayPay (1)', 'value' => $cur.'800'],
            ],
            'non_cash_change' => [['label' => $shift->nonCashChange, 'value' => $cur.'0']],
            'discount_summary' => [['label' => 'WELCOME10 (1)', 'value' => '-'.$cur.'91']],
            'service_charge' => [['label' => $shift->serviceCharge, 'value' => $cur.'330']],
            'acct_correction' => [['label' => $shift->acctCorrection, 'value' => $cur.'0']],
            'check_count' => [['label' => $shift->checkCount, 'value' => '3']],
            'cash_movement' => [
                ['label' => $shift->paidIn.' (1)', 'value' => $cur.'5,000'],
                ['label' => $shift->paidOut.' (1)', 'value' => $cur.'2,000'],
            ],
            'void_summary' => [['label' => $shift->voidBills.' (1)', 'value' => $cur.'500']],
            'variance' => [
                ['label' => $shift->countedCash, 'value' => $cur.'16,000'],
                ['label' => $shift->expectedCash, 'value' => $cur.'16,100'],
                ['label' => $shift->variance, 'value' => '-'.$cur.'91', 'bold' => true],
            ],
            'denomination_table' => [
                ['label' => $cur.'10,000 x 1', 'value' => $cur.'10,000'],
                ['label' => $cur.'1,000 x 6', 'value' => $cur.'6,000'],
            ],
            // The opening float row is the 開始 slip's total line
            // (`emitOpenTotal`), which is why it reads 合計 and not 釣銭準備金.
            'float_count' => [['label' => $open->total, 'value' => $cur.'13,000', 'bold' => true]],
        ];

        // #2036 — phiếu ghi nợ KHÔNG dùng khối tiền của họ bill.
        //
        // `DocsKindPlans::debtSlipPlan()` đăng ký lại `grand_total` sang
        // `emitDebtTotal` và `payments` sang `emitDebtPaid`, và cả hai in ASCII
        // ở MỌI locale — "Tong" / "Da thanh toan" — vì phiếu ghi nợ là chứng từ
        // tiếng Việt theo cấu tạo, đúng như `emitDebtOwed` ("GHI NO") bên dưới.
        // Trước đây map `locked` chỉ rẽ theo kind cho họ shift, nên bản xem
        // trước phiếu nợ mượn nhãn của phiếu bill: 「合計」/「支払済」 ở ja,
        // "Total"/"Paid" ở en.
        //
        // `payments` chỉ MỘT dòng ở đây: `emitDebtPaid` in đúng một dòng số đã
        // trả và không in phương thức thanh toán — một phiếu ghi nợ ghi nhận
        // phần CHƯA trả, phương thức là chuyện của lần trả sau.
        //
        // Ba nhãn này là literal của emitter, không có mục nào trong catalog để
        // đọc. Chúng KHÔNG khai vào `unmappedSampleLabels()` được: danh sách đó
        // khoá theo block id, nên khai `grand_total`/`payments` sẽ miễn trừ
        // luôn cả họ bill (nơi hai block đó PHẢI đến từ catalog) và làm R29 mục
        // ruỗng. Chúng được ghim bằng R36 thay vì bằng miễn trừ.
        $debtRows = [
            'grand_total' => [['label' => 'Tong', 'value' => $cur.'3,530', 'bold' => true]],
            'payments' => [['label' => 'Da thanh toan', 'value' => $cur.'3,530']],
            // #2286 — `emitDebtIssuedAt` in ngày + `#code` cùng một dòng; hàng
            // mốc trần trong `$money` là của họ bill, không phải phiếu ghi nợ.
            'issued_at' => self::debtIssuedAtSample($columns),
        ];

        // #2045 mục 2 — biên bản huỷ dựng dòng số hoá đơn bằng CHUỖI CỦA CHÍNH
        // NÓ, không phải nhãn của họ bill.
        //
        // `DocsKindPlans::voidNoticePlan()` (:128) đăng ký `invoice_number` sang
        // `emitVoidInvoiceNumber` (:688-695), và emitter ấy phát đúng MỘT lệnh:
        // `line('So HD bi huy: '.$invoiceNo)`. ASCII cứng ở MỌI locale — biên
        // bản huỷ là chứng từ Việt theo cấu tạo, đúng như phiếu ghi nợ và hoá
        // đơn GTGT ở trên; không có mục nào trong catalog để đọc.
        //
        // Hai chỗ sai chứ không một, và chỗ thứ hai mới là chỗ đo sai bề rộng:
        // sample cũ mượn hàng HAI CỘT của họ bill (`label` + `value`), nên bản
        // xem trước vẽ 「請求書番号」 căn trái rồi số căn phải cách đó 22 cột.
        // Tờ giấy chỉ có một dòng chữ liền. Vì thế hàng này dùng `text`, không
        // dùng `label`/`value`.
        //
        // Chuỗi này là literal của emitter, KHÔNG khai vào
        // `unmappedSampleLabels()` được: danh sách đó khoá theo block id, nên
        // khai `invoice_number` là miễn trừ luôn cả họ bill và hai kind VAT.
        // Nó được ghim bằng R39, chặt hơn một miễn trừ chứ không lỏng hơn.
        //
        // #2045 (đợt hai) — `void_marker` và `issued_at` cùng bệnh, đo ở
        // comment của issue:
        //
        //   - `emitVoidMarker` in headline ASCII đậm canh giữa
        //     "BIEN BAN HUY HOA DON" ở MỌI locale; sample cũ vẽ 「*** 取消 ***」
        //     trong khi comment ngay cạnh đã ghi đúng chuỗi của emitter — hệt
        //     bệnh "CON NO"/"GHI NO" mà #2036 sửa.
        //   - `emitVoidVoidedAt` in "Thoi diem huy: {ts}"; sample cũ mượn hàng
        //     mốc thời gian TRẦN của họ bill.
        //
        // `footer_text` CỐ Ý chưa sửa: emitter (`emitVoidFooter`) bỏ qua chữ
        // brand soạn và in cứng "KHACH HANG NHAN BIET HOA DON DA HUY" — sửa
        // sample là chọn phe cho câu hỏi "brand có được soạn footer biên bản
        // huỷ không", một quyết định sản phẩm còn treo ở #2045.
        $voidRows = [
            'invoice_number' => [['text' => 'So HD bi huy: HN1-202607-00042']],
            'void_marker' => [['text' => 'BIEN BAN HUY HOA DON', 'align' => SlipComposer::ALIGN_CENTER, 'bold' => true]],
            'issued_at' => [['text' => 'Thoi diem huy: 2026/07/20 14:32']],
        ];

        // #2039 — hoá đơn GTGT cũng KHÔNG dùng khối tiền của họ bill, và nó có
        // HAI tập nhãn chứ không một.
        //
        // `DocsKindPlans::vatPlan()` đăng ký lại `subtotal`/`grand_total`/
        // `payments` sang `emitVatSubtotal` (:497 → "Tam tinh"),
        // `emitVatGrandTotal` (:573 → "Tong cong") và `emitVatPaymentMethod`
        // (:623 → "Hinh thuc TT"). Cả ba in ASCII ở MỌI locale — hoá đơn GTGT
        // là chứng từ Việt Nam theo cấu tạo, đúng như phiếu ghi nợ ở trên.
        //
        // `payments` chỉ MỘT dòng: `emitVatPaymentMethod` in phương thức thanh
        // toán và KHÔNG in số đã trả (hoá đơn nêu tổng phải trả, không phải
        // nhật ký thu tiền), nên dòng `paidAmount` của họ bill là một dòng tờ
        // giấy không có.
        //
        // `registration_number` RỖNG chứ không phải thiếu: `emitVatRegistration
        // Number` (:598) là một thân RỖNG có chủ ý từ #1224 — mã số thuế người
        // bán in trong khối NGƯỜI BÁN (`emitVatParties`), và phát ở đây nữa là
        // in cùng con số hai lần. Sample cấp một dòng 登録番号 cho block đó là
        // hứa một dòng KHÔNG máy in nào vẽ.
        //
        // `tax_breakdown` CỐ Ý không nằm đây: nó dùng chung dòng rút gọn của
        // `$money`, vì bản trung thực không vừa 32 cột ở bất kỳ locale nào
        // (#2035 — phép đo ở comment của chính dòng ấy).
        $vatRows = [
            'invoice_number' => self::vatInvoiceNumber('So HD: HN1-202607-00042', '2026/07/20 14:32', $columns),
            'subtotal' => [['label' => 'Tam tinh', 'value' => $cur.'3,300']],
            'grand_total' => [['label' => 'Tong cong', 'value' => $cur.'3,530', 'bold' => true]],
            'registration_number' => [],
            'payments' => [['label' => 'Hinh thuc TT', 'value' => 'cash']],
        ];

        // #2039 — 適格簡易請求書 đi nhánh `japaneseDoc` → {@see VatInvoiceJa},
        // một TẬP NHÃN KHÁC HẲN, không phải bản dịch của khối trên.
        //
        // Và nó là tiếng Nhật ở MỌI locale: cờ `japaneseDoc` là thuộc tính của
        // KIND, không suy từ locale (#1493) — quán Việt để giao diện tiếng Nhật
        // vẫn không được in ra chứng từ Nhật, và ngược lại một 適格簡易請求書 in
        // ở máy đặt locale `vi` vẫn là tờ giấy Nhật. Đây là lý do R29 (cấm nhãn
        // tiếng Nhật trong sample) miễn trừ RIÊNG kind này, và R38 ghim từng
        // chuỗi một để miễn trừ ấy không rỗng ruột.
        //
        // `subtotal` mang HAI dòng: {@see VatInvoiceJa::subtotal} in 小計 rồi
        // một dòng điều chỉnh RÒNG (サービス料 khi dương, 値引き khi âm) — hai
        // block `discounts`/`service_charge` của họ bill không có trong định
        // nghĩa kind này, nên phí phục vụ chỉ xuất hiện ở đây. Số mẫu giữ đúng
        // quan hệ cộng: 3,300 + 230 = 3,530.
        //
        // `payments` là một dòng CHỮ 「 お支払: cash」, không phải hàng hai cột.
        $vatJaRows = [
            'invoice_number' => self::vatInvoiceNumber('No.HN1-202607-00042', '2026/07/20 14:32', $columns),
            'subtotal' => [
                ['label' => ' 小計', 'value' => $cur.'3,300'],
                ['label' => ' サービス料', 'value' => $cur.'230'],
            ],
            'grand_total' => [['label' => ' 合計', 'value' => $cur.'3,530', 'bold' => true]],
            'registration_number' => [],
            'payments' => [['text' => ' お支払: cash']],
        ];

        $locked = match (true) {
            in_array($kindValue, ['shift_open', 'shift_report', 'chain_report'], true) => $shiftRows + $money,
            $kindValue === PrintTemplateKind::DebtSlip->value => $debtRows + $money,
            $kindValue === PrintTemplateKind::VoidNotice->value => $voidRows + $money,
            $kindValue === PrintTemplateKind::QualifiedSimplifiedInvoice->value => $vatJaRows + $money,
            $kindValue === PrintTemplateKind::VatInvoice->value => $vatRows + $money,
            default => $money,
        };

        return [
            'params' => $params,
            'items' => $items,
            'locked' => self::withoutUnrenderableBlocks($kindValue, $locked),
            'columns' => $columns,
        ];
    }

    /**
     * Bỏ khỏi bản xem trước những khối mà KHÔNG renderer nào vẽ (#2045 mục 1).
     *
     * `print_blocks.renderable_debt` là danh sách khối CÓ trong catalog mà chưa
     * có emitter (#1949). Nó không phải một danh sách chép tay thứ hai:
     * `CatalogRenderableRatchetTest` cưỡng chế nó theo CẢ HAI chiều — thêm một
     * khối vào catalog mà quên emitter thì đỏ, viết emitter mà quên hạ danh
     * sách cũng đỏ. Nên đọc nó ở đây là đọc đúng câu trả lời cho "khối này có
     * ra giấy không".
     *
     * Hai ca của #2045: `receipt.discounts` và `receipt.invoice_number` là block
     * `locked`, mặc định BẬT, chưa từng có emitter trong `BillKindPlans::BLOCKS`
     * — nhưng sample vẫn cấp dòng cho cả hai. Brand thiết kế mẫu nhìn thấy hai
     * dòng **sẽ không bao giờ ra giấy**, và đo bề rộng cột theo chúng. Nặng hơn
     * hẳn một nhãn sai chữ: đây là dòng không tồn tại.
     *
     * Lọc theo danh sách nợ chứ không xoá cứng, vì nợ là trạng thái tạm: ngày
     * ai đó viết emitter, ratchet buộc hạ danh sách và dòng tự trở lại bản xem
     * trước — không ai phải nhớ chỗ này.
     *
     * `'__NO_PLAN__'` (chuỗi, không phải mảng) là nợ ở tầng khác — kind chưa có
     * plan nào bên PHP — nên không có phép đo per-block nào để áp; bỏ qua.
     *
     * @param  array<string, mixed>  $locked
     * @return array<string, mixed>
     */
    private static function withoutUnrenderableBlocks(string $kind, array $locked): array
    {
        $owed = config('print_blocks.renderable_debt.'.$kind, []);

        if (! is_array($owed)) {
            return $locked;
        }

        foreach ($owed as $block) {
            unset($locked[(string) $block]);
        }

        return $locked;
    }

    /**
     * Dòng số hoá đơn đúng như {@see DocsKindPlans::emitVatInvoiceNumber} và
     * {@see VatInvoiceJa::invoiceNumber} vẽ nó: số căn trái, mốc phát hành căn
     * phải, và **xuống hai dòng khi không đủ chỗ** thay vì ép một dòng tràn.
     *
     * Cái xuống dòng ấy là lý do helper này tồn tại: `So HD: …` (23 cột) cộng
     * mốc `2026/07/20 14:32` (16) cộng khe tối thiểu là 40 — vừa 48, KHÔNG vừa
     * 32. Sample cũ cấp nhãn tự chế 「請求書番号」 nên vừa mọi khổ và giấu mất cả
     * hai chuyện: chữ sai VÀ hình dạng sai.
     *
     * @return list<array{label?: string, value?: string, text?: string, align?: string}>
     */
    private static function vatInvoiceNumber(string $noLine, string $issuedAt, int $columns): array
    {
        if (Layout::displayWidth($noLine) + 1 + Layout::displayWidth($issuedAt) <= $columns) {
            return [['label' => $noLine, 'value' => $issuedAt]];
        }

        return [
            ['text' => $noLine],
            ['text' => $issuedAt, 'align' => SlipComposer::ALIGN_RIGHT],
        ];
    }

    /**
     * Một dòng đúng như {@see DocsKindPlans::emitDebtIssuedAt}: ngày căn trái,
     * `#` + hậu tố mã đơn căn phải — không phải mốc thời gian trần của họ bill.
     *
     * @return list<array{text: string}>
     */
    private static function debtIssuedAtSample(int $columns, string $issuedAt = '2026/07/20 14:32', string $codeSuffix = '004'): array
    {
        $code = '#'.$codeSuffix;
        $dateWidth = max($columns - Layout::runeLength($code), 1);

        return [['text' => Layout::padRight($issuedAt, $dateWidth).$code]];
    }

    /**
     * The split banner exactly as {@see BillKindPlans::emitSplitBanner} draws
     * it: `=` bar, bold centred title + " (idx/count)", centred mode line,
     * `=` bar. The sample split is an EQUAL split of two, so the mode line is
     * the one `splitModeText('even')` returns.
     *
     * @return list<array{text: string, bold?: bool, align?: string}>
     */
    private static function splitBanner(PrintLabels $labels, int $columns): array
    {
        $bar = str_repeat('=', max($columns, 1));

        return [
            ['text' => $bar],
            [
                'text' => $labels->splitTitle.' (1/2)',
                'bold' => true,
                'align' => SlipComposer::ALIGN_CENTER,
            ],
            [
                'text' => $labels->splitModeText('even').' - 2 '.$labels->splitPeople,
                'align' => SlipComposer::ALIGN_CENTER,
            ],
            ['text' => $bar],
        ];
    }
}
