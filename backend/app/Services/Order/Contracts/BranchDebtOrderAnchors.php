<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1993 — Ordering công bố hai thứ mà sổ nợ cần biết về ĐƠN: **ai mua** và
 * **đơn nào**.
 *
 * Cùng lối cắt với {@see BranchOrderReads} và {@see OrderCustomerContacts}: cắt
 * theo QUYỀN SỞ HỮU DỮ LIỆU, không phải theo màn hình. `DebtController` trước đó
 * `JOIN customer_orders` thẳng trong cùng một câu SQL với `order_payments`, và
 * chỗ đó sống được **chỉ vì controller là Composition** — tầng duy nhất bộ quét
 * `architecture:raw-table-reads` bỏ qua. Đưa nguyên câu ấy vào một service của
 * Payments là +2 lần đọc thô xuyên module, mà ngưỡng đang là 0.
 *
 * ## Ba việc, không phải một
 *
 * Cổng trả về **map theo id đơn**, và một id KHÔNG có khoá trong map mang nghĩa
 * "đơn này không còn thuộc phạm vi" — gộp cả ba lý do làm một:
 *
 *   1. đơn không thuộc chi nhánh đang hỏi (cách ly tenant — plan-038 audit
 *      CRITICAL: thiếu nó thì sổ nợ gom nợ của TOÀN BỘ cơ sở dữ liệu);
 *   2. đơn đã **xoá mềm** — và điều này đúng **theo cấu trúc**, không theo trí
 *      nhớ: hiện thực đọc qua model `CustomerOrder`, nên `SoftDeletes` scope tự
 *      áp. Đó chính là bài học #1801 (`LedgerDriftScanner` bỏ `DB::table` để
 *      được scope y hệt phép chiếu nó kiểm) và là một nửa lý do #1993 tồn tại;
 *   3. đơn không tồn tại.
 *
 * Người gọi không cần phân biệt ba ca đó: cả ba đều có nghĩa **khoản nợ này
 * không được tính**.
 *
 * ## Chi nhánh: hỏi ở đây, không suy từ `order_payments.branch_id`
 *
 * `order_payments` CÓ cột `branch_id` riêng, và Payments dùng nó để lọc thô cho
 * rẻ (cột có index). Nhưng nó do người gọi API điền lúc tạo payment, trong khi
 * `brand_id` đã phải sửa để lấy từ ĐƠN (#1800) đúng vì lý do ấy. Phạm vi cuối
 * cùng vì thế vẫn phải là chi nhánh của ĐƠN — hai cột lệch nhau thì cổng này là
 * bên nói lời cuối, y như trước khi tách.
 */
interface BranchDebtOrderAnchors
{
    /**
     * @param  list<string>  $orderIds
     * @return array<string, DebtOrderAnchor> khoá là id đơn; thiếu khoá = ngoài phạm vi
     */
    public function anchorsForBranch(string $branchId, array $orderIds): array;
}
