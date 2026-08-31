<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 — cổng Ordering công bố cho luồng ĐÁNH GIÁ SAU ĐƠN: **đơn này gồm những
 * dòng món nào mà khách được phép chấm sao**.
 *
 * `ProductReviewService` (CustomerEngagement) nhận nguyên model `CustomerOrder`
 * rồi tự đi `$order->items()`. Nó không chỉ là một cạnh trên đồ thị: nó đưa CẢ
 * đơn — trạng thái, tiền, thanh toán — vào tầm với của một service chỉ cần ba
 * cột của dòng món.
 *
 * ## "Được phép chấm sao" nghĩa là gì, và vì sao luật đó ở ĐÂY
 *
 * Món đã VOID bị loại. Đó là luật của Ordering (`OrderItemStatusEnum::Voided`
 * là từ vựng vòng đời dòng món), nên nó nằm sau cổng thay vì lặp lại ở mỗi chỗ
 * gọi. Bản cũ lọc đúng như vậy ở HAI chỗ trong cùng một file — chép rời nhau là
 * cách một trong hai chỗ âm thầm lệch khi trạng thái mới xuất hiện.
 *
 * Cổng KHÔNG kiểm "đơn đã đóng chưa": bản cũ chặn việc đó ở controller (404 khi
 * không thấy đơn, 422 khi chưa đóng) và cổng này không dời chốt chặn đó đi.
 */
interface ReviewableOrderLines
{
    /**
     * Dòng món CHƯA VOID của một đơn, theo thứ tự lưu trữ (bản cũ không sắp xếp).
     *
     * Trả mảng rỗng khi đơn không tồn tại — phía gọi đã kiểm sự tồn tại trước đó,
     * và một cổng đọc không nên throw cho câu hỏi "đơn này có gì".
     *
     * @return list<ReviewableOrderLine>
     */
    public function forOrder(string $orderId): array;
}
