<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use Carbon\CarbonImmutable;

/**
 * plan-053 T5.1d slice 0 (#1897) — bản đối ứng PHP của `PrintRenderData`
 * (workstation `internal/service/print_renderer.go`).
 *
 * Đây là TOÀN BỘ thứ một phiếu được vẽ từ. Emitter không đọc gì ngoài object
 * này, {@see PrintJobConfig}, {@see PrintLabels} và {@see TaxLabels} — cùng một
 * ràng buộc mà bên Go tự đặt cho mình, và là điều làm cổng parity mức slip
 * (T5.2b) khả thi: hai renderer nhận cùng đầu vào thì mới so được đầu ra.
 *
 * ── Vì sao slice 0 dựng cái này TRƯỚC emitter ────────────────────────────
 *
 * Ba slice emitter (bill 23 · docs 30 · shift 19) đều đọc chung hình dạng này.
 * Viết emitter trước nghĩa là mỗi slice tự nghĩ ra một `PrintRenderData` rồi
 * phải gộp ba bản lệch nhau. Phần đắt của việc port không phải hàm vẽ.
 *
 * ── Ô hợp thành đang là `?array`, CÓ CHỦ ĐÍCH và sẽ đổi ─────────────────
 *
 * `order` · `items` · `slip` · `vat` · `debt` · `shift` · `shiftOpen` ·
 * `tablePaid` là dữ liệu có cấu trúc, không phải mảng tự do. Slice 0 chưa
 * dựng VO cho chúng vì hình dạng CHÍNH XÁC của từng cái chỉ lộ ra khi emitter
 * tiêu thụ nó, và đoán trước tám VO rồi sửa lại tám lần là churn đắt hơn.
 *
 * Thứ slice 0 ghim được ngay, và là thứ hỏng IM LẶNG, là **Ô CÓ TỒN TẠI HAY
 * KHÔNG**: Go thêm `ShiftOpen` mà PHP không có ô ấy thì emitter đọc phải null
 * và trên giấy nó hiện thành một dòng vắng — không phải một lỗi.
 * `print_golden.json` không bắt được (nó render bằng renderer Go), nên
 * `PrintContractParityTest` là chỗ duy nhất bắt được.
 *
 * Slice sau siết `?array` thành VO theo từng ô nó chạm. Đừng siết trước.
 *
 * **#1925 — `order` và `items` ĐÃ siết**, và chúng được siết ở một issue RIÊNG
 * chứ không trong slice bill, vì đo ra cả hai file emitter (bill và docs) đều
 * đọc chúng. Một VO có hai chủ tiêu thụ thì không thuộc slice nào — đặt nó
 * trong slice bill sẽ chặn slice docs mà không chỗ nào ghi lý do.
 *
 * Sáu ô còn lại (`slip` · `vat` · `debt` · `shift` · `shiftOpen` · `tablePaid`)
 * mỗi cái có ĐÚNG MỘT slice tiêu thụ, nên chúng ở lại `?array` cho tới slice đó.
 *
 * **#1934 — `shift` và `shiftOpen` ĐÃ siết** thành {@see ShiftReportInfo} /
 * {@see ShiftOpenReportInfo}, cùng năm bộ sưu tập bên trong chúng
 * (`chainIndex` · `payments` · `discounts` · `taxBreakdown` · `denominations`).
 * Đoạn "ở lại `?array`" bên trên vì vậy KHÔNG còn đúng cho hai ô này.
 *
 * Ghi lại vì nó suýt làm tôi port sai: đọc đoạn trên rồi đi dựng VO, trong khi
 * chữ ký constructor đã có kiểu từ trước. Ở một file mà doc và chữ ký nằm cách
 * nhau 40 dòng, **chữ ký mới là phép đo** — doc là thứ trôi sau.
 *
 * ── `now` do NGƯỜI GỌI cấp, không phải renderer đọc đồng hồ ─────────────
 *
 * Dấu thời gian trên phiếu là giờ NGHIỆP VỤ của chi nhánh (#1091). Renderer
 * không có `branchId` và không được đoán, nên nó nhận thời điểm đã giải sẵn.
 * `null` = người gọi chưa cấp; emitter nào cần thì phải xử lý, chứ class này
 * không gọi `now()` để lấp — đó đúng là cách một phiếu Tokyo bị đóng dấu theo
 * đồng hồ app.
 */
