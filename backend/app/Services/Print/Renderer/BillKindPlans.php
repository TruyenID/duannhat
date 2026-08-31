<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use Illuminate\Support\Facades\Log;

/**
 * plan-053 T5.1d slice 1a (#1908) — họ **bill** bên PHP.
 *
 * Đối ứng của `billPlan()` trong `workstation/internal/service/print_renderer_bill.go`.
 * Năm kind dùng CHUNG một plan: `receipt` · `runner` · `delta_qr` · `remaining`
 * · `red_invoice`. `kitchen` có plan riêng và **không** thuộc đây.
 *
 * ## Slice này đăng ký KIND, chưa port thân emitter
 *
 * Cổng `PrintContractParityTest` so **tập block id**, `default_width` và
 * `japanese_doc` với Go. Đó là thứ quyết định một định nghĩa template do brand
 * publish có hợp lệ ở cả hai phía hay không — và nó phải khớp TRƯỚC khi có ai
 * dựa vào nó, vì một block id chỉ tồn tại ở một phía sẽ render ở máy trạm và
 * biến mất ở Cloud preview, hoặc ngược lại.
 *
 * Thân emitter (23 hàm, 530 dòng Go) là slice 1b/1c. Đăng ký trước cho phép
 * cổng parity cắn ngay, thay vì chờ toàn bộ port xong mới biết tập block lệch.
 *
 * ## Ba cạm bẫy đã đo bên Go, chép sang đây để không đạp lại
 *
 * **23 emitter nhưng chỉ ~20 hành vi.** `store_info` và `title` cùng trỏ
 * `emitBillHeader`; `header_text`/`footer_text`/`greeting` cùng trỏ
 * `emitAuthoredText`. Port thành 23 hàm riêng là nhân bản ba lần một hành vi.
 *
 * **`headerDrawn` là trạng thái CHIA SẺ.** Hai block (`store_info`, `title`)
 * cùng vẽ một dòng "tên quán … TIÊU ĐỀ"; cái nào đứng trước trong definition
 * thì vẽ, cái kia thành no-op. Bỏ cờ này thì brand bật cả hai sẽ in **hai
 * dòng**. Ô đã có sẵn ở `PrintRenderContext::$headerDrawn`.
 *
 * **`reprint_marker` MƯỢN emitter của họ docs** — slice 1 và slice 2 dùng chung
 * một hàm. Chủ sở hữu là **họ docs** (slice 2); ở đây chỉ khai block id, không
 * cài thân, để hai bản port không đánh nhau ở đúng cái dấu 「再印刷 #N」 mà #1890
 * vừa sửa.
 */
final class BillKindPlans
{
    /**
     * Sáu kind dùng chung plan này. Thứ tự không quan trọng; tập hợp thì có.
     *
     * `kitchen` nằm TRONG danh sách, không đứng cạnh: phiếu bếp và phiếu hall
     * render qua CÙNG một plan, khác nhau đúng ở QR. Thứ còn khác là DỮ LIỆU
     * (một lượt bắn món, không phải cả đơn) và dữ liệu là việc của người gọi.
     */
    public const KINDS = ['receipt', 'runner', 'delta_qr', 'remaining', 'red_invoice', 'kitchen'];

    /**
     * Số dòng trắng mở đầu mỗi phiếu. Đối ứng `slipTopPadding` bên Go — lệch số
     * này là lệch byte, và `SlipByteParityTest` so nguyên phiếu.
     */
    private const TOP_PADDING = 3;

    /**
     * Cỡ ô QR tính bằng dot — đối ứng `qrCellSize` bên Go. Giảm nửa từ 7 theo
     * yêu cầu của quán; 4 chứ không phải 3.5 vì lệnh chỉ nhận số dot nguyên, và
     * làm tròn XUỐNG sẽ đẩy mã 42 ký tự xuống dưới ngưỡng ~4 dot mà camera điện
     * thoại cần trên giấy nhiệt — hỏng kiểu "quét không ra", không phải kiểu
     * thấy được ở đây.
     */
    private const QR_CELL_SIZE = 4;

    /**
     * Tập block id, chép NGUYÊN THỨ TỰ từ `billPlan()` bên Go.
     *
     * Thứ tự trong map không lái thứ tự in (definition quyết định thứ tự đó),
     * nhưng giữ nguyên để hai file đọc đối chiếu được bằng mắt — thứ mà cổng
     * parity không làm thay được khi ai đó thêm block mới.
     *
     * @var list<string>
     */
    public const BLOCKS = [
        'logo',
        'store_info',
        'title',
        'issued_at',
        'split_banner',
        'order_meta',
        'customer_header',
        'order_note',
        'column_header',
        'items',
        'subtotal',
        // #2071 — chỉ `receipt` khai block này trong `config/print_blocks.php`,
        // nhưng emitter sống ở plan DÙNG CHUNG của họ bill, nên nó phải có tên
        // ở đây và trong `billPlan()` bên Go, hoặc `PrintContractParityTest` đỏ.
        'discounts',
        'service_charge',
        'grand_total',
        'tax_breakdown',
        'tax_legend',
        'registration_number',
        'payments',
        'change_due',
        'remaining',
        'reprint_marker',
        'qr_block',
        'header_text',
        'footer_text',
        'greeting',
    ];

    public static function register(PrintKindRegistry $registry): void
    {
        foreach (self::KINDS as $kind) {
            $registry->register($kind, self::plan());
        }
    }

    private static function plan(): PrintKindPlan
    {
        // #1937 khép lại họ bill: 18/18 hành vi đã có thân.
        //
        // Bốn block id còn no-op ở đây và KHÔNG phải việc bỏ dở:
        // `reprint_marker` thuộc sở hữu của họ docs (xem doc của class), còn
        // `header_text` · `footer_text` · `greeting` cùng trỏ `emitAuthoredText`
        // bên Go — một hàm DÙNG CHUNG ba họ, đã có bản PHP ({@see AuthoredText})
        // và được họ docs nối. Nối chúng ở đây là slice riêng, không phải #1937.
        $ported = [
            'logo' => LogoBlock::emit(...),
            'issued_at' => self::emitIssuedAt(...),
            'subtotal' => self::emitSubtotal(...),
            'discounts' => self::emitDiscounts(...),
            'service_charge' => self::emitServiceCharge(...),
            'payments' => self::emitPayments(...),
            'change_due' => self::emitChangeDue(...),
            'remaining' => self::emitRemaining(...),
            'order_note' => self::emitOrderNote(...),
            'tax_legend' => self::emitTaxLegend(...),
            'registration_number' => self::emitRegistrationNumber(...),
            'store_info' => self::emitHeader(...),
            'title' => self::emitHeader(...),
            'split_banner' => self::emitSplitBanner(...),
            'customer_header' => self::emitCustomerHeader(...),
            'column_header' => self::emitColumnHeader(...),
            'order_meta' => self::emitOrderMeta(...),
            'items' => self::emitItems(...),
            'grand_total' => self::emitGrandTotal(...),
            'tax_breakdown' => self::emitTaxBreakdown(...),
            'qr_block' => self::emitQrBlock(...),
            'reprint_marker' => self::emitReprintMarker(...),
        ];

        $emitters = [];
        foreach (self::BLOCKS as $block) {
            if (isset($ported[$block])) {
                $emitters[$block] = $ported[$block];

                continue;
            }

            // Thân là slice 1b/1c. No-op ở đây giữ đúng TẬP block cho cổng parity;
            // phiếu thật trên workstation đi qua seam + renderer (#1945 layer 0).
            $emitters[$block] = static function (PrintRenderContext $ctx, array $block): void {};
        }

        return new PrintKindPlan(
            // 48 — chép từ Go. KHÔNG suy từ khổ giấy: `defaultWidth` là bề rộng
            // NỘI DUNG khi người gọi không chỉ định, và nó là một hằng của kind.
            defaultWidth: 48,
            emitters: $emitters,
            prologue: static function (PrintRenderContext $ctx): void {
                // Ba dòng này là BYTE trên dây, không phải khởi tạo cho có.
                // `align(ALIGN_LEFT)` phát `1B 1D 61 00` (StarPRNT `ESC GS a`,
                // KHÔNG phải `ESC a` của Epson) và đó đúng là 4 byte mà PHP
                // từng thiếu ngay sau `ESC @` — cả 45 ô của họ bill lệch từ
                // offset 3 chỉ vì nó. Máy in mặc định canh trái nên bỏ đi
                // "trông vẫn đúng" trên giấy, và đó là lý do nó sống sót lâu:
                // sai sót duy nhất thấy được là ở tầng byte.
                $ctx->encoder->setLeftMargin($ctx->config->leftMargin($ctx->width));
                $ctx->encoder->align(Escpos::ALIGN_LEFT);
                // Lề TRÊN: phiếu bị kẹp vào kẹp giấy đúng ở MÉP ĐẦU, ngay chỗ
                // 伝票 và 卓 nằm. Phóng to hai ô đó vô nghĩa nếu cái kẹp đang
                // đè lên chúng. Đối ứng `slipTopPadding` bên Go.
                $ctx->encoder->feed(self::TOP_PADDING);
                self::prepareBillTax($ctx);
            },
            epilogue: static function (PrintRenderContext $ctx): void {
                // Nhánh này KHÔNG phải tối ưu: khối QR tự để lại hai dòng trắng
                // phía sau nó. Không có QR thì phiếu vẫn cần đúng lề đuôi đó
                // trước khi cắt — bỏ nhánh là **phiếu bị cắt sát chữ**, và đó
                // là nhánh `else` của `formatBillTicket` cũ, không phải một
                // lựa chọn thẩm mỹ mới.
                if (! self::hasBlock($ctx, 'qr_block')) {
                    $ctx->encoder->feed(2);
                }

                $ctx->finish();
            },
            // Họ bill KHÔNG phải chứng từ Nhật (適格簡易請求書) — cờ đó thuộc họ
            // docs. #1493 làm nó thành thuộc tính của KIND, không phải của
            // locale, nên một quán JP in `receipt` vẫn không bật cờ này.
            japaneseDoc: false,
        );
    }

