<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d (#1927 đăng ký · #1934 thân) — 3 kind họ **shift**.
 *
 * Đối ứng của `workstation/internal/service/print_renderer_shift.go`.
 *
 * ── `chain_report` DÙNG LẠI plan của `shift_report`, không lật cờ nào ─────
 *
 * Khác {@see DocsKindPlans} một điểm đáng chú ý: ở đó
 * `qualified_simplified_invoice` dùng lại plan của `vat_invoice` **và lật** cờ
 * `japaneseDoc`. Ở đây `chain_report` dùng lại NGUYÊN plan của `shift_report`,
 * không đổi gì — báo cáo chuỗi ca và 精算 một ca có cùng 18 block, cùng bề rộng,
 * cùng prologue/epilogue. Khác biệt nằm trong dữ liệu (`ChainShiftLine[]`), không
 * nằm trong hình dạng phiếu. Bên Go điều đó được viết thẳng ra:
 * `FormatChainReport` chính là `FormatShiftReport(info.asShiftReport())`.
 *
 * ── HAI plan, và chúng KHÔNG chỉ khác tập block ──────────────────────────
 *
 * Đây là chỗ dễ port sai nhất của cả họ. `shift_meta` và `denomination_table`
 * CÓ MẶT Ở CẢ HAI plan nhưng trỏ vào HAI emitter khác nhau: phiếu mở ca đọc
 * `data->shiftOpen`, phiếu 精算 đọc `data->shift`. Một bảng `blockId => emitter`
 * dùng chung cho cả hai kind sẽ làm phiếu mở ca in ra khối 精算 rỗng — không có
 * gì đỏ, chỉ là một tờ giấy thiếu bảng kiểm đếm tiền.
 *
 * Bản đăng ký ở #1927 dựng plan bằng MỘT hàm `plan(array $blocks)` chung cho cả
 * hai kind, vì lúc đó mọi emitter đều no-op nên khác biệt chưa nhìn thấy được.
 * Nó phải tách ra ở đây.
 *
 * ── `shift_open` KHÔNG đặt lề trái, và đó là quyết định có lý do ──────────
 *
 * Prologue của nó **rỗng**. Comment bên Go nói thẳng vì sao:
 * `FormatShiftOpenReport` tự căn giữa phần đầu và in các dòng đầy chiều rộng, nên
 * thụt thêm lề sẽ **đẩy cột tiền bên phải ra khỏi giấy**.
 *
 * Đây đúng loại chỗ mà "cho nhất quán với hai kind kia" là một thay đổi làm hỏng
 * giấy in — nên nó được ghi ở đây, không chỉ ở Go.
 *
 * ── `Feed(1)` trước `FullCut()` KHÔNG thừa ────────────────────────────────
 *
 * `FullCut` (ESC d 3) đã tự đẩy 3 dòng trước khi cắt, nên **một** dòng đuôi là
 * vừa đủ lề. Con số 1 là đo, không phải thói quen — bỏ đi thì cắt sát chữ, thêm
 * vào thì phí giấy trên mỗi phiếu của mỗi ca.
 *
 * Cả ba kind đều cắt giấy; khác họ bill, nơi chỉ vài kind cắt.
 *
 * ── Tiền của họ shift KHÔNG đi qua `$ctx->money()` ───────────────────────
 *
 * `$ctx->money()` đặt ký hiệu ĐỨNG TRƯỚC và lấy từ `PrintJobConfig` (`¥1,500`).
 * Họ shift thì đặt đơn vị ĐỨNG SAU và lấy từ ảnh chụp tiền tệ của CHÍNH CA ĐÓ
 * (`1,500円`) — xem {@see money()}. Hai thứ này trông giống nhau đủ để lẫn, và
 * lẫn thì phiếu 精算 in ra một ký hiệu tiền tệ không phải của ca đang đóng.
 */
final class ShiftKindPlans
{
    /**
     * Bề rộng cột đếm (件/枚) và cột tiền, tính bằng CỘT hiển thị.
     *
     * Hai hằng này là thứ giữ cho mọi mục của phiếu 精算 thẳng hàng với nhau —
     * dòng phương thức thanh toán, dòng thu chi, dòng huỷ đơn đều xếp vào đúng
     * hai cột này. Đổi một trong hai là xô lệch cả phiếu, không chỉ một mục.
     */
    private const COUNT_COL = 6;

    private const AMOUNT_COL = 12;

    /**
     * 8 block của phiếu mở ca (レジ開け).
     *
     * @var list<string>
     */
    private const OPEN_BLOCKS = [
        'logo',
        'store_info',
        'title',
        'shift_meta',
        'denomination_table',
        'float_count',
        'order_note',
        'shift_signature',
        'footer_text',
    ];

    /**
     * 18 block của 精算 — dùng chung cho `shift_report` và `chain_report`.
     *
     * @var list<string>
     */
    private const REPORT_BLOCKS = [
        'logo',
        'store_info',
        'title',
        'shift_meta',
        'chain_summary',
        'sales_summary',
        'tax_breakdown',
        'tender_summary',
        'non_cash_change',
        'discount_summary',
        'service_charge',
        'acct_correction',
        'check_count',
        'cash_movement',
        'void_summary',
        'variance',
        'denomination_table',
        'shift_signature',
        'footer_text',
    ];

    public static function register(PrintKindRegistry $registry): void
    {
        $registry->register('shift_open', self::openPlan());

        $report = self::reportPlan();

        $registry->register('shift_report', $report);

        // Cùng một plan, không lật cờ nào — xem doc class.
        $registry->register('chain_report', $report);
    }

    private static function openPlan(): PrintKindPlan
    {
        return self::plan(self::OPEN_BLOCKS, leftMargin: false, ported: [
            'logo' => LogoBlock::emit(...),
            'store_info' => self::emitHeader(...),
            'title' => self::emitHeader(...),
            'shift_meta' => self::emitOpenMeta(...),
            'denomination_table' => self::emitOpenDenominations(...),
            'float_count' => self::emitOpenTotal(...),
            'order_note' => self::emitOpenNote(...),
        ]);
    }

    private static function reportPlan(): PrintKindPlan
    {
        return self::plan(self::REPORT_BLOCKS, leftMargin: true, ported: [
            'logo' => LogoBlock::emit(...),
            'store_info' => self::emitHeader(...),
            'title' => self::emitHeader(...),
            'shift_meta' => self::emitReportMeta(...),
            'chain_summary' => self::emitChainIndex(...),
            'sales_summary' => self::emitSalesSummary(...),
            'tax_breakdown' => self::emitTaxBreakdown(...),
            'tender_summary' => self::emitTenderSummary(...),
            'non_cash_change' => self::emitNonCashChange(...),
            'discount_summary' => self::emitDiscounts(...),
            'service_charge' => self::emitServiceCharge(...),
            'acct_correction' => self::emitAcctCorrection(...),
            'check_count' => self::emitCheckCount(...),
            'cash_movement' => self::emitCashMovement(...),
            'void_summary' => self::emitVoidSummary(...),
            'variance' => self::emitDrawerCheck(...),
            'denomination_table' => self::emitDenominations(...),
        ]);
    }

    /**
     * @param  list<string>  $blocks
     * @param  bool  $leftMargin  `shift_open` KHÔNG đặt lề — xem doc class
     * @param  array<string, callable>  $ported
     */
    private static function plan(array $blocks, bool $leftMargin, array $ported): PrintKindPlan
    {
        $emitters = [];

        foreach ($blocks as $block) {
            // `shift_signature` và `footer_text` là chữ do BRAND soạn, và bên Go
            // cả hai trỏ vào `emitAuthoredText` — một hàm DÙNG CHUNG với họ bill
            // (`header_text`/`footer_text`/`greeting`) và họ docs. Chủ sở hữu bản
            // port PHP của nó là slice 2 (#1932), đúng tiền lệ mà
            // {@see BillKindPlans} đã đặt cho `reprint_marker`: khai block id ở
            // đây, không cài thân, để hai bản port không đánh nhau ở cùng một
            // hàm. Chúng nối vào `AuthoredText::emit` khi slice đó vào `dev`.
            $emitters[$block] = $ported[$block]
                ?? static function (PrintRenderContext $ctx, array $block): void {};
        }

        return new PrintKindPlan(
            // 42 cho cả ba — hẹp hơn họ bill/docs (48). Bề rộng là hằng của
            // KIND, không suy từ khổ giấy.
            defaultWidth: 42,
            emitters: $emitters,
            prologue: static function (PrintRenderContext $ctx) use ($leftMargin): void {
                if ($leftMargin) {
                    $ctx->encoder->setLeftMargin($ctx->config->leftMargin($ctx->width));
                }
            },
            epilogue: static function (PrintRenderContext $ctx): void {
                $ctx->encoder->feed(1);
                $ctx->finish();
            },
            // Phiếu ca KHÔNG phải 適格簡易請求書 — cờ đó thuộc họ docs (#1493).
            japaneseDoc: false,
        );
    }

    // ─── phần đầu, dùng chung cho cả ba kind ────────────────────────────

    /**
     * `store_info` và `title` — MỘT khối đầu phiếu, hai block id cùng trỏ vào đây.
     *
     * Đây là chỗ `headerDrawn` tồn tại để giữ: cái nào đứng trước trong
     * definition thì vẽ, cái kia thành no-op. Bỏ cờ thì brand bật cả hai in ra
     * **hai lần** tên quán và tiêu đề.
     *
     * ── Tên quán quá dài thì HẠ CỠ, không cắt ────────────────────────────
     *
     * Tên in ở cỡ đôi cả chiều cao lẫn chiều rộng, nên nó chiếm gấp đôi số cột.
     * Không vừa thì rơi về CHỈ gấp đôi chiều cao — vẫn nổi bật, nhưng không tràn
     * mép giấy. Tiêu đề thì LUÔN cỡ đôi: nó ngắn, và nó là thứ phân biệt phiếu
     * mở ca với phiếu 精算 khi hai tờ nằm cạnh nhau trong kẹp chứng từ.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitHeader(PrintRenderContext $ctx, array $block): void
    {
        // TRONG guard: emitter này đăng ký cho cả `store_info` lẫn `title`.
        if ($ctx->headerDrawn) {
            return;
        }

        $ctx->headerDrawn = true;

        StoreInfoBlock::emitAbove($ctx);

        $ctx->encoder->align(Escpos::ALIGN_CENTER);
        $ctx->encoder->bold(true);

        // `has` bên Go là "có block VÀ block đang bật" — một `store_info` bị
        // brand tắt phải im, chứ không phải in tên quán vì nó vẫn nằm trong
        // definition.
        if (self::hasEnabledBlock($ctx, 'store_info')) {
            $name = trim($ctx->config->storeName);

            if ($name !== '') {
                $ctx->encoder->size(
                    Layout::displayWidth($name) * 2 > $ctx->width
                        ? Escpos::DOUBLE_HEIGHT
                        : Escpos::DOUBLE_SIZE
                );
                $ctx->encoder->line($name);
            }
        }

        $ctx->encoder->size(Escpos::DOUBLE_SIZE);
        $ctx->encoder->line(self::title($ctx));
        $ctx->encoder->size(Escpos::NORMAL_SIZE);
        $ctx->encoder->bold(false);

        StoreInfoBlock::emitBelow($ctx);
    }

    /**
     * Tiêu đề phiếu — DỮ LIỆU thắng definition ở hai trường hợp.
     *
     * 引き継ぎ (bàn giao ca) và 精算（チェーン） không phải lựa chọn thương hiệu:
     * chúng nói tờ giấy này ghi nhận HÀNH VI KẾ TOÁN nào. Một ca bàn giao in ra
     * chữ 精算 là nói sai việc đã xảy ra — chuỗi ca vẫn đang mở, tiền chưa chốt.
     * Nên renderer chọn hai chữ ấy từ dữ liệu, và chữ brand soạn chỉ phục vụ
     * trường hợp thường (精算 / レジ開け).
     *
     * Thứ tự ở nhánh mở ca ngược lại: brand soạn gì thì dùng, không có mới rơi
     * về nhãn mặc định. Phiếu mở ca không có biến thể kế toán nào để nói sai.
     */
    private static function title(PrintRenderContext $ctx): string
    {
        // `block()` bên Go KHÔNG lọc `enabled` (khác `has()`): tiêu đề vẫn lấy
        // chữ từ block ngay cả khi block bị tắt, vì chính emitter này mới là
        // thứ quyết định có in hay không.
        $titleBlock = self::blockById($ctx, 'title') ?? [];

        if ($ctx->data->shiftOpen !== null) {
            $authored = Definition::resolveText($titleBlock, $ctx->locale);

            return $authored !== '' ? $authored : ShiftOpenLabels::forLocale($ctx->locale)->title;
        }

        $labels = ShiftLabels::forLocale($ctx->locale);
        $info = $ctx->data->shift;

        if ($info !== null) {
            if ($info->isChain) {
                return $labels->chainTitle;
            }

            if ($info->reportKind === 'handover') {
                return $labels->handoverTitle;
            }
        }

        $authored = Definition::resolveText($titleBlock, $ctx->locale);

        return $authored !== '' ? $authored : $labels->title;
    }

    // ─── phiếu mở ca ────────────────────────────────────────────────────

    /**
     * `shift_meta` của phiếu mở ca — máy nào, ai mở, lúc mấy giờ.
     *
     * Ba trường này là thứ trả lời "ai chịu trách nhiệm cho số tiền đầu ca", nên
     * brand được sắp xếp lại thứ tự qua `fields` nhưng `cashier_name` in cả khi
     * chưa đặt (rơi về 未設定): một dòng vắng và một ca không ai nhận là hai
     * chuyện khác nhau.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitOpenMeta(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shiftOpen;

        if ($info === null) {
            return;
        }

        $labels = ShiftOpenLabels::forLocale($ctx->locale);
        $ctx->encoder->align(Escpos::ALIGN_LEFT);

        $fields = $block['fields'] ?? null;

        if (! is_array($fields) || $fields === []) {
            $fields = ['device_name', 'cashier_name', 'opened_at'];
        }

        foreach ($fields as $field) {
            switch ((string) $field) {
                // #2188 — alias `till_name` đã bị XOÁ (legacy không tồn tại);
                // definition phải gọi đúng `device_name`.
                case 'device_name':
                    $device = trim($info->deviceName);

                    if ($device !== '') {
                        $ctx->row($labels->device, $device);
                    }
                    break;

                case 'cashier_name':
                    $operator = trim($info->operator);

                    $ctx->row($labels->operator, $operator !== '' ? $operator : $labels->operatorNone);
                    break;

                case 'opened_at':
                    if ($info->openedAt !== '') {
                        $ctx->row($labels->openedAt, $info->openedAt);
                    }
                    break;
            }
        }
    }

    /**
     * `denomination_table` của phiếu mở ca — bảng kiểm đếm tiền đầu ca.
     *
     * Có DÒNG TIÊU ĐỀ CỘT (金種 / 枚数 / 金額), khác hẳn bảng mệnh giá của phiếu
     * 精算 ({@see emitDenominations}) vốn chỉ có một nhãn khối. Lý do: phiếu mở ca
     * là thứ thu ngân vừa đếm vừa đối chiếu, nên cột phải tự giải thích; phiếu
     * 精算 thì đọc sau khi đã xong.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitOpenDenominations(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shiftOpen;

        if ($info === null) {
            return;
        }

        $labels = ShiftOpenLabels::forLocale($ctx->locale);
        self::separator($ctx);

        // Tối thiểu 1 khoảng trắng: giấy hẹp không đủ chỗ cho cả ba cột, và dính
        // sát vẫn đọc được, còn số âm thì `spaces()` nuốt mất và hai cột bên
        // phải trôi lên đè nhãn.
        $gap = max($ctx->width - Layout::displayWidth($labels->denomHeader) - self::COUNT_COL - self::AMOUNT_COL, 1);

        $ctx->encoder->line(
            $labels->denomHeader
            .Layout::spaces($gap)
            .self::padLeft($labels->qtyHeader, self::COUNT_COL)
            .self::padLeft($labels->amountHeader, self::AMOUNT_COL)
        );

        foreach ($info->denominations as $line) {
            $ctx->row(
                '  '.self::money($ctx, $line->value),
                self::padLeft($line->quantity.$labels->qtyUnit, self::COUNT_COL)
                .self::padLeft(self::money($ctx, $line->subtotal), self::AMOUNT_COL)
            );
        }
    }

    /**
     * `float_count` — tổng quỹ đầu ca, con số mà cả ca sẽ đối chiếu ngược lại.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitOpenTotal(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shiftOpen;

        if ($info === null) {
            return;
        }

        self::separator($ctx);
        $ctx->row(ShiftOpenLabels::forLocale($ctx->locale)->total, self::money($ctx, $info->openingFloat));
    }

    /**
     * `order_note` — ghi chú mở ca, thụt vào 2 cột.
     *
     * Đây là chữ do NGƯỜI gõ, nên nó là chỗ duy nhất trên phiếu có độ dài không
     * giới hạn. Không ngắt dòng thì nó tràn và đẩy vỡ mọi thứ bên dưới.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitOpenNote(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shiftOpen;

        if ($info === null) {
            return;
        }

        $note = trim($info->note);

        if ($note === '') {
            return;
        }

        self::separator($ctx);
        $ctx->encoder->line(ShiftOpenLabels::forLocale($ctx->locale)->note);

        foreach (Layout::wrapText($note, $ctx->width - 2) as $line) {
            $ctx->encoder->line('  '.$line);
        }
    }

    // ─── phiếu 精算 / chuỗi ca ──────────────────────────────────────────

    /**
     * `shift_meta` của phiếu 精算 — khối định danh CĂN PHẢI.
     *
     * Hai hình dạng loại trừ nhau: một chuỗi ca in mã chuỗi + số ca, một ca lẻ
     * in mã quầy + số Z + vị trí trong chuỗi. Số Z là `No.00003` — năm chữ số có
     * đệm 0, vì nó là số sổ liên tục và được đối soát bằng mắt theo cột.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitReportMeta(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shift;

        if ($info === null) {
            return;
        }

        $labels = ShiftLabels::forLocale($ctx->locale);
        $ctx->encoder->align(Escpos::ALIGN_RIGHT);

        if ($info->isChain) {
            // Cắt theo BYTE như Go (`chainShort[:8]`). Mã chuỗi là ULID/UUID
            // toàn ASCII nên byte trùng ký tự; dùng `substr` chứ không
            // `mb_substr` để một mã có ký tự lạ cũng cắt giống hệt ở hai phía,
            // thay vì hai repo ra hai chuỗi khác nhau.
            $chainShort = strlen($info->chainId) > 8 ? substr($info->chainId, 0, 8) : $info->chainId;

            $ctx->encoder->line($labels->chainLabel.' '.$chainShort);
            $ctx->encoder->line($info->shiftCount.$labels->chainShiftUnit);
        } else {
            $code = trim($info->tillCode);

            if ($code !== '') {
                $ctx->encoder->line($labels->till.$code);
            }

            $ctx->encoder->line(sprintf('No.%05d', $info->zNumber));

            if ($info->chainSequence > 0) {
                $ctx->encoder->line($labels->chainShift.$info->chainSequence);
            }
        }

        if ($info->openedAt !== '') {
            $ctx->encoder->line($labels->period.' '.$info->openedAt);
        }

        // Giờ đóng in TRẦN, không tiền tố: nó nằm ngay dưới dòng 対象期間 và cùng
        // căn phải, nên cặp mốc thời gian đọc như một khoảng.
        if ($info->closedAt !== '') {
            $ctx->encoder->line($info->closedAt);
        }

        $phone = trim($info->phone);

        if ($phone !== '') {
            $ctx->encoder->line($labels->phone.' '.$phone);
        }

        $ctx->encoder->align(Escpos::ALIGN_LEFT);
    }

    /**
     * `chain_summary` — từng ca trong chuỗi, kèm quá/thiếu và người chịu trách nhiệm.
     *
     * Chỉ in cho phiếu CHUỖI. Đây là thứ giữ cho một chuỗi bàn giao không đánh
     * mất dấu vết người giữ ngăn kéo TRƯỚC người đóng ca — nếu chỉ có tổng cộng
     * thì một khoản thiếu hụt sẽ mặc định treo lên người cuối cùng.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitChainIndex(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shift;

        if ($info === null || ! $info->isChain || $info->chainIndex === []) {
            return;
        }

        $labels = ShiftLabels::forLocale($ctx->locale);
        self::separator($ctx);

        foreach ($info->chainIndex as $shift) {
            $kind = $shift->kind === 'handover' ? $labels->chainHandover : $labels->chainFinal;

            $ctx->row(
                sprintf('%s%d (%s)', $labels->chainShift, $shift->sequence, $kind),
                self::money($ctx, $shift->gross)
            );

            // `!== 0` chứ không `> 0`: thiếu tiền cũng phải hiện, và đó mới là
            // trường hợp người ta đọc phiếu này để tìm.
            if ($shift->variance !== 0) {
                $ctx->row('  '.$labels->variance, self::money($ctx, $shift->variance));
            }

            if ($shift->operator !== '') {
                $ctx->row('  '.$labels->operator, $shift->operator);
            }
        }
    }

    /**
     * `sales_summary` — 総売上 / 純売上 / 消費税総額.
     *
     * Số lượng món và số khách in trên DÒNG RIÊNG căn phải, không ghép vào dòng
     * tiền: chúng không phải tiền, và xếp chung một cột sẽ đọc như một khoản.
     *
     * Dòng trắng trước 消費税総額 là KHOẢNG TRẮNG, không phải đường kẻ — nó tách
     * cặp tổng/thuần khỏi dòng thuế đúng như phiếu 精算 mẫu. Thay bằng gạch ngang
     * là làm phiếu trông như có thêm một mục.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitSalesSummary(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shift;

        if ($info === null) {
            return;
        }

        $labels = ShiftLabels::forLocale($ctx->locale);

        $right = static function (string $value) use ($ctx): void {
            $ctx->encoder->line(Layout::spaces(max($ctx->width - Layout::displayWidth($value), 0)).$value);
        };

        self::separator($ctx);
        $ctx->row($labels->grossSales, self::money($ctx, $info->grossSales));
        $right($info->itemCount.$labels->itemUnit);
        $ctx->row($labels->netSales, self::money($ctx, $info->netSales));
        $right($info->guestCount.$labels->guestUnit);
        $ctx->encoder->feed(1);
        $ctx->row($labels->taxTotal, self::money($ctx, $info->taxTotal));
    }

    /**
     * `tax_breakdown` — 売上内訳 + 消費税内訳.
     *
     * Hai khối này CÙNG một điều kiện rẽ nhánh, và đó là chủ đích: quán bật chi
     * tiết theo mức thuế thì cả hai khối in theo mức, tắt thì cả hai thu về một
     * dòng. Cho một khối theo mức còn khối kia gộp là ra một tờ giấy mà hai nửa
     * không cộng khớp nhau.
     *
     * Điều kiện là `showTaxBreakdown` VÀ có ảnh chụp thuế theo dòng. Vế thứ hai
     * cần thật: một ca cũ (bán trước plan-043) bật cờ vẫn không có dữ liệu theo
     * mức, và in ra một khối rỗng dưới tiêu đề 消費税内訳 là tệ hơn gộp.
     *
     * Phí phục vụ được nêu RIÊNG trong cả hai khối vì các mức thuế chỉ phủ dòng
     * món — thiếu nó thì cột không cộng ra 純売上 và người đối soát đi tìm chênh
     * lệch không tồn tại.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitTaxBreakdown(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shift;

        if ($info === null) {
            return;
        }

        $labels = ShiftLabels::forLocale($ctx->locale);
        self::separator($ctx);

        $perRate = $info->showTaxBreakdown && $info->taxBreakdown !== [];

        $ctx->encoder->line($labels->salesBreakdown);

        if ($perRate) {
            foreach ($info->taxBreakdown as $line) {
                $ctx->row(
                    '  '.sprintf($labels->rateTarget, TaxLabels::formatRatePercent($line->rate)),
                    self::money($ctx, $line->taxableSales)
                );
            }

            if ($info->serviceCharge !== 0) {
                $ctx->row('  '.$labels->serviceCharge, self::money($ctx, $info->serviceCharge));
            }
        } else {
            $ctx->row('  '.$labels->taxableSales, self::money($ctx, $info->netSales));
        }

        $ctx->encoder->line($labels->taxBreakdown);

        if ($perRate) {
            foreach ($info->taxBreakdown as $line) {
                $ctx->row(
                    '  '.sprintf($labels->rateTarget, TaxLabels::formatRatePercent($line->rate)),
                    self::money($ctx, $line->tax)
                );
            }

            if ($info->serviceChargeTax !== 0) {
                $ctx->row('  '.$labels->serviceChargeTax, self::money($ctx, $info->serviceChargeTax));
            }
        } else {
            $ctx->row('  '.$labels->tax, self::money($ctx, $info->taxTotal));
        }
    }

    /**
     * `tender_summary` — 支払方法, khối DUY NHẤT dịch tên phương thức thanh toán
     * (xem {@see PaymentMethodLabels}).
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitTenderSummary(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shift;

        if ($info === null || ! $info->showPaymentMethods) {
            return;
        }

        $labels = ShiftLabels::forLocale($ctx->locale);
        self::separator($ctx);
        $ctx->encoder->line($labels->paymentMethods);

        foreach ($info->payments as $payment) {
            $ctx->row(
                '  '.PaymentMethodLabels::localize($ctx->locale, $payment->code, $payment->label),
                self::stat($ctx, $payment->count, self::money($ctx, $payment->amount))
            );
        }
    }

    /**
     * `non_cash_change` — 現金以外おつり, LUÔN in số 0.
     *
     * Máy trạm không theo dõi mục này, nên con số là 0 thật chứ không phải chỗ
     * chưa làm xong. Khối vẫn in vì phiếu mẫu có nó, và một kiểm toán viên đặt
     * phiếu của hai quán cạnh nhau phải thấy CÙNG một tập mục — một mục vắng đọc
     * như "quán này không có khoản đó", khác hẳn "khoản đó bằng 0".
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitNonCashChange(PrintRenderContext $ctx, array $block): void
    {
        if ($ctx->data->shift === null) {
            return;
        }

        $labels = ShiftLabels::forLocale($ctx->locale);
        self::separator($ctx);
        $ctx->encoder->line($labels->nonCashChange);
        $ctx->row('  '.$labels->nonCashUnpaid, self::stat($ctx, 0, self::money($ctx, 0)));
        $ctx->row('  '.$labels->nonCashPaid, self::stat($ctx, 0, self::money($ctx, 0)));
    }

    /**
     * `discount_summary` — 割引・割増.
     *
     * Có coupon đặt tên thì liệt kê từng cái; không có thì gộp một dòng 割引. Thứ
     * tự hai nhánh quan trọng: danh sách tên THẮNG tổng gộp, vì tổng gộp là bản
     * dự phòng cho ca cũ chưa lưu tên coupon — in cả hai là đếm đôi.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitDiscounts(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shift;

        if ($info === null) {
            return;
        }

        $labels = ShiftLabels::forLocale($ctx->locale);
        self::separator($ctx);
        $ctx->encoder->line($labels->discounts);

        if ($info->discounts !== []) {
            foreach ($info->discounts as $discount) {
                $ctx->row(
                    '  '.$discount->label,
                    self::stat($ctx, $discount->count, '▲'.self::money($ctx, $discount->amount))
                );
            }
        } elseif ($info->discountTotalAmount > 0) {
            $ctx->row(
                '  '.$labels->discountGeneric,
                self::stat($ctx, $info->discountTotalCount, '▲'.self::money($ctx, $info->discountTotalAmount))
            );
        }

        // Giảm giá / phụ thu theo TỪNG MÓN chưa được mô hình hoá ở máy trạm —
        // in 0 vì cùng lý do với 現金以外おつり.
        $ctx->row('  '.$labels->itemDiscount, self::stat($ctx, 0, self::money($ctx, 0)));
        $ctx->row('  '.$labels->itemSurcharge, self::stat($ctx, 0, self::money($ctx, 0)));
    }

    /**
     * `service_charge` — サービス料, chỉ in khi quán bật.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitServiceCharge(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shift;

        if ($info === null || ! $info->showServiceCharge) {
            return;
        }

        self::separator($ctx);
        $ctx->row(
            ShiftLabels::forLocale($ctx->locale)->serviceCharge,
            self::stat($ctx, 0, self::money($ctx, $info->serviceCharge))
        );
    }

    /**
     * `acct_correction` — 会計修正, chưa theo dõi ở máy trạm nên luôn 0.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitAcctCorrection(PrintRenderContext $ctx, array $block): void
    {
        if ($ctx->data->shift === null) {
            return;
        }

        self::separator($ctx);
        $ctx->row(
            ShiftLabels::forLocale($ctx->locale)->acctCorrection,
            self::stat($ctx, 0, self::money($ctx, 0))
        );
    }

    /**
     * `check_count` — 会計回数.
     *
     * Chỉ có cột ĐẾM, không có cột tiền — nên nó dừng ở {@see COUNT_COL} thay vì
     * gọi {@see stat()}. Ghép thêm một cột tiền rỗng sẽ đẩy con số sang trái và
     * lệch khỏi mọi dòng đếm khác.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitCheckCount(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shift;

        if ($info === null) {
            return;
        }

        $labels = ShiftLabels::forLocale($ctx->locale);
        self::separator($ctx);
        $ctx->row($labels->checkCount, self::padLeft($info->checkCount.$labels->countUnit, self::COUNT_COL));
    }

    /**
     * `cash_movement` — 入出金合計金額.
     *
     * Dòng tổng là HIỆU (thu trừ chi), không phải tổng cộng: nó trả lời "ngăn kéo
     * dày lên hay mỏng đi bao nhiêu ngoài doanh thu", và đó là con số đi vào phép
     * đối chiếu quỹ. Cộng hai chiều lại sẽ ra một số không có nghĩa kế toán nào.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitCashMovement(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shift;

        if ($info === null) {
            return;
        }

        $labels = ShiftLabels::forLocale($ctx->locale);
        self::separator($ctx);
        $ctx->row($labels->cashMovementTotal, self::money($ctx, $info->paidInAmount - $info->paidOutAmount));
        $ctx->row('  '.$labels->paidIn, self::stat($ctx, $info->paidInCount, self::money($ctx, $info->paidInAmount)));
        $ctx->row('  '.$labels->paidOut, self::stat($ctx, $info->paidOutCount, self::money($ctx, $info->paidOutAmount)));
    }

    /**
     * `void_summary` — 伝票削除, tách CHƯA thanh toán và ĐÃ thanh toán.
     *
     * Hai con số này không thay thế nhau được: huỷ một đơn chưa trả tiền là việc
     * vận hành bình thường, huỷ một đơn ĐÃ trả tiền là một lần đảo tiền và là thứ
     * người đối soát tìm.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitVoidSummary(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shift;

        if ($info === null) {
            return;
        }

        $labels = ShiftLabels::forLocale($ctx->locale);
        self::separator($ctx);
        $ctx->encoder->line($labels->voidBills);
        $ctx->row('  '.$labels->voidUnpaid, self::stat($ctx, $info->voidUnpaidCount, self::money($ctx, $info->voidUnpaidAmount)));
        $ctx->row('  '.$labels->voidPaid, self::stat($ctx, $info->voidPaidCount, self::money($ctx, $info->voidPaidAmount)));
    }

    /**
     * `variance` — レジ点検, khối quá/thiếu tiền mặt.
     *
     * Chỉ số DƯƠNG mới được thêm dấu `+`; số âm đã tự mang dấu `-` từ
     * {@see money()}. Thừa tiền không phải chuyện tốt hơn thiếu tiền — cả hai là
     * sai lệch — nên dấu `+` ở đây để phân biệt, không để khen.
     *
     * Người phụ trách in cả khi chưa đặt (rơi về 未設定): một ô trống ở dòng này
     * đọc như "bị cắt mất", còn 未設定 nói rõ là chưa ai nhận.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitDrawerCheck(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shift;

        if ($info === null || ! $info->showDrawerCheck) {
            return;
        }

        $labels = ShiftLabels::forLocale($ctx->locale);
        self::separator($ctx);
        $ctx->encoder->line($labels->drawerCheck);
        $ctx->row('  '.$labels->countedCash, self::money($ctx, $info->countedCash));
        $ctx->row('  '.$labels->expectedCash, self::money($ctx, $info->expectedCash));

        $variance = self::money($ctx, $info->cashVariance);

        if ($info->cashVariance > 0) {
            $variance = '+'.$variance;
        }

        $ctx->row('  '.$labels->variance, $variance);

        $operator = trim($info->operator);

        $ctx->row('  '.$labels->operator, $operator !== '' ? $operator : $labels->operatorNone);
    }

    /**
     * `denomination_table` của phiếu 精算 — bảng mệnh giá cuối ca, KHÔNG có tiêu
     * đề cột (xem {@see emitOpenDenominations}).
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitDenominations(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->shift;

        if ($info === null || ! $info->showDenominations) {
            return;
        }

        $labels = ShiftLabels::forLocale($ctx->locale);
        self::separator($ctx);
        $ctx->encoder->line($labels->denomination);

        foreach ($info->denominations as $line) {
            $ctx->row(
                '  '.self::money($ctx, $line->value),
                self::padLeft($line->quantity.$labels->pieceUnit, self::COUNT_COL)
                .self::padLeft(self::money($ctx, $line->subtotal), self::AMOUNT_COL)
            );
        }
    }

    // ─── helper ─────────────────────────────────────────────────────────

    /**
     * Số tiền của họ shift: đơn vị ĐỨNG SAU, lấy từ tiền tệ của CHÍNH CA ĐÓ.
     *
     * Đối ứng của `(*printRenderCtx).shiftMoney`, và nó CỐ Ý không dùng
     * {@see PrintRenderContext::money()} — xem doc class.
     *
     * Dấu trừ đứng TRƯỚC toàn bộ (`-1,500円`), không phải trước đơn vị: 過不足 có
     * thể âm, và đó là con số người ta đọc phiếu này để tìm.
     */
    private static function money(PrintRenderContext $ctx, int $amount): string
    {
        $unit = self::moneyUnit(self::currency($ctx));

        return $amount < 0
            ? '-'.Layout::formatPrice(-$amount).$unit
            : Layout::formatPrice($amount).$unit;
    }

    /**
     * Tiền tệ của phiếu — ảnh chụp trên chính ca, KHÔNG phải cấu hình hiện tại.
     *
     * plan-046 đóng băng `currency` lên `TillSession` lúc mở ca đúng để một lần
     * admin đổi tiền tệ giữa chừng không viết lại phiếu của ca đã đóng. Đọc
     * `config` ở đây là làm hỏng chính điều đó.
     */
    private static function currency(PrintRenderContext $ctx): string
    {
        if ($ctx->data->shift !== null) {
            return $ctx->data->shift->currency;
        }

        if ($ctx->data->shiftOpen !== null) {
            return $ctx->data->shiftOpen->currency;
        }

        return '';
    }

    /**
     * Hậu tố tiền tệ. JPY (và rỗng) ra glyph 円 như phiếu mẫu; mã khác ra mã ISO
     * có khoảng trắng đứng trước, để con số không bao giờ mập mờ.
     *
     * 円 là kanji nên nó mã hoá GỌN trong Shift_JIS — khác ký hiệu `¥` của
     * {@see PrintRenderContext::money()}, vốn ra byte 0x5C và đọc như dấu `\`
     * nếu ai đó soi output bằng UTF-8.
     */
    private static function moneyUnit(string $currency): string
    {
        return ($currency === '' || strcasecmp($currency, 'JPY') === 0)
            ? '円'
            : ' '.strtoupper($currency);
    }

    /**
     * Khối bên phải "{n}件      {tiền}" dùng chung cho các dòng có ĐẾM kèm TIỀN.
     *
     * Đối ứng của `(*printRenderCtx).shiftStat`.
     */
    private static function stat(PrintRenderContext $ctx, int $count, string $amount): string
    {
        return self::padLeft($count.ShiftLabels::forLocale($ctx->locale)->countUnit, self::COUNT_COL)
            .self::padLeft($amount, self::AMOUNT_COL);
    }

    /**
     * Căn phải trong một cột rộng cố định, đo bằng CỘT hiển thị.
     *
     * Đối ứng của `shiftPadLeft`. Bên Go đây là một hàm RIÊNG, tồn tại song song
     * với `padLeft` của hoá đơn GTGT (`print_vat_invoice.go`) dù thân giống hệt —
     * nên bản PHP giữ nguyên cặp song sinh đó thay vì gộp: hai họ phiếu này tiến
     * hoá độc lập, và gộp chúng là tạo một chỗ mà sửa cho phiếu ca sẽ lặng lẽ
     * dịch cột trên hoá đơn.
     */
    private static function padLeft(string $s, int $width): string
    {
        $displayWidth = Layout::displayWidth($s);

        return $displayWidth >= $width ? $s : Layout::spaces($width - $displayWidth).$s;
    }

    /** Đường kẻ ngắt mục — đối ứng của `(*printRenderCtx).shiftSep`. */
    private static function separator(PrintRenderContext $ctx): void
    {
        $ctx->encoder->line(Layout::dashedLine($ctx->width));
    }

    /**
     * Block có mặt VÀ đang bật — đối ứng của `def.has`.
     */
    private static function hasEnabledBlock(PrintRenderContext $ctx, string $id): bool
    {
        $block = self::blockById($ctx, $id);

        // Không khai `enabled` nghĩa là BẬT: một definition chỉ ĐỊNH VỊ block
        // không phải nhắc lại điều đó.
        return $block !== null && (! array_key_exists('enabled', $block) || $block['enabled'] === true);
    }

    /**
     * Block theo id, KHÔNG lọc `enabled` — đối ứng của `def.block`.
     *
     * @return array<string, mixed>|null
     */
    private static function blockById(PrintRenderContext $ctx, string $id): ?array
    {
        $blocks = $ctx->definition['blocks'] ?? [];

        if (! is_array($blocks)) {
            return null;
        }

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['id'] ?? null) === $id) {
                return $block;
            }
        }

        return null;
    }
}
