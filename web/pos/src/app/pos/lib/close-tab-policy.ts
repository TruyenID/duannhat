import type { CustomerOrder } from "../types";

/**
 * Đóng tab (✕) là thao tác CỤC BỘ trên dải tab: nó gỡ tab khỏi màn hình và
 * KHÔNG đụng gì tới đơn — đơn ở lại `open` trên máy chủ, không xoá, không huỷ.
 * Muốn huỷ thì có nút "Huỷ đơn" riêng (có lý do + audit log).
 *
 * Trước đây ✕ gọi `DELETE /orders/{id}` — xoá cứng, không nhật ký. Dải tab là
 * chỗ làm việc, không phải sổ đơn; một thao tác dọn màn hình không được phép
 * phá dữ liệu.
 *
 * Nhưng "không đụng vào đơn" mở ra một lỗ: đóng tab xong thì mở lại đơn bằng
 * đường nào? POS chỉ có HAI lối vào một đơn đang mở:
 *
 *   - chạm vào bàn đang phục vụ ở màn Tổng quan (`TablesOverview` map
 *     `table.current_order_id` → đơn), tức đơn phải CÓ BÀN;
 *   - ngăn kéo "Đơn takeaway", chạy trên feed lọc cứng `order_type=takeaway`.
 *
 * Đơn không thoả cái nào — `spot` ("Nhanh", **mặc định** của hộp thoại tạo đơn)
 * hoặc `dine_in` chưa gán bàn — sẽ không còn lối nào mở lại sau khi tab đóng.
 * Nó vẫn sống trong DB và vẫn chảy vào báo cáo, chỉ là thu ngân không thấy nữa.
 * Đó chính là kiểu hỏng im lặng mà việc bỏ xoá cứng phải tránh, nên ca này —
 * và CHỈ ca này — hỏi lại trước khi đóng.
 *
 * Đảo lại thì sao: cho `warn_unreachable` thành `close` sẽ khiến mỗi lần thu
 * ngân bấm ✕ trên một đơn Nhanh là một đơn mồ côi, không ai đếm được.
 */
export type CloseTabDecision =
  /** Gỡ tab ngay, không hỏi — đơn còn lối mở lại. */
  | "close"
  /** Hỏi lại: đóng xong sẽ không mở lại được đơn này từ POS. */
  | "warn_unreachable";

export function decideCloseTab(order: CustomerOrder | undefined): CloseTabDecision {
  // KHÔNG BIẾT đơn ⇒ hỏi, không im lặng đóng (#2528 review).
  //
  // Bản đầu trả `close` ở đây và biện minh là "đoán sai vô hại". Không vô hại,
  // và cửa sổ để nó xảy ra là thật: dải tab persist qua localStorage nên nó
  // hiện NGAY sau khi tải lại trang, còn `useOpenOrders` — nguồn duy nhất
  // `getOrderFromCache` tra được cho một tab không hoạt động — thì chưa về.
  // Bấm ✕ trong khoảng đó đóng thẳng tab của một đơn Nhanh, tức tạo đúng cái
  // đơn mồ côi mà cả module này sinh ra để chặn.
  //
  // Và đơn mồ côi không nằm im: `open` là trạng thái mà `OpenOrderSkuUsage`
  // dùng để CHẶN xoá SKU, nên rác đó khoá thao tác ở admin mà không ai truy
  // được nguồn.
  //
  // Giá của hướng ngược lại là một lần bấm xác nhận thừa trong một cửa sổ
  // hiếm. Đó đúng là "sai theo hướng an toàn" mà docblock trên đã đặt ra cho
  // nhánh `tables` — nhánh này chỉ là áp cùng nguyên tắc cho nhất quán.
  if (!order) return "warn_unreachable";
  // `tables` là whenLoaded — vắng mặt trên đơn vừa tạo. Vắng ⇒ coi như chưa gán
  // bàn, tức cảnh báo thừa chứ không phải bỏ sót; sai theo hướng an toàn.
  if ((order.tables?.length ?? 0) > 0) return "close";
  if (order.order_type === "takeaway") return "close";
  return "warn_unreachable";
}
