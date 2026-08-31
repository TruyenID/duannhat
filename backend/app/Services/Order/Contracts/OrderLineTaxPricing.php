<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 · 7a-7 — Ordering xin Pricing đóng thuế cho từng dòng đơn, thay vì tự cầm
 * `TaxResolver` và model `TaxType` của Pricing.
 *
 * Docblock ở thư mục này KHÔNG được viết ra tên model đầy đủ (kể cả trong văn xuôi):
 * `DomainMutationContractsTest` quét cả file, không riêng phần `use`, vì một hợp đồng
 * công bố "chỉ nhắc tên model trong comment" là bước đầu của việc nhắc nó trong chữ ký.
 *
 * Do **Ordering** khai vì lý do publish-theo-namespace — xem
 * {@see OrderMenuLineDirectory}. Pricing hiện thực.
 *
 * ## Vì sao là "batch" chứ không phải một hàm phẳng
 *
 * Đây KHÔNG phải trang trí. `TaxResolver` memo hoá mặc định
 * branch/brand/menu/section **theo từng instance**, và docblock của nó nói rõ:
 * *"create a fresh resolver per order operation so the memo can't go stale
 * mid-request"*. Đường thêm món cố ý dựng MỘT resolver cho cả lô
 * (`plan-043 §7`) — 20 dòng đơn thì 1 truy vấn mặc định chứ không phải 20.
 *
 * Một cổng phẳng `resolveForLine(...)` giải qua container sẽ hoặc dựng resolver mới
 * mỗi dòng (mất memo, N+1 quay lại — đã bị trần đếm-truy-vấn của menu endpoint bắt
 * một lần rồi), hoặc dùng chung một resolver singleton sống suốt request (memo ôi
 * thiu giữa hai thao tác — đúng cái docblock kia cấm). `beginBatch()` giữ nguyên
 * vòng đời cũ: một lô = một memo.
 *
 * **Một lô = một thao tác đơn hàng.** Đừng giữ `OrderLineTaxBatch` qua nhiều
 * thao tác, và đừng dùng lại nó sau khi thuế/menu vừa bị sửa trong cùng request.
 */
interface OrderLineTaxPricing
{
    /**
     * Mở một lô giải thuế mới — memo mặc định branch/brand/menu/section dùng chung
     * cho mọi dòng trong lô, và chết cùng lô.
     */
    public function beginBatch(): OrderLineTaxBatch;
}