    /**
     * `issued_at` — thời điểm phát hành, lấy từ NGƯỜI GỌI.
     *
     * `$ctx->data->now` do người gọi cấp và renderer KHÔNG được lấp bằng đồng
     * hồ máy: đó đúng là cách một phiếu Tokyo bị đóng dấu theo giờ máy chủ UTC
     * (#1091). Không có `now` thì không in dòng này — thà thiếu một dòng còn
     * hơn in một mốc thời gian sai lên chứng từ.
     */
    private static function emitIssuedAt(PrintRenderContext $ctx, array $block): void
    {
        $now = $ctx->data->now;

        if ($now === null) {
            return;
        }

        // Ngày giao dịch ĐỨNG MỘT MÌNH. Mã đơn từng nằm cuối chính dòng này;
        // chủ dự án chốt 2026-08-17 bỏ nó trên MỌI kind và MỌI trạng thái —
        // `order_meta` đã in 伝票番号 ở ×2 vài dòng bên dưới, và một sự việc nói
        // hai lần trên cùng tờ giấy là một lần thừa.
        //
        // Đối ứng `emitBillIssuedAt` bên Go, nơi `printIssuedAtRow` nay nhận
        // chuỗi rỗng thay cho hậu tố mã đơn.
        $ctx->encoder->line($now->format('Y/m/d H:i'));
    }

    /**
     * Nhắc lại số bàn trong khối tiền, giữa thuế và số dư — đúng chỗ tờ phiếu
     * của quán đặt nó.
     *
     * Là NHẮC LẠI, không phải nguồn thứ hai: người chạy bàn đọc chân phiếu để
     * biết đồ đi bàn nào thì không phải đưa mắt ngược lên đầu tờ. Đơn mang đi
     * không có bàn nên không in gì.
     */
    private static function emitMoneyTableRow(PrintRenderContext $ctx): void
    {
        $order = $ctx->data->order;

        if ($order === null || $order->orderType === 'takeaway') {
            return;
        }

        $ctx->row($ctx->labels->table, $order->tableNumber !== '' ? $order->tableNumber : '-');
    }

    /**
     * `subtotal` — bỏ qua khi đang in phiếu con của một lần chia bill.
     *
     * `suppressOrderRows` được tính MỘT lần ở prologue. Kiểm lại điều kiện ở
     * đây thay vì đọc cờ là cách dòng tạm tính và khối thuế nói khác nhau trên
     * cùng tờ giấy.
     */
    private static function emitSubtotal(PrintRenderContext $ctx, array $block): void
    {
        $order = $ctx->data->order;

        // `<= 0` chứ không phải `=== 0`: một tạm tính âm là dữ liệu hỏng, và in
        // nó ra dưới dạng "-¥500" trông như một khoản giảm giá hợp lệ.
        if ($ctx->suppressOrderRows || $order === null || $order->subtotal <= 0) {
            return;
        }

        $ctx->row($ctx->labels->subtotal, $ctx->money($order->subtotal));
    }

    /**
     * `discounts` — MỖI DÒNG SỔ một dòng giấy (#2071), đối ứng `emitBillDiscounts`.
     *
     * Số đến từ các dòng `order_conditions` (type='discount') mà engine đã ghi
     * MỘT DÒNG CHO MỖI NHÓM MỨC (#2031) — tầng in không cộng, không phân bổ
     * lại, và KHÔNG có fallback về một cột tổng: `discount_amount` là số YÊU
     * CẦU, sổ là số ĐÃ ÁP DỤNG, khi hai bên khác nhau thì sổ nói về tiền.
     *
     *     Giam gia (8%)                     -¥9
     *     Giam gia (10%)                   -¥91
     *
     * Chữ đầu dòng là của CATALOG ({@see PrintLabels::$discount}), không phải
     * `label` đóng băng của dòng sổ — chuỗi đó không theo locale và không chắc
     * mã hoá được Shift_JIS. Suffix mức đi theo mọi dòng có nhóm mức; dòng
     * không nhóm (đơn không có dòng chịu thuế) in nhãn trần. Ẩn trên phiếu con
     * chia bill / phiếu delta cùng luật với `subtotal`: tờ đó hiển thị một
     * phần tiền mà các dòng sổ mức-đơn không mô tả.
     *
     * Dấu tiền in NGUYÊN VĂN theo sổ (âm cho khoản trừ); dấu xử lý ngoài
     * `money()` vì `Layout::formatPrice` chỉ nhóm chữ số.
     */
    private static function emitDiscounts(PrintRenderContext $ctx, array $block): void
    {
        $order = $ctx->data->order;

        if ($ctx->suppressOrderRows || $order === null) {
            return;
        }

        foreach ($order->discounts as $d) {
            $label = $d->rate === null
                ? $ctx->labels->discount
                : sprintf('%s (%s%%)', $ctx->labels->discount, TaxLabels::formatRatePercent($d->rate));

            $value = $d->amount < 0
                ? '-'.$ctx->money(-$d->amount)
                : $ctx->money($d->amount);

            $ctx->row($label, $value);
        }
    }

    /** `service_charge` — cùng ba điều kiện như `subtotal`. */
    private static function emitServiceCharge(PrintRenderContext $ctx, array $block): void
    {
        $order = $ctx->data->order;

        if ($ctx->suppressOrderRows || $order === null || $order->serviceCharge <= 0) {
            return;
        }

        $ctx->row($ctx->labels->serviceCharge, $ctx->money($order->serviceCharge));
    }

    /**
     * `payments` — số đã trả trên phiếu này + phương thức.
     *
     * Số đến từ `data->paidShown`, KHÔNG từ `slip->amountPaid`: người gọi quyết
     * con số hiển thị, và hai đường đó khác nhau ở phiếu chia bill. Đọc nhầm ô
     * làm phiếu của một người in ra số tiền của cả đơn.
     */
    private static function emitPayments(PrintRenderContext $ctx, array $block): void
    {
        $slip = $ctx->data->slip;

        if ($slip === null) {
            return;
        }

        $ctx->encoder->bold(true);
        $ctx->row($ctx->labels->paidAmount, $ctx->money($ctx->data->paidShown));
        $ctx->encoder->bold(false);

        $method = trim($slip->paymentMethod);

        // In NGUYÊN VĂN, không dịch: chuỗi này đến từ cấu hình phương thức thanh
        // toán của quán, và dịch nó ở đây sẽ làm phiếu khác với báo cáo ca.
        if ($method !== '') {
            $ctx->row($ctx->labels->paymentMethod, $method);
        }
    }

    /**
     * `change_due` — khách đưa / tiền thối.
     *
     * Điều kiện là `tendered <= 0`, KHÔNG phải `change > 0`: trả đúng số thì
     * tiền thối bằng 0 nhưng dòng "khách đưa" vẫn phải in — nó là bằng chứng
     * khách đã đưa bao nhiêu, và thiếu nó thì một tranh cãi ở quầy không có gì
     * để đối chiếu.
     */
    private static function emitChangeDue(PrintRenderContext $ctx, array $block): void
    {
        $slip = $ctx->data->slip;

        if ($slip === null || $slip->tendered <= 0) {
            return;
        }

        $ctx->row($ctx->labels->tendered, $ctx->money($slip->tendered));
        $ctx->row($ctx->labels->change, $ctx->money($slip->change));
    }

    /**
     * `remaining` — phần còn phải trả, đọc từ `data->remaining`.
     *
     * KHÔNG đọc `slip->remaining`: hai ô này khác nhau có chủ đích — một là số
     * người gọi quyết cho tờ giấy, một là số của phiếu con.
     */
    private static function emitRemaining(PrintRenderContext $ctx, array $block): void
    {
        if ($ctx->data->remaining <= 0) {
            return;
        }

        self::emitMoneyTableRow($ctx);
        $ctx->encoder->bold(true);
        $ctx->row($ctx->labels->remaining, $ctx->money($ctx->data->remaining));
        $ctx->encoder->bold(false);
    }

    /**
     * `order_note` — ghi chú toàn đơn, xuống dòng theo bề rộng giấy.
     *
     * Ghi chú là chữ do NGƯỜI dùng gõ, nên nó là chỗ duy nhất trên phiếu có độ
     * dài không giới hạn. Không ngắt dòng thì nó tràn và đẩy vỡ mọi thứ bên
     * dưới — chứ không phải chỉ xấu một dòng.
     */
    private static function emitOrderNote(PrintRenderContext $ctx, array $block): void
    {
        $order = $ctx->data->order;

        if ($order === null) {
            return;
        }

        $note = trim($order->note);

        if ($note === '') {
            return;
        }

        foreach (Layout::wrapText($note, $ctx->width - Layout::displayWidth($ctx->labels->notePrefix)) as $i => $line) {
            // Tiền tố chỉ ở dòng ĐẦU; các dòng sau thụt vào đúng bề rộng tiền
            // tố để khối chữ thẳng lề — giống `printNoteLines` bên Go.
            $ctx->encoder->line($i === 0
                ? $ctx->labels->notePrefix.$line
                : Layout::spaces(Layout::displayWidth($ctx->labels->notePrefix)).$line);
        }
    }

