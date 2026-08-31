import { test } from "node:test";
import assert from "node:assert/strict";

import {
  isPaidSettled,
  isDeadOrder,
  paidAmountIncreased,
  nextPollStep,
  type SettlementSnapshot,
  FAST_POLL_MS,
  SLOW_POLL_MS,
  SETTLED_POLL_MS,
  FAST_WINDOW_MS,
  BACKOFF_BASE_MS,
  BACKOFF_MAX_MS,
  type PollStepInput,
} from "./order-settlement.ts";

// ---------------------------------------------------------------------------
// plan-050 — màn "Đang chờ thanh toán" đọc trạng thái đơn bằng poll thay vì
// chờ event Reverb. Các test dưới khoá lại đúng những cách mà quyết định
// "khách đã trả tiền chưa" từng bị viết sai trong lúc thiết kế — mỗi cái là
// một lần suýt cho khách thấy "Thanh toán thành công" nhầm, hoặc bỏ rơi khách
// giữa chừng.
// ---------------------------------------------------------------------------

// ── isPaidSettled ──────────────────────────────────────────────────────────

test("isPaidSettled: chỉ is_fully_paid mới là đã thanh toán", () => {
  assert.equal(isPaidSettled({ is_fully_paid: true }), true);
  assert.equal(isPaidSettled({ is_fully_paid: false }), false);
  assert.equal(isPaidSettled(null), false);
  assert.equal(isPaidSettled(undefined), false);
  assert.equal(isPaidSettled({}), false);
});

test("isPaidSettled: đơn bị VOID không bao giờ là đã thanh toán", () => {
  // Staff void đơn ở POS. Nếu hàm này trả true, FE sẽ đẩy khách sang màn
  // "Thanh toán thành công" và khách đi về mà chưa trả đồng nào.
  assert.equal(isPaidSettled({ status: "voided", is_fully_paid: false }), false);
});

test("isPaidSettled: status closed mà chưa thu tiền vẫn KHÔNG phải đã thanh toán", () => {
  // `resolveBannerCfg` trong orders/[id]/page.tsx render "chưa thanh toán" khi
  // !is_fully_paid && payment_count === 0, và nhánh đó với tới được khi status
  // là closed. Repo tự nói closed ≠ đã trả, hàm này phải nói y hệt.
  assert.equal(
    isPaidSettled({ status: "closed", is_fully_paid: false, paid: 0 }),
    false,
  );
});

test("isPaidSettled: đơn ¥0 chưa đóng KHÔNG phải đã thanh toán", () => {
  // Đơn được comp / coupon -100%: remaining = 0 nhưng đơn vẫn mở, khách phải
  // bấm "Hoàn tất" để chạy settle-zero. BE tính is_fully_paid =
  // ($paid >= $total && $paid > 0) nên guard $paid > 0 cho ra false ở đây.
  assert.equal(
    isPaidSettled({ status: "open", remaining: 0, paid: 0, is_fully_paid: false }),
    false,
  );
});

test("isPaidSettled: remaining <= 0 một mình không đủ để kết luận", () => {
  assert.equal(isPaidSettled({ remaining: 0 }), false);
  assert.equal(isPaidSettled({ remaining: -50 }), false);
});

// ── isDeadOrder ────────────────────────────────────────────────────────────

test("isDeadOrder: voided / cancelled / expired là đơn chết", () => {
  assert.equal(isDeadOrder({ status: "voided" }), true);
  assert.equal(isDeadOrder({ status: "cancelled" }), true);
  assert.equal(isDeadOrder({ status: "expired" }), true);
});

test("isDeadOrder: đơn QUÁ HẠN vẫn còn sống — không được dừng theo dõi", () => {
  // Bẫy quan trọng: lib/order-expiry.ts `isServerConfirmedExpired` coi
  // is_payment_overdue là hết hạn, nhưng nó trả lời câu hỏi khác ("có an toàn
  // để giấu QR không"). orders/[id] và orders/[id]/pay đều đã BỎ gate hạn chót
  // để khách quay lại vẫn trả được. Nếu dừng poll ở đây thì khách xếp hàng lâu
  // quá hạn, thu ngân thu tiền xong, màn hình không bao giờ đổi.
  //
  // BE có trả `is_payment_overdue` trên cùng payload, nên test phải đưa field
  // đó vào thật — chứ không phải chỉ khẳng định một status còn sống là còn
  // sống, thế thì không khoá được gì.
  const overdueButPayable: SettlementSnapshot & {
    is_payment_overdue?: boolean;
  } = { status: "confirmed", is_fully_paid: false, is_payment_overdue: true };
  assert.equal(isDeadOrder(overdueButPayable), false);

  assert.equal(isDeadOrder({ status: "open" }), false);
  assert.equal(isDeadOrder({ status: "paying" }), false);
  assert.equal(isDeadOrder(null), false);
});