final class PrintRenderData
{
    /**
     * @param  list<PrintRenderItem>  $items  các dòng món đã giải sẵn
     * @param  PrintRenderSlip|null  $slip  thông tin thanh toán của phiếu
     *
     * `$slip` từng được ghi ở đây là `array<string, mixed>|null` — và vẫn còn ghi
     * thế **sau khi** #1923 siết nó thành VO. Docblock nói dối đó không vô hại:
     * `prepareBillTax` đọc `$slip['split_count']` đúng theo nó, nên mọi lần render
     * phiếu có slip đều ném ngay ở prologue — không phải in sai, mà KHÔNG RA PHIẾU
     * (sửa ở #1937). Kiểu phần tử của `$items` cũng thiếu, và thiếu kiểu phần tử là
     * thứ không có công cụ nào kêu: PHP không tả được "mảng của Foo", nên chỗ duy
     * nhất nói ra điều đó là dòng này.
     */
    public function __construct(
        public readonly string $kind,
        public readonly PrintJobConfig $config,
        public readonly ?CarbonImmutable $now = null,
        public readonly ?PrintRenderOrder $order = null,
        /** @var list<PrintRenderItem> */
        public readonly array $items = [],
        public readonly int $ticketNo = 0,
        /** Tiền của họ phiếu bill — do NGƯỜI GỌI quyết, đúng như các entry point Format*Ticket. */
        public readonly int $total = 0,
        public readonly int $remaining = 0,
        public readonly int $paidShown = 0,
        public readonly bool $showQr = false,
        public readonly bool $deltaBill = false,
        // #1923 — ô RIÊNG của họ bill.
        public readonly ?PrintRenderSlip $slip = null,
        public readonly ?VatInvoiceInfo $vat = null,
        public readonly string $voidReason = '',
        public readonly ?CarbonImmutable $voidedAt = null,
        public readonly ?DebtSlipInfo $debt = null,
        public readonly ?ShiftReportInfo $shift = null,
        public readonly ?ShiftOpenReportInfo $shiftOpen = null,
        public readonly ?TablePaidInfo $tablePaid = null,
        /** Điều khiển dấu 「<mark> #N」 trên các kind có mang nó. In từ bản thứ 2. */
        public readonly int $reprintNumber = 0,
    ) {}

    /**
     * Ánh xạ tên thuộc tính PHP → tên trường Go.
     *
     * Phải khai tường minh vì hai bên đặt tên khác quy ước, và ba cái không
     * suy ra được bằng `ucfirst`: `showQr` → `ShowQR` (Go viết hoa cả từ viết
     * tắt), `ticketNo` → `TicketNo`, `now` → `Now`. Một hàm ánh xạ tự động sẽ
     * đúng 17/20 rồi sai 3 chỗ theo kiểu khó thấy nhất.
     *
     * @var array<string, string>
     */
    private const GO_FIELD_NAMES = [
        'kind' => 'Kind',
        'config' => 'Config',
        'now' => 'Now',
        'order' => 'Order',
        'items' => 'Items',
        'ticketNo' => 'TicketNo',
        'total' => 'Total',
        'remaining' => 'Remaining',
        'paidShown' => 'PaidShown',
        'showQr' => 'ShowQR',
        'deltaBill' => 'DeltaBill',
        'slip' => 'Slip',
        'vat' => 'Vat',
        'voidReason' => 'VoidReason',
        'voidedAt' => 'VoidedAt',
        'debt' => 'Debt',
        'shift' => 'Shift',
        'shiftOpen' => 'ShiftOpen',
        'tablePaid' => 'TablePaid',
        'reprintNumber' => 'ReprintNumber',
    ];

    /**
     * Tên Ô theo cách Go đặt — khoá đối chiếu hợp đồng.
     *
     * Trả về TÊN chứ không phải giá trị: hợp đồng ở đây là "có đủ ô hay
     * không", còn giá trị thuộc về từng phiếu cụ thể.
     *
     * Dẫn xuất qua REFLECTION trên thuộc tính thật rồi tra bảng, chứ không
     * liệt kê tay. Liệt kê tay nghĩa là thêm một ô vào constructor mà quên
     * thêm ở đây — và khi đó rào vẫn xanh (nó so danh sách tay với Go, cả hai
     * đều không biết ô mới), tức nó im đúng lúc phải kêu. Ô nào không có
     * trong bảng thì ném ngay, ở đây, thay vì lệch âm thầm.
     *
     * @return list<string>
     */
    public static function goFieldNames(): array
    {
        $names = [];

        foreach ((new \ReflectionClass(self::class))->getConstructor()?->getParameters() ?? [] as $param) {
            $php = $param->getName();
            $go = self::GO_FIELD_NAMES[$php] ?? null;

            if ($go === null) {
                throw new \LogicException(
                    "PrintRenderData::\${$php} chưa có tên Go trong GO_FIELD_NAMES — "
                    .'thêm ô mới thì phải khai tên Go của nó, nếu không cổng parity không thấy ô đó.'
                );
            }

            $names[] = $go;
        }

        sort($names);

        return $names;
    }
}
