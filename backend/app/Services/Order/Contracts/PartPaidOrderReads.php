<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1992 — Ordering công bố "đơn nào của chi nhánh này khách trả chưa đủ".
 *
 * Cùng lối cắt với `BranchOrderReads` / `OrderCustomerContacts`: chủ sở hữu dữ
 * liệu là chủ truy vấn. `DebtController::partPaid()` trước đó chạy HAI câu
 * `DB::table('customer_orders')` — một để gom nhóm, một để lấy chi tiết từng đơn
 * — và câu đó lặp lại vị ngữ mà `CustomerOutstandingOrderService` đã viết bằng
 * Eloquent. Hai bản viết tay của cùng một định nghĩa.
 *
 * Giờ cả hai đường đọc **cùng một scope** trên model
 * (`CustomerOrder::partPaid()`), và cổng này là cách Composition với tới nó mà
 * không phải chạm model của module khác.
 *
 * ## Vì sao KHÔNG gom nhóm theo khách ở đây
 *
 * Gom nhóm + đếm + cộng là hình dạng của MỘT màn hình. Ordering trả sự thật cấp
 * đơn; tầng lắp ráp dựng báo cáo. Cùng ranh giới mà `OpenAccountDebtReads`
 * (Payments) giữ cho sổ nợ ghi sổ, và giữ như vậy thì một màn hình thứ hai —
 * xếp theo đơn, theo ngày, theo bàn — không phải xin thêm method.
 *
 * ## Chỉ trả đơn CÓ KHÁCH
 *
 * Tra cứu là tra **theo khách**; một đơn vãng lai không có ai để quy trách hay
 * đòi. Lúc viết cổng này chi nhánh thật chưa có đơn nào như vậy; nếu sau này có
 * thì chúng cần báo cáo riêng, không phải một nhóm `null` trong báo cáo này.
 */
interface PartPaidOrderReads
{
    /**
     * Đơn trả chưa đủ của một chi nhánh, cũ nhất trước (`opened_at`).
     *
     * KHÔNG có bộ lọc thời gian, và đó là chủ ý: một đơn đang được phục vụ NGAY
     * LÚC NÀY cũng là `paying` với `paid < total`, nên mọi mốc cắt đều là phỏng
     * đoán về thời điểm khách đã bỏ đi. Mỗi dòng mang theo mã đơn và `openedAt`
     * để người đọc tự phân biệt — việc mà một luật cứng trong đây không làm nổi.
     *
     * @return list<PartPaidOrder>
     */
    public function forBranch(string $branchId): array;
}
