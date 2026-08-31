<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 2 (#1932, tiếp #1909) — họ **docs** bên PHP.
 *
 * Đối ứng của `print_renderer_docs.go`. Năm kind, bốn plan:
 *
 * | kind | bề rộng | prologue | epilogue |
 * |---|---:|---|---|
 * | `vat_invoice` | 48 | lề → canh trái | cắt |
 * | `qualified_simplified_invoice` | 48 | lề → canh trái | cắt |
 * | `debt_slip` | 48 | lề → canh trái | **3 dòng** + cắt |
 * | `void_notice` | 48 | **chỉ lề** | **3 dòng** + cắt |
 * | `table_paid` | **42** | **canh trái → lề** | **1 dòng** + cắt |
 *
 * Bốn khác biệt ấy đều nhỏ và đều nhìn như thừa. Chép đúng, không "chuẩn hoá":
 * đây là hình học của những chứng từ quán ĐÃ nộp, và TR-40 nói tái hiện y hệt
 * rồi sửa bằng một lần sửa template có chủ đích, thấy được.
 *
 * ── Cả họ này đo cột bằng MÃ ĐIỂM, không phải bề rộng hiển thị ────────────
 *
 * Hoá đơn GTGT và phiếu ghi nợ được viết trước phiếu bán hàng và đo cột bằng
 * số mã điểm ({@see PrintRenderContext::runeRow()}). Trên một tờ giấy tiếng
 * Nhật đó gần như chắc chắn là sai, nhưng nó là hình học của mọi hoá đơn quán
 * đã nộp. Nhánh NHẬT thì ngược lại, đo bằng CỘT — xem {@see VatInvoiceJa}.
 *
 * ── `qualified_simplified_invoice` KHÔNG chép plan của VAT ────────────────
 *
 * Nó dùng lại NGUYÊN plan đó và chỉ lật một cờ, đúng như Go. Hai chứng từ có
 * cùng danh sách block; khác biệt Nhật–Việt nằm bên trong từng emitter. Viết
 * một plan thứ hai chép y hệt là tạo sẵn chỗ cho hai bản trôi khỏi nhau — và
 * cái trôi ra là một chứng từ thuế.
 *
 * ── Cờ `japaneseDoc` đến từ KIND, không từ locale (#1493) ─────────────────
 *
 * Chỉ `qualified_simplified_invoice` bật. Rẽ theo `locale === 'ja'` là lỗi đã
 * trả giá: quán Việt để giao diện tiếng Nhật in ra chứng từ Nhật.
 *
 * ── Họ này SỞ HỮU `reprint_marker` ───────────────────────────────────────
 *
 * Họ bill chỉ khai block id. Thân nằm ở {@see ReprintMarker}, và thang ba bậc
 * đọc số bản in nằm ở {@see PrintRenderContext::reprintNumber()} — số bản sao
 * của hoá đơn GTGT / phiếu nợ đi trong CHÍNH payload chứng từ, không đi ở ô
 * chung của `PrintRenderData`.
 *
 * ── 30 emitter, 26 hành vi ───────────────────────────────────────────────
 *
 * `store_info` + `title` của hai kind cùng trỏ `emitDocHeader` (4 mục bảng, 1
 * hàm); `footer_text` của `debt_slip` + `table_paid` cùng trỏ
 * {@see AuthoredText}. Port thành 30 hàm riêng là nhân bản hành vi ra nhiều
 * bản sẽ trôi khỏi nhau.
 */
final class DocsKindPlans
{
    /**
     * Cột tên món của bảng hàng = giấy trừ ba cột số cố định
     * (SL 3 + Đơn giá 11 + Thành tiền 11).
     */
    private const VAT_NUMERIC_COLUMNS = 25;

    /** Các trường của khối NGƯỜI MUA khi definition không liệt kê. */
    private const VAT_BUYER_FIELDS = ['customer_name', 'customer_tax_code', 'customer_address'];

    public static function register(PrintKindRegistry $registry): void
    {
        $vat = self::vatPlan();

        $registry->register('vat_invoice', $vat);

        // Lật MỘT cờ trên chính plan đó — xem doc class.
        $registry->register('qualified_simplified_invoice', $vat->withJapaneseDoc());

        $registry->register('void_notice', self::voidNoticePlan());
        $registry->register('debt_slip', self::debtSlipPlan());
        $registry->register('table_paid', self::tablePaidPlan());
    }

    // ─── plan ─────────────────────────────────────────────────────────────

    private static function vatPlan(): PrintKindPlan
    {
        return new PrintKindPlan(
            defaultWidth: 48,
            emitters: [
                'logo' => LogoBlock::emit(...),
                // `store_info` và `title` cùng trỏ MỘT hàm: chúng vẽ chung một
                // dòng "tên quán … TIÊU ĐỀ", và cờ `headerDrawn` quyết cái nào
                // vẽ. Tách thành hai hàm là in ra hai dòng.
                'store_info' => self::emitDocHeader(...),
                'title' => self::emitDocHeader(...),
                'reprint_marker' => self::emitDocReprintMarker(...),
                'invoice_number' => self::emitVatInvoiceNumber(...),
                'customer_header' => self::emitVatParties(...),
                'column_header' => self::emitVatColumnHeader(...),
                'items' => self::emitVatItems(...),
                'subtotal' => self::emitVatSubtotal(...),
                'tax_breakdown' => self::emitVatTaxBreakdown(...),
                'grand_total' => self::emitVatGrandTotal(...),
                'registration_number' => self::emitVatRegistrationNumber(...),
                'payments' => self::emitVatPaymentMethod(...),
                'footer_text' => self::emitVatFooter(...),
            ],
            prologue: static function (PrintRenderContext $ctx): void {
                $ctx->encoder->setLeftMargin($ctx->config->leftMargin($ctx->width));
                $ctx->encoder->align(Escpos::ALIGN_LEFT);
            },
            epilogue: static function (PrintRenderContext $ctx): void {
                // KHÔNG đẩy giấy trước khi cắt, khác ba kind kia: `emitVatFooter`
                // tự để lại 3 dòng trắng ở chân phiếu. Thêm ở đây là phí giấy
                // trên MỖI hoá đơn.
                $ctx->finish();
            },
            japaneseDoc: false,
        );
    }

    private static function voidNoticePlan(): PrintKindPlan
    {
        return new PrintKindPlan(
            defaultWidth: 48,
            emitters: [
                'logo' => LogoBlock::emit(...),
                'void_marker' => self::emitVoidMarker(...),
                'invoice_number' => self::emitVoidInvoiceNumber(...),
                'issued_at' => self::emitVoidVoidedAt(...),
                'order_meta' => self::emitVoidReason(...),
                'footer_text' => self::emitVoidFooter(...),
            ],
            prologue: static function (PrintRenderContext $ctx): void {
                // CHỈ đặt lề — kind này KHÔNG phát lệnh canh trái. Chép nguyên
                // từ Go: biên bản huỷ tự canh giữa phần đầu của nó rồi tự trả
                // canh lề về trái.
                $ctx->encoder->setLeftMargin($ctx->config->leftMargin($ctx->width));
            },
            epilogue: static function (PrintRenderContext $ctx): void {
                $ctx->encoder->feed(3);
                $ctx->finish();
            },
            japaneseDoc: false,
        );
    }

    private static function debtSlipPlan(): PrintKindPlan
    {
        return new PrintKindPlan(
            defaultWidth: 48,
            emitters: [
                'logo' => LogoBlock::emit(...),
                'store_info' => self::emitDocHeader(...),
                'title' => self::emitDocHeader(...),
                'reprint_marker' => self::emitDocReprintMarker(...),
                'issued_at' => self::emitDebtIssuedAt(...),
                'order_meta' => self::emitDebtOrderMeta(...),
                'customer_header' => self::emitDebtCustomerHeader(...),
                'column_header' => self::emitDebtColumnHeader(...),
                'items' => self::emitDebtItems(...),
                'grand_total' => self::emitDebtTotal(...),
                'payments' => self::emitDebtPaid(...),
                'debt_summary' => self::emitDebtOwed(...),
                'debt_signature' => self::emitDebtSignature(...),
                'footer_text' => AuthoredText::emit(...),
            ],
            prologue: static function (PrintRenderContext $ctx): void {
                $ctx->encoder->setLeftMargin($ctx->config->leftMargin($ctx->width));
                $ctx->encoder->align(Escpos::ALIGN_LEFT);
            },
            epilogue: static function (PrintRenderContext $ctx): void {
                $ctx->encoder->feed(3);
                $ctx->finish();
            },
            japaneseDoc: false,
        );
    }

    private static function tablePaidPlan(): PrintKindPlan
    {
        return new PrintKindPlan(
            // 42, không phải 48 — kind duy nhất của họ này hẹp hơn. Bề rộng là
            // hằng của KIND, không suy từ khổ giấy.
            defaultWidth: 42,
            emitters: [
                'logo' => LogoBlock::emit(...),
                'store_info' => self::emitTablePaidStore(...),
                'issued_at' => self::emitTablePaidAt(...),
                'order_meta' => self::emitTablePaidOrderNo(...),
                'paid_summary' => self::emitTablePaidHeadline(...),
                'footer_text' => AuthoredText::emit(...),
            ],
            prologue: static function (PrintRenderContext $ctx): void {
                // Canh trái TRƯỚC rồi mới đặt lề — ngược thứ tự ba kind kia.
                // Chép nguyên từ Go.
                $ctx->encoder->align(Escpos::ALIGN_LEFT);
                $ctx->encoder->setLeftMargin($ctx->config->leftMargin($ctx->width));
            },
            epilogue: static function (PrintRenderContext $ctx): void {
                $ctx->encoder->feed(1);
                $ctx->finish();
            },
            japaneseDoc: false,
        );
    }

    // ─── header dùng chung (hoá đơn GTGT + phiếu ghi nợ) ──────────────────

    /**
     * "tên quán … TIÊU ĐỀ" rồi dòng tên phụ.
     *
     * Hai block (`store_info`, `title`) cùng trỏ vào đây; cái nào đứng trước
     * trong definition thì vẽ, cái kia thành no-op nhờ `headerDrawn`. Bỏ cờ
     * này thì brand bật cả hai sẽ in **hai dòng**.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitDocHeader(PrintRenderContext $ctx, array $block): void
    {
        // TRONG guard: emitter này đăng ký cho CẢ `store_info` lẫn `title`, nên
        // đặt ngoài sẽ in các dòng danh tính hai lần.
        if ($ctx->headerDrawn) {
            return;
        }

        $ctx->headerDrawn = true;

        // Chứng từ Nhật mở đầu bằng đề 領収書 canh giữa. Ràng vào ĐÚNG hoá đơn
        // Nhật để phiếu ghi nợ và bản vi/en không bị chạm tới.
        if ($ctx->data->vat !== null && $ctx->japaneseDoc) {
            VatInvoiceJa::receiptHeader($ctx->encoder);
        }

        // SAU đề 領収書: trên chứng từ Nhật nhãn loại chứng từ đứng trên cùng tờ
        // giấy chứ không phải dưới tên pháp nhân. Formatter cũ đặt đúng thứ tự đó.
        StoreInfoBlock::emitAbove($ctx);

        $narrow = $ctx->width < 42;
        $title = Definition::resolveText(self::blockById($ctx, 'title'), $ctx->locale, $narrow);

        // #1734 — trên chứng từ Nhật, nhãn loại chứng từ là một TUYÊN BỐ PHÁP
        // LÝ: nó chỉ đúng khi có 登録番号 của người bán. Emitter ghi đè chữ do
        // brand soạn ở đúng ca đó — brand không được phép dán "適格簡易請求書"
        // lên một tờ giấy không đủ điều kiện.
        if ($ctx->japaneseDoc && $ctx->data->vat !== null) {
            $title = VatInvoiceJa::qualifiedTitle($ctx->data->vat->sellerRegistrationNumber, $narrow);
        }

        self::renderDocHeader($ctx->encoder, $ctx->width, self::storeName($ctx), $title);

        StoreInfoBlock::emitBelow($ctx);
    }

    /**
     * Bề rộng tiêu đề đo bằng CỘT để tiêu đề tiếng Nhật (適格簡易請求書) căn
     * phải đúng; với các tiêu đề ASCII của mọi phiếu khác thì hai phép đo trùng
     * nhau nên không byte nào của phiếu cũ đổi.
     */
    private static function renderDocHeader(
        Escpos $encoder,
        int $width,
        string $storeName,
        string $title,
    ): void {
        // #1734 — nhãn rỗng thì in mỗi tên cửa hàng, KHÔNG đệm khoảng trắng tới
        // hết dòng. Nhánh dưới sẽ tạo một hàng dài đầy khoảng trắng đuôi, vô
        // hình trên màn hình và lộ ra trên giấy nhiệt.
        if (trim($title) === '') {
            $encoder->bold(true);
            $encoder->line($storeName);
            $encoder->bold(false);

            return;
        }

        $titleWidth = Layout::displayWidth($title);
        $storeWidth = Layout::displayWidth($storeName);

        $encoder->bold(true);

        if ($storeWidth + 1 + $titleWidth <= $width) {
            $encoder->line($storeName.Layout::spaces($width - $storeWidth - $titleWidth).$title);
        } else {
            $encoder->line($storeName);
            $encoder->line(Layout::spaces($width - $titleWidth).$title);
        }

        $encoder->bold(false);

    }

    /**
     * Dấu bản sao, căn phải — 「BAN IN #N」 ở vi/en, 「再印刷 #N」 ở ja.
     *
     * Uỷ quyền cho {@see ReprintMarker} chứ không lặp lại chuỗi: renderer này
     * và formatter cũ phải phát ra CÙNG byte (cổng golden so hai bên), và hai
     * bản của "chữ nào, locale nào, đệm tới bề rộng nào" đúng là cách cổng đó
     * bắt đầu đỏ vì một lý do không ai nhìn thấy.
     *
     * Locale lấy từ CẤU HÌNH quán (`data->config->locale`), không phải locale
     * của lượt render — chép đúng Go.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitDocReprintMarker(PrintRenderContext $ctx, array $block): void
    {
        ReprintMarker::emit($ctx->encoder, $ctx->width, $ctx->reprintNumber(), $ctx->data->config->locale);
    }

    // ─── hoá đơn GTGT ─────────────────────────────────────────────────────

    /**
     * "So HD: {số}" căn trái + thời điểm phát hành căn phải.
     *
     * Hai thứ chia MỘT dòng vật lý, nên block số hoá đơn sở hữu cả hai và
     * `issued_at` không có emitter ở kind này.
     *
     * Thời điểm: ưu tiên mốc đóng băng trên hoá đơn, rơi về `now` do NGƯỜI GỌI
     * cấp. KHÔNG rơi tiếp về đồng hồ máy (Go có, PHP thì không — đó đúng là
     * cách một phiếu Tokyo bị đóng dấu theo giờ máy chủ UTC, #1091). Không có
     * mốc nào thì in mỗi số hoá đơn: **số hoá đơn là định danh chứng từ**, bỏ
     * cả dòng là mất nó.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitVatInvoiceNumber(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->vat;

        if ($info === null) {
            return;
        }

        $issuedAt = $info->issuedAt ?? $ctx->data->now;
        $dateStr = $issuedAt?->format('Y/m/d H:i') ?? '';

        if ($ctx->japaneseDoc) {
            VatInvoiceJa::invoiceNumber($ctx->encoder, $ctx->width, $info->invoiceNo, $dateStr);

            return;
        }

        $noLine = 'So HD: '.$info->invoiceNo;

        if ($dateStr === '') {
            $ctx->encoder->line($noLine);

            return;
        }

        $noWidth = Layout::runeLength($noLine);
        $dateWidth = Layout::runeLength($dateStr);

        if ($noWidth + 1 + $dateWidth <= $ctx->width) {
            $ctx->encoder->line($noLine.Layout::spaces($ctx->width - $noWidth - $dateWidth).$dateStr);

            return;
        }

        $ctx->encoder->line($noLine);
        $ctx->encoder->line(Layout::spaces($ctx->width - $dateWidth).$dateStr);
    }

    /**
     * Cặp NGƯỜI MUA / NGƯỜI BÁN.
     *
     * Hai bên nằm chung MỘT block vì hoá đơn là một tuyên bố VỀ một giao dịch
     * giữa hai bên có tên — một template hiện được bên này mà không hiện bên
     * kia thì không còn là hoá đơn.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitVatParties(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->vat;

        if ($info === null) {
            return;
        }

        if ($ctx->japaneseDoc) {
            VatInvoiceJa::parties($ctx->encoder, $ctx->width, $info);

            return;
        }

        $ctx->encoder->feed(1);
        $ctx->encoder->bold(true);
        $ctx->encoder->line('NGUOI MUA');
        $ctx->encoder->bold(false);

        $fields = array_values(array_filter(
            is_array($block['fields'] ?? null) ? $block['fields'] : [],
            'is_string',
        ));

        if ($fields === []) {
            $fields = self::VAT_BUYER_FIELDS;
        }

        foreach ($fields as $field) {
            $value = match ($field) {
                'customer_name' => trim($info->companyName),
                'customer_tax_code' => trim($info->taxCode),
                'customer_address' => trim($info->billingAddress),
                default => '',
            };

            if ($value === '') {
                continue;
            }

            $ctx->encoder->line(match ($field) {
                'customer_name' => 'Cty:  ',
                'customer_tax_code' => 'MST:  ',
                default => 'DC:   ',
            }.$value);
        }

        $ctx->encoder->feed(1);
        $ctx->encoder->bold(true);
        $ctx->encoder->line('NGUOI BAN');
        $ctx->encoder->bold(false);
        $ctx->encoder->line(self::storeName($ctx));

        // #1224 — mã số thuế người bán đứng cạnh tên người bán, đối xứng với
        // khối người mua ở trên.
        $registration = trim($info->sellerRegistrationNumber);

        if ($registration !== '') {
            $ctx->encoder->line('MST:  '.$registration);
        }
    }

    /** @param array<string, mixed> $block */
    private static function emitVatColumnHeader(PrintRenderContext $ctx, array $block): void
    {
        if ($ctx->japaneseDoc) {
            VatInvoiceJa::columnHeader($ctx->encoder, $ctx->width);

            return;
        }

        $ctx->encoder->feed(1);
        $ctx->encoder->line(Layout::dashedLine($ctx->width));
        $ctx->encoder->feed(1);

        foreach (self::vatColumnHeaderLines($ctx->width) as $line) {
            $ctx->encoder->line($line);
        }

        $ctx->encoder->line(Layout::dashedLine($ctx->width));
    }

    /**
     * Bảng cột của bảng hàng hoá đơn GTGT — MỘT dòng khi vừa giấy, HAI khi không.
     *
     * Đối ứng của `vatColumnHeaderLines` bên Go.
     *
     * Cột tên món = giấy trừ {@see self::VAT_NUMERIC_COLUMNS}, tức ở 58mm còn
     * **7 cột** — thiếu đúng một cột so với chữ "San pham". Dòng MÓN thì luôn ổn
     * (tên bị cắt theo cột); chỉ chữ trong bảng cột tràn, và tràn đúng 1 cột.
     *
     * Ở khổ hẹp: nhãn đứng riêng một dòng, ba nhãn số vẫn nằm đúng trên những
     * con số mà chúng gọi tên.
     *
     * Rút gọn nhãn đã bị loại: cắt thì ra "San pha", còn bỏ "Don gia" cho đủ chỗ
     * là gỡ một tiêu đề cột khỏi một chứng từ thuế. Yêu cầu của #2035 là không
     * được cắt mất thông tin, nên biến duy nhất còn tự do là chỗ ngắt dòng.
     *
     * @return list<string>
     */
    private static function vatColumnHeaderLines(int $width): array
    {
        $nameWidth = $width - self::VAT_NUMERIC_COLUMNS;
        $numeric = Layout::padLeft('SL', 3)
            .Layout::padLeft('Don gia', 11)
            .Layout::padLeft('Thanh tien', 11);

        if (! Layout::isNarrow($width) || Layout::displayWidth('San pham') <= $nameWidth) {
            return [Layout::padRight('San pham', $nameWidth).$numeric];
        }

        return ['San pham', Layout::spaces(max($nameWidth, 0)).$numeric];
    }

    /** @param array<string, mixed> $block */
    private static function emitVatItems(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->vat;

        if ($info === null) {
            return;
        }

        if ($ctx->japaneseDoc) {
            VatInvoiceJa::itemLines($ctx->encoder, $ctx->width, $info->items, self::vatCurrency($ctx));
        } else {
            self::vatItemLines($ctx->encoder, $ctx->width, self::vatNameWidth($ctx), $info->items);
        }

        $ctx->encoder->line(Layout::dashedLine($ctx->width));
    }

    /** @param array<string, mixed> $block */
    private static function emitVatSubtotal(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->vat;

        if ($info === null) {
            return;
        }

        if ($ctx->japaneseDoc) {
            VatInvoiceJa::subtotal($ctx->encoder, $ctx->width, $info, self::vatCurrency($ctx));

            return;
        }

        // #1169 — phiếu chia đều / chia theo tiền vẫn LIỆT KÊ cả đơn (Σ món >
        // Tạm tính) → tiêu đề là "Tong mon" (toàn đơn), không phải tạm tính.
        if ($info->isAmountSplit) {
            $ctx->runeRow('Tong mon', self::vatCurrency($ctx).Layout::formatPrice(VatInvoiceJa::itemLinesSum($info)));

            return;
        }

        $ctx->runeRow('Tam tinh', self::vatCurrency($ctx).Layout::formatPrice($info->subtotal));
    }

    /**
     * Khối thuế theo từng mức đã đóng băng lúc phát hành, rơi về dòng "Thue X %"
     * đơn mức cho hoá đơn phát hành trước khi có breakdown.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitVatTaxBreakdown(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->vat;

        if ($info === null) {
            return;
        }

        if ($ctx->japaneseDoc) {
            VatInvoiceJa::taxBreakdown($ctx->encoder, $ctx->width, $info, self::vatCurrency($ctx));

            return;
        }

        // #1169 — phiếu chia theo tiền chỉ in "Tổng món" / "Khách thanh toán
        // (phần chia)". Khối theo mức (cơ sở chịu thuế là phần chia, không phải
        // món đang liệt kê) sẽ đọc thành một con số thứ BA mâu thuẫn.
        if ($info->isAmountSplit) {
            return;
        }

        $currency = self::vatCurrency($ctx);

        if ($info->taxBreakdown !== []) {
            // Nhãn theo locale của CHÍNH HOÁ ĐƠN, không phải locale của lượt
            // render: hoá đơn đã phát hành mang ngôn ngữ nó được phát hành, và
            // in lại ở một máy đặt locale khác không được đổi chữ trên chứng từ.
            //
            // #2057 — riêng khối này đo VÀ căn bằng CỘT HIỂN THỊ, khác các hàng
            // `runeRow` quanh nó: vì nhãn theo locale hoá đơn, một hoá đơn phát
            // hành ở `ja` mang chữ toàn khổ (10%対象 / 内消費税) vào đây, và đếm
            // mã điểm hụt 6 cột nên hàng tràn Ở MỌI khổ giấy (32→38, 42→48,
            // 48→54). Các hàng còn lại của chân GTGT giữ `runeRow`: nhãn của
            // chúng là ASCII cứng, hai phép đo cho cùng kết quả. Phải khớp
            // từng byte với `emitVatTaxBreakdown` phía Go (TR-40).
            $rows = VatTaxRateRows::layout(
                $ctx->width,
                Layout::displayWidth(...),
                '',
                TaxLabels::forLocale($info->locale),
                $currency,
                $info->taxBreakdown,
            );

            foreach ($rows as [$label, $value]) {
                $ctx->row($label, $value);
            }

            return;
        }

        if ($info->taxAmount <= 0) {
            return;
        }

        $label = $info->taxRatePercent > 0 ? 'Thue '.$info->taxRatePercent.' %' : 'Thue';

        $ctx->runeRow($label, $currency.Layout::formatPrice($info->taxAmount));
    }

    /** @param array<string, mixed> $block */
    private static function emitVatGrandTotal(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->vat;

        if ($info === null) {
            return;
        }

        if ($ctx->japaneseDoc) {
            VatInvoiceJa::grandTotal($ctx->encoder, $ctx->width, $info, self::vatCurrency($ctx));

            return;
        }

        $ctx->encoder->bold(true);
        // #1169 — trên phiếu chia theo tiền, tổng cộng là số KHÁCH NÀY trả.
        $ctx->runeRow(
            $info->isAmountSplit ? 'Khach thanh toan (phan chia)' : 'Tong cong',
            self::vatCurrency($ctx).Layout::formatPrice($info->total),
        );
        $ctx->encoder->bold(false);

        if ($info->isAmountSplit) {
            $ctx->encoder->line('(Hoa don phan chia)');
        }
    }

    /**
     * CỐ Ý im lặng từ #1224.
     *
     * Mã số thuế người bán giờ in trong khối NGƯỜI BÁN ({@see emitVatParties}),
     * nơi một hoá đơn GTGT Việt Nam mong đợi nó — cạnh tên người bán và đối
     * xứng với người mua. Phát ở đây nữa là in cùng con số hai lần lên tờ giấy.
     *
     * BLOCK vẫn được giữ chứ không xoá khỏi định nghĩa template. Các định nghĩa
     * ấy được soi từng byte với workstation (cổng parity chéo repo) và là thứ
     * mà overlay do brand publish được soạn dựa trên; xoá một block id là đổi
     * hợp đồng đó cho cả bảy kind cùng lúc, để bỏ đi một thứ không tốn gì khi
     * giữ. Một block khoá mà không vẽ gì thì không in ra gì.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitVatRegistrationNumber(PrintRenderContext $ctx, array $block): void {}

    /** @param array<string, mixed> $block */
    private static function emitVatPaymentMethod(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->vat;

        if ($info === null) {
            return;
        }

        if ($ctx->japaneseDoc) {
            VatInvoiceJa::payment($ctx->encoder, $info);

            return;
        }

        $method = trim($info->paymentMethod);

        if ($method === '') {
            return;
        }

        // In NGUYÊN VĂN, không dịch: chuỗi này đến từ cấu hình phương thức
        // thanh toán của quán, và dịch nó ở đây sẽ làm chứng từ khác báo cáo ca.
        $ctx->runeRow('Hinh thuc TT', $method);
    }

    /**
     * Cột chữ ký + câu miễn trừ pháp lý.
     *
     * v1 KHÔNG phải hoá đơn điện tử có chữ ký CQT (DESIGN Q-decision-11), và
     * câu miễn trừ nói đúng điều đó chính là lý do block này không thể là chữ
     * do brand soạn.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitVatFooter(PrintRenderContext $ctx, array $block): void
    {
        if ($ctx->japaneseDoc) {
            VatInvoiceJa::footer(
                $ctx->encoder,
                $ctx->width,
                self::storeName($ctx),
                $ctx->data->vat?->sellerRegistrationNumber ?? '',
            );

            return;
        }

        $ctx->encoder->feed(2);

        $gap = max($ctx->width - Layout::runeLength('Ben mua') - Layout::runeLength('Ben ban'), 1);

        $ctx->encoder->line('Ben mua'.Layout::spaces($gap).'Ben ban');
        $ctx->encoder->feed(3);

        $half = intdiv($ctx->width - 1, 2);

        $ctx->encoder->line(
            str_repeat('_', max($half, 0)).' '.str_repeat('_', max($ctx->width - $half - 1, 0))
        );

        $ctx->encoder->feed(2);
        $ctx->encoder->align(Escpos::ALIGN_CENTER);
        $ctx->encoder->line('HOA DON THAM CHIEU NOI BO');

        foreach (Layout::vatDisclaimerLines($ctx->width) as $line) {
            $ctx->encoder->line($line);
        }

        $ctx->encoder->align(Escpos::ALIGN_LEFT);
        $ctx->encoder->feed(3);
    }

    /*
     * #2062 — ba hằng của câu miễn trừ + `vatDisclaimerLines` đã CHUYỂN sang
     * {@see Layout}, nơi Go cũng để chúng (`print_narrow.go`).
     *
     * Lý do chuyển: `red_invoice` thuộc họ BILL ({@see BillKindPlans}) và giờ
     * cũng phải in câu này. Để chúng `private` ở đây thì họ bill hoặc phải chép
     * lại câu, hoặc phải gọi ngược sang họ chứng từ — cách một lời phủ nhận pháp
     * lý tồn tại ở hai bản và chỉ một bản được sửa.
     */

    // ─── biên bản huỷ hoá đơn ─────────────────────────────────────────────

    /**
     * Block DUY NHẤT trên phiếu này không bao giờ tắt được (TR-18): một biên
     * bản huỷ không nói rằng nó là biên bản huỷ thì nó là một cái biên lai.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitVoidMarker(PrintRenderContext $ctx, array $block): void
    {
        $ctx->encoder->align(Escpos::ALIGN_CENTER);
        $ctx->encoder->bold(true);
        $ctx->encoder->line('BIEN BAN HUY HOA DON');
        $ctx->encoder->bold(false);
        $ctx->encoder->feed(1);
        $ctx->encoder->align(Escpos::ALIGN_LEFT);
    }

    /** @param array<string, mixed> $block */
    private static function emitVoidInvoiceNumber(PrintRenderContext $ctx, array $block): void
    {
        if ($ctx->data->vat === null) {
            return;
        }

        $ctx->encoder->line('So HD bi huy: '.$ctx->data->vat->invoiceNo);
    }

    /** @param array<string, mixed> $block */
    private static function emitVoidVoidedAt(PrintRenderContext $ctx, array $block): void
    {
        if ($ctx->data->voidedAt === null) {
            return;
        }

        $ctx->encoder->line('Thoi diem huy: '.$ctx->data->voidedAt->format('Y/m/d H:i'));
    }

    /** @param array<string, mixed> $block */
    private static function emitVoidReason(PrintRenderContext $ctx, array $block): void
    {
        $reason = trim($ctx->data->voidReason);

        if ($reason === '') {
            return;
        }

        $ctx->encoder->line('Ly do: '.$reason);
    }

    /** @param array<string, mixed> $block */
    private static function emitVoidFooter(PrintRenderContext $ctx, array $block): void
    {
        $ctx->encoder->feed(2);
        $ctx->encoder->align(Escpos::ALIGN_CENTER);
        $ctx->encoder->line('KHACH HANG NHAN BIET HOA DON DA HUY');
        $ctx->encoder->align(Escpos::ALIGN_LEFT);
    }

    // ─── phiếu ghi nợ ─────────────────────────────────────────────────────

    /**
     * Ngày giờ căn trái + mã đơn căn phải, chung một dòng.
     *
     * Không có `now` thì in mỗi mã đơn (ô ngày để trống) — mã đơn là thứ đối
     * chiếu được công nợ, còn một mốc thời gian do renderer bịa ra là lỗi #1091.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitDebtIssuedAt(PrintRenderContext $ctx, array $block): void
    {
        if ($ctx->data->order === null) {
            return;
        }

        $dateStr = $ctx->data->now?->format('Y/m/d H:i') ?? '';
        $code = '#'.self::orderCodeSuffix($ctx->data->order->orderCode);
        $dateWidth = max($ctx->width - Layout::runeLength($code), 1);

        $ctx->encoder->line(Layout::padRight($dateStr, $dateWidth).$code);
    }

    /** @param array<string, mixed> $block */
    private static function emitDebtOrderMeta(PrintRenderContext $ctx, array $block): void
    {
        $order = $ctx->data->order;

        if ($order === null) {
            return;
        }

        $ctx->encoder->feed(1);
        $ctx->runeRow('So HD', self::orderCodeSuffix($order->orderCode));
        $ctx->runeRow('Ban', $order->tableNumber !== '' ? $order->tableNumber : '-');
    }

    /** @param array<string, mixed> $block */
    private static function emitDebtCustomerHeader(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->debt;

        if ($info === null) {
            return;
        }

        $name = trim($info->customerName);

        // "-" chứ không phải bỏ dòng: một phiếu ghi nợ KHÔNG có tên người mắc
        // nợ vẫn phải cho thấy rằng ô đó trống, để thu ngân điền tay.
        $ctx->runeRow('Khach hang', $name !== '' ? $name : '-');

        $phone = trim($info->customerPhone);

        if ($phone !== '') {
            $ctx->runeRow('SDT', $phone);
        }

        $taxCode = trim($info->customerTaxCode);

        if ($taxCode !== '') {
            $ctx->runeRow('MST', $taxCode);
        }
    }

    /** @param array<string, mixed> $block */
    private static function emitDebtColumnHeader(PrintRenderContext $ctx, array $block): void
    {
        $header = Layout::columnHeaderText(Definition::resolveText($block, $ctx->locale, $ctx->width < 42));

        $ctx->separatorBand();
        $ctx->encoder->line(
            Layout::padRight($header['left'], $ctx->width - Layout::runeLength($header['right'])).$header['right']
        );
        $ctx->encoder->feed(1);
    }

    /**
     * Dòng món, KHÔNG có dấu ※ thuế suất giảm: PHIẾU GHI NỢ là bản ghi công nợ,
     * không phải biên lai インボイス.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitDebtItems(PrintRenderContext $ctx, array $block): void
    {
        $priceColWidth = ItemLines::priceColumnWidth($ctx->data->items, $ctx->config->currencySymbol());

        foreach ($ctx->data->items as $item) {
            ItemLines::emit(
                $ctx->encoder,
                $ctx->width,
                $priceColWidth,
                $item,
                $ctx->config->currencySymbol(),
                false,
                $ctx->locale,
                $ctx->labels->notePrefix,
            );
        }

        $ctx->separatorBand();
    }

    /** @param array<string, mixed> $block */
    private static function emitDebtTotal(PrintRenderContext $ctx, array $block): void
    {
        $ctx->encoder->bold(true);
        $ctx->runeRow('Tong', $ctx->money($ctx->data->total));
        $ctx->encoder->bold(false);
    }

    /** @param array<string, mixed> $block */
    private static function emitDebtPaid(PrintRenderContext $ctx, array $block): void
    {
        $ctx->runeRow('Da thanh toan', $ctx->money($ctx->data->paidShown));
    }

    /** @param array<string, mixed> $block */
    private static function emitDebtOwed(PrintRenderContext $ctx, array $block): void
    {
        $ctx->encoder->bold(true);
        $ctx->runeRow('GHI NO', $ctx->money(self::debtAmount($ctx)));
        $ctx->encoder->bold(false);
    }

    /**
     * Số ghi nợ. KHÔNG khai ⇒ cả hoá đơn vào sổ nợ — chép đúng `FormatDebtSlip`.
     *
     * Rơi về 0 ở đây sẽ in ra "GHI NO ¥0" trên một tờ phiếu nợ, tức một chứng
     * từ nói rằng khách không nợ gì.
     */
    private static function debtAmount(PrintRenderContext $ctx): int
    {
        $debt = $ctx->data->debt;

        return $debt !== null && $debt->debtAmount > 0 ? $debt->debtAmount : $ctx->data->total;
    }

    /** @param array<string, mixed> $block */
    private static function emitDebtSignature(PrintRenderContext $ctx, array $block): void
    {
        $ctx->encoder->feed(2);
        $ctx->encoder->line('Chu ky khach hang:');
        $ctx->encoder->feed(2);
        $ctx->encoder->line(str_repeat('_', 25));
        $ctx->encoder->feed(1);
        $ctx->encoder->line('Khach hang xac nhan da nhan no');
    }

    // ─── giấy báo bàn đã thanh toán ───────────────────────────────────────

    /** @param array<string, mixed> $block */
    private static function emitTablePaidStore(PrintRenderContext $ctx, array $block): void
    {
        StoreInfoBlock::emitAbove($ctx);

        $name = trim($ctx->config->storeName);

        // KHÔNG rơi về "Store" như header hoá đơn: đây là giấy nội bộ cho chạy
        // bàn, và một dòng "Store" bịa ra không giúp ai.
        if ($name === '') {
            return;
        }

        $ctx->encoder->bold(true);
        $ctx->encoder->line($name);
        $ctx->encoder->bold(false);

        StoreInfoBlock::emitBelow($ctx);
    }

    /** @param array<string, mixed> $block */
    private static function emitTablePaidAt(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->tablePaid;

        if ($info === null) {
            return;
        }

        // Chuỗi ĐÃ định dạng ở nơi biết múi giờ chi nhánh — tầng in không đụng
        // vào (#1091).
        $paidAt = trim($info->paidAt);

        if ($paidAt === '') {
            return;
        }

        $ctx->encoder->line($paidAt);
    }

    /** @param array<string, mixed> $block */
    private static function emitTablePaidOrderNo(PrintRenderContext $ctx, array $block): void
    {
        $info = $ctx->data->tablePaid;

        if ($info === null) {
            return;
        }

        $code = self::orderCodeSuffix(trim($info->orderCode));

        if ($code === '') {
            return;
        }

        // `row` (bề rộng hiển thị), KHÔNG phải `runeRow`: kind này viết sau và
        // đo bằng cột, khác hoá đơn GTGT / phiếu nợ. Nhãn ja là 「伝票」 nên
        // khác biệt hiện ra ngay ở locale mặc định.
        $ctx->row(TablePaidLabels::forLocale($ctx->locale)->order.':', $code);
    }

    /**
     * Cả tờ giấy tồn tại vì dòng này: đậm, cao gấp đôi, một hàng mà người chạy
     * bàn đi ngang đọc được.
     *
     * @param  array<string, mixed>  $block
     */
    private static function emitTablePaidHeadline(PrintRenderContext $ctx, array $block): void
    {
        $labels = TablePaidLabels::forLocale($ctx->locale);

        // Tiêu đề lấy từ block `title` của DEFINITION nếu brand có soạn — dù
        // kind này không đăng ký emitter cho `title` (nó không tự vẽ ra dòng
        // nào). Không có thì dùng nhãn catalog.
        $title = Definition::resolveText(self::blockById($ctx, 'title'), $ctx->locale, $ctx->width < 42);

        if ($title === '') {
            $title = $labels->title;
        }

        $table = '-';

        if ($ctx->data->tablePaid !== null && trim($ctx->data->tablePaid->tableNumber) !== '') {
            $table = trim($ctx->data->tablePaid->tableNumber);
        }

        $ctx->encoder->line(Layout::dashedLine($ctx->width));
        $ctx->encoder->bold(true);
        $ctx->encoder->size(Escpos::DOUBLE_HEIGHT);
        $ctx->encoder->line($title.' '.$labels->table.' '.$table);
        $ctx->encoder->size(Escpos::NORMAL_SIZE);
        $ctx->encoder->bold(false);
    }

    // ─── helper riêng của họ docs ─────────────────────────────────────────

    /** Quán chưa đặt tên vẫn phải có một dòng đầu — chép đúng Go. */
    private static function storeName(PrintRenderContext $ctx): string
    {
        return $ctx->config->storeName !== '' ? $ctx->config->storeName : 'Store';
    }

    /**
     * Đơn vị tiền ĐÃ ĐÓNG BĂNG lên hoá đơn lúc phát hành, ưu tiên hơn cấu hình
     * đang sống của quán: in lại hoá đơn tháng trước mà lấy tiền tệ hiện tại là
     * âm thầm đổi mệnh giá một chứng từ đã nộp thuế.
     */
    private static function vatCurrency(PrintRenderContext $ctx): string
    {
        $frozen = $ctx->data->vat?->currencyPrefix ?? '';

        return $frozen !== '' ? $frozen : $ctx->config->currencySymbol();
    }

    private static function vatNameWidth(PrintRenderContext $ctx): int
    {
        return $ctx->width - self::VAT_NUMERIC_COLUMNS;
    }

    /**
     * Thân bảng hàng của hoá đơn GTGT: mỗi dòng một món (tên / SL / Đơn giá /
     * Thành tiền), rồi thụt vào dưới nó là biến thể đã chọn, từng topping, và
     * ghi chú của dòng.
     *
     * Cắt tên theo MÃ ĐIỂM nhưng đệm theo CỘT — chép đúng Go. Hai phép đo trong
     * một hàm nhìn như lỗi, nhưng đây là hình học đã nằm trên giấy (TR-40).
     *
     * @param  list<VatInvoiceLine>  $items
     */
    private static function vatItemLines(Escpos $encoder, int $width, int $nameWidth, array $items): void
    {
        foreach ($items as $item) {
            $name = $item->name !== '' ? $item->name : '-';

            if (Layout::runeLength($name) > $nameWidth) {
                $name = mb_substr($name, 0, max($nameWidth, 0), 'UTF-8');
            }

            $encoder->line(
                Layout::padRight($name, $nameWidth)
                .Layout::padLeft((string) $item->quantity, 3)
                .Layout::padLeft(Layout::formatPrice($item->unitPrice), 11)
                .Layout::padLeft(Layout::formatPrice($item->lineTotal), 11)
            );

            $variant = trim($item->variantName);

            if ($variant !== '') {
                self::vatIndentLine($encoder, $width, '   -- '.$variant);
            }

            foreach ($item->toppings as $topping) {
                if ($topping->name === '') {
                    continue;
                }

                $label = '   —— '.$topping->name;

                if ($topping->quantity > 1) {
                    $label .= ' ×'.$topping->quantity;
                }

                $price = Layout::formatPrice($topping->unitPrice * max($topping->quantity, 1));
                $labelWidth = $width - Layout::runeLength($price);

                if (Layout::runeLength($label) > $labelWidth) {
                    $label = mb_substr($label, 0, max($labelWidth, 0), 'UTF-8');
                }

                $encoder->line(Layout::padRight($label, $labelWidth).$price);
            }

            $note = trim($item->note);

            if ($note !== '') {
                self::vatIndentLine($encoder, $width, '   Ghi chu: '.$note);
            }
        }
    }

    /** Một dòng con thụt vào, cắt theo bề rộng giấy để không bao giờ cuộn dòng. */
    private static function vatIndentLine(Escpos $encoder, int $width, string $s): void
    {
        if (Layout::runeLength($s) > $width) {
            $s = mb_substr($s, 0, max($width, 0), 'UTF-8');
        }

        $encoder->line($s);
    }

    /**
     * Đoạn cuối của mã đơn sau dấu "-": "WS-019e-20260608-004" → "004". Không
     * có dấu "-" thì trả nguyên mã.
     */
    private static function orderCodeSuffix(string $code): string
    {
        $at = strrpos($code, '-');

        return $at !== false && $at + 1 < strlen($code) ? substr($code, $at + 1) : $code;
    }

    /**
     * Block theo id trong definition — đối ứng `def.block(...)`.
     *
     * Trả mảng rỗng khi không có: {@see Definition::resolveText} xử lý được và
     * trả chuỗi rỗng, đúng như Go trả con trỏ nil rồi `text()` trả "".
     *
     * @return array<string, mixed>
     */
    private static function blockById(PrintRenderContext $ctx, string $id): array
    {
        foreach ($ctx->definition['blocks'] ?? [] as $block) {
            if (is_array($block) && ($block['id'] ?? null) === $id) {
                return $block;
            }
        }

        return [];
    }
}
