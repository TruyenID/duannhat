"use client";

/**
 * plan-054 — the PayPay dynamic-QR screen body.
 *
 * Mounted by `/orders/[id]/pay` when the customer picks PayPay on a branch
 * whose `payment-context.paypay_enabled` is true. Deliberately a plain panel
 * and not a route: the pay page already owns the hard parts (reload safety via
 * the id in the URL, the guest-order access gate, the "already paid" gate, and
 * `useOrderPaidRealtime`) — plan-054 §10.3.
 *
 * Division of labour with the host page:
 *   - **Settlement is the host's job.** The page runs `useOrderSettlement`
 *     (plan-050, which replaced the old `useOrderPaidRealtime`: the Reverb
 *     subscription failed silently and in fact never ran, so the order is now
 *     watched by an adaptive poll that also fires immediately when the tab is
 *     foregrounded). It calls back into `onPaid` here, which is the primary
 *     "the money landed" signal for PayPay exactly as it is for card and
 *     counter — no second mechanism is introduced for this one method.
 *   - **This panel polls the CODE**, on a self-scheduling timer seeded at
 *     `PAYPAY_STATUS_POLL_INTERVAL_MS` (12s) with backoff + jitter
 *     (`nextPayPayPollDelayMs`), plus one immediate poll whenever the tab comes
 *     back to the foreground — the exact moment the customer returns from the
 *     PayPay app. It is a fallback for "paid" and the only source for the states
 *     the order itself cannot express: the code expiring, and the wallet
 *     cancelling. 2s polling was rejected (§10.4).
 *
 * The countdown is seeded ONLY from the server's `expires_in_seconds` and
 * advanced by a client-measured interval, so a skewed device clock can never
 * grey out a QR that is still live (`lib/paypay-qr.ts`, invariant 3). The
 * status endpoint re-states `expires_in_seconds` on every read, so the client
 * never decides a code has lapsed either — see `resolvePayPayQrPhase`.
 *
 * **Every dead end reaches the refresh CTA.** The button used to hang off
 * `expired || failed`, and everything else — a cancelled wallet, a NOT_FOUND
 * code, a poll that cannot reach the server, a screen superseded by a second
 * mint — sat in `waiting`, which renders no way out at all. See
 * `shouldOfferPayPayRefresh`.
 *
 * **And the screen must LOOK like whatever it is.** Not declaring a lapse is
 * a rule about state, not about pixels: for a while it also meant the lapsed
 * screen kept a full-opacity QR under "scan this to pay", a red deep link
 * aimed at the dead code, and no mention of expiry anywhere — indistinguishable
 * from a working screen, on a code PayPay would refuse. `payPayQrPresentation`
 * now decides every one of those pixels from the same state, and only the
 * pixels.
 */

