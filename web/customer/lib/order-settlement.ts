/**
 * plan-050 — quyết định "khách đã thanh toán xong chưa" cho màn hình chờ.
 *
 * Bối cảnh: màn "Đang chờ thanh toán" từng chỉ có MỘT đường cập nhật là event
 * Reverb `order.paid`. Đường đó chết câm trong suốt thời gian dài (BE chạy
 * `BROADCAST_CONNECTION=log` → event chỉ rơi vào file log, không lên WebSocket)
 * và không có gì thay thế, nên khách thanh toán xong phải tự reload mới thấy
 * màn thành công. Customer-web nay đọc lại trạng thái đơn bằng poll: đơn giản
 * hơn, hỏng thì ồn ào chứ không im lặng, và vì kéo STATE chứ không nhận EVENT
 * nên lỡ vài nhịp vẫn tự lành.
 *
 * Toàn bộ phần *quyết định* nằm ở đây, tách khỏi hook, để `node --test` chạy
 * được với clock giả — phần dễ vỡ phải là phần test được. Module giữ alias-free
 * (không `@/…`) vì `node --test` không resolve alias; xem ghi chú cùng lý do ở
 * `lib/payment-policy-context.ts`.
 *
 * Hai cái bẫy mà các predicate dưới đây tồn tại để chặn — cả hai đều là CÙNG
 * một loại sai: bê một predicate trả lời "có nên rời màn này không" sang trả
 * lời "khách đã trả tiền chưa". Hai câu hỏi khác nhau, không dùng chung hàm:
 *
 *   1. `status === 'voided'` KHÔNG phải đã thanh toán. Staff void đơn ở POS mà
 *      FE báo "Thanh toán thành công" thì khách đi về chưa trả đồng nào.
 *   2. `is_payment_overdue` KHÔNG phải đơn đã chết. Repo nói rõ hai lần rằng
 *      quá hạn vẫn trả được (`orders/[id]/page.tsx`, `orders/[id]/pay/page.tsx`
 *      đều đã bỏ gate hạn chót). Dừng theo dõi vì quá hạn = khách xếp hàng lâu,
 *      thu ngân thu tiền, màn hình không bao giờ đổi.
 */

/** Nhịp poll trong 5 phút đầu. */
export const FAST_POLL_MS = 5_000;
/** Nhịp poll sau 5 phút — khách chờ lâu ở quầy, giảm tải. */
export const SLOW_POLL_MS = 15_000;
/** Nhịp sau khi đã báo `paid` mà call site chưa tự tắt (xem `alreadyFiredPaid`). */
export const SETTLED_POLL_MS = 30_000;
/** Mốc chuyển từ FAST sang SLOW. */
export const FAST_WINDOW_MS = 5 * 60_000;
/** Backoff khởi điểm sau lần lỗi đầu. */
export const BACKOFF_BASE_MS = 5_000;
/** Trần backoff — lỗi kéo dài vẫn phải còn nhịp tim. */
export const BACKOFF_MAX_MS = 60_000;

/**
 * Các field của `GET /api/v1/customer/orders/{id}` mà quyết định settle cần.
 * Tất cả optional vì payload legacy có thể thiếu — nhưng xem `isPaidSettled`,
 * thiếu field nghĩa là "chưa settle", không bao giờ là "đã settle".
 */
export interface SettlementSnapshot {
  /** BE `CustomerOrderResource.status` — open/dining/checkout/paying/closed/voided/… */
  status?: string | null;
  /** BE `CustomerOrderSummaryResource`: `$paid >= $total && $paid > 0`. */
  is_fully_paid?: boolean | null;
  /** Tổng đã thu. Dùng để phát hiện partial payment giữa hai nhịp poll. */
  paid?: number | null;
  /** Còn phải thu. KHÔNG dùng một mình để kết luận đã trả — xem `isPaidSettled`. */
  remaining?: number | null;
}

