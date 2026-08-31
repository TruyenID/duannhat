"use client";

/**
 * plan-050 — theo dõi một đơn cho tới khi khách thanh toán xong.
 *
 * Thay cho `use-order-paid-realtime.ts` (đã gỡ). Hook cũ chỉ nghe event Reverb
 * `order.paid`; khi hạ tầng broadcast hỏng thì nó hỏng CÂM — không log, không
 * UI, `connected` trả ra mà không call site nào đọc — nên khách thanh toán xong
 * phải tự reload. Thực tế nó chưa từng chạy: BE đặt `BROADCAST_CONNECTION=log`
 * nên event chỉ rơi vào file log.
 *
 * Vì sao poll chứ không phải socket cho ĐÚNG màn hình này:
 *
 *   - Chỉ cần biết đúng MỘT tin ("đã trả xong"), trong cửa sổ 1-3 phút. Trễ
 *     vài giây vô hại: khách đang nhìn thu ngân, không nhìn điện thoại.
 *   - Poll kéo STATE chứ không nhận EVENT, nên lỡ nhịp vẫn tự lành. Socket lỡ
 *     event là mất vĩnh viễn, và luôn phải kèm một cú fetch state lúc reconnect
 *     — tức là socket là lớp CHỒNG LÊN poll, không thay thế được nó.
 *   - Socket ở đây phải trả giá bằng hạ tầng (service riêng, domain WSS riêng vì
 *     CloudFront của Amplify không proxy WS) và bằng rủi ro thật: các event là
 *     `ShouldBroadcastNow` nằm trong transaction đóng đơn và KHÔNG có
 *     `ShouldRescue`, nên broadcast hỏng = HTTP 500 trên đúng đường thanh toán.
 *
 * Socket vẫn hợp lý cho luồng nhiều event/phiên dài (`use-table-session-realtime`,
 * admin-web, KDS) — chỉ là màn hình này không nên buộc số phận vào đó.
 */

import { useEffect, useEffectEvent, useRef, useState } from "react";

import { apiFetch, ApiError } from "@/lib/api";
import {
  isPaidSettled,
  nextPollStep,
  SLOW_POLL_MS,
  type SettlementSnapshot,
} from "@/lib/order-settlement";

/**
 * Sàn chờ khi server trả 429. `apiFetch` không đưa header ra ngoài nên ta không
 * đọc được `Retry-After`; 30s là mức thủ mà vẫn giữ được nhịp tim.
 */
const RATE_LIMITED_FLOOR_MS = 30_000;

export interface UseOrderSettlementOptions {
  /** Tắt hẳn việc theo dõi. Đây LÀ tín hiệu dừng — xem `nextPollStep`. */
  enabled?: boolean;
  /**
   * Tạm ngưng gọi API nhưng giữ lịch. Bật khi đang có confirm Stripe chạy dở:
   * một cú `onPaid` lúc modal 3DS / voucher Konbini còn mở sẽ unmount trang
   * ngay dưới chân Stripe, và đơn có thể bị tính tiền hai lần.
   */
  paused?: boolean;
  /** Đơn vừa chuyển sang đã-thanh-toán-đủ. Bắn một lần cho mỗi lần chuyển. */
  onPaid?: (snapshot: SettlementSnapshot) => void;
  /** Có khoản thu mới nhưng đơn chưa đủ (split-bill). */
  onPaymentRecorded?: (snapshot: SettlementSnapshot) => void;
}

export interface OrderSettlementState {
  /**
   * Snapshot mới nhất đọc được từ server, `null` trước lần poll đầu thành công.
   *
   * Call site nên lái UI + `enabled` bằng CÁI NÀY, đừng chỉ dựa vào callback:
   * callback chỉ bắn một lần, còn màn hình có thể cần vài nhịp nữa mới đồng ý
   * là xong (vd `/order-success` chỉ đổi khi `status === 'closed'`).
   */
  snapshot: SettlementSnapshot | null;
  /** `null` = còn đang theo dõi. */
  stoppedReason: "dead" | "fatal" | null;
}