    /**
     * `tax_legend` — dòng chú thích dấu ※ cho thuế suất giảm.
     *
     * Hai điều kiện, và cả hai đều cần: KHÔNG in trên phiếu con của lần chia
     * bill (`showTaxBreakdown`), và KHÔNG in khi đơn không có dòng nào chịu
     * thuế giảm (`hasReduced`). Một chú thích cho ký hiệu không xuất hiện ở đâu
     * còn tệ hơn không có chú thích — nó làm người đọc đi tìm dấu ※ không tồn
     * tại.
     */
    private static function emitTaxLegend(PrintRenderContext $ctx, array $block): void
    {
        if (! $ctx->showTaxBreakdown || ($ctx->taxSummary['has_reduced'] ?? false) !== true) {
            return;
        }

        $ctx->encoder->line($ctx->tax->reducedLegend);
    }

    /**
     * `registration_number` — 登録番号 của người bán (適格請求書, T+13).
     *
     * KHÔNG in trên phiếu con chia bill, và không in khi quán chưa đăng ký.
     * Quán 免税事業者 không có số này là HỢP PHÁP — nên vắng mặt là im lặng, cố
     * ý, và không kèm cảnh báo nào (ruling #1152).
     */
    private static function emitRegistrationNumber(PrintRenderContext $ctx, array $block): void
    {
        // #2064 — KHÔNG gate theo `showTaxBreakdown`: 登録番号 là DANH TÍNH người
        // bán (trường ① của 適格簡易請求書), không phụ thuộc phiếu là con hay
        // nguyên đơn. Cờ cũ đi kèm việc ẩn khối thuế theo mức (Q13) một cách
        // tình cờ và làm rơi một trường bắt buộc khỏi mọi phiếu con chia bill —
        // tờ giấy mà chính khách đó cầm về. Khối theo mức (④⑤) và dấu ※ (③)
        // vẫn ẩn trên phiếu con cho tới khi phần chia mang snapshot phân bổ
        // theo mức — bất biến làm tròn chặn chúng ghi ở #2064.
        $reg = trim($ctx->config->sellerRegistrationNumber);

        if ($reg === '') {
            return;
        }

        $ctx->encoder->line($ctx->tax->registrationNumber.': '.$reg);
    }

    /**
     * `store_info` và `title` — MỘT dòng, hai block id cùng trỏ vào đây.
     *
     * Đây là chỗ `headerDrawn` tồn tại để giữ. Hai block cùng vẽ một dòng
     * "tên quán … TIÊU ĐỀ"; cái nào đứng trước trong definition thì vẽ, cái kia
     * thành no-op. **Bỏ cờ thì brand bật cả hai sẽ in hai dòng** — và nó chỉ lộ
     * ra ở quán nào bật cả hai, tức không lộ ra trong test mặc định.
     *
     * Nội dung phụ thuộc block NÀO có mặt: `store_info` cấp tên quán,
     * `title` cấp tiêu đề. Một phiếu chỉ bật `title` vẫn phải in tiêu đề căn
     * phải, không phải in tên quán rỗng.
     */
    private static function emitHeader(PrintRenderContext $ctx, array $block): void
    {
        if ($ctx->headerDrawn) {
            return;
        }

        $ctx->headerDrawn = true;

        StoreInfoBlock::emitAbove($ctx);

        $storeName = '';
        if (self::hasBlock($ctx, 'store_info')) {
            $storeName = $ctx->config->storeName;

            // "Store" là bản dự phòng của Go, không phải chỗ để bỏ trống: một
            // phiếu không tên quán trông như phiếu của máy chưa cấu hình.
            if ($storeName === '') {
                $storeName = 'Store';
            }
        }

        $title = self::billTitle($ctx);

        $ctx->encoder->bold(true);

        $titleW = Layout::displayWidth($title);
        $storeW = Layout::displayWidth($storeName);

        if ($storeName !== '' && $title !== '') {
            // Vừa một dòng thì căng ra hai đầu; không vừa thì XUỐNG DÒNG chứ
            // không thu nhỏ — tên quán bị cắt còn tệ hơn phiếu dài thêm một dòng.
            if ($storeW + 1 + $titleW <= $ctx->width) {
                $ctx->encoder->line($storeName.Layout::spaces($ctx->width - $storeW - $titleW).$title);
            } else {
                $ctx->encoder->line($storeName);
                $ctx->encoder->line(Layout::spaces($ctx->width - $titleW).$title);
            }
        } elseif ($storeName !== '') {
            $ctx->encoder->line($storeName);
        } elseif ($title !== '') {
            $ctx->encoder->line(Layout::spaces($ctx->width - $titleW).$title);
        }

        $ctx->encoder->bold(false);

        StoreInfoBlock::emitBelow($ctx);
    }

    /**
     * Tiêu đề phiếu — `delta_qr` là kind DUY NHẤT có tiêu đề phụ thuộc dữ liệu.
     *
     * Đơn mang đi không có vòng chạy bàn, nên phiếu đó xác định một lượt LẤY
     * HÀNG chứ không phải "món vừa thêm". Nhánh này nằm ở renderer vì definition
     * không rẽ được theo dữ liệu (nguyên tắc #1) — và nó khớp
     * `FormatDeltaQRTicket`.
     */
    private static function billTitle(PrintRenderContext $ctx): string
    {
        $block = self::blockById($ctx, 'title');

        if ($block === null || ($block['enabled'] ?? true) === false) {
            return '';
        }

        $order = $ctx->data->order;

        if ($ctx->data->kind === 'delta_qr' && $order !== null && $order->orderType === 'takeaway') {
            return mb_strtoupper($ctx->labels->takeaway);
        }

        return Definition::resolveText($block, $ctx->locale, $ctx->width < 42);
    }

