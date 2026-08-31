"use client";

/**
 * godx-tempo#1737 — keep asking about a PayPay code the customer walked away
 * from.
 *
 * Leaving the QR panel does not kill the code. Dine-in's "Đổi số tiền"
 * (`setPaypayMint(null)`) and takeaway's method radio both unmount
 * `PayPayQrPanel` while the code stays scannable at PayPay for the rest of its
 * ~5 minutes, and every poll inside the panel dies with it. A guest who scanned
 * the abandoned code then had their money sitting at PayPay with nothing here
 * noticing, until `payments:sweep-paypay-qr` came round — a job scheduled every
 * fifteen minutes.
 *
 * What makes this small: `GET orders/{id}/paypay-qr/status` is not a read.
 * `PayPayPaymentService::syncStatus()` asks PayPay and BOOKS the money through
 * `recordPayPayPaymentByOrderId` if it has moved. So merely continuing to call
 * it settles the order on the next tick. No new endpoint, and — the reason this
 * shipped ahead of the cancel route — no way for it to conclude anything from
 * local state, which is the failure mode that makes money unbookable.
 *
 * It does NOT close the window in which the code can still be scanned; it only
 * shortens how long the money stays invisible. Closing that window is the
 * cancel endpoint's job, and that one has to ask PayPay before it destroys
 * anything.
 *
 * Cadence and jitter are shared with the panel's own poll
 * (`nextPayPayPollDelayMs`) because they land in the same 120/min
 * `customer-order-read` bucket, keyed on the order — several phones on one
 * split bill can saturate it, and this poller is the one that must yield.
 */

import { useEffect, useRef, useState } from "react";

import { ApiError, apiFetch } from "@/lib/api";
import {
  PAYPAY_STATUS_RATE_LIMITED_FLOOR_MS,
  nextPayPayPollDelayMs,
  payPayQrStatusPath,
  readPayPayQrStatus,
  shouldWatchOrphanedPayPayQr,
  type PayPayQrPhase,
} from "@/lib/paypay-qr";

interface UsePayPayOrphanWatchInput {
  orderId: string | null | undefined;
  /**
   * When the panel handed the code over, or `null` for "nothing orphaned".
   * The host clears it via `onResolved`.
   */
  orphanedAtMs: number | null;
  /** The watch reached a definite answer, or ran past the code's lifetime. */
  onResolved: () => void;
  /**
   * The order settled while we were watching — money booked by our own poll.
   * The host redirects / refreshes exactly as it would for any other payment.
   */
  onPaid?: () => void;
}

export function usePayPayOrphanWatch({
  orderId,
  orphanedAtMs,
  onResolved,
  onPaid,
}: UsePayPayOrphanWatchInput): void {
  // Phase is stored WITH the watch it belongs to, and read back only when the
  // two still match. A fresh orphan therefore starts at "waiting" without an
  // effect resetting it — `react-hooks/set-state-in-effect` rejects that reset,
  // and it would also mean one render where a new code wears the old verdict.
  const [tracked, setTracked] = useState<{
    at: number | null;
    phase: PayPayQrPhase;
  }>({ at: null, phase: "waiting" });
  const phase: PayPayQrPhase = tracked.at === orphanedAtMs ? tracked.phase : "waiting";

  // Callback identities change on every host render; taking them as
  // dependencies would clear and restart the timer chain each time, so the poll
  // would never actually fire. Same treatment the panel gives `onPaid`.
  const onResolvedRef = useRef(onResolved);
  const onPaidRef = useRef(onPaid);
  useEffect(() => {
    onResolvedRef.current = onResolved;
    onPaidRef.current = onPaid;
  }, [onResolved, onPaid]);

  const failuresRef = useRef(0);
  const retryAfterRef = useRef<number | null>(null);

  useEffect(() => {
    if (!orderId || orphanedAtMs === null) return;
    if (!shouldWatchOrphanedPayPayQr({ phase, elapsedMs: Date.now() - orphanedAtMs })) {
      onResolvedRef.current();
      return;
    }

    let cancelled = false;
    let timer: number | undefined;

    const tick = async () => {
      try {
        const raw = await apiFetch<unknown>(payPayQrStatusPath(orderId), {
          silent401: true,
        });
        if (cancelled) return;
        const reading = readPayPayQrStatus(raw);
        failuresRef.current = 0;
        retryAfterRef.current = null;

        // `is_fully_paid` is about the ORDER, so it outranks the code's own
        // status: whoever's code took the money, this bill is settled.
        if (reading.isFullyPaid || reading.phase === "paid") {
          setTracked({ at: orphanedAtMs, phase: "paid" });
          onPaidRef.current?.();
          return;
        }
        // A server still saying CREATED leaves us waiting, which is correct —
        // only a definite answer ends the watch.
        if (reading.phase !== "waiting") {
          setTracked({ at: orphanedAtMs, phase: reading.phase });
        }
      } catch (error) {
        if (cancelled) return;
        // Never surfaced: nobody is looking at a screen for this code any more.
        // Counted only to back the cadence off, and to honour a 429 — the
        // sweeper is still underneath as the backstop.
        failuresRef.current += 1;
        retryAfterRef.current =
          error instanceof ApiError && error.status === 429
            ? PAYPAY_STATUS_RATE_LIMITED_FLOOR_MS
            : null;
      }
    };

    const schedule = () => {
      if (cancelled) return;
      timer = window.setTimeout(() => {
        void tick().then(() => {
          if (cancelled) return;
          // Re-running the effect is what re-checks the window and the phase,
          // so a resolved watch stops here rather than scheduling again.
          if (
            shouldWatchOrphanedPayPayQr({
              phase,
              elapsedMs: Date.now() - orphanedAtMs,
            })
          ) {
            schedule();
          } else {
            onResolvedRef.current();
          }
        });
      }, nextPayPayPollDelayMs({
        consecutiveFailures: failuresRef.current,
        retryAfterMs: retryAfterRef.current,
        jitter: Math.random(),
      }));
    };
    schedule();

    return () => {
      cancelled = true;
      if (timer !== undefined) window.clearTimeout(timer);
    };
  }, [orderId, orphanedAtMs, phase]);
}
