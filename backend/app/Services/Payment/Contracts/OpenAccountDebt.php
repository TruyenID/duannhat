<?php

declare(strict_types=1);

namespace App\Services\Payment\Contracts;

/**
 * #1993 — MỘT khoản nợ ghi sổ (`on_account`) đang còn mở.
 *
 * Hai số tiền, và khác nhau ở chỗ quan trọng:
 *
 *   - `amount`    số nợ GỐC. Một khoản thu nợ phải khớp CHÍNH XÁC con số này —
 *                 `OrderPaymentStoreRequest` so với `orig->amount` và trả
 *                 `settles_amount_mismatch` nếu lệch, vì một khoản nợ là MỘT
 *                 dòng và liên kết `settles_payment_id` là một-lần (không có mô
 *                 hình trả góp nợ).
 *   - `netAmount` chính khoản đó sau khi trừ hoàn. Đây mới là số khách còn
 *                 thiếu, và là số mà tổng theo khách cộng lại.
 *
 * Hai số chỉ khác nhau khi nợ bị hoàn MỘT PHẦN, và ở trạng thái đó khoản nợ
 * **không thu được qua đường thanh toán**: trả net thì trúng guard số tiền, trả
 * gốc thì thu thừa. {@see isSettleable()} nói điều đó ra thành cờ để màn hình
 * biết TRƯỚC, thay vì để thu ngân phát hiện bằng một cái 422 giữa lúc khách
 * đứng đợi.
 *
 * `createdAt` là chuỗi thời gian **y như đã lưu**, cố ý KHÔNG chuẩn hoá sang
 * ISO-8601 như `OpenOrderSummary` (Ordering) làm. Lý do cụ thể, không phải sở thích: pos-web in thẳng trường này ra màn hình
 * (`{debt.created_at}` trong `debt-search-dialog`), nên đổi định dạng ở đây là
 * đổi giao diện — và đổi thành ISO thì thu ngân ở JST/ICT sẽ đọc một cái đuôi
 * `+00:00` nói sai giờ quán. Muốn chuẩn hoá thì phải làm cùng lượt với màn hình,
 * không đi ké một PR về ranh giới.
 */
final readonly class OpenAccountDebt
{
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public float $amount,
        public float $netAmount,
        public string $createdAt,
        public ?string $note,
    ) {}

    /**
     * Khoản nợ này có thu được qua `metadata.settles_payment_id` không.
     *
     * Ngưỡng 0.01 là một đơn vị tiền nhỏ nhất của các loại tiền hai chữ số thập
     * phân — cùng ngưỡng `OrderPaymentStoreRequest` dùng khi so số tiền thu nợ,
     * và phải giữ y hệt: hai ngưỡng lệch nhau nghĩa là màn hình cho phép một
     * khoản mà backend sẽ từ chối.
     */
    public function isSettleable(): bool
    {
        return abs($this->netAmount - $this->amount) < 0.01;
    }
}
