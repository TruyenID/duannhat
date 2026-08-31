/**
 * #2524 — MỘT chỗ quyết định ô bàn ở màn tổng quan làm gì khi bấm.
 *
 * Trước đây điều kiện `disabled` và nhánh `onClick` là hai biểu thức viết rời,
 * và chúng đã lệch nhau: ô `occupied` không có `current_order_id` bị
 * `disabled` (nhìn như hỏng, không bấm được) trong khi nhân viên đang cần mở
 * đơn cho đúng bàn đó. Hai biểu thức rời là cách êm nhất để có một nút bấm được
 * mà bấm không ra gì, hoặc một nút chết trong khi vẫn còn việc để làm.
 *
 * Nay cả hai đọc CÙNG hàm này: `null` nghĩa là không có việc gì để làm ⇒
 * `disabled`.
 *
 * ## Vì sao "occupied mà không có đơn" lại mở đơn MỚI
 *
 * Đó là **bàn ma** — trạng thái đã đo trên production (本郷店 C-1 · A-3 · B-3,
 * #2524): khách quét QR làm bàn thành `occupied` rồi bỏ đi không gọi món, và
 * lượt dọn phiên treo đã không trả bàn về `free`. Không có đơn nào để mở, nên
 * việc đúng là mở đơn mới — chính thao tác nhân viên định làm khi có khách thật
 * ngồi xuống bàn đó. Nhãn trạng thái vẫn hiển thị "đang phục vụ" vì đó là giá
 * trị THẬT trong DB; giấu nó đi thì không ai biết dữ liệu đang lệch.
 *
 * `current_order_id` khác null nhưng không tra ra đơn thì **không** rơi vào
 * nhóm này: ở đó đơn có tồn tại, chỉ là không nằm trong danh sách đang tải
 * (đã đóng, khác ca, chưa fetch tới) — mở một đơn mới lên bàn đó là tạo đơn
 * thứ hai chồng lên đơn thật.
 */

export interface TableTileInput {
  status: string;
  current_order_id?: string | null;
  is_active?: boolean;
}

export type TableTileAction =
  /** Có đơn đang mở trên bàn — bấm để mở đơn đó. */
  | { kind: "open-order"; orderId: string }
  /** Bàn trống, hoặc bàn ma (occupied mà không có đơn) — bấm để tạo đơn mới. */
  | { kind: "create-order" }
  /** Không có việc gì: nút phải `disabled`. */
  | null;

/**
 * @param resolvedOrderId đơn tra được từ `current_order_id`, hoặc `undefined`
 *                        khi id có mà đơn không nằm trong danh sách đang tải.
 */
export function tableTileAction(
  table: TableTileInput,
  resolvedOrderId: string | undefined,
): TableTileAction {
  const isActive = table.is_active !== false;

  if (resolvedOrderId !== undefined) {
    return { kind: "open-order", orderId: resolvedOrderId };
  }

  if (!isActive) {
    return null;
  }

  if (table.status === "free") {
    return { kind: "create-order" };
  }

  // Bàn ma: occupied nhưng KHÔNG có id đơn nào để mở.
  if (table.status === "occupied" && !table.current_order_id) {
    return { kind: "create-order" };
  }

  return null;
}
