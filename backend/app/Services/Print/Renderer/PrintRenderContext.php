<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 0 (#1897) — bản đối ứng PHP của `printRenderCtx`
 * (workstation `internal/service/print_renderer.go`).
 *
 * Chỗ nháp dùng chung của mọi emitter trong MỘT lượt render. Dựng một lần
 * trước vòng duyệt block, rồi truyền cho từng emitter.
 *
 * Vì sao là object có trạng thái chứ không phải tham số rời: hai thứ dưới đây
 * bắt buộc phải chia sẻ giữa các emitter, và chia sẻ chúng qua tham số thì mỗi
 * emitter phải nhận đủ và trả về đủ — tức mọi chữ ký đổi khi thêm một trạng
 * thái.
 *
 * - `headerDrawn`: hai block (`store_info` và `title`) cùng vẽ MỘT dòng "tên
 *   quán … TIÊU ĐỀ". Cái nào được definition đặt trước thì vẽ, cái còn lại
 *   thành no-op. Không có cờ này thì brand nào bật cả hai sẽ in hai dòng.
 * - `japaneseDoc`: chép từ plan của KIND, không suy từ locale (#1493). Xem
 *   {@see PrintKindPlan}.
 */
final class PrintRenderContext
{
    /**
     * Đúng MỘT dòng header đã được vẽ chưa. Xem doc của class.
     */
    public bool $headerDrawn = false;

    /**
     * Trạng thái dẫn xuất của họ phiếu bill, tính MỘT lần trước vòng duyệt
     * block để dấu ※ trên dòng món và khối thuế theo mức ở chân phiếu không
     * bao giờ nói khác nhau — chúng phải là cùng một bản tổng hợp.
     *
     * Slice 0 chưa tính gì vào đây (chưa có emitter nào tiêu thụ); slice bill
     * là chỗ đổ đầy. Để sẵn ô vì nó thuộc HÌNH DẠNG của context, và một slice
     * sau thêm ô vào đây sẽ phải sửa mọi chỗ dựng context.
     *
     * @var array<string, mixed>
     */
    public array $taxSummary = [];

    public bool $showTaxBreakdown = false;

    public bool $suppressOrderRows = false;

    public function __construct(
        public readonly Escpos $encoder,
        /** @var array<string, mixed> definition đã chuẩn hoá */
        public readonly array $definition,
        public readonly PrintRenderData $data,
        public readonly PrintJobConfig $config,
        public readonly string $locale,
        /** Bề rộng NỘI DUNG thật sự dùng cho lượt render này, tính theo cột. */
        public readonly int $width,
        public readonly bool $japaneseDoc,
        public readonly PrintLabels $labels,
        public readonly TaxLabels $tax,
        /**
         * #1937 — ẢNH CHỤP thuế theo mức của đơn, do NGƯỜI GỌI cấp. `null` =
         * người gọi không có (đơn cũ, hoặc bề mặt chưa nối dây).
         *
         * ── Vì sao nó đi qua ĐÂY chứ không qua `PrintRenderData` ───────────
         *
         * Hai ràng buộc, cả hai đều đúng, và chúng cắn nhau:
         *
         *  1. PHP **không được tính lại** thuế ở tầng in — làm tròn xảy ra MỘT
         *     lần cho mỗi nhóm mức ở Cloud rồi mới phân bổ xuống dòng, nên tính
         *     lại ra một con số KHÁC hoá đơn đã phát hành (xem
         *     {@see ReceiptTaxSummary}). Không tính lại ⇒ phải NHẬN snapshot.
         *  2. `PrintRenderData` phải phủ ĐÚNG tập ô của Go
         *     (`PrintContractParityTest`). Go **có** tính lại, nên Go không có
         *     ô mang snapshot — thêm ô ở PHP là cổng đỏ ngay, và đã đo: thử
         *     thêm `taxBreakdown` vào `PrintRenderData` thì ca "#1897
         *     PrintRenderData phủ ĐÚNG tập Ô của Go" đỏ.
         *
         * Context là chỗ thoát: cổng parity so `data_fields` / `config_fields` /
         * `tax_labels` / `kinds` — **không** so context. Và nó không phải một
         * cửa sau: `printRenderCtx` bên Go CŨNG mang `taxSummary`, chỉ khác là
         * Go tự dựng nó trong prologue còn PHP nhận từ ngoài. Đặt snapshot ở
         * context vì vậy làm hai context GIỐNG nhau hơn, không lệch hơn.
         *
         * Nguồn thật: `OrderTaxBreakdownReads::forOrders()` →
         * {@see ReceiptTaxSummary::fromBreakdown()}.
         */
        public readonly ?ReceiptTaxSummary $taxBreakdown = null,
        /**
         * #1950 — cách kết thúc tờ giấy của MÁY sẽ in nó. Xem
         * {@see PrintRenderProfile::$finishing}.
         *
         * Go mang cả `PrintRenderProfile` trong `printRenderCtx` và gọi
         * `c.finish()`; ở đây context chỉ mang đúng phần epilogue cần, vì đó là
         * thứ duy nhất của profile mà một emitter được phép đọc — bề rộng đã
         * được giải thành `$width` trước vòng duyệt block, và một emitter đọc
         * được cả profile là một emitter có thể rẽ theo model máy in.
         */
        public readonly ?Finishing $finishing = null,
        /**
         * #1957 mảnh C — BITMAP đã raster hoá cho các khối `logo`, do NGƯỜI GỌI
         * cấp. Khoá là `"{source}:{max_width_dots}"`.
         *
         * Đi qua context chứ không qua `PrintRenderData` vì đúng lý do của
         * `$taxBreakdown` ở trên: cổng parity so `data_fields` với Go, và Go
         * **không** có ô nào mang bitmap — nó tra thẳng SQLite lúc render. Thêm
         * một ô ở PHP là cổng đỏ ngay.
         *
         * Và giống snapshot thuế, đây không phải cửa sau: `printRenderCtx` bên Go
         * cũng cầm một `*PrintImageStore`. Khác biệt duy nhất là Go TỰ TRA còn
         * PHP NHẬN TỪ NGOÀI — vì PHP phục vụ nhiều branch trong một tiến trình
         * còn máy trạm chỉ có đúng một.
         *
         * `[]` = người gọi không nối dây (bề mặt xem trước, đơn cũ). Khối logo
         * khi đó không vẽ gì và phiếu vẫn in (TR-05).
         *
         * @var array<string, array{width: int, data: string}>
         */
        public readonly array $images = [],
    ) {}

    /**
     * Bitmap cho một `source` ở một bề rộng, hoặc null.
     *
     * `null` là câu trả lời HỢP LỆ và là đường đi phổ biến — xem {@see LogoBlock}.
     *
     * @return array{width: int, data: string}|null
     */
    public function image(string $source, int $maxWidthDots): ?array
    {
        return $this->images[$source.':'.$maxWidthDots] ?? null;
    }

    /**
     * Kết thúc tờ giấy: đẩy giấy và cắt theo cách MÁY NÀY khai (#1950).
     *
     * Không có profile ⇒ đúng `fullCut()` như trước, không lệch một byte. Đây
     * là bản đối ứng của `printRenderCtx.finish()` bên Go, và hai bên phải
     * giống nhau đến từng nhánh: cổng parity mức slip so byte, nên một nhánh
     * lệch là một phiếu Cloud in khác phiếu máy trạm in cho cùng đơn hàng.
     */
    public function finish(): void
    {
        if ($this->finishing === null) {
            $this->encoder->fullCut();

            return;
        }

        $this->encoder->finish($this->finishing);
    }

    /**
     * Số bản in lại, đã kẹp về 0 khi âm.
     *
     * Đặt ở context vì có ít nhất hai emitter đọc nó (`reprint_marker` của họ
     * bill và của họ chứng từ), và cả hai phải đọc cùng một giá trị đã kẹp —
     * một emitter tự kẹp còn emitter kia không là cách hai tờ giấy của cùng
     * một lần in mang hai con số.
     *
     * ── Thang BA BẬC, không phải một ô (#1932) ──────────────────────────
     *
     * Đối ứng của `(*printRenderCtx).reprintNumber` bên Go, và bản đầu ở đây
     * chỉ đọc bậc thứ nhất. Hai bậc sau là **đường đi thật của một phiếu in
     * lại**: `LANPrintVatInvoice` / `LANPrintDebtSlip` cấp số bản sao vào
     * chính payload chứng từ (`VatInvoiceInfo.ReprintNumber` /
     * `DebtSlipInfo.ReprintNumber`), không vào ô chung của `PrintRenderData`.
     *
     * Thiếu hai bậc ấy thì bản in thứ hai của một hoá đơn GTGT ra giấy **KHÔNG
     * mang dấu 「BAN IN #2」** — tức một bản sao trông y hệt bản gốc, đúng thứ
     * mà #1166 đặt cái dấu này ra để chặn. Và nó hỏng im lặng: không có gì đỏ,
     * chỉ có hai tờ giấy giống hệt nhau trong tay khách.
     */
    public function reprintNumber(): int
    {
        if ($this->data->reprintNumber > 0) {
            return $this->data->reprintNumber;
        }

        if ($this->data->vat !== null) {
            return max(0, $this->data->vat->reprintNumber);
        }

        if ($this->data->debt !== null) {
            return max(0, $this->data->debt->reprintNumber);
        }

        return 0;
    }

    /**
     * Một dòng "nhãn … giá trị" đo bằng MÃ ĐIỂM — #1932.
     *
     * Đối ứng của `(*printRenderCtx).runeRow`, và nó tồn tại song song với
     * {@see row} một cách CÓ CHỦ ĐÍCH. Hoá đơn GTGT và phiếu ghi nợ được viết
     * trước phiếu bán hàng và đo cột bằng số mã điểm; vị trí cột của chúng đã
     * nằm trên những tờ giấy quán ĐÃ nộp. TR-40 nói tái hiện y hệt rồi sửa
     * bằng một lần sửa template có chủ đích, thấy được — nên đừng "chuẩn hoá"
     * hai hàm này thành một.
     *
     * Khác biệt chỉ lộ ra khi nhãn có chữ Nhật: 「合計」 là 2 mã điểm nhưng 4
     * cột. Trên chứng từ Việt (ASCII) hai phép đo cho cùng kết quả.
     */
    public function runeRow(string $label, string $value): void
    {
        $gap = $this->width - Layout::runeLength($label) - Layout::runeLength($value);

        if ($gap < 1) {
            $gap = 1;
        }

        $this->encoder->line($label.Layout::spaces($gap).$value);
    }

    /**
     * Dải "trắng / gạch / trắng" mà các phiếu dựng từ đơn hàng đặt trên đầu
     * bảng cột và dưới danh sách món — đối ứng của `separatorBand`.
     */
    public function separatorBand(): void
    {
        $this->encoder->feed(1);
        $this->encoder->line(Layout::dashedLine($this->width));
        $this->encoder->feed(1);
    }

    /**
     * Một dòng "nhãn … giá trị" căng ra đúng bề rộng — #1923.
     *
     * Đối ứng của `(*printRenderCtx).row`. Mười tám emitter họ bill đều gọi nó,
     * nên nó ở đây chứ không chép vào từng cái.
     *
     * Dùng `displayWidth`, KHÔNG dùng `strlen` hay `mb_strlen`: một glyph Kanji
     * chiếm HAI cột trên đầu in nhiệt. Đếm sai cột thì cột tiền lệch dần theo
     * độ dài tên món, và nó chỉ lộ ra trên phiếu tiếng Nhật — tức lộ ra ở quán,
     * không ở máy người viết code.
     *
     * Khoảng cách tối thiểu là 1: nhãn quá dài thì thà dính sát còn hơn tràn
     * dòng, vì tràn dòng đẩy giá trị xuống dòng dưới và cột tiền vỡ hẳn.
     */
    public function row(string $label, string $value): void
    {
        $gap = $this->width - Layout::displayWidth($label) - Layout::displayWidth($value);

        if ($gap < 1) {
            $gap = 1;
        }

        $this->encoder->line($label.Layout::spaces($gap).$value);
    }

    /**
     * `row` với GIÁ TRỊ được nhấn — đối ứng của `printEmphasisRow` bên Go.
     *
     * Mã đơn và số bàn là hai thứ nhân viên QUÉT MẮT tìm chứ không đọc, nên giá
     * trị in đậm ở chiều cao GẤP ĐÔI. Nhãn giữ nguyên cỡ: nhãn là chữ người đọc
     * đã biết, giá trị mới là thứ họ đang tìm.
     *
     * Cỡ chữ là thứ ĐO ĐƯỢC, không phải thứ chọn. Gấp đôi chiều cao thì miễn
     * phí: `ESC i 1 0` không đổi bề rộng ô ký tự nên không cột nào xê dịch. Gấp
     * đôi cả chiều RỘNG thì mỗi glyph ăn hai cột, và vừa hay không phụ thuộc khổ
     * giấy lẫn dữ liệu của quán — trên 58mm (width=32) một mã đơn 21 ký tự ở ×2
     * cần 42 cột. Nên mỗi hàng tự hỏi ×2 có vừa không, không vừa thì lùi về
     * ×2-chiều-cao. Phiếu tràn thì XUỐNG DÒNG chứ không báo lỗi, tức hỏng ở quán
     * chứ không hỏng ở test.
     *
     * Thứ tự byte phải khớp Go từng byte: `SlipByteParityTest` so nguyên phiếu.
     */
    public function emphasisRow(string $label, string $value): void
    {
        $labelWidth = Layout::displayWidth($label);
        $valueWidth = Layout::displayWidth($value);

        // +1 giữ ít nhất một khoảng trắng giữa nhãn và giá trị; thiếu nó thì một
        // giá trị vừa khít phần còn lại sẽ dính sát nhãn và đọc thành một chữ.
        $scale = $labelWidth + 1 + $valueWidth * 2 > $this->width ? 1 : 2;

        $gap = $this->width - $labelWidth - $valueWidth * $scale;

        if ($gap < 1) {
            $gap = 1;
        }

        $this->encoder->text($label.Layout::spaces($gap));
        $this->encoder->bold(true);
        $this->encoder->size($scale === 2 ? Escpos::DOUBLE_SIZE : Escpos::DOUBLE_HEIGHT);
        $this->encoder->text($value);
        $this->encoder->size(Escpos::NORMAL_SIZE);
        $this->encoder->bold(false);
        $this->encoder->feed(1);
    }

    /**
     * Số tiền kèm ký hiệu tiền tệ — đối ứng của `(*printRenderCtx).money`.
     *
     * Ký hiệu đến từ CẤU HÌNH, không hard-code: một quán VN và một quán JP dùng
     * cùng đoạn mã này, và `¥` in trên phiếu tiếng Việt là lỗi không ai báo cáo
     * vì nó trông "chỉ hơi lạ".
     */
    public function money(int $amount): string
    {
        return $this->config->currencySymbol().Layout::formatPrice($amount);
    }
}
