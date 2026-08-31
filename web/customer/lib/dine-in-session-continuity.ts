/**
 * Phiên dine-in của khách còn là phiên cũ, hay đã bị thay? (#2634)
 *
 * ## Chuyện gì xảy ra
 *
 * Nhân viên trả bàn về `free` ⇒ `TableSession` đang mở bị đóng (#2611). Nhưng
 * máy khách vẫn giữ `localStorage[dine_in_session_<qrToken>]` của phiên CŨ.
 *
 * Lần gọi món kế tiếp, backend phân giải phiên **theo BÀN** — lấy phiên `open`
 * mới nhất, không có thì **tạo mới** (`CustomerTableOrderService`). Nên khách
 * không gặp lỗi nào cả: họ lặng lẽ được cấp một phiên mới và một đơn mới, còn
 * những món đã gọi nằm lại ở đơn cũ.
 *
 * **Giỏ hàng tách đôi, không một thông báo nào.** Im lặng còn tệ hơn báo lỗi, và
 * #2611 làm nó xảy ra thường xuyên hơn.
 *
 * ## Vì sao KHÔNG nhìn `tables.status`
 *
 * Cách hiển nhiên là gate theo trạng thái bàn. Đừng. `page.tsx` đã ghi rõ, kèm
 * ngày trả giá:
 *
 * > Tuyệt đối KHÔNG redirect khi bàn `free` vì đó là bàn đã được dọn, sẵn sàng
 * > cho lượt khách tiếp theo — kể cả khi localStorage còn dư session id từ phiên
 * > trước (bug khẩn 2026-06-12: device dư localStorage bị redirect oan khỏi bàn
 * > free).
 *
 * Mà #2611 đóng phiên đúng vào lúc bàn về `free`. Gate theo status là dựng lại
 * đúng lỗi đó.
 *
 * So **ID phiên** thì không dính bẫy ấy: nó phân biệt được "phiên của tôi vừa bị
 * thay" với "tôi có rác localStorage từ hôm qua, và bàn giờ trống" — hai thứ mà
 * `status` gộp làm một.
 */

export type SessionContinuity =
  /** Chưa từng join trên máy này — luồng bình thường. */
  | "fresh"
  /** Phiên khách đang giữ vẫn là phiên hiện hành. */
  | "same"
  /** Phiên khách đang giữ ĐÃ BỊ THAY — món cũ không còn thuộc đơn đang mở. */
  | "replaced";

export function sessionContinuity(
  storedSessionId: string | null | undefined,
  serverSessionId: string | null | undefined,
): SessionContinuity {
  if (!storedSessionId) return "fresh";

  // Server không trả phiên nào: không kết luận được, và đoán "đã bị thay" sẽ
  // đá khách ra chỉ vì một response thiếu field. Giữ nguyên luồng — lượt gọi
  // món kế tiếp vẫn đi qua backend, nơi có sự thật.
  if (!serverSessionId) return "same";

  return storedSessionId === serverSessionId ? "same" : "replaced";
}