// ── paidAmountIncreased ────────────────────────────────────────────────────

test("paidAmountIncreased: nhịp đầu tiên chỉ lập baseline, không bao giờ bắn", () => {
  // prev = null nghĩa là chưa có nhịp poll thành công nào. Nếu trả true, mọi
  // lần mount trên đơn split-bill đã có người trả sẽ bắn onPaymentRecorded giả.
  assert.equal(paidAmountIncreased(null, { paid: 5000 }), false);
  assert.equal(paidAmountIncreased(undefined, { paid: 5000 }), false);
});

test("paidAmountIncreased: chỉ tăng mới tính", () => {
  assert.equal(paidAmountIncreased({ paid: 0 }, { paid: 3000 }), true);
  assert.equal(paidAmountIncreased({ paid: 3000 }, { paid: 3000 }), false);
  assert.equal(paidAmountIncreased({ paid: 3000 }, { paid: 1000 }), false); // refund
  assert.equal(paidAmountIncreased({}, { paid: 100 }), true); // thiếu field = 0
});

// ── nextPollStep ───────────────────────────────────────────────────────────

/** Input mặc định "mọi thứ bình thường" — từng test chỉ override phần nó quan tâm. */
function step(over: Partial<PollStepInput> = {}) {
  const base: PollStepInput = {
    nowMs: 1_000_000,
    startedAtMs: 1_000_000,
    paused: false,
    hidden: false,
    consecutiveFailures: 0,
    lastPaidFireAtMs: null,
    retryAfterMs: null,
    fatal: false,
    prev: null,
    next: null,
    jitter: 0.5, // 0.5 → hệ số 1.0, tức không lệch: dễ assert
  };
  return nextPollStep({ ...base, ...over });
}

test("nextPollStep: 5 phút đầu poll nhanh, sau đó chậm lại", () => {
  assert.equal(step().delayMs, FAST_POLL_MS);
  assert.equal(
    step({ nowMs: 1_000_000 + FAST_WINDOW_MS - 1 }).delayMs,
    FAST_POLL_MS,
  );
  assert.equal(step({ nowMs: 1_000_000 + FAST_WINDOW_MS }).delayMs, SLOW_POLL_MS);
});

test("nextPollStep: KHÔNG có trần thời gian — khách chờ lâu vẫn được theo dõi", () => {
  // Konbini / chuyển khoản (#1125) tiền về sau hàng giờ; hàng dài ở quầy cũng
  // vượt 30 phút. Một trần cứng ở đây sẽ biến chính màn hình này thành ngõ cụt.
  const after2h = step({ nowMs: 1_000_000 + 2 * 60 * 60_000 });
  assert.equal(after2h.delayMs, SLOW_POLL_MS);
  assert.equal(after2h.stop, null);
});

test("nextPollStep: bắn paid ngay lần đầu thấy đơn đã trả đủ", () => {
  assert.equal(step({ next: { is_fully_paid: true } }).fire, "paid");
});

test("nextPollStep: không bắn paid dồn dập trong cùng một nhịp settled", () => {
  const s = step({
    nowMs: 1_000_000,
    next: { is_fully_paid: true },
    lastPaidFireAtMs: 1_000_000 - 1_000,
  });
  assert.equal(s.fire, null);
});

test("nextPollStep: call site chưa đổi màn sau 30s thì BẮN LẠI", () => {
  // Ca kẹt vĩnh viễn: /order-success chỉ đổi màn khi status === 'closed' và
  // KHÔNG đọc is_fully_paid; handler refetch của call site cũng có thể lỗi.
  // Nếu paid chỉ bắn một lần trọn đời thì màn hình kẹt mãi — tệ hơn bug ban
  // đầu. Chỗ nào chạy đúng thì call site đã unmount / tắt enabled nên không
  // bao giờ có phát thứ hai.
  const s = step({
    nowMs: 1_000_000,
    next: { is_fully_paid: true, status: "paying" },
    lastPaidFireAtMs: 1_000_000 - SETTLED_POLL_MS,
  });
  assert.equal(s.fire, "paid");
});