/**
 * Điều kiện DUY NHẤT được phép dẫn tới "Thanh toán thành công".
 *
 * Chỉ `is_fully_paid === true`, không gì khác. Ba thứ bị loại có chủ đích:
 *
 *   - `status === 'voided'` — đơn bị huỷ, không có tiền nào vào. Xem header.
 *   - `status === 'closed'` — repo tự nói closed ≠ đã trả: `resolveBannerCfg`
 *     trong `orders/[id]/page.tsx` render "chưa thanh toán" khi
 *     `!is_fully_paid && payment_count === 0`, và nhánh đó với tới được khi
 *     status là closed.
 *   - `remaining <= 0` — đơn ¥0 (món được comp / coupon −100%) có remaining 0
 *     nhưng CHƯA đóng: khách vẫn phải bấm "Hoàn tất" để chạy settle-zero.
 *
 * `is_fully_paid` một mình an toàn cho cả đơn ¥0 vì BE tính
 * `$paid >= $total && $paid > 0` — guard `$paid > 0` khiến đơn ¥0 ra `false`.
 */
export function isPaidSettled(
  snapshot: SettlementSnapshot | null | undefined,
): boolean {
  return snapshot?.is_fully_paid === true;
}

/**
 * Đơn đã chết hẳn — dừng poll, và KHÔNG BAO GIỜ báo là đã thanh toán.
 *
 * Chỉ theo `status`. Cố tình KHÔNG dùng `isServerConfirmedExpired` của
 * `lib/order-expiry.ts`: hàm đó còn nhánh `is_payment_overdue === true`, mà
 * quá hạn ở đây vẫn là đơn trả được (xem header, bẫy #2). Hàm kia trả lời
 * "có an toàn để giấu QR / xoá pointer localStorage không" — câu hỏi khác.
 */
export function isDeadOrder(
  snapshot: SettlementSnapshot | null | undefined,
): boolean {
  const status = snapshot?.status;
  return status === "voided" || status === "cancelled" || status === "expired";
}

/**
 * Có khoản thu mới giữa hai nhịp poll (split-bill: khách kế vừa trả phần mình).
 *
 * `prev` null (nhịp đầu tiên) LUÔN trả false: nhịp đầu chỉ lập baseline. Nếu
 * không, mọi lần mount trên đơn đã có người trả sẽ bắn một `onPaymentRecorded`
 * giả → refetch thừa → và ở dine-in còn kéo theo một vòng setState không cần.
 */
export function paidAmountIncreased(
  prev: SettlementSnapshot | null | undefined,
  next: SettlementSnapshot | null | undefined,
): boolean {
  if (!prev || !next) return false;
  return (next.paid ?? 0) > (prev.paid ?? 0);
}

export interface PollStepInput {
  nowMs: number;
  /** Mốc bắt đầu theo dõi đơn này. Reset khi tab quay lại foreground. */
  startedAtMs: number;
  /**
   * Đang có confirm Stripe chạy dở → hook bỏ REQUEST của nhịp này.
   * Cố tình KHÔNG ảnh hưởng tới nhịp: confirm xong là muốn hỏi lại ngay.
   */
  paused: boolean;
  /** Tab đang ẩn → bỏ nhịp này (0 request khi chạy nền). */
  hidden: boolean;
  /** Số lần fetch lỗi liên tiếp; 0 sau mỗi lần thành công. */
  consecutiveFailures: number;
  /**
   * Lần cuối đã báo `paid`, hoặc null nếu chưa lần nào.
   *
   * Không phải cờ một-lần-trọn-đời: nếu call site vẫn còn `enabled` sau
   * `SETTLED_POLL_MS` thì nó CHƯA đổi được màn hình (vd handler refetch của nó
   * lỗi, hoặc UI đó đòi điều kiện chặt hơn `is_fully_paid`) — bắn lại là cách
   * duy nhất để nó tự gỡ. Nơi mọi thứ chạy đúng thì call site đã unmount hoặc
   * đã tắt `enabled`, nên không bao giờ có phát thứ hai.
   */
  lastPaidFireAtMs: number | null;
  /** Sàn nhịp do server áp (429). null khi không có. */
  retryAfterMs: number | null;
  /** Lỗi không bao giờ thành công được (401/403/404). */
  fatal: boolean;
  /** Snapshot đã áp gần nhất; null trước lần poll thành công đầu tiên. */
  prev: SettlementSnapshot | null;
  /** Snapshot của nhịp này; null khi nhịp này không lấy được gì. */
  next: SettlementSnapshot | null;
  /** Hệ số jitter [0,1). Caller truyền vào để hàm này thuần & test được. */
  jitter: number;
}

