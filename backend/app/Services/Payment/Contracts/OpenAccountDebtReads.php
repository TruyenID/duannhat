<?php

declare(strict_types=1);

namespace App\Services\Payment\Contracts;

/**
 * #1993 — "khoản nợ ghi sổ nào của chi nhánh này còn mở", do **Payments** trả
 * lời.
 *
 * Trước đây toàn bộ luật này là một câu `DB::table` nằm trong
 * `Shop\DebtController::openDebtQuery()`. Nó dựng nên `GET /pos/debts`,
 * `GET /pos/debts/{customer}` và `GET /shops/{slug}/debts` — tức con số thu ngân
 * đọc to lên trước khi nhận tiền, và tập dòng mà một khoản thu nợ ghim vào qua
 * `settles_payment_id`. Luật tiền, sống trong một controller.
 *
 * ## Cái gì ở lại đây, cái gì đi ra
 *
 * Ở LẠI (đều là luật tiền, và đều đã trả giá bằng một sự cố thật):
 *
 *   - **nợ là một SỐ RÒNG, không phải một bộ lọc** (#821 A6). Dòng hoàn được
 *     mang theo rồi bù trừ, chứ không loại bỏ: `refund()` lật dòng gốc sang
 *     `refunded` vô điều kiện, nên loại cả hai làm biến mất phần còn nợ của một
 *     khoản bị hoàn MỘT PHẦN — nợ 5.000.000 hoàn 1.000 thì 4.999.000 rơi khỏi
 *     mọi báo cáo;
 *   - **settlement phải còn SỐNG** mới xoá được nợ: thất bại (thẻ từ chối) thì
 *     không, đã hoàn thì trả nợ lại, và bản đảo của chính settlement không được
 *     đóng vai settlement (nó thừa kế `settles_payment_id`);
 *   - **xoá mềm** ở cả dòng nợ lẫn dòng settlement (#1993 — chính là lỗi mở ra
 *     issue này).
 *
 * ĐI RA — vì không phải dữ liệu của Payments: khách nào (`customer_orders`), tên
 * và số điện thoại của khách (`customers`). Chúng đi qua `BranchDebtOrderAnchors`
 * (Ordering) và `CustomerDirectory` (CustomerEngagement).
 *
 * ## Vì sao KHÔNG gom nhóm theo khách ở đây
 *
 * Vì Payments không biết "khách" là gì — nó chỉ có `customer_order_id`. Gom nhóm
 * là việc của tầng lắp ráp, sau khi hỏi Ordering. Đẩy nó vào đây sẽ buộc Payments
 * đọc `customer_orders`, tức đúng cạnh vừa gỡ ra.
 */
interface OpenAccountDebtReads
{
    /**
     * Nợ còn mở của một chi nhánh, cũ nhất trước, đã bù trừ hoàn.
     *
     * `$from`/`$to` là **NGÀY KINH DOANH của chi nhánh** (`Y-m-d`), không phải
     * mốc UTC. Hiện thực quy đổi qua `BusinessClock::utcRangeForBusinessDates()`
     * thành khoảng nửa mở `[from 00:00, until+1 00:00)` theo múi giờ quán —
     * #1091. Bản cũ đem thẳng chuỗi so với một cột UTC, nên ở JST chín tiếng đầu
     * mỗi ngày bán hàng bị tính sang ngày hôm trước.
     *
     * Phạm vi lọc thô là `order_payments.branch_id`. Cột đó do người gọi API
     * điền lúc tạo payment, nên nó là **bộ lọc rẻ, không phải lời cuối**: chi
     * nhánh có thẩm quyền là chi nhánh của ĐƠN, và `BranchDebtOrderAnchors`
     * (Ordering) chốt lại điều đó. Đừng bỏ bước ấy — bỏ là mở lại đúng lỗ cách ly tenant mà plan-038 xếp
     * mức CRITICAL.
     *
     * @return list<OpenAccountDebt>
     */
    public function openDebtsForBranch(string $branchId, ?string $from = null, ?string $to = null): array;

    /**
     * Trong tập đơn này, đơn nào còn khoản nợ ghi sổ CHƯA tất toán (#2063).
     *
     * Theo LÔ, không phải theo từng đơn: cờ "đơn treo" được đóng dấu lên các
     * đường ĐỌC vốn trả về danh sách đơn, nên một phép hỏi mỗi đơn là N+1 trên
     * đúng màn hình bận nhất của quán.
     *
     * Dùng lại NGUYÊN câu truy vấn nợ đang có (`openDebtRows`) chứ không viết
     * điều kiện thứ hai — ba luật tiền trong đó đều đã trả giá bằng sự cố thật:
     * nợ là số RÒNG (#821 A6), settlement phải còn SỐNG mới xoá được nợ, và xoá
     * mềm phải lọc ở cả hai vế (#1993). Một bản chép sẽ đánh rơi ít nhất một.
     *
     * @param  iterable<string>  $orderIds
     * @return list<string> id của những đơn CÒN nợ mở, không kèm thứ tự
     */
    public function orderIdsWithOpenDebt(iterable $orderIds): array;
}
