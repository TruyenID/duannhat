/**
 * #2804 (theo dõi sau #2790) — phân loại kết quả `stripe.confirmPayment`.
 *
 * Tách khỏi component vì đây là **luật tiền**, và luật tiền phải test được mà
 * không cần dựng React + Stripe.js. Đo được ở #2804 trước khi tách: tiêm lại
 * đúng lỗi của #2790 (cho `requires_payment_method` rơi về "đã thu tiền") thì
 * **594/594 test vẫn xanh** — vì bài ratchet duy nhất chạm chỗ này là loại đọc
 * MÃ NGUỒN khớp chuỗi, mà chuỗi thì vẫn còn nguyên sau khi tiêm.
 *
 * Ba kết cục, và chúng KHÔNG đối xứng:
 *
 * - `succeeded` — tiền đã thu.
 * - `pending` — #1125: konbini / 銀行振込 vừa hiện phiếu, hoặc 3DS đang mở.
 *   Chưa có tiền nhưng cũng CHƯA hỏng; webhook chốt sau.
 * - `error` — mọi thứ còn lại. Quan trọng nhất là `requires_payment_method`:
 *   đó chính là hình dạng 16 lượt trả thẻ chết trên production ở #2790. Báo nó
 *   là "thành công" nghĩa là đơn được đóng mà **không có tiền**.
 */

/** Trạng thái nghĩa là "chưa xong nhưng chưa hỏng" — xem #1125. */
export const AWAITING_STATUSES: readonly string[] = ["processing", "requires_action"];

export type ConfirmGateOutcome = "succeeded" | "pending" | "error";

export interface ConfirmDecision {
  /** Tiền ĐÃ thu. Chỉ đúng một trạng thái cho ra `true`. */
  succeeded: boolean;
  /** Chưa có tiền, chưa hỏng — caller hiện màn hình chờ. */
  pending: boolean;
  /**
   * Khoá i18n hiển thị cho khách, `null` khi không phải lỗi. Là KHOÁ chứ không
   * phải câu chữ: thông điệp lọt ra ngoài i18n thì khách Nhật đọc tiếng Anh.
   */
  errorKey: "cardNotCharged" | null;
  /** Trạng thái đưa vào deferred-submit gate (#2741). */
  gate: ConfirmGateOutcome;
}

/**
 * KHÔNG có nhánh cho `requires_capture`: đường thanh toán này chưa bao giờ tạo
 * PaymentIntent với `capture_method: manual` (đo 2026-08-13 — chỉ
 * `StripeTerminalService` đụng capture_method, và nó đặt `automatic`; client
 * không truyền `captureMethod` ở đâu cả). Nếu sau này có ai bật manual capture
 * thì trạng thái đó rơi vào nhánh `error` ở đây và khách sẽ bị báo lỗi trong
 * khi tiền ĐÃ được duyệt — sửa hàm này TRƯỚC khi bật, đừng sửa sau.
 */
export function decideConfirmOutcome(status: string | null | undefined): ConfirmDecision {
  if (status === "succeeded") {
    return { succeeded: true, pending: false, errorKey: null, gate: "succeeded" };
  }
  if (typeof status === "string" && AWAITING_STATUSES.includes(status)) {
    return { succeeded: false, pending: true, errorKey: null, gate: "pending" };
  }
  return { succeeded: false, pending: false, errorKey: "cardNotCharged", gate: "error" };
}
