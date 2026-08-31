<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 2 (#1932) — layout NHẬT của hoá đơn (適格簡易請求書).
 *
 * Đối ứng của `print_vat_invoice_ja.go`. Khi cờ `japaneseDoc` bật (thuộc tính
 * của KIND, không phải của locale — #1493), phiếu GTGT tiếng Việt được thay
 * bằng layout này: đề mục 【お客様】/【明細】, tên món + biến thể + トッピング +
 * 備考, KHÔNG có khối chữ ký 買い手/売り手, và danh tính người bán dồn xuống một
 * dòng 発行 gọn ở chân phiếu.
 *
 * ── Cả file này đo bằng CỘT, không phải mã điểm ──────────────────────────
 *
 * Ngược với phần thân hoá đơn GTGT tiếng Việt (`runeRow`). Với nhãn tiếng Nhật,
 * đếm mã điểm sẽ đẩy MỌI con số căn phải ra khỏi mép giấy và làm dòng bị cuộn —
 * 「合計」 là 2 mã điểm nhưng 4 cột. Đây là lý do hai phép đo cùng tồn tại và
 * không được "chuẩn hoá" thành một.
 */
final class VatInvoiceJa
{
    private const TITLE = '適格簡易請求書';

    private const TITLE_NARROW = '簡易請求書';

    /**
     * Nhãn loại chứng từ — một TUYÊN BỐ PHÁP LÝ, không phải chữ trang trí
     * (#1734).
     *
     * Theo インボイス制度, thứ làm một tờ giấy trở thành 適格簡易請求書 là 登録番号
     * của NGƯỜI BÁN. Có số ⇒ tờ giấy đúng là chứng từ đủ điều kiện và người mua
     * khấu trừ được thuế đầu vào. KHÔNG có số ⇒ dán nhãn ấy lên là nói sai, nên
     * nhãn biến mất và tờ giấy chỉ là 領収書 — đúng như tiêu đề canh giữa ở đầu
     * phiếu vốn đã nói.
     *
     * Đây là lý do nhãn KHÔNG thể do brand tự soạn: emitter ghi đè chữ trong
     * definition ở đúng ca này.
     */
    public static function qualifiedTitle(string $sellerRegistration, bool $narrow): string
    {
        if (trim($sellerRegistration) === '') {
            return '';
        }

        return $narrow ? self::TITLE_NARROW : self::TITLE;
    }

    /**
     * Đề 領収書 canh giữa mà một biên lai Nhật mở đầu, nằm TRÊN dòng tên
     * quán/tiêu đề. Tự khôi phục canh trái mà `renderDocHeader` cần ngay sau đó.
     */
    public static function receiptHeader(Escpos $encoder): void
    {
        $encoder->align(Escpos::ALIGN_CENTER);
        $encoder->bold(true);
        $encoder->line('領収書');
        $encoder->bold(false);
        $encoder->align(Escpos::ALIGN_LEFT);
    }

    /** "nhãn <đệm> giá trị", căn phải theo CỘT. */
    public static function row(Escpos $encoder, int $width, string $label, string $value): void
    {
        $gap = $width - Layout::displayWidth($label) - Layout::displayWidth($value);

        if ($gap < 1) {
            $gap = 1;
        }

        $encoder->line($label.Layout::spaces($gap).$value);
    }

    /**
     * Cắt còn nhiều nhất `$width` CỘT (bỏ nguyên ký tự ở đuôi cho tới khi vừa)
     * để tên món / biến thể / ghi chú dài không bao giờ cuộn dòng.
     */
    public static function truncate(string $s, int $width): string
    {
        if ($width < 0) {
            $width = 0;
        }

        if (Layout::displayWidth($s) <= $width) {
            return $s;
        }

        $chars = Layout::chars($s);

        while ($chars !== [] && Layout::displayWidth(implode('', $chars)) > $width) {
            array_pop($chars);
        }

        return implode('', $chars);
    }

    /**
     * "No.{số}" căn trái, thời điểm phát hành căn phải.
     *
     * `$dateStr` rỗng ⇒ chỉ in số hoá đơn. Bên Go trường hợp này không tới
     * được (nó lấp bằng đồng hồ máy); bên PHP thì `now` do NGƯỜI GỌI cấp và có
     * thể vắng, và **số hoá đơn quan trọng hơn mốc thời gian** — bỏ cả dòng sẽ
     * làm mất định danh của chứng từ, còn in một mốc do renderer bịa ra chính
     * là lỗi #1091.
     */
    public static function invoiceNumber(Escpos $encoder, int $width, string $invoiceNo, string $dateStr): void
    {
        $noLine = 'No.'.$invoiceNo;

        if ($dateStr === '') {
            $encoder->line($noLine);

            return;
        }

        if (Layout::displayWidth($noLine) + 1 + Layout::displayWidth($dateStr) <= $width) {
            $encoder->line($noLine.Layout::spaces($width - Layout::displayWidth($noLine) - Layout::displayWidth($dateStr)).$dateStr);

            return;
        }

        $encoder->line($noLine);
        $encoder->line(Layout::spaces($width - Layout::displayWidth($dateStr)).$dateStr);
    }

    /**
     * Khối 【お客様】. CHỈ người mua — danh tính người bán dồn xuống chân phiếu
     * ({@see footer}), vì một biên lai Nhật nêu người mua ở đầu và người phát
     * hành ở cuối, khác khối hai bên của phiếu GTGT Việt.
     */
    public static function parties(Escpos $encoder, int $width, VatInvoiceInfo $info): void
    {
        $encoder->feed(1);
        $encoder->bold(true);
        $encoder->line('【お客様】');
        $encoder->bold(false);

        $buyer = trim($info->buyerName);
        $company = trim($info->companyName);
        $taxCode = trim($info->taxCode);
        $billing = trim($info->billingAddress);

        if ($buyer !== '') {
            $encoder->line(' 宛名: '.$buyer);
        } elseif ($company === '' && $taxCode === '' && $billing === '') {
            // Khách lẻ, không gõ gì → một dòng gạch để viết tay, giống phiếu VI.
            $underline = max($width - Layout::displayWidth(' 宛名: '), 1);
            $encoder->line(' 宛名: '.str_repeat('_', $underline));
        }

        if ($company !== '') {
            $encoder->line(' 会社: '.$company);
        }

        if ($taxCode !== '') {
            $encoder->line(' 登録番号: '.$taxCode);
        }

        if ($billing !== '') {
            $encoder->line(' 住所: '.$billing);
        }
    }

    /** Đề 【明細】 + đường kẻ trên. */
    public static function columnHeader(Escpos $encoder, int $width): void
    {
        $encoder->feed(1);
        $encoder->bold(true);
        $encoder->line('【明細】');
        $encoder->bold(false);
        $encoder->line(Layout::dashedLine($width));
    }

    /**
     * Thân 【明細】: tên món (đứng riêng một dòng khi có biến thể, ngược lại đi
     * chung với số lượng/thành tiền), biến thể kèm số lượng + tiền, từng topping
     * kèm phụ thu, rồi ghi chú.
     *
     * @param  list<VatInvoiceLine>  $items
     */
    public static function itemLines(Escpos $encoder, int $width, array $items, string $currency): void
    {
        foreach ($items as $item) {
            $name = trim($item->name);

            if ($name === '') {
                $name = '-';
            }

            $variant = trim($item->variantName);
            $qtyAmount = $item->quantity.'点  '.$currency.Layout::formatPrice($item->lineTotal);

            if ($variant !== '') {
                $encoder->line(' '.self::truncate($name, $width - 1));
                self::row(
                    $encoder,
                    $width,
                    '   '.self::truncate($variant, $width - Layout::displayWidth($qtyAmount) - 4),
                    $qtyAmount,
                );
            } else {
                self::row(
                    $encoder,
                    $width,
                    ' '.self::truncate($name, $width - Layout::displayWidth($qtyAmount) - 2),
                    $qtyAmount,
                );
            }

            foreach ($item->toppings as $topping) {
                $toppingName = trim($topping->name);

                if ($toppingName === '') {
                    continue;
                }

                $price = $currency.Layout::formatPrice($topping->unitPrice * max($topping->quantity, 1));
                $label = '     ＋'.$toppingName;

                if ($topping->quantity > 1) {
                    $label .= ' ×'.$topping->quantity;
                }

                self::row($encoder, $width, self::truncate($label, $width - Layout::displayWidth($price) - 1), $price);
            }

            $note = trim($item->note);

            if ($note !== '') {
                $encoder->line(self::truncate('   備考: '.$note, $width));
            }
        }
    }

    /**
     * Khối tiền tới trước 消費税: 小計, rồi khoản điều chỉnh サービス料 / 値引き.
     *
     * Khoản điều chỉnh là DẪN XUẤT (Total − Subtotal − TaxAmount) để tiền in ra
     * cộng đúng — 小計 + サービス料 + 消費税 = 合計 — thay vì tờ phiếu cũ nơi phí
     * phục vụ biến mất im lặng và các cột không cộng lại thành tổng. Nó là số
     * ròng của phí phục vụ trừ giảm giá đơn: dương thì in サービス料, âm thì in
     * 値引き.
     */
    public static function subtotal(Escpos $encoder, int $width, VatInvoiceInfo $info, string $currency): void
    {
        if ($info->isAmountSplit) {
            self::row($encoder, $width, ' 商品合計', $currency.Layout::formatPrice(self::itemLinesSum($info)));
            self::row($encoder, $width, ' 小計(按分)', $currency.Layout::formatPrice($info->subtotal));
        } else {
            self::row($encoder, $width, ' 小計', $currency.Layout::formatPrice($info->subtotal));
        }

        $adjustment = $info->total - $info->subtotal - $info->taxAmount;

        if ($adjustment > 0) {
            self::row($encoder, $width, ' サービス料', $currency.Layout::formatPrice($adjustment));
        } elseif ($adjustment < 0) {
            self::row($encoder, $width, ' 値引き', '-'.$currency.Layout::formatPrice(-$adjustment));
        }
    }

    /**
     * Khối 消費税 theo từng mức (hoặc dòng đơn mức cũ cho hoá đơn phát hành
     * trước khi có breakdown).
     *
     * In cho CẢ hoá đơn nguyên đơn LẪN phiếu chia theo tiền — phiếu chia mang
     * breakdown đã phân bổ của khách đó, nên phần thuế vốn thiếu trên phiếu
     * chia giờ có in ra. Đây là chỗ nhánh JA khác nhánh VI, vốn bỏ hẳn khối này
     * khi chia theo tiền.
     */
    public static function taxBreakdown(Escpos $encoder, int $width, VatInvoiceInfo $info, string $currency): void
    {
        if ($info->taxBreakdown !== []) {
            // Chứng từ Nhật luôn nói tiếng Nhật bất kể locale của lượt render,
            // nên nhãn ghim cứng chứ không lấy theo `$info->locale`.
            $rows = VatTaxRateRows::layout(
                $width,
                Layout::displayWidth(...),
                ' ',
                TaxLabels::forLocale('ja'),
                $currency,
                $info->taxBreakdown,
            );

            foreach ($rows as [$label, $value]) {
                self::row($encoder, $width, $label, $value);
            }

            return;
        }

        if ($info->taxAmount <= 0) {
            return;
        }

        $label = $info->taxRatePercent > 0 ? ' 消費税'.$info->taxRatePercent.'%' : ' 消費税';

        self::row($encoder, $width, $label, $currency.Layout::formatPrice($info->taxAmount));
    }

    /** 合計 (hoặc 合計(按分) cho phiếu chia theo tiền). */
    public static function grandTotal(Escpos $encoder, int $width, VatInvoiceInfo $info, string $currency): void
    {
        $encoder->bold(true);
        self::row(
            $encoder,
            $width,
            $info->isAmountSplit ? ' 合計(按分)' : ' 合計',
            $currency.Layout::formatPrice($info->total),
        );
        $encoder->bold(false);

        if ($info->isAmountSplit) {
            $encoder->line(' (按分請求)');
        }
    }

    /** Dòng お支払 khi biết phương thức thanh toán. */
    public static function payment(Escpos $encoder, VatInvoiceInfo $info): void
    {
        $method = trim($info->paymentMethod);

        if ($method !== '') {
            $encoder->line(' お支払: '.$method);
        }
    }

    /**
     * Đường kẻ chân, dòng 発行 kèm 登録番号 của người bán, và câu miễn trừ canh
     * giữa. KHÔNG cắt giấy — epilogue của plan cắt, giống nhánh VI.
     *
     * ── Câu miễn trừ CHỈ in khi tờ giấy thật sự chưa đủ điều kiện (#1734) ──
     *
     * Trước đây nó in vô điều kiện, ngay dưới nhãn 適格簡易請求書: tờ giấy tự
     * nhận là chứng từ đủ điều kiện ở trên rồi tự phủ nhận ở dưới. Hậu quả:
     * kế toán của khách đọc dòng "không thay thế được hoá đơn đủ điều kiện" rồi
     * từ chối dùng nó để khấu trừ thuế đầu vào — trong khi về mặt pháp lý nó đủ.
     */
    public static function footer(Escpos $encoder, int $width, string $storeName, string $sellerRegistration): void
    {
        $encoder->line(Layout::dashedLine($width));

        $line = ' 発行: '.$storeName;
        $registration = trim($sellerRegistration);

        if ($registration !== '') {
            $line .= '   登録番号 '.$registration;
        }

        $encoder->line(self::truncate($line, $width));
        $encoder->feed(2);

        if ($registration === '') {
            $encoder->align(Escpos::ALIGN_CENTER);
            $encoder->line('社内参照用（控え）');
            $encoder->line('※適格請求書等の代替ではありません');
            $encoder->align(Escpos::ALIGN_LEFT);
        }

        $encoder->feed(3);
    }

    /** Σ thành tiền của các dòng hàng — đối ứng `itemLinesSum`. */
    public static function itemLinesSum(VatInvoiceInfo $info): int
    {
        $sum = 0;

        foreach ($info->items as $item) {
            $sum += $item->lineTotal;
        }

        return $sum;
    }
}
