import { useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { ApiError } from "@/lib/api";
import {
  customerKeys,
  orderKeys,
  orderPaymentKeys,
  tableKeys,
} from "@/hooks/api/query-keys";
import {
  workstationCashChangerService,
  type CashChangerSession,
  type CashChangerSplitMetadata,
} from "@/services/workstation-cash-changer-service";

/** How often to poll a running collection. The machine takes 30–300s. */
const POLL_MS = 1_000;

/**
 * How many CONSECUTIVE failed polls to tolerate before giving up (#1810).
 * At POLL_MS this is ~30s of silence — long enough to ride out a wifi blip,
 * short enough that a cashier is not left watching a spinner.
 */
const MAX_CONSECUTIVE_POLL_FAILURES = 30;

/**
 * Is this error worth polling through, or is the session gone for good?
 *
 * `ApiError(0)` is our synthetic "LAN fetch failed" — the workstation is
 * probably still running and still holding the session, so retrying is right.
 * A 4xx is the opposite: `404` means the workstation NO LONGER HAS this
 * session, `401/403` mean it will never answer us again. Retrying those is how
 * #1810 happened — an infinite 1s loop against an answer that will not change.
 *
 * `408`/`429` are 4xx that explicitly mean "ask again".
 */
function isTransientPollError(err: unknown): boolean {
  if (!(err instanceof ApiError)) return true; // unknown shape — assume transient
  if (err.status === 0) return true; // LAN blip
  if (err.status === 408 || err.status === 429) return true;
  return err.status < 400 || err.status >= 500;
}

export interface UseCashChangerResult {
  /** False when no workstation is paired — the caller must hide its UI. */
  available: boolean;
  /** Non-null from start() until the caller dismisses it. */
  session: CashChangerSession | null;
  /** True while a start/cancel request is in flight. */
  busy: boolean;
  /** Set when start/cancel itself failed (not a machine-side terminal state). */
  error: string | null;
  /**
   * True when polling gave up without ever seeing a terminal status (#1810).
   *
   * This is NOT "the collection failed" — it is "we no longer know". The
   * machine may have taken the customer's money or may not have. It must be
   * shown with the same weight as `timeout`: cash unaccounted for, a human has
   * to open the machine. Showing it like `cancel` would tell the cashier the
   * customer has their money back, which we cannot know.
   */
  outcomeUnknown: boolean;
  /**
   * `amount` bỏ trống → thu toàn bộ phần còn thiếu (đường mặc định).
   * `amount` có → phần của MỘT người khi chia bill (#2941). Máy trạm vẫn kẹp
   * `0 < amount <= outstanding` và trả 422 nếu vượt — con số này KHÔNG được
   * tin ở đầu kia.
   */
  start: (
    orderId: string,
    amount?: number,
    metadata?: CashChangerSplitMetadata
  ) => Promise<void>;
  cancel: () => Promise<void>;
  dismiss: () => void;
}

/**
 * Drives one 釣銭機 collection: start → poll → terminal.
 *
 * **It never creates a payment.** The workstation's `RecordCashPayment` is "the
 * single writer of the payments table for the cash-changer flow" — on `finish`
 * it has already inserted the payment (idempotent on the Glory transaction id),
 * run the order lifecycle and queued the sync UP. So all this hook does on a
 * finish is invalidate the order queries and let the payment the WORKSTATION
 * created appear. Posting one from here would double-charge the customer.
 *
 * Polling stops on any terminal status, and the snapshot is kept on screen
 * afterwards: `timeout`/`abort`/`failure` mean the machine is still holding the
 * customer's cash, which a cashier must be told explicitly rather than left to
 * infer from a dialog that closed itself.
 */
export function useCashChanger(
  shopSlug: string,
  /**
   * #2535 B12 — chủ đơn, để làm tươi dư nợ sau khi thu. Tuỳ chọn: đơn khách lẻ
   * không có ai để làm tươi, và một hook thanh toán không được đòi thứ nó không
   * cần mới chạy.
   */
  customerId?: string | null,
): UseCashChangerResult {
  const qc = useQueryClient();
  // Đọc trong callback của poll — poll sống qua nhiều lần render, nên bắt giá
  // trị qua ref thay vì đóng gói giá trị của lần render đầu.
  //
  // `useLayoutEffect`, KHÔNG phải `useEffect` và cũng không phải gán thẳng
  // trong render (#2602). Ba lựa chọn, chỉ một cái vừa hợp lệ vừa giữ nguyên
  // hành vi:
  //
  //   gán trong render  — `react-hooks/refs` chặn: ghi ref lúc render làm
  //                       component có thể không cập nhật như mong đợi.
  //   useEffect         — effect thụ động bị HOÃN, nên một lượt poll (macrotask)
  //                       chen được vào giữa render và effect và sẽ đọc
  //                       `customerIdRef.current` của lượt TRƯỚC. Trên màn
  //                       釣銭機 đó là gắn nợ vào nhầm khách.
  //   useLayoutEffect   — chạy ĐỒNG BỘ ngay sau khi commit, trong cùng task,
  //                       trước khi trình duyệt kịp chạy bất kỳ timer nào. Không
  //                       có khe cho poll chen vào ⇒ ngữ nghĩa y hệt bản cũ.
  //
  // pos-web là Vite SPA, không SSR, nên `useLayoutEffect` dùng thẳng được.
  const customerIdRef = useRef(customerId);
  useLayoutEffect(() => {
    customerIdRef.current = customerId;
  });
  const [session, setSession] = useState<CashChangerSession | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [outcomeUnknown, setOutcomeUnknown] = useState(false);
  const failStreakRef = useRef(0);

  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const stoppedRef = useRef(false);
  // The poll re-arms itself on a timer. Going through a ref rather than naming
  // `poll` inside its own body keeps the self-reference out of the useCallback
  // closure — otherwise each re-creation leaves the previously scheduled timer
  // calling a stale copy.
  const pollRef = useRef<(sessionId: string) => Promise<void>>(async () => {});

  const clearTimer = () => {
    if (timerRef.current) clearTimeout(timerRef.current);
    timerRef.current = null;
  };

  // Unmount mid-collection must not leave a poll running — but it also must not
  // cancel the machine: the cash is physically in it, and the collection is the
  // workstation's to finish. The session survives; only our timer dies.
  useEffect(() => {
    return () => {
      stoppedRef.current = true;
      clearTimer();
    };
  }, []);

  const poll = useCallback(
    async (sessionId: string) => {
      if (stoppedRef.current) return;
      try {
        const snap = await workstationCashChangerService.status(sessionId);
        if (stoppedRef.current) return;
        failStreakRef.current = 0;
        setSession(snap);

        if (snap.running) {
          timerRef.current = setTimeout(() => void pollRef.current(sessionId), POLL_MS);
          return;
        }

        // Terminal. On a finish the workstation has already written the payment,
        // so the only correct action here is to refetch and let it show up.
        if (snap.status === "finish") {
          // #2535 B12 — cùng bộ khoá với đường POS thường
          // (`use-payment-actions.ts::invalidateAfterPayment`). Chỉ bắn
          // `orderKeys.all` thì thẻ BÀN giữ nguyên trạng thái trước thanh toán
          // cho tới khi có thứ khác tình cờ refetch, và danh sách thanh toán
          // của đơn thì không bao giờ thấy dòng tiền mặt vừa thu.
          void qc.invalidateQueries({ queryKey: orderKeys.all(shopSlug) });
          void qc.invalidateQueries({ queryKey: orderKeys.detail(shopSlug, snap.order_id) });
          void qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
          void qc.invalidateQueries({
            queryKey: orderPaymentKeys.list(shopSlug, snap.order_id),
          });
          if (customerIdRef.current) {
            void qc.invalidateQueries({
              queryKey: customerKeys.outstanding(shopSlug, customerIdRef.current),
            });
          }
        }
      } catch (e) {
        if (stoppedRef.current) return;

        // #1810 — the old code retried EVERY error forever, on the stated
        // assumption that "the workstation keeps the session". The session is
        // in-memory on the workstation, so that assumption breaks the moment it
        // restarts mid-collection: the poll gets 404, retries at 1/s for ever,
        // and the cashier watches a spinner that can never end while the cash
        // may be sitting inside the machine.
        failStreakRef.current += 1;
        const giveUp =
          !isTransientPollError(e) ||
          failStreakRef.current >= MAX_CONSECUTIVE_POLL_FAILURES;

        if (giveUp) {
          clearTimer();
          // Keep the last snapshot on screen and mark the outcome UNKNOWN —
          // never silently "cancelled". We did not observe an ending.
          setOutcomeUnknown(true);
          setSession((prev) => (prev ? { ...prev, running: false } : prev));
          return;
        }

        // A dropped poll is not a failed collection — the machine keeps going.
        // Retry rather than declaring an outcome we did not observe.
        timerRef.current = setTimeout(() => void pollRef.current(sessionId), POLL_MS);
      }
    },
    [qc, shopSlug],
  );
  // Ref writes belong in an effect, not in render. `start` is only ever called
  // from a click, long after mount, so the effect has always run by then.
  useEffect(() => {
    pollRef.current = poll;
  }, [poll]);

  const start = useCallback(
    async (orderId: string, amount?: number, metadata?: CashChangerSplitMetadata) => {
      setError(null);
      setOutcomeUnknown(false);
      failStreakRef.current = 0;
      setBusy(true);
      stoppedRef.current = false;
      try {
        const started = await workstationCashChangerService.start(orderId, amount, metadata);
        setSession({
          session_id: started.session_id,
          order_id: started.order_id,
          running: true,
          status: "",
          payment_id: "",
          total: started.total,
          tendered: 0,
          change: 0,
          error: "",
        });
        void pollRef.current(started.session_id);
      } catch (e) {
        setError(e instanceof Error ? e.message : "cash changer failed");
      } finally {
        setBusy(false);
      }
    },
    [],
  );

  const cancel = useCallback(async () => {
    if (!session) return;
    setBusy(true);
    try {
      await workstationCashChangerService.cancel(session.session_id);
      // Do NOT assume it is cancelled: the machine has to physically return the
      // cash, and the authoritative outcome arrives through the poll as a
      // terminal `cancel`. Keep polling.
    } catch (e) {
      setError(e instanceof Error ? e.message : "cancel failed");
    } finally {
      setBusy(false);
    }
  }, [session]);

  const dismiss = useCallback(() => {
    stoppedRef.current = true;
    clearTimer();
    setSession(null);
    setError(null);
    setOutcomeUnknown(false);
    failStreakRef.current = 0;
  }, []);

  return {
    available: workstationCashChangerService.enabled,
    session,
    busy,
    error,
    outcomeUnknown,
    start,
    cancel,
    dismiss,
  };
}