/** So sánh 4 field quyết định — tránh re-render cả trang mỗi 5 giây. */
function sameSnapshot(
  a: SettlementSnapshot | null,
  b: SettlementSnapshot | null,
): boolean {
  if (a === b) return true;
  if (!a || !b) return false;
  return (
    a.status === b.status &&
    a.is_fully_paid === b.is_fully_paid &&
    a.paid === b.paid &&
    a.remaining === b.remaining
  );
}

function isAbortError(err: unknown): boolean {
  return err instanceof DOMException && err.name === "AbortError";
}

export function useOrderSettlement(
  orderId: string | null | undefined,
  options: UseOrderSettlementOptions = {},
): OrderSettlementState {
  const { enabled = true, paused = false, onPaid, onPaymentRecorded } = options;

  const [snapshot, setSnapshot] = useState<SettlementSnapshot | null>(null);
  const [stoppedReason, setStoppedReason] =
    useState<OrderSettlementState["stoppedReason"]>(null);

  // `paused` đổi liên tục (mỗi lần bấm Pay) nhưng KHÔNG được nằm trong deps của
  // effect — làm thế là dựng lại cả chuỗi timer giữa lúc thanh toán. Ghi ref
  // trong effect chứ không trong render (render-phase ref write là thứ
  // `react-hooks/refs` chặn, và React Compiler bail).
  const pausedRef = useRef(paused);
  const abortRef = useRef<AbortController | null>(null);
  useEffect(() => {
    pausedRef.current = paused;
    // `paused` chỉ đọc được ở ĐẦU mỗi tick, nên một request đã bay trước khi
    // khách bấm Pay vẫn sẽ về và vẫn có thể bắn `onPaid` — tức là unmount
    // trang ngay giữa lúc Stripe confirm chạy dở, đúng cái mà `paused` sinh ra
    // để chặn. Huỷ nó ngay tại đây mới thực sự khoá.
    if (paused) abortRef.current?.abort();
  }, [paused]);

  const emitPaid = useEffectEvent((s: SettlementSnapshot) => {
    onPaid?.(s);
  });
  const emitPaymentRecorded = useEffectEvent((s: SettlementSnapshot) => {
    onPaymentRecorded?.(s);
  });

  useEffect(() => {
    if (!enabled || !orderId) return;
    if (typeof window === "undefined") return;

    let cancelled = false;
    let running = false;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let lastResumeAtMs = 0;

    let startedAtMs = Date.now();
    let consecutiveFailures = 0;
    let lastPaidFireAtMs: number | null = null;
    let prev: SettlementSnapshot | null = null;
    let seq = 0;

    const schedule = (delayMs: number) => {
      if (cancelled) return;
      if (timer) clearTimeout(timer);
      timer = setTimeout(() => {
        void tick();
      }, delayMs);
    };

    const tick = async () => {
      if (cancelled || running) return;
      running = true;

      // Giá trị mặc định để nếu có gì nổ ngoài dự kiến thì vòng lặp VẪN sống.
      // Bất biến: N lần lỗi liên tiếp vẫn phải sinh ra nhịp thứ N+1. Một throw
      // lọt ra trong `setTimeout` đệ quy là giết lưới an toàn vĩnh viễn — và
      // đúng ca đó (wifi quán rớt một nhịp) là ca hay xảy ra nhất.
      let delayMs: number | null = SLOW_POLL_MS;

      try {
        const isPaused = pausedRef.current;
        const isHidden = document.visibilityState === "hidden";

        let next: SettlementSnapshot | null = null;
        let fatal = false;
        let retryAfterMs: number | null = null;

        if (!isPaused && !isHidden) {
          const mySeq = ++seq;
          const controller = new AbortController();
          abortRef.current = controller;
          try {
            // `silent401` bắt buộc: luồng guest, 401 trần sẽ
            // `window.location.href = "/"` VÀ trả promise không bao giờ settle
            // (lib/api.ts) — tức là hất khách ra khỏi màn thanh toán rồi treo.
            // `?_t=` theo tiền lệ dine-in: `cache:'no-store'` chỉ là gợi ý cho
            // HTTP cache của browser, không đủ trên iOS Safari.
            const res = await apiFetch<{ data: SettlementSnapshot }>(
              `/api/v1/customer/orders/${orderId}?_t=${Date.now()}`,
              { silent401: true, cache: "no-store", signal: controller.signal },
            );
            if (cancelled) return;
            // Bỏ response về sau nhưng cũ hơn cái đã áp — mạng di động đảo thứ
            // tự liên tục, và ghi đè bằng snapshot trước-thanh-toán sẽ làm
            // `paid_quantity` tụt về, panel by-items mở lại món đã trả.
            if (mySeq === seq) {
              next = res.data ?? null;
              consecutiveFailures = 0;
            }
          } catch (err) {
            if (cancelled) return;
            if (isAbortError(err)) return;
            consecutiveFailures += 1;
            if (err instanceof ApiError) {
              if (err.status === 401 || err.status === 403 || err.status === 404) {
                fatal = true;
              } else if (err.status === 429) {
                retryAfterMs = RATE_LIMITED_FLOOR_MS;
              }
            }
          } finally {
            if (abortRef.current === controller) abortRef.current = null;
          }
        }

        const step = nextPollStep({
          nowMs: Date.now(),
          startedAtMs,
          paused: isPaused,
          hidden: isHidden,
          consecutiveFailures,
          lastPaidFireAtMs,
          retryAfterMs,
          fatal,
          prev,
          next,
          jitter: Math.random(),
        });

        delayMs = step.delayMs;

        if (next) {
          prev = next;
          setSnapshot((cur) => (sameSnapshot(cur, next) ? cur : next));
          // Đơn quay lại chưa-trả-đủ (refund / thêm món) → lần settle sau được
          // báo ngay, không phải chờ hết nhịp settled.
          if (!isPaidSettled(next)) lastPaidFireAtMs = null;
        }

        // Bắn callback TRONG try: handler của call site gọi `router.replace` /
        // `refetchOrder` đồng bộ, nếu nó throw mà nằm ngoài try thì lại giết
        // vòng lặp ở tầng trên.
        if (step.fire === "paid" && next) {
          lastPaidFireAtMs = Date.now();
          emitPaid(next);
        } else if (step.fire === "payment" && next) {
          emitPaymentRecorded(next);
        }

        if (step.stop) {
          setStoppedReason(step.stop);
          if (step.stop === "fatal") {
            // Chuông báo duy nhất. Không có dòng này thì ta chỉ dời chỗ im lặng
            // từ WebSocket sang poll.
            console.warn(
              `[useOrderSettlement] dừng theo dõi đơn ${orderId}: server trả lỗi không cứu được (401/403/404). Màn hình sẽ không tự cập nhật.`,
            );
          }
        }
      } catch (err) {
        if (!cancelled) {
          console.warn("[useOrderSettlement] tick lỗi ngoài dự kiến:", err);
        }
      } finally {
        running = false;
        if (!cancelled && delayMs !== null) schedule(delayMs);
      }
    };

    // Đưa tab lên trước = bằng chứng khách còn ngồi đó: coi như bắt đầu lại
    // (về nhịp nhanh) và hỏi server ngay. Đây là kịch bản chủ đạo — khách khoá
    // máy lúc mang QR ra quầy, mở lại sau khi trả xong.
    // `pageshow` đi kèm vì bfcache của iOS Safari không phát `visibilitychange`
    // một cách đáng tin.
    const handleResume = () => {
      if (cancelled) return;
      if (document.visibilityState !== "visible") return;
      // Sàn 1s: chuyển tab qua lại liên tục không được biến thành một request
      // mỗi lần chuyển — đây là đường duy nhất đi vòng qua mọi delay đã tính.
      const now = Date.now();
      if (now - lastResumeAtMs < 1_000) return;
      lastResumeAtMs = now;
      startedAtMs = now;
      consecutiveFailures = 0;
      if (!running) void tick();
    };

    document.addEventListener("visibilitychange", handleResume);
    window.addEventListener("pageshow", handleResume);

    void tick();

    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
      abortRef.current?.abort();
      document.removeEventListener("visibilitychange", handleResume);
      window.removeEventListener("pageshow", handleResume);
    };
    // `emitPaid` / `emitPaymentRecorded` cố tình KHÔNG nằm trong deps: hàm do
    // `useEffectEvent` trả về vốn ổn định và luôn đọc callback mới nhất — đưa
    // vào là dựng lại cả chuỗi timer mỗi lần call site re-render.
  }, [enabled, orderId]);

  return { snapshot, stoppedReason };
}