import { useCallback, useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { QRCodeSVG } from "qrcode.react";
import { AlertCircle, Clock, Loader2, RefreshCw, Smartphone } from "lucide-react";

import { Button } from "@/components/ui/button";
import { ApiError, apiFetch } from "@/lib/api";
import { formatCurrency } from "@/lib/currency";
import { shortOrderCode } from "@/lib/utils";
import {
  PAYPAY_STATUS_RATE_LIMITED_FLOOR_MS,
  classifyPayPayCreateFailure,
  formatPayPayCountdown,
  hasLostPayPayStatusContact,
  isAwaitingExpiryConfirmation,
  isPayPayQrTerminal,
  isPayPayScreenSuperseded,
  nextCountdownAnchor,
  nextPayPayPollDelayMs,
  parsePayPayQrSession,
  cancelPayPayQr,
  payPayQrCreatePath,
  payPayQrPresentation,
  payPayQrSecondsLeft,
  payPayQrStatusPath,
  readPayPayQrStatus,
  resolvePayPayQrPhase,
  shouldPollPayPayStatus,
  type PayPayCountdownAnchor,
  type PayPayCreateFailure,
  type PayPayQrPhase,
  type PayPayQrSession,
} from "@/lib/paypay-qr";

interface PayPayQrPanelProps {
  orderId: string;
  orderCode: string;
  /** Display fallback until the server echoes the charged amount back. */
  amount: number;
  /** ISO 4217 of the ORDER, not of the ambient selected branch. */
  currency: string;
  /**
   * #1296 — the share to collect, when it is NOT the whole outstanding balance.
   *
   * Omitting it asks the server for whatever is still owed, computed under the
   * order row lock — right for takeaway, where one payer settles one bill.
   * A dine-in split MUST pass its slice: the mint body used to be a literal
   * `{}`, so the first of four payers was handed a QR for the entire table.
   */
  chargeAmount?: number;
  /**
   * How that share was arrived at (`split_type` / `split_count` /
   * `item_allocations`). Not a money input — see `payPaySplitPayload`. Without
   * it a per-dish payment records as a bare amount and locks the next payer out
   * of the by-items flow.
   */
  splitPayload?: Record<string, unknown>;
  /** Called once the order is settled — the host page owns the redirect. */
  onPaid: () => void;
  /**
   * godx-tempo#1737 — this panel is going away while still holding a live,
   * unpaid code.
   *
   * Unmounting does not kill the code: it stays scannable at PayPay for the
   * rest of its ~5 minutes, and every poll in here dies with the component. The
   * host is told so it can keep asking the status endpoint — which is what
   * BOOKS the money (`syncStatus`) — instead of leaving the payment to the
   * fifteen-minute sweeper.
   *
   * Fires on BOTH surfaces and needs no cooperation from either: dine-in's
   * "Đổi số tiền" (`setPaypayMint(null)`) and takeaway's method radio unmount
   * this same component, and their exit paths otherwise have nothing in common.
   */
  onAbandoned?: () => void;
}

export function PayPayQrPanel({
  orderId,
  orderCode,
  amount,
  currency,
  chargeAmount,
  splitPayload,
  onPaid,
  onAbandoned,
}: PayPayQrPanelProps) {
  const t = useTranslations("paypay");

  /**
   * The minted code and the "still minting" flag held as one value so they can
   * never disagree. `null` = a mint is in flight; `{ session: null }` = the
   * mint failed, and `failure` says whether the customer can do anything about
   * it — a 422 behind a retry button can only 422 again.
   */
  const [mint, setMint] = useState<{
    session: PayPayQrSession | null;
    failure: PayPayCreateFailure | null;
  } | null>(null);
  /** Bumped by the retry button to re-run the mint effect. */
  const [attempt, setAttempt] = useState(0);
  const [nowMs, setNowMs] = useState(() => Date.now());
  const [serverPhase, setServerPhase] = useState<PayPayQrPhase>("waiting");
  /**
   * Countdown anchor, kept apart from `mint` because the status poll RE-ANCHORS
   * it: the server restates `expires_in_seconds` on every read, and its answer
   * outranks whatever a throttled background interval accumulated here.
   *
   * `null` = no countdown at all (PayPay returned no `expiryDate`). That is a
   * perfectly payable code with no timer, NOT a lapsed one — the distinction
   * matters because a lapsed timer offers the refresh CTA and this must not.
   */
  const [anchor, setAnchor] = useState<PayPayCountdownAnchor | null>(null);
  /** Consecutive failed status reads. Drives backoff AND the "lost contact" UI. */
  const [statusFailures, setStatusFailures] = useState(0);
  const statusFailuresRef = useRef(0);
  const retryAfterRef = useRef<number | null>(null);
  /**
   * A newer code exists for this order, so nothing the status endpoint says is
   * about the QR on screen. Sticky: the next poll saying nothing must not
   * un-say it, and there is no way back — the code here is gone server-side.
   */
  const [superseded, setSuperseded] = useState(false);
  /** The code this screen renders, for the staleness comparison above. */
  const displayedCodeRef = useRef<string | null>(null);

  // Fire the host redirect at most once: realtime and the fallback poll can
  // both land on "paid" within the same tick.
  //
  // `onPaid` is held in a ref, NOT closed over: the host page recreates the
  // callback on every render, and a changing identity here would cascade
  // through `pollStatus` into the poll interval's dependency array — which
  // clears and restarts the 12s timer on every render. With a 1s countdown
  // heartbeat driving those renders, the fallback poll would then never fire
  // at all.
  const onPaidRef = useRef(onPaid);
  useEffect(() => {
    onPaidRef.current = onPaid;
  }, [onPaid]);

  // Same treatment, same reason: what the mint should ask for, reachable from the
  // effect without becoming a trigger for it. The retry button re-reads these, so
  // a guest whose first mint failed gets a code for their CURRENT share.
  const chargeAmountRef = useRef(chargeAmount);
  const splitPayloadRef = useRef(splitPayload);
  useEffect(() => {
    chargeAmountRef.current = chargeAmount;
    splitPayloadRef.current = splitPayload;
  }, [chargeAmount, splitPayload]);
  const paidFiredRef = useRef(false);
  const firePaid = useCallback(() => {
    if (paidFiredRef.current) return;
    paidFiredRef.current = true;
    onPaidRef.current();
  }, []);

  // ─── godx-tempo#1737: on the way out, kill the code AND hand it over ─────
  //
  // Unmounting this panel does NOT kill the code — it stays scannable at PayPay
  // for the rest of its ~5 minutes while every poll in here dies with the
  // component. Both halves of the answer live HERE and not in either host,
  // because the two hosts leave by completely different doors:
  //
  //   dine-in  `payment-view.tsx`  — the "Đổi số tiền" button
  //   takeaway `orders/[id]/pay`   — the payment-method radio, which has always
  //                                  carried a warning admitting "the switch
  //                                  really does abandon the QR on screen"
  //
  // Wiring either door individually leaves the other one open; the first attempt
  // at #1737 wired dine-in only and takeaway kept leaking codes. One cleanup
  // covers both, plus every future door, and cannot double-fire.
  //
  // WHY BOTH, and not just the cancel: the cancel is allowed to decline. The
  // server asks PayPay first and REFUSES to kill a code with a scan already in
  // flight (`paypay_qr_cancel_refused_scan_in_flight`), and it also declines on
  // any error reaching PayPay. Those are exactly the cases where a live code is
  // left behind with nobody polling it — so the host is told too, and keeps
  // asking the status endpoint, which is what BOOKS the money. Cancel shuts the
  // window; the handover makes sure somebody is still watching when it cannot.
  //
  // Empty dependency array so the cleanup runs on unmount ONLY — closing over
  // state would make it run on every change and cancel a perfectly live code.
  const onAbandonedRef = useRef(onAbandoned);
  useEffect(() => {
    onAbandonedRef.current = onAbandoned;
  }, [onAbandoned]);
  const exitStateRef = useRef<{ code: string | null; superseded: boolean }>({
    code: null,
    superseded: false,
  });
  // Reads `mint` rather than the `session` alias below: that alias is declared
  // further down the component, and this hook has to sit up here beside the
  // other refs it belongs with.
  useEffect(() => {
    exitStateRef.current = {
      code: mint?.session?.merchant_payment_id ?? null,
      superseded,
    };
  });
  useEffect(
    () => () => {
      const { code, superseded: isSuperseded } = exitStateRef.current;
      // Nothing to do: never minted; already paid (read live — `firePaid` can
      // land in the same tick as the unmount, after the last commit); or
      // superseded, where a newer code exists and acting by order id would hit
      // SOMEBODY ELSE'S live code on a split bill.
      if (!code || isSuperseded || paidFiredRef.current) return;
      void cancelPayPayQr(orderId, code, (url, init) =>
        apiFetch<unknown>(url, { ...init, silent401: true }),
      );
      onAbandonedRef.current?.();
    },
    [orderId],
  );

  // ─── Mint a code ─────────────────────────────────────────────────────────
  // The body carries `amount` only when the host names a share (#1296). Omitted,
  // the server is authoritative on what is still owed — partial counter
  // payments, coupons applied after the page was opened — and the contract makes
  // it optional precisely so the client need not guess. A dine-in split is the
  // one case the client DOES know better: the server cannot tell which quarter
  // of the table this phone is settling.
  //
  // Read through refs, NOT dependencies. `splitPayload` is a fresh object every
  // render, so listing it would re-run this effect on any parent re-render —
  // and re-running it mints a second code and voids the one the customer is
  // looking at, which is the same reason `t` is excluded below. The host
  // therefore freezes these while a code is live (see `payment-view.tsx`) rather
  // than the panel chasing them.
  //
  // `t` is deliberately NOT a dependency — a new translator identity must
  // never re-run this effect, because re-running it mints a second PayPay code
  // and voids the one the customer is looking at. Failure is therefore stored
  // as a shape, not as a translated string.
  // The AbortController is not tidiness — it is correctness. The cleanup used
  // to only set `cancelled = true`, which drops the RESPONSE but leaves the
  // POST in flight, so a StrictMode double-invoke (or a fast retry) sent two
  // creates. Two concurrent mints race `invalidateOutstandingQr` — each runs
  // before the other's attempt row exists — and both codes can survive, which
  // is the "two scannable codes for one order" the service's own comment names
  // as how an overpayment happens with nobody doing anything wrong.
  useEffect(() => {
    let cancelled = false;
    const controller = new AbortController();
    const run = async () => {
      try {
        const share = chargeAmountRef.current;
        const raw = await apiFetch<unknown>(payPayQrCreatePath(orderId), {
          method: "POST",
          body: JSON.stringify({
            ...(share !== undefined && share > 0 ? { amount: share } : {}),
            ...(splitPayloadRef.current ?? {}),
          }),
          signal: controller.signal,
        });
        if (cancelled) return;
        const receivedAtMs = Date.now();
        const session = parsePayPayQrSession(raw);
        paidFiredRef.current = false;
        displayedCodeRef.current = session?.merchant_payment_id ?? null;
        setMint({ session, failure: session ? null : "retryable" });
        setAnchor(
          session && session.expires_in_seconds !== null
            ? { expiresInSeconds: session.expires_in_seconds, anchoredAtMs: receivedAtMs }
            : null,
        );
        setServerPhase("waiting");
        setNowMs(receivedAtMs);
      } catch (error) {
        if (cancelled || controller.signal.aborted) return;
        displayedCodeRef.current = null;
        // A 422 `PAYPAY_NOT_AVAILABLE` is not "try again" — it is "this order
        // has nothing left to pay" / "this branch has no PayPay". Handing that
        // a retry button showed a customer who had *just paid* a failure screen
        // whose only button re-failed. The host page's settlement watcher is
        // still running underneath, so a paid order still redirects itself.
        setMint({
          session: null,
          failure: classifyPayPayCreateFailure(
            error instanceof ApiError ? error.status : null,
          ),
        });
        setAnchor(null);
      }
    };
    void run();
    return () => {
      cancelled = true;
      controller.abort();
    };
  }, [orderId, attempt]);

  const retry = () => {
    setMint(null);
    setAnchor(null);
    setSuperseded(false);
    setStatusFailures(0);
    statusFailuresRef.current = 0;
    retryAfterRef.current = null;
    displayedCodeRef.current = null;
    setAttempt((n) => n + 1);
  };

  // ─── Countdown ───────────────────────────────────────────────────────────
  // A 1s heartbeat feeds `nowMs`; the remaining seconds are RECOMPUTED from
  // (server seconds, anchor, now) at render rather than decremented, so a
  // throttled background timer cannot drift the display.
  useEffect(() => {
    const id = window.setInterval(() => setNowMs(Date.now()), 1000);
    return () => window.clearInterval(id);
  }, []);

  const session = mint?.session ?? null;
  const hasCountdown = anchor !== null;
  const secondsLeft = anchor
    ? payPayQrSecondsLeft({
        expiresInSeconds: anchor.expiresInSeconds,
        anchoredAtMs: anchor.anchoredAtMs,
        nowMs,
      })
    : 0;
  // Only the server expires a code. A zeroed local countdown means "our clock
  // ran out", never "the code is dead" — see `resolvePayPayQrPhase`.
  const phase: PayPayQrPhase = resolvePayPayQrPhase({ serverPhase, secondsLeft });
  // Gated on `hasCountdown`: a code that never had a timer has not "lapsed", so
  // it must not read as awaiting-confirmation (which would offer the refresh
  // CTA on a freshly minted, perfectly good QR).
  const awaitingExpiryConfirmation =
    hasCountdown && isAwaitingExpiryConfirmation({ serverPhase, secondsLeft });
  const lostContact = hasLostPayPayStatusContact(statusFailures);

  // ─── Fallback poll ───────────────────────────────────────────────────────
  const pollStatus = useCallback(async () => {
    try {
      const raw = await apiFetch<unknown>(payPayQrStatusPath(orderId), {
        silent401: true,
      });
      const reading = readPayPayQrStatus(raw);
      const receivedAtMs = Date.now();
      statusFailuresRef.current = 0;
      retryAfterRef.current = null;
      setStatusFailures(0);

      // `is_fully_paid` is about the ORDER, so it outranks everything below,
      // staleness included: whoever's code took the money, this customer is
      // done.
      if (reading.isFullyPaid) {
        setServerPhase("paid");
        firePaid();
        return;
      }

      // A second mint (another tab, another phone on a split bill) makes the
      // status endpoint answer about the NEWER code — `liveAttempt()` always
      // resolves the newest. Adopting any of that here would re-anchor this
      // screen's countdown to a code it is not showing, painting a healthy
      // ticking timer on a QR the server already deleted.
      if (
        isPayPayScreenSuperseded({
          displayed: displayedCodeRef.current,
          reported: reading.merchantPaymentId,
        })
      ) {
        setSuperseded(true);
        return;
      }

      // Re-anchor: even a read that only says "still CREATED" carries a fresh
      // countdown, which is the whole point of the server echoing it. A screen
      // that started WITHOUT a countdown adopts the first one it is offered.
      setAnchor((cur) =>
        cur
          ? nextCountdownAnchor({
              current: cur,
              serverExpiresInSeconds: reading.expiresInSeconds,
              receivedAtMs,
            })
          : reading.expiresInSeconds !== null
            ? { expiresInSeconds: reading.expiresInSeconds, anchoredAtMs: receivedAtMs }
            : cur,
      );
      // Only a definite server answer is recorded; a server still reporting
      // CREATED leaves the screen waiting, which is what it should do.
      if (reading.phase !== "waiting") setServerPhase(reading.phase);
      if (reading.phase === "paid") firePaid();
    } catch (error) {
      // Still no toast — the host page's settlement watcher is the primary
      // channel for "paid" and one flaky poll is not news. But it is COUNTED
      // now: silence used to be indistinguishable from a healthy wait, so a
      // screen whose poll had died forever looked exactly like one that was
      // working. Past the limit the customer is told, and offered a new code.
      statusFailuresRef.current += 1;
      setStatusFailures(statusFailuresRef.current);
      retryAfterRef.current =
        error instanceof ApiError && error.status === 429
          ? PAYPAY_STATUS_RATE_LIMITED_FLOOR_MS
          : null;
    }
  }, [orderId, firePaid]);

  // Self-scheduling rather than `setInterval`: a fixed interval has no backoff,
  // no jitter and no way to honour a 429. This route shares the 120/min
  // `customer-order-read` bucket — keyed on the ORDER — with `useOrderSettlement`
  // at 5s, so several phones on one split bill can saturate it; the poller that
  // must yield is this one, not the one that actually settles the order.
  useEffect(() => {
    if (!session || superseded || !shouldPollPayPayStatus(phase)) return;
    let cancelled = false;
    let timer: number | undefined;
    const schedule = () => {
      if (cancelled) return;
      timer = window.setTimeout(
        () => {
          void pollStatus().then(schedule);
        },
        nextPayPayPollDelayMs({
          consecutiveFailures: statusFailuresRef.current,
          retryAfterMs: retryAfterRef.current,
          jitter: Math.random(),
        }),
      );
    };
    schedule();
    return () => {
      cancelled = true;
      if (timer !== undefined) window.clearTimeout(timer);
    };
  }, [session, superseded, phase, pollStatus]);

  // Immediate poll when the tab is foregrounded — the customer coming back
  // from the PayPay app, where the websocket most likely died meanwhile.
  // Deliberately NOT gated on `shouldPollPayPayStatus`: a wallet that settled
  // a second after our local countdown lapsed would otherwise never be seen by
  // this screen. Anything short of `paid` is worth one request on return.
  useEffect(() => {
    if (!session || superseded) return;
    const onVisible = () => {
      if (document.visibilityState !== "visible") return;
      if (isPayPayQrTerminal(phase)) return;
      void pollStatus();
    };
    document.addEventListener("visibilitychange", onVisible);
    return () => document.removeEventListener("visibilitychange", onVisible);
  }, [session, superseded, phase, pollStatus]);

  // ─── Render ──────────────────────────────────────────────────────────────

  const displayAmount = session?.amount || amount;
  const fmt = (v: number) => formatCurrency(v, currency);

  if (!mint) {
    return (
      <Shell>
        <div className="flex flex-col items-center gap-3 py-10">
          <Loader2 className="size-7 animate-spin text-primary" />
          <p className="text-sm text-neutral-500">{t("checking")}</p>
        </div>
      </Shell>
    );
  }

  if (!session) {
    // A 4xx is the server telling us a retry cannot help — most often "this
    // order has nothing left to pay", i.e. the customer paid in the PayPay app,
    // iOS discarded the tab, and the reload minted into an already-settled
    // order. Showing that a retry button is showing a paying customer a broken
    // button.
    const failure = mint.failure ?? "retryable";
    const failureMessage =
      failure === "not_available"
        ? t("createUnavailable")
        : failure === "rate_limited"
          ? t("createRateLimited")
          : t("createFailed");
    return (
      <Shell>
        <div className="flex flex-col items-center gap-4 py-8">
          <div className="flex size-14 items-center justify-center rounded-full bg-amber-50">
            <AlertCircle className="size-8 text-amber-500" />
          </div>
          <p className="max-w-xs text-center text-sm leading-relaxed text-neutral-600">
            {failureMessage}
          </p>
          {failure !== "not_available" && (
            <Button variant="outline" onClick={retry}>
              <RefreshCw className="size-4" />
              {t("retryCta")}
            </Button>
          )}
        </div>
      </Shell>
    );
  }

  if (phase === "paid") {
    return (
      <Shell>
        <div className="flex flex-col items-center gap-3 py-10">
          <Loader2 className="size-7 animate-spin text-emerald-500" />
          <p className="text-sm font-semibold text-emerald-700">{t("alreadyPaid")}</p>
        </div>
      </Shell>
    );
  }

  // Everything below is decided by ONE pure function so the "looks alive but
  // is not" window cannot come back: a browser walkthrough found the lapsed
  // screen rendering a full-opacity QR under "scan this to pay", a red deep
  // link aimed at the dead code, and no mention of expiry anywhere.
  //
  // It is presentation only — no phase, no poll, no `firePaid` is reached from
  // here, so the client still never declares a lapse. The instant the server
  // answers, `awaitingExpiryConfirmation` is false and its answer renders.
  const ui = payPayQrPresentation({
    phase,
    superseded,
    awaitingExpiryConfirmation,
    hasCountdown,
    lostContact,
    hasDeepLink: Boolean(session.deeplink),
  });

  return (
    <Shell>
      <div className="flex flex-col items-center gap-4">
        <p className="max-w-xs text-center text-sm leading-relaxed text-neutral-600">
          {t(ui.headlineKey)}
        </p>

        <div className="relative rounded-2xl border border-neutral-100 p-3">
          <div
            className={ui.dimQr ? "opacity-20 blur-[2px]" : undefined}
            aria-hidden={ui.dimQr}
          >
            {/* godx-tempo#1719 — `qrcode.react` emits `role="img"` with no
                accessible name, so a screen reader announced a bare "image" on
                the one element the whole screen is about. Not a dead end (the
                deep link below is the text alternative) but the amount belongs
                in the name — that is what the guest is being asked to confirm. */}
            <QRCodeSVG
              value={session.qr_url}
              size={220}
              fgColor="#000000"
              bgColor="#ffffff"
              title={t("qrCodeAlt", { amount: fmt(displayAmount) })}
            />
          </div>
          {ui.badgeKey && (
            <div className="absolute inset-0 flex items-center justify-center">
              <span className="rounded-full bg-neutral-900/85 px-4 py-1.5 text-center text-xs font-bold text-white">
                {t(ui.badgeKey)}
              </span>
            </div>
          )}
        </div>

        <div className="text-center">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-neutral-400">
            {t("amountLabel")}
          </p>
          <p className="text-2xl font-extrabold tabular-nums text-emerald-600">
            {fmt(displayAmount)}
          </p>
          <p className="mt-0.5 text-xs text-neutral-500">
            {t("orderCodeLabel")} #{shortOrderCode(orderCode)}
          </p>
        </div>

        {/* Same-device path: the QR is unscannable on the phone rendering it,
            so hand the customer the PayPay deeplink instead. Gone the moment
            the code stops being presented as scannable — it opens the wallet
            on THIS code, so leaving it as the loudest button on a lapsed
            screen is an invitation to try paying a code PayPay will refuse. */}
        {ui.showDeepLink && (
          <a
            href={session.deeplink}
            className="flex w-full max-w-xs items-center justify-center gap-2 rounded-2xl bg-[#FF0033] px-6 py-3.5 text-base font-bold text-white transition-opacity active:translate-y-px active:opacity-90"
          >
            <Smartphone className="size-5" />
            {t("openAppCta")}
          </a>
        )}

        {/* Hidden once the local countdown lapses: the code may well still be
            live and only the server can say otherwise, so a frozen `0:00`
            would be the one thing on screen that is not true. Also hidden when
            there is no countdown at all — PayPay returned no `expiryDate`,
            which is a scannable code with no timer, not a broken one. */}
        {ui.showCountdown && (
          <div className="flex items-center gap-1.5 text-sm font-medium text-neutral-500 tabular-nums">
            <Clock className="size-4" />
            <span>{t("expiresIn", { time: formatPayPayCountdown(secondsLeft) })}</span>
          </div>
        )}

        {/* Primary once the QR is no longer scannable: minting a new code IS
            the customer's next move, and it used to be a small low-contrast
            button below everything else. Secondary while the QR still works —
            there it is only an escape hatch from a poll that lost the server. */}
        {ui.refreshCta === "primary" && (
          <Button
            className="h-auto w-full max-w-xs gap-2 rounded-2xl px-6 py-3.5 text-base font-bold"
            onClick={retry}
          >
            <RefreshCw className="size-5" />
            {t("retryCta")}
          </Button>
        )}

        {ui.waitingKey && (
          <div className="flex max-w-xs items-center justify-center gap-2 text-center text-xs text-neutral-400">
            <Loader2 className="size-3.5 shrink-0 animate-spin" />
            <span>{t(ui.waitingKey)}</span>
          </div>
        )}

        {ui.showKeepOpenHint && (
          <p className="max-w-xs text-center text-[11px] leading-relaxed text-neutral-400">
            {t("keepOpenHint")}
          </p>
        )}

        {/* The way out of a `waiting` that has stopped making progress: the
            poll cannot reach the server. Without this the customer is looking
            at a screen with no button on it. */}
        {ui.refreshCta === "secondary" && (
          <div className="flex flex-col items-center gap-2 border-t border-neutral-100 pt-4">
            {lostContact && (
              <p className="max-w-xs text-center text-xs leading-relaxed text-amber-600">
                {t("connectionLost")}
              </p>
            )}
            <Button variant="outline" size="sm" onClick={retry}>
              <RefreshCw className="size-4" />
              {t("retryCta")}
            </Button>
          </div>
        )}
      </div>
    </Shell>
  );
}

function Shell({ children }: { children: React.ReactNode }) {
  const t = useTranslations("paypay");
  return (
    <div className="rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm">
      <div className="mb-4 flex items-center justify-center gap-2">
        <span className="inline-flex h-6 items-center rounded bg-[#FF0033] px-2 text-xs font-black italic tracking-tight text-white">
          PayPay
        </span>
        <h2 className="text-sm font-semibold text-neutral-700">{t("title")}</h2>
      </div>
      {children}
    </div>
  );
}
