<?php

namespace App\Services\Order\Contracts;

interface OrderQueryPort
{
    public function findById(string $organizationId, string $orderId): ?OrderSnapshot;

    /**
     * Khoá dòng đơn TRONG transaction của người gọi, rồi trả ảnh chụp của nó.
     *
     * Tên nói "settlement" vì tất toán là chỗ gọi đầu tiên (#1544), nhưng đây là
     * đường **khoá-rồi-đọc** dùng chung: #1603 đưa cả đường mint QR PayPay qua
     * đây thay vì tự `CustomerOrder::lockForUpdate()`. Tên giữ nguyên vì đổi tên
     * một method của hợp đồng đã công bố là một thay đổi phá vỡ, không đáng cho
     * một cái tên hẹp hơn thực tế — nhưng đừng đọc nó như một giới hạn.
     *
     * Khác {@see OrderRowLock::lockForUpdate()} ở hai điểm, và cả hai đều cố ý:
     * cái kia trả `void` (người gọi chỉ cần **hàng đợi**) và dùng query THÔ nên
     * khoá được cả đơn đã xoá mềm; cái này trả **giá trị** và đi qua model
     * builder nên đơn đã xoá mềm **không khớp**. Chọn nhầm là đổi tập dòng bị
     * khoá mà không có lỗi nào phát ra.
     *
     * **Phải gọi trong một transaction.** `SELECT … FOR UPDATE` ngoài transaction
     * nhả khoá ngay khi câu lệnh xong: chạy, không lỗi, và không khoá gì cả.
     */
    public function findForSettlement(string $organizationId, string $orderId): ?OrderSnapshot;

    /**
     * Đơn đã trả đủ chưa — theo luật của ORDERING, không phải theo phép trừ của
     * người gọi.
     *
     * Payments hỏi câu này để quyết có phải tất toán đơn hay không, và trước
     * #1594 nó tự trả lời bằng `OrderClosingService::isPaidEnough($order)` —
     * tức phải cầm model. Nhưng câu trả lời KHÔNG phải một phép trừ đơn giản:
     * dung sai làm tròn lấy từ `shop_order_settings.currency_code`, và đọc sai
     * nguồn tiền tệ từng ghi nhận 1.99 USD doanh thu ma (#821 E3, 'JPY' fallback
     * cho dung sai 2 yên trên hoá đơn USD).
     *
     * Để luật đó ở một phía. Người gọi hỏi, không tự tính.
     */
    public function isPaidInFull(string $organizationId, string $orderId): bool;
}
