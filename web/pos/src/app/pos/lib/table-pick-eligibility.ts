/**
 * Bàn nào chọn được trong `TablePicker` (#2606).
 *
 * Tách khỏi component vì đây là **luật**, không phải giao diện — và vì luật này
 * phải là GƯƠNG của cổng backend, nên nó cần được ghim bằng test chứ không nằm
 * lẫn trong JSX.
 *
 * ## Cổng thật nằm ở backend
 *
 * `WritesCustomerOrders::validateAndAssignTables` chặn bằng `$table->isHeld()`
 * — tức **đã có đơn khác giữ** — rồi cho phép rõ ràng ba trạng thái:
 *
 * ```php
 * // Allow free, reserved, OR occupied (customer just occupied but hasn't ordered yet)
 * ```
 *
 * ## Lỗi mà module này sửa
 *
 * pos-web trước đây gộp `status === "occupied"` vào "đã bị giữ", nên bàn có
 * khách vừa quét QR ngồi xuống **mà chưa gọi món** thì không chọn được — đúng
 * cái trạng thái CẦN gán đơn nhất. Nhân viên hết đường, và lối thoát duy nhất
 * là ép bàn về `free`, thứ để lại `TableSession` mở và đẻ ra "bàn ma" của #2596.
 *
 * Đo trên production 2026-08-12: ba bàn ở trạng thái này, session mở 3 phút /
 * 1,2 giờ / 2,0 giờ — khách thật đang ngồi, không phải dữ liệu hỏng.
 *
 * `tables-overview` đã sửa nửa của nó ở #2524 (`tableTileAction`); đây là nửa
 * còn lại, và hai chỗ nay nói cùng một luật.
 */

export interface TablePickInput {
  /** Đơn đang giữ bàn. Khác `null` ⇒ backend sẽ 422. */
  current_order_id?: string | null;
  status: string;
  is_active?: boolean;
}

/**
 * Bàn KHÔNG chọn được vì lý do thuộc về chính nó.
 *
 * `disabledTableIds` của call-site (ví dụ ChangeTableDialog loại bàn hiện tại)
 * là chuyện riêng của màn hình đó, cố ý không nằm ở đây.
 */
export function isTablePickBlocked(table: TablePickInput): boolean {
  // Đã có đơn khác giữ — gương của `isHeld()`.
  if (table.current_order_id != null) return true;

  if (table.is_active === false) return true;

  // Bàn đang dọn hoặc ngừng dùng thì backend cũng từ chối.
  if (table.status === "cleaning" || table.status === "out_of_service") {
    return true;
  }

  // Còn lại — free · reserved · occupied-chưa-có-đơn — đều gán được.
  return false;
}
