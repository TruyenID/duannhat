<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d (#1925) — đơn hàng như tầng in nhìn thấy nó.
 *
 * BẢY trường, đo từ cả hai file emitter (`print_renderer_bill.go` ×5,
 * `print_renderer_docs.go` ×3). `Order` của workstation lớn hơn nhiều; tầng in
 * không cần phần còn lại.
 *
 * ── BA trường thuế của Go CỐ Ý KHÔNG có mặt ──────────────────────────────
 *
 * Go còn `IsTaxIncluded` · `TaxRoundingMode` · `TaxRoundingDecimals`. Chúng chỉ
 * tồn tại để `buildReceiptTaxSummary` **tính lại** thuế bằng `priceGroups()`.
 *
 * Bản PHP đọc snapshot qua `OrderTaxBreakdownReads` thay vì tính lại (#1908) —
 * số in ra phải khớp hoá đơn đã phát hành, và đó là báo cáo thuế. Mang ba
 * trường ấy vào VO là **mời người sau tính lại**, nên chúng vắng mặt có chủ
 * đích chứ không phải bỏ sót.
 *
 * `taxAmount` thì CÓ, vì nó là tổng thuế đã chốt của đơn — một con số để in,
 * không phải đầu vào của phép tính.
 */
final class PrintRenderOrder
{
    /**
     * @param  list<PrintRenderDiscount>  $discounts  các dòng `discount` của sổ
     *                                                `order_conditions`, mỗi nhóm mức một dòng (#2071). Docblock này
     *                                                là LOAD-BEARING: cả hai hydrator (SlipByteParityTest ·
     *                                                PrintRenderDataHydrator) đọc kiểu phần tử từ `@param list<...>`,
     *                                                thiếu nó thì emitter nhận mảng thô thay vì VO.
     */
    public function __construct(
        public readonly string $orderCode = '',
        public readonly string $orderType = '',
        public readonly string $tableNumber = '',
        public readonly string $note = '',
        public readonly int $subtotal = 0,
        public readonly int $serviceCharge = 0,
        /** Tổng thuế ĐÃ CHỐT của đơn — số để in, không phải đầu vào phép tính. */
        public readonly int $taxAmount = 0,
        /**
         * Tổng phải trả và số đã trả của ĐƠN.
         *
         * Có mặt để trả lời đúng một câu: đơn này đã trả đủ chưa. Tờ HALL đã
         * thu đủ tiền thì không mang QR — khách không còn gì để quét, và một mã
         * thanh toán trên tờ đã trả là lời mời trả lần hai. Đối ứng
         * `hallQRSuppressed`/`OrderIsSettled` bên Go, và fixture chung
         * (`print_input_golden.json`) vốn đã chở hai số này.
         */
        public readonly int $totalAmount = 0,
        public readonly int $paidAmount = 0,
        /*
         * #1925 bổ sung — ba trường của khối khách MANG ĐI.
         *
         * Bản đầu dừng ở 7 trường mà issue liệt kê. Đo lại `printCustomerHeader`
         * (`internal/service/print_service.go:257-267`) — hàm mà họ bill gọi hai
         * lần — thì nó đọc thêm ba trường nữa, và cả ba đều chỉ có nghĩa khi
         * `orderType === 'takeaway'`:
         *
         *     if order.OrderType != "takeaway" { return }
         *     order.CustomerTakeawayName · CustomerTakeawayPhone · ScheduledPickupTime
         *
         * Thiếu chúng thì phiếu mang đi mất tên khách, số điện thoại và giờ hẹn
         * lấy — mất IM LẶNG: không có gì đỏ, chỉ có một tờ giấy thiếu chữ, và
         * người phát hiện là nhân viên đang tìm xem đơn này của ai.
         */
        public readonly string $customerTakeawayName = '',
        public readonly string $customerTakeawayPhone = '',
        /** ISO-8601 như Cloud mirror xuống; `formatPickupTime` bên Go định dạng lúc vẽ. */
        public readonly string $scheduledPickupTime = '',
        /*
         * #1937 bổ sung — ĐỊNH DANH đơn, và nó chỉ có một chỗ dùng: mã QR.
         *
         * `kioskQRPayload` (`print_service.go`) dựng JSON
         * `{"orderId","orderCode","type"}` từ `order.ID`, và kiosk đọc `orderCode`
         * ra khỏi JSON đó để resolve. Không có ô này thì `qr_block` bên PHP chỉ
         * dựng được payload thiếu `orderId` — tức một chuỗi KHÁC byte-for-byte với
         * cái workstation đang in, và #1190 tồn tại đúng vì payload lệch làm mọi
         * lượt quét 404.
         *
         * ĐO TRƯỚC KHI THÊM: nếu mọi definition thật đều khai `source: order_code`
         * thì Go dùng thẳng `OrderCode` và ô này thừa. Đo ra ngược lại —
         * `config/print_templates.php` khai `source: 'order_url'` cho cả ba kind
         * bật QR (`runner` · `delta_qr` · `remaining`), tức nhánh MẶC ĐỊNH
         * (`kioskQRPayload`) mới là nhánh quán chạy. `order_code` có trong
         * allow-list `config/print_blocks.php` nhưng không quán nào mặc định vào đó.
         *
         * Ô này KHÔNG bị cổng parity chặn: `PrintPayloadContractParityTest` chỉ so
         * 14 struct trong `payload_fields`, và `Order` không nằm trong đó — VO này
         * cố ý là tập CON đã cắt của `Order` bên Go.
         */
        public readonly string $id = '',
        /*
         * #2071 — các dòng giảm giá của SỔ, không phải một cột tổng.
         *
         * `WritesCustomerOrders::writeConditions` ghi MỘT dòng cho MỖI nhóm mức
         * (#2031); khối `discounts` của phiếu bill in đúng các dòng đó, không
         * cộng, không phân bổ lại. VO này cố ý KHÔNG mang
         * `discount_amount` dạng tổng: cột đó giữ số YÊU CẦU, sổ giữ số ĐÃ ÁP
         * DỤNG, và mang cả hai vào tầng in là mời người sau "đối chiếu" rồi
         * tính lại — đúng lớp lỗi #2067.
         *
         * @var list<PrintRenderDiscount>
         */
        public readonly array $discounts = [],
    ) {}
}
