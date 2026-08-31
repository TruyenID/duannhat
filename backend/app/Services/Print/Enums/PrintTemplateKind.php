<?php

declare(strict_types=1);

namespace App\Services\Print\Enums;

/**
 * plan-053 (#1171) — the 13 printable document kinds.
 *
 * The list is NOT arbitrary: it is exactly the 13 `Format*` entry points the
 * workstation renders today (`workstation/internal/service/print_*.go`),
 * because TR-40 makes the hard-coded Go formatter the migration baseline —
 * a system default definition must render byte-identical to the formatter it
 * replaces before anyone's slip is allowed to change.
 *
 *   receipt      ← FormatPaidTicket           kitchen      ← FormatKitchenTicket
 *   runner       ← FormatRunnerTicket         delta_qr     ← FormatDeltaQRTicket
 *   remaining    ← FormatRemainingTicket      vat_invoice  ← FormatVatInvoice
 *   red_invoice  ← FormatRedInvoiceTicket     void_notice  ← FormatVoidNotice
 *   debt_slip    ← FormatDebtSlip             shift_open   ← FormatShiftOpenReport
 *   shift_report ← FormatShiftReport          chain_report ← FormatChainReport
 *   table_paid   ← FormatTablePaid
 *
 * DESIGN.md §2 names a 14th (`diagnostic`) in its parenthetical while its own
 * prose says "13 loại"; there is no diagnostic formatter in the workstation,
 * so it has no migration baseline and is deliberately NOT a kind here. Add it
 * only together with a real renderer.
 */
enum PrintTemplateKind: string
{
    case Receipt = 'receipt';
    case Kitchen = 'kitchen';
    case Runner = 'runner';
    case DeltaQr = 'delta_qr';
    case Remaining = 'remaining';
    case VatInvoice = 'vat_invoice';
    /**
     * 適格簡易請求書 — chứng từ luật định NHẬT theo インボイス制度 (#1492/#1459).
     *
     * KHÔNG phải bản dịch của `vat_invoice`. Hai chứng từ khác nhau về nội dung
     * bắt buộc: hoá đơn GTGT Việt Nam có khối chữ ký 買い手/売り手 và mã số thuế
     * NGƯỜI MUA; bản 簡易 của Nhật thì không cần tên người mua và chỉ cần
     * **登録番号 của NGƯỜI BÁN**.
     *
     * Vì sao là bản 簡易 chứ không phải 適格請求書 đầy đủ — chốt theo luật, không
     * đoán: 国税庁 cho phép 簡易 với các ngành mà khách vãng lai là chuẩn mực, và
     * danh sách đó gồm đúng ngành của sản phẩm này — 小売業 và **飲食店業**
     * (cùng 写真業・旅行業・タクシー業・駐車場業). Khác biệt thực dụng là bản 簡易
     * bỏ được 宛名 (tên người nhận). Bản đầy đủ cần một luồng NHẬP tên người mua
     * mà hệ thống chưa có, nên nó là tính năng sản phẩm, không phải một mẫu in —
     * đó là kind thứ ba khi nào có nhu cầu thật, không phải bây giờ.
     */
    case QualifiedSimplifiedInvoice = 'qualified_simplified_invoice';
    case RedInvoice = 'red_invoice';
    case VoidNotice = 'void_notice';
    case DebtSlip = 'debt_slip';
    case ShiftOpen = 'shift_open';
    case ShiftReport = 'shift_report';
    case ChainReport = 'chain_report';
    case TablePaid = 'table_paid';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Quốc gia mà chứng từ này thuộc về; `null` = dùng ở mọi nước (#1445).
     *
     * Chứng từ đi theo QUỐC GIA NƠI SHOP TỒN TẠI — không theo quốc tịch người
     * dùng, không theo ngôn ngữ giao diện. Hoá đơn GTGT và hoá đơn đỏ là chứng
     * từ **luật định Việt Nam**: một quán ở Nhật không in bản dịch của chúng, nó
     * in chứng từ Nhật (適格簡易請求書 — một `kind` KHÁC, tách plan riêng).
     *
     * Hệ quả kéo theo: tên mẫu KHÔNG dịch. "Quốc gia nào ngôn ngữ đó" được thoả
     * bằng việc chọn đúng chứng từ, không bằng việc dịch tên một chứng từ nước
     * khác. Xem `config/print_templates.php` khối `vat_invoice`.
     *
     * @return list<string>|null ISO 3166-1 alpha-2
     */
    public function countries(): ?array
    {
        return match ($this) {
            self::VatInvoice, self::RedInvoice => ['VN'],
            self::QualifiedSimplifiedInvoice => ['JP'],
            default => null,
        };
    }

    /** Chứng từ này có được phát cho một shop ở `$country` không. */
    public function availableIn(?string $country): bool
    {
        $countries = $this->countries();
        if ($countries === null) {
            return true;
        }

        // Không biết quốc gia → KHÔNG ẩn. Ẩn nhầm một chứng từ luật định là chặn
        // người ta xuất hoá đơn; hiện thừa thì chỉ là một dòng không dùng tới.
        if (! is_string($country) || $country === '') {
            return true;
        }

        return in_array(strtoupper($country), $countries, true);
    }

    /**
     * @return list<self>
     */
    public static function availableFor(?string $country): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $kind): bool => $kind->availableIn($country),
        ));
    }
}
