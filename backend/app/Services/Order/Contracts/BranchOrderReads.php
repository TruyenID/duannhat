<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1647 (#962 · 7a-5) — Ordering công bố những gì phía TIỀN cần biết về ĐƠN.
 *
 * ## Vì sao là cổng này chứ không phải "Ordering công bố nguyên câu truy vấn"
 *
 * `TillSessionService` (Payments) có bốn chỗ đọc `customer_orders`. Hai trong số
 * đó cần **cả hai** bảng trong một câu SQL:
 *
 *   - đơn CHƯA TRẢ = đơn đang mở của chi nhánh **trừ đi** đơn đã có payment thành công;
 *   - đơn VOID trong ca, kèm **số payment còn sống** của từng đơn.
 *
 * Cách dễ nhất là để Ordering công bố nguyên câu — và nó **sai chiều**: Ordering
 * sẽ phải truy vấn `order_payments`, tức ôm một mối quan tâm của Payments để trả
 * nợ ranh giới của Payments. Đổi một cạnh lấy một cạnh, và cạnh mới nằm ở chỗ
 * khó gỡ hơn.
 *
 * Nên cắt theo **quyền sở hữu dữ liệu**: cổng này chỉ trả dữ liệu **cấp đơn**,
 * còn phần lọc theo payment do Payments tự làm trên bảng của chính nó.
 *
 * ## Cái giá, và vì sao nó chấp nhận được
 *
 * Tách ra là **hai lượt truy vấn** thay vì một. Đo phạm vi trước khi lo:
 *
 * | chỗ | tập trung gian |
 * |---|---|
 * | đơn chưa trả | đơn ĐANG MỞ của MỘT chi nhánh — vài chục |
 * | đơn void | đơn void trong MỘT ca — thường bằng 0 |
 *
 * Cả hai bị chặn theo phạm vi sẵn, không phải "toàn bộ đơn hàng". Đây là lý do
 * lối tách khả thi ở ĐÂY mà chưa chắc khả thi ở chỗ khác — đừng chép kết luận
 * này sang một truy vấn không có trần.
 */
interface BranchOrderReads
{
    /*
     * #1664 — `OPEN_STATUSES` TỪNG ở đây và đã bị GỠ.
     *
     * #1647 dựng nó với lý do đúng: kéo tập trạng thái ra khỏi
     * `TillSessionService::ACTIVE_ORDER_STATUSES`, vì một hằng `public` trên
     * một service của **Payments** nghĩa là phía tiền đang giữ định nghĩa vòng
     * đời ĐƠN HÀNG. Nhưng #1596 đã làm đúng việc đó trước rồi, ở
     * {@see OrderStatusVocabulary::OPEN} — nên #1647 chép ra bản thứ HAI, y hệt
     * bảy phần tử, cùng thứ tự, **cùng thư mục này**.
     *
     * Hai bản sao không sai gì cho tới lần Ordering thêm một trạng thái đang-mở
     * (ví dụ `held`): người sửa cập nhật cái mình đang nhìn, cái kia đứng im, và
     * không gì kêu. Từ đó ca thu ngân bỏ sót đơn `held` khỏi danh sách đơn chưa
     * trả — tức lệch tiền, âm thầm.
     *
     * `OrderStatusVocabulary` nằm cùng namespace công bố này, nên chỗ gọi ngoài
     * module vẫn đọc được mà không phải với tay vào `Internal`: tính chất mà
     * #1647 cần vẫn còn nguyên, chỉ là nó vốn đã có sẵn.
     */

    /**
     * Đơn đang MỞ của chi nhánh, cũ nhất trước.
     *
     * "Đang mở" là định nghĩa của **Ordering** (tập trạng thái), không phải của
     * người gọi — trước #1647 tập đó là một hằng số `public` trên một service
     * của Payments, nên Payments đang giữ định nghĩa vòng đời đơn hàng.
     *
     * @return list<OpenOrderSummary>
     */
    public function openForBranch(string $branchId): array;

    /**
     * Đơn bị VOID của chi nhánh trong một cửa sổ thời gian (theo `voided_at`).
     *
     * `$from`/`$to` là biên NỬA MỞ ở phía người gọi truyền vào — giữ nguyên
     * ngữ nghĩa `>=` / `<=` của bản cũ, không tự siết lại.
     *
     * @return list<VoidedOrderSummary>
     */
    public function voidedForBranchBetween(string $branchId, ?string $from, ?string $to): array;

    /**
     * #2696 — đơn còn `paying`/`checkout` SINH RA TRƯỚC một mốc thời gian.
     *
     * Mốc đó là **ranh ca** (lần đóng ca gần nhất), do Payments truyền vào —
     * Ordering không biết gì về ca thu ngân và không nên biết. Ngược lại,
     * Payments không được tự truy `customer_orders`: đó là cạnh deptrac chặn
     * (`Payments on Ordering`) và là lý do cổng này tồn tại.
     *
     * "Paying/checkout" là định nghĩa vòng đời của Ordering
     * ({@see OrderStatusVocabulary::UNRESOLVED_MONEY}). Chỉ trả dữ liệu **cấp
     * đơn**. Phần "đã thu bao nhiêu / còn thiếu bao nhiêu" do Payments tự tính
     * trên `order_payments`.
     *
     * @return list<UnresolvedOrderSummary>
     */
    public function unresolvedForBranchBefore(string $branchId, string $cutoffIso): array;

    /**
     * Tổng số khách của một tập đơn.
     *
     * @param  list<string>  $orderIds
     */
    public function guestCountForOrders(array $orderIds): int;

    /**
     * Tổng SỐ LƯỢNG món (点数) của một tập đơn, BỎ QUA dòng đã void.
     *
     * Đi cùng {@see guestCountForOrders} vì phiếu ca in chúng cạnh nhau (点数 /
     * 人). Nhưng chúng đọc HAI bảng khác nhau — số khách trên `customer_orders`,
     * số món trên `customer_order_items` — nên "bỏ dòng void" chỉ có nghĩa ở
     * method này. Luật ấy là ĐỊNH NGHĨA của Ordering: một dòng bị huỷ không còn
     * là món đã bán, và phía tiền không được tự quyết lại điều đó.
     *
     * @param  list<string>  $orderIds
     */
    public function itemQuantityForOrders(array $orderIds): int;

    /**
     * Doanh thu gộp lại theo một tập đơn.
     *
     * @param  list<string>  $orderIds
     */
    public function revenueForOrders(array $orderIds): OrderRevenueRollup;
}
