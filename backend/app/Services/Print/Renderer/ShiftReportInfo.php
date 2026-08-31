<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d (#1910) — phiếu 精算 (đóng ca) và báo cáo CHUỖI ca (plan-046).
 *
 * 41 trường, đo từ `ShiftReportInfo` bên workstation. Rộng vì Z-report là
 * chứng từ đối soát: nó phải nói được tiền vào, tiền ra, tiền bị huỷ, và tiền
 * đếm được — mỗi thứ tách riêng để người vận hành tìm ra chênh lệch nằm ở đâu.
 *
 * ── MỌI con số ở đây là SNAPSHOT, không phải phép tính ────────────────────
 *
 * Chúng đến từ `settlement_snapshot` BẤT BIẾN của ca (plan-046). Tầng in không
 * được cộng lại từ đơn hàng: một refund cross-period sẽ ra số khác, và Z-report
 * là chứng từ đã ký. Cùng luật với {@see ReceiptTaxSummary} ở phiếu bán hàng.
 *
 * ── Bốn cờ `show*` là QUYẾT ĐỊNH CỦA BRAND, không phải "có dữ liệu hay không"
 *
 * `showPaymentMethods` · `showServiceCharge` · `showDrawerCheck` ·
 * `showDenominations` · `showTaxBreakdown` bật/tắt từng khối trên giấy. Suy
 * chúng từ "mảng có rỗng không" là sai: một brand tắt khối mệnh giá vẫn có dữ
 * liệu mệnh giá, và một brand bật khối đó vẫn phải in tiêu đề khi ca chưa đếm.
 *
 * ── Chuỗi ca ─────────────────────────────────────────────────────────────
 *
 * `isChain` + `chainIndex[]` biến phiếu này thành báo cáo TỔNG của một chuỗi:
 * mỗi phần tử là bản rút gọn snapshot của một ca ({@see ChainShiftLine}), và
 * tổng chuỗi là Σ các snapshot đó. `reportKind` phân biệt `handover` (bàn giao,
 * chuỗi còn mở) với `final` (đóng chuỗi).
 *
 * Thời điểm là CHUỖI đã định dạng theo timezone chi nhánh (#1091) — tầng in chỉ
 * đặt chữ lên giấy, không giải múi giờ.
 */
final class ShiftReportInfo
{
    /**
     * @param  list<ChainShiftLine>  $chainIndex
     * @param  list<ShiftPaymentLine>  $payments
     * @param  list<ShiftDiscountLine>  $discounts
     * @param  list<ShiftDenominationLine>  $denominations
     * @param  list<ShiftTaxRateLine>  $taxBreakdown
     */
    public function __construct(
        /** `handover` (chuỗi còn mở) | `final` (đóng chuỗi). */
        public readonly string $reportKind = '',
        public readonly bool $isChain = false,
        public readonly string $chainId = '',
        public readonly int $chainSequence = 0,
        public readonly int $shiftCount = 0,
        public readonly array $chainIndex = [],

        public readonly string $tillCode = '',
        /** Số Z — bộ đếm chứng từ đối soát, không được tua ngược. */
        public readonly int $zNumber = 0,
        public readonly string $phone = '',
        public readonly string $operator = '',
        public readonly string $openedAt = '',
        public readonly string $closedAt = '',
        public readonly string $currency = '',

        public readonly int $grossSales = 0,
        public readonly int $netSales = 0,
        public readonly int $taxTotal = 0,
        public readonly int $itemCount = 0,
        public readonly int $guestCount = 0,
        public readonly int $checkCount = 0,
        public readonly int $serviceCharge = 0,
        public readonly int $serviceChargeTax = 0,

        public readonly array $payments = [],
        public readonly array $discounts = [],
        public readonly int $discountTotalCount = 0,
        public readonly int $discountTotalAmount = 0,
        public readonly array $taxBreakdown = [],

        /** Tiền mặt vào/ra ngoài doanh thu (nộp thêm, rút bớt). */
        public readonly int $paidInCount = 0,
        public readonly int $paidInAmount = 0,
        public readonly int $paidOutCount = 0,
        public readonly int $paidOutAmount = 0,

        /**
         * Huỷ tách làm HAI: đơn chưa trả tiền và đơn ĐÃ trả rồi mới huỷ. Gộp
         * lại thì người đối soát không phân biệt được "khách đổi ý" với "tiền
         * đã vào két rồi phải trả ra".
         */
        public readonly int $voidUnpaidCount = 0,
        public readonly int $voidUnpaidAmount = 0,
        public readonly int $voidPaidCount = 0,
        public readonly int $voidPaidAmount = 0,

        public readonly int $countedCash = 0,
        public readonly int $expectedCash = 0,
        /** 過不足 — đã CHỞ THEO, không tính lại từ counted − expected. */
        public readonly int $cashVariance = 0,
        public readonly array $denominations = [],

        public readonly bool $showPaymentMethods = false,
        public readonly bool $showServiceCharge = false,
        public readonly bool $showDrawerCheck = false,
        public readonly bool $showDenominations = false,
        public readonly bool $showTaxBreakdown = false,
    ) {}
}