export interface PollStep {
  /** Chờ bao lâu tới nhịp kế. `null` = dừng hẳn. */
  delayMs: number | null;
  /** Callback cần bắn cho nhịp này. */
  fire: "paid" | "payment" | null;
  /** Vì sao dừng — để hook báo ra ngoài thay vì im lặng. */
  stop: "dead" | "fatal" | null;
}

/**
 * Toàn bộ lịch trình poll trong một hàm thuần.
 *
 * Bất biến quan trọng: hàm này KHÔNG BAO GIỜ trả `delayMs: null` chỉ vì đã
 * settle. Việc dừng là của call site (nó tự tắt `enabled` khi UI đã đổi) —
 * bản thân hook không được tự cho là xong. Lý do: `/order-success` chỉ đổi
 * màn khi `status === 'closed'`, KHÔNG đọc `is_fully_paid`; nếu hook bắn
 * `paid` một lần rồi tự dừng trên snapshot mà UI đó không chấp nhận thì màn
 * hình kẹt vĩnh viễn — tệ hơn cả bug ban đầu. Nên sau khi bắn `paid` ta giãn
 * nhịp ra `SETTLED_POLL_MS` và BẮN LẠI ở nhịp đó chừng nào call site còn
 * `enabled` — xem `lastPaidFireAtMs`.
 */
export function nextPollStep(input: PollStepInput): PollStep {
  const {
    nowMs,
    startedAtMs,
    hidden,
    consecutiveFailures,
    lastPaidFireAtMs,
    retryAfterMs,
    fatal,
    prev,
    next,
    jitter,
  } = input;

  if (fatal) return { delayMs: null, fire: null, stop: "fatal" };
  if (isDeadOrder(next)) return { delayMs: null, fire: null, stop: "dead" };

  let fire: PollStep["fire"] = null;
  if (next) {
    if (isPaidSettled(next)) {
      const due =
        lastPaidFireAtMs === null || nowMs - lastPaidFireAtMs >= SETTLED_POLL_MS;
      if (due) fire = "paid";
    } else if (paidAmountIncreased(prev, next)) {
      fire = "payment";
    }
  }

  const base = (() => {
    if (consecutiveFailures > 0) {
      const grown = BACKOFF_BASE_MS * 2 ** (consecutiveFailures - 1);
      return Math.min(grown, BACKOFF_MAX_MS);
    }
    // Đã báo settle rồi thì giãn nhịp, kể cả khi tab đang ẩn / đang paused —
    // gộp `!paused && !hidden` vào đây là làm ngược: đơn đã xong nằm trong tab
    // nền lại quay về nhịp 5s.
    if (lastPaidFireAtMs !== null) return SETTLED_POLL_MS;
    // Tab ẩn: timer vẫn quay nhưng không gửi request nào. Giãn ra cho đỡ churn
    // — lúc khách mở lại tab thì `handleResume` poll ngay, không mất gì.
    if (hidden) return SLOW_POLL_MS;
    return nowMs - startedAtMs < FAST_WINDOW_MS ? FAST_POLL_MS : SLOW_POLL_MS;
  })();

  // ±20% jitter: 40 điện thoại mount cùng lúc (một bàn 4 người quét cùng QR,
  // hoặc cả quán sau khi BE hồi phục) không được phép khoá pha với nhau.
  const jittered = Math.round(base * (0.8 + 0.4 * jitter));

  // 429: server đã nói rõ chờ bao lâu — không bao giờ nhanh hơn thế.
  const delayMs =
    retryAfterMs != null ? Math.max(jittered, retryAfterMs) : jittered;

  return { delayMs, fire, stop: null };
}