    /**
     * `reprint_marker` — dấu 「再印刷 #N」 ở chân phiếu tiền.
     *
     * Họ **docs** sở hữu thân dấu ({@see ReprintMarker}); họ bill MƯỢN lại, đúng
     * như Go trỏ cả hai họ vào chung `emitDocReprintMarker`. Doc của class này
     * từng nói block đó "thuộc sở hữu của họ docs" và để no-op ở đây — đọc đúng
     * về quyền sở hữu, nhưng kết luận sai về việc phải nối: sở hữu nói ai giữ
     * chuỗi chữ, không nói phiếu nào được in nó ra.
     *
     * Cái giá của việc để trống: `receipt` và `red_invoice` — hai trong bốn
     * chứng từ tiền mà #1166 bắt buộc phải mang dấu — in bản sao thứ hai KHÔNG
     * có dấu, tức trên tay khách là hai tờ giấy không phân biệt được. Không có
     * gì đỏ, vì một block no-op vẫn đúng "tập block id" mà cổng contract so.
     *
     * Locale lấy từ CẤU HÌNH quán, không phải locale của lượt render — chép Go.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitReprintMarker(PrintRenderContext $ctx, array $block): void
    {
        ReprintMarker::emit($ctx->encoder, $ctx->width, $ctx->reprintNumber(), $ctx->data->config->locale);
    }

    /** @return array<string, mixed>|null */
    private static function blockById(PrintRenderContext $ctx, string $id): ?array
    {
        foreach ($ctx->definition['blocks'] ?? [] as $block) {
            if (is_array($block) && ($block['id'] ?? null) === $id) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Đối ứng `def.has(id)` bên Go — **CÓ và ĐANG BẬT**, không chỉ "có".
     *
     * Phân biệt này là byte thật: `receipt`/`red_invoice` khai `qr_block` với
     * `enabled: false`, nên phép hỏi chỉ-tồn-tại nuốt mất `feed(2)` ở epilogue
     * và cắt sát chữ.
     */
    private static function hasBlock(PrintRenderContext $ctx, string $id): bool
    {
        $block = self::blockById($ctx, $id);

        return $block !== null && Definition::blockIsEnabled($block);
    }

    /**
     * `split_banner` — băng "CHIA BILL" đóng khung đầu phiếu con.
     *
     * Chỉ in khi ĐÚNG là phiếu chia (`splitModeKind() !== ''`). Băng này là thứ
     * duy nhất nói cho khách biết tờ giấy họ cầm chỉ là một phần — thiếu nó,
     * một phiếu chia trông y hệt phiếu đầy đủ với số tiền nhỏ hơn.
     */
    private static function emitSplitBanner(PrintRenderContext $ctx, array $block): void
    {
        $slip = $ctx->data->slip;

        if ($slip === null || $slip->splitModeKind() === '') {
            return;
        }

        $bar = str_repeat('=', $ctx->width);
        $e = $ctx->encoder;

        $e->feed(1);
        $e->line($bar);

        $e->bold(true);
        $e->line(self::center($ctx, $ctx->labels->splitTitle.self::splitIdxSuffix($slip)));
        $e->bold(false);

        $mode = $ctx->labels->splitModeText($slip->splitModeKind());

        if ($slip->splitCount > 1) {
            $mode = sprintf('%s - %d %s', $mode, $slip->splitCount, $ctx->labels->splitPeople);
        }

        $e->line(self::center($ctx, $mode));

        $label = trim($slip->label);

        if ($label !== '') {
            $e->line(self::center($ctx, $label));
        }

        $e->line($bar);
    }

    /**
     * `customer_header` — tên khách, và trên hoá đơn đỏ là một CHỖ TRỐNG để ghi tay.
     *
     * 18 gạch dưới khi `red_invoice` không có tên: hoá đơn đỏ là chứng từ pháp
     * lý, và một tờ không có chỗ điền tên người mua thì không dùng được — khác
     * hẳn biên lai thường, nơi thiếu tên chỉ là thiếu thông tin.
     *
     * Khối "khách số mấy" chỉ in khi KHÔNG phải phiếu chia: phiếu chia đã có
     * băng ở trên nói điều đó rồi, in lại là hai chỗ nói cùng một việc và chúng
     * sẽ lệch nhau khi ai đó sửa một chỗ.
     */
    private static function emitCustomerHeader(PrintRenderContext $ctx, array $block): void
    {
        $data = $ctx->data;

        if ($data->order === null) {
            return;
        }

        $slip = $data->slip;

        if ($slip !== null) {
            $name = trim($slip->customerName);

            if ($name !== '') {
                $ctx->encoder->line($ctx->labels->customerLabel.': '.$name);
            } elseif ($data->kind === 'red_invoice') {
                $ctx->encoder->line($ctx->labels->customerLabel.': '.str_repeat('_', 18));
            }
        }

        if ($slip === null || $slip->splitModeKind() !== '') {
            return;
        }

        $label = trim($slip->label);
        $numbered = $slip->splitCount > 1 && $slip->slipIndex > 0;

        $value = match (true) {
            $label !== '' && $numbered => sprintf('%s (%d/%d)', $label, $slip->slipIndex, $slip->splitCount),
            $label !== '' => $label,
            $numbered => sprintf('%d/%d', $slip->slipIndex, $slip->splitCount),
            default => null,
        };

        if ($value !== null) {
            $ctx->row($ctx->labels->guest, $value);
        }
    }

    /** Căn giữa; chuỗi rộng hơn giấy thì trả nguyên — cắt còn tệ hơn lệch. */
    private static function center(PrintRenderContext $ctx, string $s): string
    {
        $dw = Layout::displayWidth($s);

        return $dw >= $ctx->width ? $s : Layout::spaces(intdiv($ctx->width - $dw, 2)).$s;
    }

    private static function splitIdxSuffix(PrintRenderSlip $slip): string
    {
        return $slip->splitCount > 1 && $slip->slipIndex > 0
            ? sprintf(' (%d/%d)', $slip->slipIndex, $slip->splitCount)
            : '';
    }

    /**
     * `column_header` — dòng tiêu đề cột "Món … Giá", có băng ngăn cách.
     *
     * Chuỗi trong definition mang CẢ HAI nhãn; `columnHeaderText` tách chúng ở
     * khoảng trắng cuối. Tách ở đây chứ không bắt brand khai hai trường: một
     * template chỉ khai một dòng thì người sửa nó nhìn thấy đúng dòng sẽ in ra.
     */
    private static function emitColumnHeader(PrintRenderContext $ctx, array $block): void
    {
        // `columnHeaderText` trả mảng có KHOÁ (`left`/`right`), không phải
        // list — destructure theo vị trí cho `Undefined array key 0`.
        $parts = Layout::columnHeaderText(
            Definition::resolveText($block, $ctx->locale, $ctx->width < 42),
        );
        $left = $parts['left'];
        $right = $parts['right'];

        // Khoảng trắng, không phải đường kẻ: tờ phiếu của quán ngăn các khối
        // bằng dòng trống. Một gạch ở đây tốn một dòng giấy mỗi khối mà không
        // nói thêm điều gì dòng trống chưa nói.
        $ctx->encoder->feed(1);

        $ctx->encoder->line(Layout::padRight($left, $ctx->width - Layout::displayWidth($right)).$right);
        $ctx->encoder->feed(1);
    }

    /**
     * `order_meta` — số đơn và số bàn, theo danh sách field trong definition.
     *
     * Definition quyết định IN GÌ và THEO THỨ TỰ NÀO; renderer chỉ biết vẽ từng
     * loại. Không có `fields` thì rơi về `['order_no', 'table']` — đó là bản
     * mặc định của formatter cũ, không phải một lựa chọn mới.
     *
     * Bàn bị BỎ QUA với đơn mang đi, không phải in "-": đơn mang đi không có
     * bàn, và in một ô trống cho nó làm nhân viên đi tìm bàn không tồn tại.
     */
    private static function emitOrderMeta(PrintRenderContext $ctx, array $block): void
    {
        $order = $ctx->data->order;

        if ($order === null) {
            return;
        }

        $ctx->encoder->feed(1);

        $fields = $block['fields'] ?? [];

        if (! is_array($fields) || $fields === []) {
            $fields = self::orderMetaFieldsFor($ctx->data->kind);
        }

        // Phiếu bếp giữ NGUYÊN khối header bốn cột nó vốn có — nhãn một dòng,
        // giá trị phóng to bên dưới. Nó dùng chung template với phiếu hall ở mọi
        // chỗ khác; riêng khối này ở lại vì bếp đọc nó như một BẢNG (cách đặt, số
        // thứ tự, số phiếu, bàn — bốn dữ kiện trong một liếc mắt), còn xếp thành
        // hàng rời thì tốn bốn dòng giấy để nói đúng bấy nhiêu.
        if ($ctx->data->kind === 'kitchen') {
            self::emitKitchenMetaRows($ctx, self::kitchenMetaCells($ctx, $order));
            self::emitTakeawayPaymentRow($ctx, $order);

            return;
        }

        foreach ($fields as $field) {
            match ($field) {
                'order_no' => self::emitOrderMetaRow($ctx, $ctx->labels->orderNo, self::orderCodeSuffix($order->orderCode), false),
                'table' => self::emitTableRow($ctx, $order),
                'order_type' => self::emitOrderMetaRow($ctx, $ctx->labels->orderMethod, self::orderTypeLabel($ctx, $order->orderType), false),
                'ticket_seq' => self::emitOrderMetaRow($ctx, $ctx->labels->ticketSeq, (string) $ctx->data->ticketNo, false),
                default => null,
            };
        }

        self::emitTakeawayPaymentRow($ctx, $order);
    }

    /**
     * Dòng trạng thái thanh toán DUY NHẤT của đơn mang đi: ngay dưới khối mã
     * đơn, ngay trên khối khách hàng. Đối ứng `printTakeawayPaymentRow` bên Go.
     *
     * Chỉ ba tờ đi CÙNG ĐỒ ĂN tới quầy nhận — phiếu bếp, phiếu hall delta-QR và
     * phiếu hall `runner`. Người trao túi phải biết đã thu tiền hay chưa. Các
     * chứng từ tiền (`receipt` · `remaining` · `red_invoice`) tự có khối
     * `payments`/`remaining` đầy đủ, nên một câu trả lời thứ hai và ngắn hơn ở
     * đó chính là cách hai khẳng định về một sự việc bắt đầu mâu thuẫn nhau.
     *
     * Nhấn ×2 cho khớp mã đơn nó nằm dưới; `emphasisRow` tự lùi về ×1 khi dòng
     * hết vừa, và đó là thứ giữ giấy 58mm không tràn.
     *
     * Điều kiện tiền chép ĐÚNG `orderIsSettled` bên Go: `total > 0` là rào chặn
     * đơn CHƯA định giá — `0 >= 0` sẽ đọc thành "đã trả xong" trên một tờ in ra
     * trước khi đơn kịp được định giá.
     */
    private static function emitTakeawayPaymentRow(PrintRenderContext $ctx, PrintRenderOrder $order): void
    {
        if ($order->orderType !== 'takeaway') {
            return;
        }

        if (! in_array($ctx->data->kind, ['kitchen', 'delta_qr', 'runner'], true)) {
            return;
        }

        $settled = $order->totalAmount > 0 && $order->paidAmount >= $order->totalAmount;

        $ctx->emphasisRow(
            $ctx->labels->paymentState,
            $settled ? $ctx->labels->paymentPaid : $ctx->labels->paymentUnpaid,
        );
    }

    /**
     * Các cột của khối meta phiếu bếp — đối ứng `kitchenMetaCells` bên Go.
     *
     * Đơn mang đi bỏ HẲN cột 卓 (cả nhãn lẫn giá trị) nên phiếu lấy mang về không
     * mang tham chiếu bàn nào. Đơn tại chỗ mà bàn trống thì GIỮ cột và in "-":
     * nó BẮT BUỘC phải có bàn, nên ô rỗng là dữ liệu thiếu và dấu gạch nói ra
     * điều đó.
     *
     * `emphasised` đánh dấu hai cột ĐỊNH DANH. 提供 và 番号 dùng chung hàng
     * nhưng không dùng chung mức nhấn: nhấn mọi ô trên một dòng là không nhấn
     * gì, và hai thứ nhân viên thật sự quét mắt tìm là mã đơn với số bàn.
     *
     * Đơn không có mã in "-", không để trống: ô trống đọc như trường nạp hỏng.
     *
     * @return list<array{label: string, value: string, emphasised: bool}>
     */
    private static function kitchenMetaCells(PrintRenderContext $ctx, PrintRenderOrder $order): array
    {
        $orderNo = self::orderCodeSuffix($order->orderCode);

        if ($orderNo === '') {
            $orderNo = '-';
        }

        $cells = [
            ['label' => $ctx->labels->orderMethod, 'value' => self::orderTypeLabel($ctx, $order->orderType), 'emphasised' => false],
            ['label' => $ctx->labels->ticketSeq, 'value' => (string) $ctx->data->ticketNo, 'emphasised' => false],
            ['label' => $ctx->labels->orderNo, 'value' => $orderNo, 'emphasised' => true],
        ];

        if ($order->orderType === 'takeaway') {
            return $cells;
        }

        $cells[] = [
            'label' => $ctx->labels->table,
            'value' => $order->tableNumber !== '' ? $order->tableNumber : '-',
            'emphasised' => true,
        ];

        return $cells;
    }

    /** Số cột GIÁ TRỊ của một ô chiếm, sau khi áp mức nhấn của chính nó. */
    private static function cellColumns(array $cell, int $scale): int
    {
        return $cell['emphasised']
            ? Layout::displayWidth($cell['value']) * $scale
            : Layout::displayWidth($cell['value']);
    }

    /**
     * Hai hàng của khối meta phiếu bếp — đối ứng `printKitchenMetaRows` bên Go.
     *
     * **Mọi giá trị dùng CHUNG một cỡ.** Bốn ô được đọc như một bộ — một hàng lẫn
     * cỡ đọc thành thứ bậc không ai định — nên một ô không nhân đôi được thì cả
     * hàng lùi xuống, chứ không để khối lởm chởm. Nhãn giữ cỡ nhỏ: chỉ giá trị
     * được phóng to.
     *
     * @param  list<array{label: string, value: string}>  $cells
     */
    private static function emitKitchenMetaRows(PrintRenderContext $ctx, array $cells): void
    {
        $scale = 2;
        [$widths, $fits] = self::kitchenMetaColumns($ctx->width, $cells, $scale);

        if (! $fits) {
            $scale = 1;
            [$widths] = self::kitchenMetaColumns($ctx->width, $cells, $scale);
        }

        $last = count($cells) - 1;
        $header = '';

        foreach ($cells as $i => $cell) {
            if ($i === $last) {
                $header .= $cell['label'];
                break;
            }
            $header .= Layout::padRight($cell['label'], $widths[$i]);
        }

        $ctx->encoder->line($header);

        $size = $scale === 2 ? Escpos::DOUBLE_SIZE : Escpos::DOUBLE_HEIGHT;

        foreach ($cells as $i => $cell) {
            if ($cell['emphasised']) {
                $ctx->encoder->bold(true);
                $ctx->encoder->size($size);
            }

            $ctx->encoder->text($cell['value']);

            if ($cell['emphasised']) {
                $ctx->encoder->size(Escpos::NORMAL_SIZE);
                $ctx->encoder->bold(false);
            }

            if ($i === $last) {
                break;
            }
            // Đệm phát ở cỡ THƯỜNG kể cả khi kề một ô đã phóng to. Một khoảng
            // trắng in dưới ×2 tốn HAI cột, nên đệm đo bằng cột thật mà in ở cỡ
            // nhân đôi sẽ đẩy mọi cột phía sau sang phải đúng bằng bề rộng của
            // chính nó, ra khỏi nhãn của nó.
            $ctx->encoder->text(Layout::spaces(max($widths[$i] - self::cellColumns($cell, $scale), 1)));
        }

        $ctx->encoder->feed(1);
    }

    /**
     * Bề rộng từng cột ở cỡ đã cho, kèm việc hàng có vừa giấy không.
     *
     * Phần dư chia ĐỀU giữa các cột thay vì dồn một cục bên phải — đó là thứ làm
     * khối này đọc ra một cái bảng chứ không phải một cụm dính trái rồi hụt.
     *
     * @param  list<array{label: string, value: string}>  $cells
     * @return array{0: list<int>, 1: bool}
     */
    private static function kitchenMetaColumns(int $width, array $cells, int $scale): array
    {
        $widths = [];
        $content = 0;

        foreach ($cells as $cell) {
            $w = max(Layout::displayWidth($cell['label']), self::cellColumns($cell, $scale));
            $widths[] = $w;
            $content += $w;
        }

        $gaps = count($cells) - 1;

        if ($gaps < 1) {
            return [$widths, $content <= $width];
        }

        if ($content + $gaps > $width) {
            // Cố hết sức: một cột trắng giữa hai ô kề nhau, và hàng chạy dài. Chỉ
            // tới được trên giấy hẹp với tên bàn dài bất thường, nơi bố cục
            // một-phần-tư cũ cũng tràn y hệt.
            for ($i = 0; $i < $gaps; $i++) {
                $widths[$i]++;
            }

            return [$widths, false];
        }

        $slack = $width - $content;
        $per = intdiv($slack, $gaps);
        $rem = $slack % $gaps;

        for ($i = 0; $i < $gaps; $i++) {
            $widths[$i] += $per;

            if ($i < $rem) {
                $widths[$i]++;
            }
        }

        return [$widths, true];
    }

    /**
     * Danh sách hàng của khối `order_meta` — đối ứng `orderMetaFieldsFor` bên Go.
     *
     * Bếp khai thêm hai hàng. Nó dùng chung template với phiếu hall, mà template
     * đó không có bảng 4 cột để chở 提供 / 番号, nên hai ô này thành HÀNG như mọi
     * thứ khác thay vì bị bỏ.
     *
     * @return list<string>
     */
    private static function orderMetaFieldsFor(string $kind): array
    {
        if ($kind === 'kitchen') {
            return ['order_no', 'table', 'order_type', 'ticket_seq'];
        }

        return ['order_no', 'table'];
    }

    private static function orderTypeLabel(PrintRenderContext $ctx, string $orderType): string
    {
        return match ($orderType) {
            'takeaway' => $ctx->labels->takeaway,
            'spot' => $ctx->labels->spot,
            default => $ctx->labels->dineIn,
        };
    }

    /**
     * Một hàng 伝票 / 卓, ở mức nhấn mà KIND của phiếu đòi hỏi.
     *
     * **`receipt` giữ NGUYÊN cỡ cũ** — số đơn thường, số bàn đậm — và đó là
     * quyết định về AI CẦM TỜ GIẤY, không phải ngoại lệ để dọn sau. Biên lai là
     * bản khách mang về, còn mã đơn và số bàn là thứ NHÂN VIÊN quét mắt tìm;
     * phóng to chúng ở đó là đem chỗ dễ đọc nhất của tờ phiếu cho một người
     * không bao giờ dùng tới. Mọi kind khác trong họ đều phóng to cả hai.
     *
     * `$bold` tái lập đúng phân chia có từ trước: số bàn vốn đã đậm, số đơn thì
     * không.
     */
    private static function emitOrderMetaRow(PrintRenderContext $ctx, string $label, string $value, bool $bold): void
    {
        if ($ctx->data->kind !== 'receipt') {
            $ctx->emphasisRow($label, $value);

            return;
        }

        if ($bold) {
            $ctx->encoder->bold(true);
        }

        $ctx->row($label, $value);

        if ($bold) {
            $ctx->encoder->bold(false);
        }
    }

    private static function emitTableRow(PrintRenderContext $ctx, PrintRenderOrder $order): void
    {
        if ($order->orderType === 'takeaway') {
            return;
        }

        // "-" khi bàn trống: đơn tại chỗ PHẢI có bàn, nên ô trống là dữ liệu
        // thiếu và dấu gạch nói ra điều đó. Khác hẳn đơn mang đi ở trên, nơi
        // vắng bàn là bình thường.
        $table = $order->tableNumber !== '' ? $order->tableNumber : '-';

        self::emitOrderMetaRow($ctx, $ctx->labels->table, $table, true);
    }

    /**
     * Phần sau dấu `-` cuối cùng của mã đơn.
     *
     * Mã đầy đủ dài và có tiền tố chi nhánh; nhân viên đọc bốn ký tự cuối. Không
     * có dấu `-` thì trả nguyên — cắt bừa một mã không theo quy ước sẽ tạo ra
     * hai đơn trông giống nhau.
     */
    private static function orderCodeSuffix(string $code): string
    {
        $i = mb_strrpos($code, '-');

        return $i !== false && $i + 1 < mb_strlen($code) ? mb_substr($code, $i + 1) : $code;
    }

    /**
     * `grand_total` — dòng tổng tiền, rẽ BA nhánh theo kiểu chia bill.
     *
     * Mỗi nhánh nói một điều khác nhau, và nhầm nhánh là **in sai số tiền khách
     * phải trả** — dòng khách đọc đầu tiên:
     *
     * | Kiểu | Dòng đậm | Dòng thường |
     * |---|---|---|
     * | `by_items` | phần của người này | tổng cả đơn |
     * | `equal` / `by_amount` | tổng cả đơn | phần của người này (cũng đậm) |
     * | không chia | tổng | — |
     *
     * Thứ tự hai dòng ĐẢO giữa `by_items` và `equal`, không phải nhầm lẫn: chia
     * theo món thì con số đáng chú ý là phần của người này, còn chia đều thì
     * người ta nhìn tổng trước rồi mới thấy phần mình.
     *
     * `by_items` lấy `data->total`, `equal` lấy `slip->amountPaid` cho dòng
     * phần-của-người-này — hai ô khác nhau, và tráo chúng cho ra một con số
     * trông hợp lý nhưng sai.
     */
    private static function emitGrandTotal(PrintRenderContext $ctx, array $block): void
    {
        $data = $ctx->data;
        $slip = $data->slip;
        $e = $ctx->encoder;

        $kind = $slip?->splitModeKind() ?? '';

        if ($kind === 'by_items') {
            $e->bold(true);
            $ctx->row($ctx->labels->splitShare.self::splitIdxSuffix($slip), $ctx->money($data->total));
            $e->bold(false);

            $ctx->row($ctx->labels->orderTotal, $ctx->money($slip->orderGrossTotal));

            return;
        }

        if ($kind === 'even' || $kind === 'by_amount') {
            $e->bold(true);
            $ctx->row($ctx->labels->orderTotal, $ctx->money($data->total));
            $e->bold(false);

            $e->bold(true);
            $ctx->row($ctx->labels->splitShare.self::splitIdxSuffix($slip), $ctx->money($slip->amountPaid));
            $e->bold(false);

            return;
        }

        $e->bold(true);
        $ctx->row($ctx->labels->total, $ctx->money($data->total));
        $e->bold(false);
    }

    /**
     * Hai con số mà dấu ※ cần: mức cao nhất, và có mức nào thấp hơn không.
     *
     * ## KHÔNG dựng `ReceiptTaxSummary` ở đây, và đó là điểm chính
     *
     * Bản đầu của hàm này trả về một `ReceiptTaxSummary` dựng từ mức của các
     * dòng, với `taxable: 0, tax: 0`. Đo được: nó tạo ra **2 block với số tiền
     * bằng 0** — và `tax_breakdown` đọc `Blocks` trước tiên, nên phiếu sẽ in ra
     * những dòng thuế **BỊA**, đúng số 0, trông hoàn toàn hợp lệ.
     *
     * Docblock của `ReceiptTaxSummary` nói thẳng vì sao không được: làm tròn đã
     * xảy ra MỘT LẦN cho mỗi nhóm mức ở Cloud rồi mới phân bổ xuống dòng, nên
     * tính lại ở tầng in *"sẽ ra một con số KHÁC với hoá đơn đã in, và đó là báo
     * cáo thuế"*.
     *
     * Nên ở đây chỉ giữ thứ suy được từ mức mà KHÔNG cần số tiền: dấu ※.
     *
     * **#1937 — snapshot GIỜ ĐÃ có đường xuống** ({@see PrintRenderContext::$taxBreakdown}),
     * nên hàm này tụt xuống vai dự phòng: nó chỉ chạy khi người gọi không cấp
     * snapshot, và khi đó khối thuế in ra là dòng gộp chứ không phải khối theo
     * mức. Nó vẫn KHÔNG được trả về tiền — đó là điều duy nhất giữ cho cạm bẫy
     * bên trên không quay lại.
     *
     * @param  list<PrintRenderItem>  $items
     * @return array<string, mixed>
     */
    private static function rateFactsFrom(array $items): array
    {
        $rates = [];

        foreach ($items as $item) {
            if ($item->taxRate !== null) {
                $rates[] = $item->taxRate;
            }
        }

        if ($rates === []) {
            return ['max_rate' => null, 'has_reduced' => false];
        }

        $max = max($rates);

        return [
            'max_rate' => $max,
            'has_reduced' => count(array_filter($rates, static fn (float $r): bool => $r < $max)) > 0,
        ];
    }

    /**
     * Đóng bảng món.
     *
     * CHỈ phiếu hall kẻ một gạch ở đây. Đó là tờ người chạy bàn liếc trong lúc
     * đi, và khối tiền là phần họ KHÔNG đọc — đường kẻ bảo mắt dừng ở đâu.
     * Phiếu bếp và biên lai đều đọc lúc đứng yên, và tờ phiếu của quán ngăn
     * khối bằng khoảng trắng, nên hai loại đó chỉ có dòng trống.
     *
     * Đối ứng `printFooterRule` bên Go.
     */
    private static function footerRule(PrintRenderContext $ctx): void
    {
        $ctx->encoder->feed(1);

        if ($ctx->data->kind !== 'runner') {
            return;
        }

        $ctx->encoder->line(Layout::dashedLine($ctx->width));
        $ctx->encoder->feed(1);
    }

    /**
     * `items` — các dòng món, kèm dấu ※ cho mức thuế giảm.
     *
     * Vẽ dòng bằng `ItemLines::emit` — cùng lớp mà họ docs dùng, và đó là chủ
     * đích: `printRunnerItem` bên Go là MỘT hàm dùng chung cho cả hai họ. Port
     * riêng mỗi họ một bản là hai bản sẽ lệch nhau, và chúng lệch ở dòng món —
     * thứ chiếm phần lớn tờ giấy.
     *
     * Dấu ※ đọc `taxSummary` đã tính ở prologue, KHÔNG tự tính lại. Đây là vế
     * còn lại của cặp bất biến: dấu trên dòng món và khối thuế ở chân phiếu
     * phải đến từ **cùng một** bản tổng hợp, nếu không tờ giấy tự mâu thuẫn.
     */
    private static function emitItems(PrintRenderContext $ctx, array $block): void
    {
        $hasReduced = ($ctx->taxSummary['has_reduced'] ?? false) === true;
        $maxRate = $ctx->taxSummary['max_rate'] ?? null;
        $priceColWidth = ItemLines::priceColumnWidth($ctx->data->items, $ctx->config->currencySymbol());

        foreach ($ctx->data->items as $item) {
            $reduced = $ctx->showTaxBreakdown
                && $hasReduced
                && $item->taxRate !== null
                && $maxRate !== null
                && $item->taxRate < $maxRate;

            ItemLines::emit(
                $ctx->encoder,
                $ctx->width,
                $priceColWidth,
                $item,
                $ctx->config->currencySymbol(),
                $reduced,
                $ctx->locale,
                $ctx->labels->notePrefix,
            );
        }

        self::footerRule($ctx);
    }

    /**
     * Thụt 3 cột của khối thuế theo mức — `printTaxIndent` bên Go.
     *
     * Không phải thẩm mỹ: thuế là 内税, ĐÃ nằm trong tổng ở dòng trên, nên khối
     * này đọc như một phần tách thông tin NẰM DƯỚI tổng (layout #1042). Bỏ thụt
     * đầu thì nó ngang hàng với tổng và trông như một khoản cộng thêm.
     */
    private const TAX_INDENT = 3;

    /**
     * `tax_breakdown` — khối 内税 theo TỪNG MỨC, hoặc một dòng "Thuế" gộp.
     *
     * Đối ứng của `emitOrderTaxBreakdown`, và nó có HAI nhánh vì hai thế hệ dữ
     * liệu tồn tại song song:
     *
     * | Nhánh | Điều kiện | In ra |
     * |---|---|---|
     * | theo mức | có snapshot với ≥1 khối | `8%対象  ¥1,000 (内消費税 ¥80)` mỗi mức |
     * | gộp (legacy) | không snapshot, có `order.tax_amount` | một dòng `Thuế … ¥N` |
     * | KHÔNG IN | không snapshot, không `tax_amount` | rỗng + một dòng log cảnh báo |
     *
     * Hàng thứ ba là #2067. Trước đó nó không tồn tại: không có dữ kiện thuế thì
     * tầng in TRÍCH một con số ra khỏi tổng bằng `FALLBACK_TAX_RATE = 10.0` —
     * xem {@see wholeOrderTaxAmount()} để biết vì sao đó là nhánh duy nhất từng chạy.
     *
     * ── Số đến từ SNAPSHOT, tuyệt đối không tính lại ────────────────────────
     *
     * Bên Go nhánh thứ nhất chạy `buildReceiptTaxSummary` → `priceGroups()`, tức
     * TÍNH LẠI từ dòng món. **Bản PHP không được làm thế** — làm tròn đã xảy ra
     * MỘT lần cho mỗi nhóm mức ở Cloud rồi mới phân bổ xuống dòng, nên tính lại
     * ra một con số khác với hoá đơn đã phát hành, và đó là báo cáo thuế (xem
     * {@see ReceiptTaxSummary}). Snapshot đi vào qua
     * {@see PrintRenderContext::$taxBreakdown}, không qua `PrintRenderData` —
     * lý do đầy đủ ở docblock của ô đó.
     *
     * ── Cạm bẫy ĐÃ TRẢ GIÁ: đừng bịa khối để lấy dấu ※ ─────────────────────
     *
     * Một bản trước dựng `ReceiptTaxSummary` từ mức của các dòng với
     * `taxable: 0, tax: 0` chỉ để biết có in dấu ※ hay không. Vì nhánh này đọc
     * `blocks` TRƯỚC TIÊN, phiếu in ra hai dòng thuế **BỊA** — đúng số 0, trông
     * hoàn toàn hợp lệ, và không test nào đỏ. Nên khi không có snapshot thì
     * `blocks` phải RỖNG và phiếu rơi về dòng gộp; {@see rateFactsFrom()} chỉ
     * trả `max_rate` + `has_reduced`, không bao giờ trả tiền.
     */
    private static function emitTaxBreakdown(PrintRenderContext $ctx, array $block): void
    {
        if (! $ctx->showTaxBreakdown) {
            return;
        }

        $snapshot = $ctx->taxBreakdown;

        if ($snapshot !== null && ! $snapshot->isEmpty()) {
            $rows = self::taxBreakdownRows(
                $ctx->tax,
                $ctx->config->currencySymbol(),
                $snapshot->blocks,
                $ctx->width,
            );

            foreach ($rows as $row) {
                $ctx->encoder->line(
                    Layout::spaces($row['indent'])
                    .self::justify($row['label'], $row['value'], $ctx->width - $row['indent']),
                );
            }

            return;
        }

        $taxAmount = self::wholeOrderTaxAmount($ctx);

        // `<= 0` chứ không phải `=== 0`: một đơn không thuế thì khối này vắng
        // mặt, và in "Thuế ¥0" nói với khách rằng đơn có thuế bằng không —
        // khác hẳn với "đơn này không có khối thuế".
        if ($taxAmount <= 0) {
            self::warnTaxOmitted($ctx);

            return;
        }

        $label = $ctx->labels->tax;
        $value = $ctx->money($taxAmount);

        // Tự tính khe thay vì gọi `$ctx->row()`: bề rộng ở đây đã TRỪ phần thụt
        // đầu, nên `row()` (đo theo `$ctx->width` đầy đủ) sẽ đẩy cột tiền lệch
        // đúng 3 cột so với các khối theo mức ngay bên trên nó.
        $gap = ($ctx->width - self::TAX_INDENT) - Layout::displayWidth($label) - Layout::displayWidth($value);

        if ($gap < 1) {
            $gap = 1;
        }

        $ctx->encoder->line(Layout::spaces(self::TAX_INDENT).$label.Layout::spaces($gap).$value);
    }

    /**
     * Cột thụt THÊM của dòng thứ hai khi khối theo mức phải xuống dòng.
     *
     * Hai cột, không phải bốn: dòng `内消費税` phải đọc ra là phần BÊN TRONG số
     * chịu thuế ngay trên nó, chứ không phải một khoản riêng — nhưng vẫn phải
     * còn đủ chỗ cho nhãn dài nhất (`thue trong`) ở khổ 32.
     */
    private const TAX_WRAP_INDENT = 2;

    /**
     * Khối theo mức: `8%対象  ¥1,000 (内消費税 ¥80)` — MỘT dòng khi vừa giấy,
     * HAI dòng khi không.
     *
     * Đối ứng của `formatRateBlockLines`. Cột giá trị căn PHẢI để các khối của
     * những mức khác nhau thẳng hàng — đó là điều làm người đọc so được 8% với
     * 10% bằng mắt, và cả hai dòng của bản xuống dòng đều chạm cùng mép phải ấy.
     *
     * ── Vì sao XUỐNG DÒNG chứ không rút gọn (#2035) ───────────────────────────
     *
     * Ở khổ 58mm (32 cột) bản một dòng KHÔNG vừa ở bất kỳ ngôn ngữ nào — 33 cột
     * ở ja, 38 ở en, 41 ở vi, đã tính 3 cột thụt và khe tối thiểu 1. `$gap` kẹp
     * xuống 1 rồi vẫn phát ra dòng dài hơn giấy, tức **máy in thật tràn**, không
     * riêng bản xem trước. Thu nhỏ số tiền không cứu được: `¥5 (thue trong ¥1)`
     * vẫn để vi ở 35 cột.
     *
     * Ba đường ra đã cân: rút nhãn trong catalog đổi chữ trên giấy ở MỌI khổ;
     * bỏ khối theo mức ở 58mm là bỏ số liệu bắt buộc của một 適格請求書 (thuế
     * theo từng mức là trường pháp định — chứng từ thắng thẩm mỹ). Còn lại là
     * bố cục, và xuống dòng không mất một con số nào.
     *
     * Chỉ khổ 32 đổi: ở 42 cột ca sát nhất (vi, 38 cột nội dung trên 39 cột khả
     * dụng) vẫn vừa, nên tờ giấy của các quán đang chạy không đổi một byte.
     *
     * Hình dạng (một dòng hay hai) quyết MỘT lần cho CẢ khối, không phải mỗi
     * mức tự đo: mức 8% thường ngắn hơn 10% (số nhỏ hơn, nhãn ngắn hơn ở ja)
     * nên nó vừa một dòng trong khi 10% phải xuống dòng — và khối in ra hai
     * hình dạng khác nhau, mất đúng cái mép phải chung làm người đọc so được
     * 8% với 10% bằng mắt.
     *
     * Dòng này KHÔNG mang dấu ※, kể cả cho mức giảm: ※ thuộc về dòng MÓN
     * (`items`) và chú thích chân phiếu (`tax_legend`). Go cũng vậy — thêm ※ ở
     * đây là một chỗ thứ ba nói cùng một việc, và nó sẽ lệch khi ai đó sửa hai
     * chỗ kia.
     *
     * ── Vì sao PUBLIC và trả HÀNG chứ không trả chuỗi (#2045 mục 3) ──────────
     *
     * {@see SampleSlipData} dựng bản xem trước từ CHÍNH hàm này. Trước đó nó
     * mang một bản chép tay của cùng bố cục — với chuỗi tự chế 「内税」 và một
     * dấu ※ mà emitter không in — và bản chép ấy còn LỌT rào "không dòng nào
     * rộng hơn giấy" chỉ vì nó ngắn hơn dòng thật. Tức một bản sao thứ hai của
     * bố cục vừa nói dối brand vừa che mất lỗi tràn khổ 58mm ở ngay bên cạnh.
     *
     * Trả về HÀNG (nhãn · giá trị · thụt) chứ không phải chuỗi đã căn, vì
     * {@see SlipComposer} tự căn hai cột theo khổ của nó; trả chuỗi đã căn thì
     * bản xem trước phải tháo ra để căn lại.
     *
     * @param  list<ReceiptTaxBlock>  $blocks
     * @param  int  $columns  bề rộng ĐẦY ĐỦ của giấy (phần thụt tính bên trong)
     * @return list<array{label: string, value: string, indent: int}>
     */
    public static function taxBreakdownRows(TaxLabels $tax, string $currency, array $blocks, int $columns): array
    {
        $money = static fn (int $amount): string => $currency.Layout::formatPrice($amount);
        $label = static fn (ReceiptTaxBlock $b): string => sprintf(
            $tax->rateTarget,
            TaxLabels::formatRatePercent($b->rate),
        );
        $inline = static fn (ReceiptTaxBlock $b): string => sprintf(
            '%s (%s %s)',
            $money($b->taxable),
            $tax->includedTax,
            $money($b->tax),
        );

        $width = $columns - self::TAX_INDENT;

        // Cổng khổ hẹp đứng TRƯỚC phép đo vừa-hay-không: xem
        // {@see Layout::NARROW_COLUMNS} — ở 42/48 nhánh xuống dòng phải không
        // với tới được, chứ không phải "đo thấy không cần".
        $wrap = false;
        if (Layout::isNarrow($columns)) {
            foreach ($blocks as $block) {
                if (Layout::displayWidth($label($block)) + 1 + Layout::displayWidth($inline($block)) > $width) {
                    $wrap = true;

                    break;
                }
            }
        }

        $rows = [];
        foreach ($blocks as $block) {
            if (! $wrap) {
                $rows[] = ['label' => $label($block), 'value' => $inline($block), 'indent' => self::TAX_INDENT];

                continue;
            }

            $rows[] = ['label' => $label($block), 'value' => $money($block->taxable), 'indent' => self::TAX_INDENT];
            $rows[] = [
                'label' => $tax->includedTax,
                'value' => $money($block->tax),
                'indent' => self::TAX_INDENT + self::TAX_WRAP_INDENT,
            ];
        }

        return $rows;
    }

    /** `label` … `value` căn đều ra hai mép, khe tối thiểu một cột. */
    private static function justify(string $label, string $value, int $width): string
    {
        $gap = $width - Layout::displayWidth($label) - Layout::displayWidth($value);

        if ($gap < 1) {
            $gap = 1;
        }

        return $label.Layout::spaces($gap).$value;
    }

    /**
     * Số thuế cho nhánh GỘP — đối ứng của `legacyOrderTax` phía Go.
     *
     * **MỘT bậc, không phải hai (#2067).** Con số duy nhất tầng in được phép đưa
     * lên giấy là `order.tax_amount` — do engine chốt và đóng băng lên đơn. Không
     * có nó thì KHÔNG CÓ dữ kiện thuế nào để in, nên không in dòng thuế nào.
     *
     * Bậc hai cũ đã bị gỡ, và đây là thứ nó làm:
     *
     *     $net = round($total / (1 + $ctx->config->effectiveTaxRate() / 100));
     *
     * với `effectiveTaxRate()` rơi về `FALLBACK_TAX_RATE = 10.0`. Ba lỗi độc
     * lập, không cái nào kêu thành tiếng:
     *
     * - 10% là một khẳng định về MỘT quốc gia áp lên mọi quán — quán VN, giỏ
     *   hàng 軽減税率 8%, dòng 非課税 đều in ra 10%.
     * - Nó trái plan-043: thuế ở hệ này là PER-RATE, giải theo từng DÒNG và
     *   snapshot bất biến lên order line. Một tỉ lệ áp lên một tổng đơn không
     *   tái tạo nổi điều đó — đơn vừa 10% tại chỗ vừa 8% mang đi ra số sai.
     * - Nó tự mâu thuẫn với chính lớp này: docblock của `emitTaxBreakdown` cấm
     *   bịa KHỐI theo mức ("đừng bịa khối để lấy dấu ※"), rồi ngay dưới đó bịa
     *   một DÒNG GỘP bằng một tỉ lệ không ai cấu hình.
     *
     * Và nó không phải ca hiếm. `PrintJobConfig::$taxRate` không có đường nào
     * được điền trong production (`grep taxRate: backend/app` = 0 hit), nên
     * `effectiveTaxRate()` LUÔN trả 10.0 — nhánh "dự phòng" là nhánh duy nhất
     * từng chạy. Phía Go cũng vậy vì cùng lý do đo được: plan-043 T6.2 đã DROP
     * cột `shop_order_settings.tax_rate`, `Workstation\BranchController::show`
     * không còn ship khoá đó, nên `shop_settings.tax_rate` rỗng ở mọi máy trạm.
     *
     * Ba kiểu phiếu vẫn bị ép về 0, và vẫn vì cùng lý do cũ: chúng hiển thị một
     * PHẦN của đơn (`kitchen` · phiếu delta · không có đơn), nên tổng thuế của
     * cả đơn không mô tả tờ giấy đó. Khác biệt là giờ chúng in RỖNG thay vì rơi
     * xuống một phép trích.
     */
    private static function wholeOrderTaxAmount(PrintRenderContext $ctx): int
    {
        $order = $ctx->data->order;

        if ($ctx->data->kind === 'kitchen' || $ctx->data->deltaBill || $order === null) {
            return 0;
        }

        return max(0, $order->taxAmount);
    }

    /**
     * Vết đọc được mà một khối thuế vắng mặt để lại (#2067).
     *
     * Lỗi cũ nguy hiểm chính vì nó IM LẶNG: một dòng thuế bịa trông y hệt một
     * dòng thuế thật trên giấy nhiệt. In rỗng thì nhìn thấy trên phiếu; dòng log
     * này làm nó chẩn đoán được cả khi không cầm tờ giấy.
     *
     * Chỉ cảnh báo cho phiếu tiền của CẢ đơn. `kitchen` và phiếu delta hiển thị
     * một phần của đơn nên chưa bao giờ mang dữ kiện thuế của riêng chúng — im
     * lặng ở đó là bình thường, không phải mất mát.
     */
    private static function warnTaxOmitted(PrintRenderContext $ctx): void
    {
        if ($ctx->data->kind === 'kitchen' || $ctx->data->deltaBill || $ctx->data->order === null) {
            return;
        }

        if ($ctx->data->total <= 0) {
            return;
        }

        Log::warning('print_tax_row_omitted_no_tax_fact', [
            'kind' => $ctx->data->kind,
            'order_id' => $ctx->data->order->id,
            'order_code' => $ctx->data->order->orderCode,
            'total' => $ctx->data->total,
            'detail' => 'no per-rate snapshot and order.tax_amount <= 0; the slip prints no tax '
                .'row rather than a computed one (#2067).',
        ]);
    }

    /**
     * Tờ HALL (`runner` · `delta_qr`) đã thu đủ tiền thì không mang QR.
     *
     * Đối ứng `hallQRSuppressed` bên Go, và điều kiện tiền chép đúng
     * `OrderIsSettled`: `total > 0` là rào chặn đơn CHƯA định giá — `0 >= 0`
     * sẽ đọc thành "đã trả xong" và nuốt mất QR của một đơn chưa ai trả đồng
     * nào.
     */
    private static function hallQrSuppressed(string $kind, PrintRenderOrder $order): bool
    {
        if ($kind !== 'runner' && $kind !== 'delta_qr') {
            return false;
        }

        return $order->totalAmount > 0 && $order->paidAmount >= $order->totalAmount;
    }

    /**
     * `qr_block` — mã QR cuối phiếu, kèm hai dòng trắng và trả lại căn trái.
     *
     * Đối ứng của `emitBillQR`. Payload MẶC ĐỊNH là JSON
     * `{"orderId","orderCode","type"}` — y hệt `kioskQRPayload` bên Go và y hệt
     * customer-web phát ra, vì kiosk đọc `orderCode` thẳng từ JSON đã parse rồi
     * resolve qua `GET /api/v1/kiosk/orders?code=`. Payload cũ là một UUID trần,
     * không khớp đường nào, nên mọi phiếu quét đều 404 (#1190). Lệch một byte ở
     * đây không làm gì đỏ — nó chỉ làm máy quét ở quán im lặng.
     *
     * `source: order_code` in mã trần, và nó là **opt-in**: đo
     * `config/print_templates.php` thì cả ba kind bật QR (`runner` · `delta_qr`
     * · `remaining`) đều khai `order_url`, tức nhánh JSON là nhánh quán chạy.
     *
     * Hai dòng trắng SAU mã là lý do epilogue không tự feed khi có `qr_block`
     * (xem `epilogue`) — đếm hai lần thì phiếu thừa lề, đếm không lần nào thì
     * mã bị cắt sát.
     */
    private static function emitQrBlock(PrintRenderContext $ctx, array $block): void
    {
        $order = $ctx->data->order;

        if ($order === null) {
            return;
        }

        // Đã thu đủ ⇒ không QR, nhưng VẪN chừa hai dòng lề cuối: nhánh `else`
        // của formatter cũ có `feed(2)` và TR-40 so hai đường in theo từng byte.
        // Trả về trần ở đây làm tờ giấy ngắn đi hai dòng đúng trên những phiếu
        // luật này chạm, rồi khác biệt ấy bị đọc thành một lỗi QR.
        if (self::hallQrSuppressed($ctx->data->kind, $order)) {
            $ctx->encoder->feed(2);

            return;
        }

        $target = ($block['source'] ?? null) === 'order_code'
            ? $order->orderCode
            : self::kioskQrPayload($order);

        $e = $ctx->encoder;

        $e->feed(2);
        $e->align(Escpos::ALIGN_CENTER);
        $e->qrCode($target, self::QR_CELL_SIZE);
        $e->feed(2);
        $e->align(Escpos::ALIGN_LEFT);
    }

    /**
     * Chuỗi nhúng trong mã QR — đối ứng của `kioskQRPayload`.
     *
     * Ba khoá, ĐÚNG thứ tự `orderId` → `orderCode` → `type`: `json_encode` của
     * PHP giữ nguyên thứ tự khai, còn Go marshal theo thứ tự trường struct. Hai
     * bên phải ra cùng chuỗi để một phiếu in bởi workstation và một phiếu in bởi
     * Cloud quét ra cùng kết quả.
     *
     * `JSON_UNESCAPED_SLASHES` là BẮT BUỘC, không phải thẩm mỹ: mặc định
     * `json_encode` của PHP đổi `/` thành `\/` còn `encoding/json` của Go thì
     * không. Đó là chỗ hai bộ mã hoá lệch nhau **thật sự có thể xảy ra** ở đây,
     * và một mã đơn chứa `/` sẽ làm phiếu Cloud quét ra chuỗi khác phiếu
     * workstation — im lặng, vì cả hai đều là JSON hợp lệ.
     *
     * Chỗ lệch còn lại KHÔNG bịt được và cũng không cần: Go escape ba ký tự
     * HTML thành dãy `\u00xx` chữ thường, PHP thì không, và ép PHP làm thế
     * (`JSON_HEX_TAG`) cho ra hex VIẾT HOA nên vẫn khác. Ba trường này là UUID,
     * mã đơn và `dine_in`/`takeaway` — không ký tự nào trong số đó lọt vào được.
     */
    private static function kioskQrPayload(PrintRenderOrder $order): string
    {
        return json_encode([
            'orderId' => $order->id,
            'orderCode' => $order->orderCode,
            'type' => $order->orderType,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Ba cờ dẫn xuất, tính MỘT lần trước vòng duyệt block.
     *
     * Đối ứng của `prepareBillTax()` bên Go, và lý do nó phải chạy ở prologue
     * chứ không trong từng emitter: dấu ※ trên dòng món và khối thuế theo mức ở
     * chân phiếu đọc CÙNG một tập dữ liệu. Tính hai lần là mở đường cho hai kết
     * quả khác nhau trên cùng tờ giấy — và người phát hiện ra sẽ là khách hàng
     * đang so hoá đơn.
     */
    private static function prepareBillTax(PrintRenderContext $ctx): void
    {
        $order = $ctx->data->order ?? null;

        if ($order === null) {
            return;
        }

        $slip = $ctx->data->slip ?? null;

        // "Hoá đơn con của một lần chia bill" — nhận diện bằng CẢ HAI điều
        // kiện, giống Go. Chỉ `splitCount > 1` là chưa đủ: một đơn chia nhưng
        // chưa có tổng thì vẫn phải in khối thuế đầy đủ.
        //
        // #1937 — SỬA LỖI SỐNG. Hai dòng này đọc `$slip` như MẢNG
        // (`$slip['split_count']`), nhưng `PrintRenderData::$slip` đã siết
        // thành {@see PrintRenderSlip} ở #1923. Mọi lượt render một phiếu CÓ
        // slip vì thế ném `Cannot use object of type PrintRenderSlip as array`
        // ngay ở prologue — tức không in nổi tờ nào, không phải in sai.
        //
        // Nó sống được vì mọi test của họ bill gọi thẳng từng emitter và tự đặt
        // cờ `showTaxBreakdown`; không ca nào chạy `prologue` với slip khác
        // null. Ca "phiếu con của lần chia bill KHÔNG in khối thuế" ở
        // `BillTaxBreakdownQrTest` là ca đầu tiên đi qua đường đó — và nó đỏ
        // ngay lượt chạy đầu.
        $isSplitSubBill = $slip !== null
            && $slip->splitCount > 1
            && $slip->billTotal > 0;

        // Tổng hợp thuế tính MỘT lần ở đây; mọi emitter đọc lại.
        //
        // #1937 — MỘT nguồn, hai thứ đọc: dấu ※ trên dòng món và khối thuế ở
        // chân phiếu. Có snapshot thì cả hai lấy từ snapshot; không có thì khối
        // thuế rơi về dòng gộp (không mức nào) và ※ suy từ mức trên dòng món —
        // thứ DUY NHẤT còn lại, và nó không sinh ra đồng nào.
        //
        // Đừng đảo thành "※ luôn suy từ items": khi có snapshot, mức cao nhất
        // của snapshot (đã lọc dòng void ở Cloud) mới là mức mà khối thuế in ra
        // dùng — lấy ※ từ chỗ khác là để hai chỗ trên cùng tờ giấy nói khác nhau.
        //
        // Snapshot đi vào qua `PrintRenderContext`, KHÔNG qua `PrintRenderData`:
        // cổng parity (`PrintRenderData phủ ĐÚNG tập Ô của Go`) đỏ ngay khi thêm
        // ô ở đó — Go tính lại nên không có ô ấy, và tập ô hai bên phải bằng
        // nhau. Đầy đủ lý do ở docblock của `PrintRenderContext::$taxBreakdown`.
        $snapshot = $ctx->taxBreakdown;

        $ctx->taxSummary = $snapshot !== null && ! $snapshot->isEmpty()
            ? ['max_rate' => $snapshot->maxRate, 'has_reduced' => $snapshot->hasReduced]
            : self::rateFactsFrom($ctx->data->items);

        $ctx->showTaxBreakdown = ! $isSplitSubBill;
        $ctx->suppressOrderRows = $isSplitSubBill || ($ctx->data->deltaBill ?? false);
    }
}