test("nextPollStep: đã bắn paid thì giãn nhịp ra settled, không dừng", () => {
  const s = step({
    next: { is_fully_paid: true, status: "paying" },
    lastPaidFireAtMs: 1_000_000,
  });
  assert.equal(s.delayMs, SETTLED_POLL_MS);
  assert.equal(s.stop, null);
});

test("nextPollStep: đơn chết thì dừng hẳn và KHÔNG bắn paid", () => {
  const s = step({ next: { status: "voided", is_fully_paid: false } });
  assert.equal(s.delayMs, null);
  assert.equal(s.stop, "dead");
  assert.equal(s.fire, null);
});

test("nextPollStep: void SAU KHI đã trả đủ vẫn không được bắn paid lần nữa", () => {
  const s = step({ next: { status: "voided", is_fully_paid: true } });
  assert.equal(s.stop, "dead");
  assert.equal(s.fire, null);
});

test("nextPollStep: lỗi không cứu được thì dừng, không quay vòng vô ích", () => {
  const s = step({ fatal: true });
  assert.equal(s.delayMs, null);
  assert.equal(s.stop, "fatal");
});

test("nextPollStep: partial payment bắn payment, không bắn paid", () => {
  const s = step({
    prev: { paid: 0, is_fully_paid: false },
    next: { paid: 3000, is_fully_paid: false },
  });
  assert.equal(s.fire, "payment");
});

test("nextPollStep: trả nốt phần cuối thì bắn paid chứ không phải payment", () => {
  const s = step({
    prev: { paid: 3000, is_fully_paid: false },
    next: { paid: 8000, is_fully_paid: true },
  });
  assert.equal(s.fire, "paid");
});

test("nextPollStep: nhịp bị bỏ qua không bắn gì", () => {
  assert.equal(step({ paused: true }).fire, null);
  assert.equal(step({ hidden: true }).fire, null);
});

test("nextPollStep: paused/hidden KHÔNG bị tính là lỗi", () => {
  // Bỏ nhịp vì tab ẩn hay vì đang confirm Stripe là chuyện bình thường —
  // không được đẩy vào backoff, nếu không khách mở lại tab phải chờ rất lâu.
  // Paused giữ nguyên nhịp nhanh: confirm xong là muốn hỏi lại ngay.
  assert.equal(step({ paused: true }).delayMs, FAST_POLL_MS);
  // Tab ẩn thì giãn ra — không request nào được gửi, và lúc mở lại tab
  // handleResume poll ngay nên không mất gì.
  assert.equal(step({ hidden: true }).delayMs, SLOW_POLL_MS);
});

test("nextPollStep: đơn đã settle nằm trong tab nền vẫn giữ nhịp settled", () => {
  // Đừng để tab nền kéo đơn đã xong về nhịp 5s.
  const s = step({
    hidden: true,
    next: { is_fully_paid: true },
    lastPaidFireAtMs: 1_000_000,
  });
  assert.equal(s.delayMs, SETTLED_POLL_MS);
});

test("nextPollStep: backoff mũ có trần", () => {
  assert.equal(step({ consecutiveFailures: 1 }).delayMs, BACKOFF_BASE_MS);
  assert.equal(step({ consecutiveFailures: 2 }).delayMs, BACKOFF_BASE_MS * 2);
  assert.equal(step({ consecutiveFailures: 3 }).delayMs, BACKOFF_BASE_MS * 4);
  assert.equal(step({ consecutiveFailures: 99 }).delayMs, BACKOFF_MAX_MS);
});

test("nextPollStep: jitter nằm trong ±20% và không bao giờ ra 0", () => {
  const lo = step({ jitter: 0 }).delayMs;
  const hi = step({ jitter: 0.999 }).delayMs;
  assert.equal(lo, Math.round(FAST_POLL_MS * 0.8));
  assert.ok(hi !== null && hi <= Math.round(FAST_POLL_MS * 1.2));
  assert.ok(lo !== null && lo > 0);
});

test("nextPollStep: 429 Retry-After là SÀN, backoff không kéo xuống dưới", () => {
  // Hook luôn tăng consecutiveFailures TRƯỚC khi set retryAfterMs, nên phải
  // test đúng tổ hợp đó — backoff lần đầu (5s) vẫn phải bị sàn 30s ghi đè.
  const s = step({ retryAfterMs: 30_000, consecutiveFailures: 1, jitter: 0 });
  assert.ok(s.delayMs !== null && s.delayMs >= 30_000);
});

test("nextPollStep: fatal thắng mọi thứ khác", () => {
  const s = step({ fatal: true, next: { is_fully_paid: true } });
  assert.equal(s.delayMs, null);
  assert.equal(s.fire, null);
});
