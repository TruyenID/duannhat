<?php

namespace App\Services\Shop\Contracts;

use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * #1590 — cổng CHIẾM DỤNG BÀN mà Organization công bố cho Ordering.
 *
 * `App\Models\Table` là hạng mục nợ lớn nhất còn lại của Organization: 37 cạnh
 * vào, 36 trong đó từ Ordering. Nhưng đo lại thì nó KHÔNG phải ranh giới vẽ sai
 * chỗ — chuyển hẳn model sang Ordering đo được **−8 cạnh nhưng +29 cặp baseline
 * mới**, vì phía Organization (CRUD bàn/khu, template, TMS) đọc nó thật. Bàn là
 * dùng chung thật; cái đi sai hướng là **cách** Ordering chạm vào nó.
 *
 * Nên ranh giới cắt theo CỘT, không theo bảng:
 *
 *   - `current_order_id` + `status`  → chiếm dụng, do đơn hàng lái  → cổng này
 *   - code · zone · sức chứa · template → sơ đồ mặt bằng            → Organization giữ
 *
 * **Luật lệ đơn hàng KHÔNG nằm ở đây.** Các cổng 409/422 ("bàn đã có đơn khác",
 * "không gỡ được bàn cuối của đơn dine-in") ở lại Ordering, vì chúng là luật của
 * ĐƠN chứ không phải của BÀN. Cổng này chỉ làm hai việc: khoá hàng rồi đưa ra
 * ảnh chụp để Ordering tự phán, và ghi lại kết quả.
 *
 * Mọi method `lock*` PHẢI được gọi trong một transaction. `lockForUpdate()` ở
 * đây là thứ đóng cuộc đua TOCTOU của plan-006 (hai request cùng đọc
 * `current_order_id === null` rồi cùng gán) — bỏ nó đi là mở lại đúng lỗi đó.
 */
interface TableOccupancy
{
    /**
     * Khoá và chụp nhiều bàn theo id. Trả về ĐÚNG những bàn tồn tại — caller
     * so số lượng để biết có id lạ hay không.
     *
     * @param  list<string>  $tableIds
     * @return list<TableOccupancySnapshot>
     */
    public function lockAll(array $tableIds): array;

    /**
     * Khoá và chụp một bàn, giới hạn trong chi nhánh — id của chi nhánh khác
     * phải 404, không được rò sự tồn tại sang tenant khác.
     *
     * @throws ModelNotFoundException
     */
    public function lockInBranch(string $branchId, string $tableId): TableOccupancySnapshot;

    /**
     * Khoá và chụp một bàn ĐANG do đơn này giữ. Không giữ ⇒ 404.
     *
     * @throws ModelNotFoundException
     */
    public function lockHeldByOrder(string $tableId, string $orderId): TableOccupancySnapshot;

    /**
     * Gán các bàn cho một đơn (chiếm dụng).
     *
     * @param  list<string>  $tableIds
     */
    public function assign(array $tableIds, string $orderId): void;

    /**
     * Id các bàn đơn này đang giữ — đọc thuần, không khoá, không đổi gì.
     *
     * @return list<string>
     */
    public function idsHeldBy(string $orderId): array;

    public function countHeldBy(string $orderId): int;

    /**
     * Nhả mọi bàn đơn này đang giữ. Trả về id đã nhả (caller cần để phát sự kiện).
     *
     * @return list<string>
     */
    public function releaseByOrder(string $orderId): array;

    /**
     * Nhả các bàn theo id. `$heldByOrderId` khác null thì chỉ nhả bàn đang do
     * đúng đơn đó giữ — chốt chặn để không nhả nhầm bàn của đơn khác trong một
     * lần thay-thế-tập-bàn.
     *
     * @param  list<string>  $tableIds
     */
    public function releaseByIds(array $tableIds, ?string $heldByOrderId = null): void;

    /**
     * Nhả mọi bàn của một đơn VỪA THANH TOÁN XONG (#491, #962).
     *
     * Khác {@see releaseByOrder()} đúng một điểm: bàn có thể về `cleaning` thay
     * vì `free`, tuỳ chính sách shop/HQ (`table_status_after_payment`). Quyết
     * định đó là của ĐƠN — `OrderClosingService` phân giải nó qua
     * `EffectiveOrderPolicyService` — nên nó vào đây dưới dạng tham số.
     *
     * Tham số là `bool`, KHÔNG phải chuỗi trạng thái, vì cùng lý do
     * {@see markOccupied()} không nhận trạng thái đích: một `setStatus(string)`
     * chung sẽ cho mọi module đặt bàn về `reserved` / `out_of_service` — những
     * giá trị do người vận hành quyết, không phải do một đơn đóng lại. Ở đây chỉ
     * có đúng hai kết cục hợp lệ, nên kiểu dữ liệu nói đúng bằng ấy.
     */
    public function releaseByOrderAfterPayment(string $orderId, bool $needsCleaning): void;

    /**
     * Đánh dấu một bàn ĐANG CÓ KHÁCH, khi CHƯA có đơn nào (#1622).
     *
     * Đây là ca của phiên QR: khách quét mã, bàn chuyển `free`/`paid` →
     * `occupied` **trước** khi đơn tồn tại, nên {@see assign()} — vốn gán
     * `current_order_id` — không dùng được.
     *
     * Cố ý KHÔNG nhận trạng thái đích làm tham số: cổng này chỉ mở đúng một
     * chuyển đổi. Một `setStatus(string $status)` chung sẽ cho phép mọi module
     * đặt bàn về `cleaning` / `out_of_service` — những trạng thái do người vận
     * hành cửa hàng quyết, không phải do luồng khách quét mã.
     */
    public function markOccupied(string $tableId): void;

    /**
     * Nhả một bàn về `free` mà KHÔNG đụng `current_order_id` (#962).
     *
     * Cố ý không dùng {@see releaseByIds()}: cái đó xoá luôn `current_order_id`,
     * còn đây là nút "Đổi bàn" của khách trên customer-web — bàn về trống nhưng
     * con trỏ đơn (nếu có) không phải việc của thao tác đó. Hai câu lệnh UPDATE
     * khác nhau, nên hai method khác nhau; gộp lại là đổi hành vi trong lúc dời
     * ranh giới.
     *
     * Cùng lý do với {@see markOccupied()}: mỗi method mở đúng MỘT chuyển đổi,
     * không có `setStatus(string $status)` chung để module khác đặt bàn về
     * `cleaning` / `out_of_service` — những trạng thái do người vận hành quyết.
     */
    public function markFree(string $tableId): void;

    /**
     * Khoá và chụp một bàn ĐANG HOẠT ĐỘNG theo `qr_token` (#962).
     *
     * `null` = không có bàn active nào mang token đó; caller quyết 404 hay
     * không, cổng không ném thay.
     *
     * PHẢI gọi trong transaction — `lockForUpdate()` ở đây là thứ tuần tự hoá
     * hai lần quét QR đồng thời trên cùng một bàn.
     */
    public function lockActiveByQrToken(string $qrToken): ?TableQrSnapshot;

    /**
     * Chụp một bàn theo id, KHÔNG khoá, KHÔNG lọc `is_active`.
     *
     * Dùng cho đường tạo đơn qua QR, nơi caller đã cầm id bàn từ bước trước.
     */
    public function snapshotById(string $tableId): ?TableQrSnapshot;
}
