<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use App\Services\Order\Enums\OrderSplitMode;

/**
 * plan-053 T5.1d (#1923) — ô `slip` của `PrintRenderData`, siết từ `?array`.
 *
 * Ô RIÊNG của họ bill: `vat`/`debt`/`tablePaid` thuộc họ docs (#1909),
 * `shift`/`shiftOpen` thuộc họ shift (#1927). Mỗi slice siết ô của mình khi
 * biết emitter của nó chạm gì — đúng luật ở docblock `PrintRenderData`.
 *
 * ## 13 trường, không phải 7
 *
 * Grep `data.Slip.X` trong `print_renderer_bill.go` chỉ ra **7**. Đó là số của
 * những emitter tôi đã đọc, không phải của struct: `PaymentSlipInfo` bên Go có
 * **13** trường, và sáu cái còn lại (`PaymentMethod` · `Tendered` · `Change` ·
 * `Remaining` · `SplitMode` · `ReprintNumber`) được đọc bởi các emitter thanh
 * toán mà lượt grep đầu chưa chạm tới.
 *
 * Ghi lại vì đây là cách một VO bị dựng thiếu: đo theo cái mình vừa đọc thay vì
 * theo định nghĩa kiểu, rồi phát hiện thiếu khi port tới emitter thứ mười.
 */
final readonly class PrintRenderSlip
{
    /**
     * @param  string  $paymentMethod  in nguyên văn nếu khác rỗng
     * @param  int  $amountPaid  số tiền thanh toán trên PHIẾU NÀY (một phần khi chia bill)
     * @param  int  $slipIndex  thứ tự 1-based của lần thanh toán trong đơn; 0 = không chia
     * @param  int  $splitCount  tổng số người chia; 0/1 = không chia
     * @param  int  $remaining  "Còn lại" trên phiếu
     * @param  int  $billTotal  "Tổng" của phiếu con này; 0 = không phải phiếu chia
     * @param  int  $orderGrossTotal  tổng cả đơn, dùng cho dòng ngữ cảnh "Tổng đơn"
     * @param  int  $tendered  khách đưa
     * @param  int  $change  tiền thối
     * @param  int  $reprintNumber  số bản in lại; ≤1 = bản gốc
     */
    public function __construct(
        public string $paymentMethod = '',
        public int $amountPaid = 0,
        public int $slipIndex = 0,
        public int $splitCount = 0,
        public int $remaining = 0,
        public int $billTotal = 0,
        public string $label = '',
        public int $tendered = 0,
        public int $change = 0,
        public string $customerName = '',
        public string $splitMode = '',
        public int $orderGrossTotal = 0,
        public int $reprintNumber = 0,
    ) {}

    /**
     * Phân loại kiểu chia bill — đối ứng của `(*PaymentSlipInfo).splitModeKind`.
     *
     * Trả `''` khi không phải phiếu chia. Ba giá trị còn lại lái nhánh của
     * `emitBillGrandTotal`, nên phân loại sai ở đây làm **dòng tổng tiền in sai
     * loại** — thứ khách đọc đầu tiên.
     *
     * #2860 — chỗ này KHÔNG còn gộp tên cũ. Chuẩn hoá đã xảy ra ở biên vào
     * ({@see OrderSplitMode::fromWire()}) và
     * migration đã viết lại dữ liệu đã lưu, nên tới đây chỉ còn ba giá trị.
     * Renderer mà tự nhận thêm một tên là mở lại đúng cái cửa đã đóng: từ vựng
     * thứ hai sinh ra ở chỗ không ai nghĩ là nơi định nghĩa từ vựng.
     *
     * Nhánh cuối là suy đoán CÓ CHỦ ĐÍCH: `splitMode` rỗng nhưng chia cho nhiều
     * người thì đó là chia đều — đơn cũ không ghi `splitMode`, và bỏ nhánh này
     * sẽ làm chúng in như phiếu thường.
     */
    public function splitModeKind(): string
    {
        return match ($this->splitMode) {
            'by_items' => 'by_items',
            'by_amount' => 'by_amount',
            'even' => 'even',
            default => $this->splitCount > 1 ? 'even' : '',
        };
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            paymentMethod: (string) ($row['payment_method'] ?? ''),
            amountPaid: (int) ($row['amount_paid'] ?? 0),
            slipIndex: (int) ($row['slip_index'] ?? 0),
            splitCount: (int) ($row['split_count'] ?? 0),
            remaining: (int) ($row['remaining'] ?? 0),
            billTotal: (int) ($row['bill_total'] ?? 0),
            label: (string) ($row['label'] ?? ''),
            tendered: (int) ($row['tendered'] ?? 0),
            change: (int) ($row['change'] ?? 0),
            customerName: (string) ($row['customer_name'] ?? ''),
            splitMode: (string) ($row['split_mode'] ?? ''),
            orderGrossTotal: (int) ($row['order_gross_total'] ?? 0),
            reprintNumber: (int) ($row['reprint_number'] ?? 0),
        );
    }
}
