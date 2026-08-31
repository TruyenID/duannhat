/**
 * plan-054 — PayPay dynamic-QR for customer-web.
 *
 * Every *decision* the PayPay flow makes lives here, deliberately free of
 * React / DOM / network so `node --test 'lib/**\/*.test.ts'` can lock it
 * (customer-web has no component-test rig — see plan-054 §10).
 *
 * Three invariants this module exists to enforce:
 *
 *  1. **The PayPay radio is never hidden.** `value="qr_pay"` renders on the
 *     checkout surfaces unconditionally and has always meant "staff settle it
 *     by hand". `paypayEnabled === false` must therefore keep *exactly* today's
 *     behaviour; only `true` upgrades the same button to the QR flow. Making
 *     the button conditional would delete a working control on every branch
 *     (0 branches are configured today) — plan-054 D-B11 / §10.2.
 *
 *  2. **The radio value stays `qr_pay`.** The backend order enum is
 *     `in:counter,transfer,call_staff,qr_pay,card`; sending `paypay` is a 422.
 *     `paypay` is only ever a *URL hint* (`/orders/{id}/pay?method=paypay`).
 *
 *  3. **The countdown anchors to the server's `expires_in_seconds`, never to
 *     `expires_at`.** A device with a fast-skewed clock would render an
 *     already-expired QR and strand a perfectly payable order. This is the
 *     same lesson `lib/order-expiry.ts` learned in plan-031 with
 *     `seconds_until_due`, so we reuse its skew-immune helper rather than
 *     re-derive it.
 */

// Explicit `.ts` extension (tsconfig `allowImportingTsExtensions`) so this
// module is loadable both by the bundler and by `node --test`, which resolves
// ESM specifiers literally. The countdown rule is NOT re-derived here — see
// `payPayQrSecondsLeft` below.
import { computeSecondsLeft } from "./order-expiry.ts";

/** Backend order enum value. NEVER `"paypay"` — that 422s. */
export const PAYPAY_RADIO_VALUE = "qr_pay";

/** `?method=` hint on `/orders/[id]/pay` that preselects the QR screen. */
export const PAYPAY_METHOD_PARAM = "paypay";

/**
 * Fallback poll cadence. Realtime (`useOrderPaidRealtime` on the public
 * `customer.order.{id}` channel) is the primary signal; this only covers a
 * dead websocket. 2s polling was explicitly rejected — plan-054 §10.4.
 */
export const PAYPAY_STATUS_POLL_INTERVAL_MS = 12_000;

/**
 * Server-side PayPay code state, straight from the status endpoint.
 *
 * This is the FULL vocabulary the endpoint can emit, not just the happy four:
 * `PayPayLifecycleMapper::mapPaymentState` folds `CANCELED`/`CANCELLED`/
 * `REVERTED` into its canceled state, and `PayPayPaymentService::syncStatus()`
 * synthesises `NOT_FOUND` whenever there is no live attempt left to ask about.
 * Every one of these used to fall through to `waiting` — a screen with no exit
 * and no refresh CTA, on an order the customer can still pay.
 */
export type PayPayQrStatus =
  | "CREATED"
  | "COMPLETED"
  | "FAILED"
  | "EXPIRED"
  | "CANCELED"
  | "CANCELLED"
  | "REVERTED"
  | "NOT_FOUND";

/** What the QR panel actually renders. */
export type PayPayQrPhase = "waiting" | "paid" | "failed" | "expired";

/** `POST /api/v1/customer/orders/{id}/paypay-qr` → 201 `data`. */
export interface PayPayQrSession {
  qr_url: string;
  deeplink: string;
  merchant_payment_id: string;
  amount: number;
  expires_at: string;
  /**
   * Countdown seed, and the ONLY thing the countdown may read.
   *
   * `null` when PayPay's create response carried no `expiryDate` — the backend
   * types this `int|null` and legitimately emits null. A live, scannable code
   * with no countdown is still a payable code, so this degrades to "render the
   * QR, omit the timer" rather than throwing the whole session away.
   */
  expires_in_seconds: number | null;
}

/** `GET /api/v1/customer/orders/{id}/paypay-qr/status` → 200 `data`. */
export interface PayPayQrStatusPayload {
  status: PayPayQrStatus | string;
  is_fully_paid: boolean;
  order_status: string;
  /**
   * Server-anchored remaining life of the CODE, echoed on every status read.
   *
   * This did not exist when the screen was first written, and its absence was
   * the bug: the browser was the only thing that could decide a code had
   * lapsed, so a wallet settling one second after the local countdown hit zero
   * was rendered as "expired" while the money was already moving. With this
   * field the client never makes that call — see `resolvePayPayQrPhase`.
   *
   * `null` when the server has no live code to speak for.
   */
  expires_in_seconds?: number | null;
  /**
   * WHICH code this answer is about.
   *
   * The status route takes only an order id and `liveAttempt()` always resolves
   * the NEWEST attempt, so a second mint on the same order (two tabs, two phones
   * on a split bill, a StrictMode double-invoke) makes every older screen's poll
   * report the new code's state — and re-anchor its countdown to the new code's
   * remaining life, painting a healthy ticking timer on a QR the server already
   * deleted. Comparing this against the code on screen is the only way a screen
   * can tell it has been superseded.
   *
   * `null` when no code is outstanding.
   */
  merchant_payment_id?: string | null;
}

/** What one status read told us: the phase, a countdown anchor, and whose. */
export interface PayPayQrStatusReading {
  phase: PayPayQrPhase;
  /** `null` = the server said nothing about expiry on this read. */
  expiresInSeconds: number | null;
  /** `null` = the server named no code, so nothing can be judged stale. */
  merchantPaymentId: string | null;
  /**
   * Order-level truth, kept apart from `phase` on purpose: it outranks
   * staleness. A superseded screen whose ORDER is fully paid is a customer who
   * is done, whoever's code took the money.
   */
  isFullyPaid: boolean;
}

export function payPayQrCreatePath(orderId: string): string {
  return `/api/v1/customer/orders/${orderId}/paypay-qr`;
}

export function payPayQrStatusPath(orderId: string): string {
  return `/api/v1/customer/orders/${orderId}/paypay-qr/status`;
}

/**
 * `DELETE /api/v1/customer/orders/{id}/paypay-qr` — huỷ mã đang sống (#1737).
 *
 * Cùng đường dẫn với mint, khác ĐỘNG TỪ. Backend luôn trả 204, kể cả khi không
 * còn mã nào: chỗ gọi là một nút thoát khỏi màn hình nên nó phải bấm được nhiều
 * lần mà không sinh lỗi.
 */
export function payPayQrCancelPath(orderId: string): string {
  return `/api/v1/customer/orders/${orderId}/paypay-qr`;
}

/**
 * Huỷ mã QR đang sống, và NUỐT mọi lỗi (#1737).
 *
 * Chỗ gọi là lối thoát khỏi panel QR. Ở đó người dùng đã quyết định đi tiếp;
 * dựng một hộp lỗi lên chỉ chặn họ lại vì một việc dọn dẹp mà họ không yêu cầu.
 *
 * Nuốt lỗi an toàn vì có LƯỚI: `payments:sweep-paypay-qr` chạy 15 phút một lần
 * và dọn các mã treo. Huỷ ở đây chỉ để rút cửa sổ nguy hiểm từ ~5 phút xuống
 * gần bằng 0 trong đường bình thường — nó không phải cơ chế duy nhất, và không
 * được cư xử như thể là.
 *
 * `merchantPaymentId` nói mã NÀO đang được huỷ, và nó bắt buộc. Backend giải
 * `liveAttempt()` ra attempt MỚI NHẤT, nên một lượt huỷ đến muộn — khách bấm
 * thoát rồi mint lại ngay — sẽ giết đúng mã vừa mint mà họ đang nhìn. Cùng bài
 * học `isPayPayScreenSuperseded` bên dưới đã phải học ở phía đọc.
 *
 * `keepalive` để lượt huỷ vẫn bay đi khi trang đang bị đóng. Nhưng KHÔNG gắn
 * hàm này vào `pagehide`/`beforeunload`/`visibilitychange`: tab bị ẩn chính là
 * lúc khách bấm deeplink `paypay://` nhảy sang app PayPay để trả tiền (xem
 * `paypay-qr-panel.tsx`, nút "mở app" và cú poll khi tab quay lại foreground).
 * Huỷ lúc đó sẽ mở app PayPay ra một mã vừa bị merchant xoá — giết luôn luồng
 * thanh toán cùng thiết bị.
 */
export async function cancelPayPayQr(
  orderId: string,
  merchantPaymentId: string,
  fetcher: (url: string, init: RequestInit) => Promise<unknown>,
): Promise<void> {
  try {
    await fetcher(payPayQrCancelPath(orderId), {
      method: "DELETE",
      keepalive: true,
      body: JSON.stringify({ merchant_payment_id: merchantPaymentId }),
    });
  } catch {
    // Xem docblock: sweeper là lưới an toàn.
  }
}

export function paymentContextPath(branchSlug: string): string {
  return `/api/v1/customer/branches/${branchSlug}/payment-context`;
}

/** In-app route for the QR screen. NOT a new route — plan-054 §10.3. */
export function payPayQrPath(orderId: string): string {
  return `/orders/${orderId}/pay?method=${PAYPAY_METHOD_PARAM}`;
}

/** True when `/orders/[id]/pay` was reached with the PayPay hint. */
export function isPayPayQrRequested(methodParam: string | null | undefined): boolean {
  return methodParam === PAYPAY_METHOD_PARAM;
}

/**
 * Does `/orders/[id]/pay` offer the method radios, or is the method settled?
 *
 * ONE screen, TWO arrivals, and until now one layout for both:
 *
 *   - **Checkout handover** (`?method=paypay`, minted by `payPayQrPath`) — the
 *     customer chose PayPay *seconds ago* on the checkout page. Re-offering the
 *     radios here asks the same question twice, and the answer that screen
 *     invites is the one that hurts: switching unmounts the panel, which voids
 *     the code server-side (`invalidateOutstandingQr`), so the chooser's own
 *     `switchMethodWarning` existed to warn about a control that had no reason
 *     to be there. The QR is also pushed below the fold by a chooser nobody
 *     asked for.
 *   - **"Pay now" CTA** (`/orders`, `/orders/[id]` — no hint at all) — the
 *     returning customer who deferred paying and has chosen nothing yet. For
 *     them the chooser IS the screen, so an absent hint must keep exactly
 *     today's behaviour.
 *
 * `optedOut` is the single door back, and it is a door out of a *dead end*, not
 * a change of mind: a hinted arrival whose branch turns out to have no PayPay
 * (turned off between checkout and here, or the hint was typed by hand) renders
 * the not-available card, and its CTA has to lead somewhere. Without this the
 * locked screen would be a hinted URL with no payable method and no controls.
 */
export function shouldShowPayMethodChooser(input: {
  hintedMethod: string | null | undefined;
  optedOut?: boolean;
}): boolean {
  if (input.optedOut === true) return true;
  return !isPayPayQrRequested(input.hintedMethod);
}

/**
 * `payment_context.paypay_enabled`, read defensively.
 *
 * Anything that is not a literal `true` — a 404, a network error, an older
 * backend that has no such field — means "keep today's manual behaviour".
 * Fail-closed here is fail-*safe*: the radio still renders, it just does not
 * upgrade.
 */
export function parsePayPayAvailability(raw: unknown): boolean {
  if (typeof raw !== "object" || raw === null) return false;
  const data = (raw as { data?: unknown }).data;
  if (typeof data !== "object" || data === null) return false;
  return (data as { paypay_enabled?: unknown }).paypay_enabled === true;
}

/**
 * #2806 — the branch's pay-at-counter switches, off the SAME payload.
 *
 * Note the asymmetry with `parsePayPayAvailability` above, which is deliberate
 * and runs the opposite way. PayPay defaults to `false` on anything unparseable
 * because an unresolved probe there must not *upgrade* behaviour — it would mint
 * a real QR for a shop that cannot honour it.
 *
 * These default to `true`, because the failure mode is reversed: reading a
 * dropped request as "counter is off" hides the payment channel from a guest
 * whose shop offers it, and on a branch with no online gateway that leaves a
 * checkout screen with no way to pay at all. The server's own defaults for a
 * branch with no settings row are `true` as well, so "could not ask" and
 * "asked, nobody has configured it" agree.
 */
export interface CounterPaySettings {
  counterPayEnabled: boolean;
  counterPayShowQr: boolean;
}

export const COUNTER_PAY_DEFAULTS: CounterPaySettings = {
  counterPayEnabled: true,
  counterPayShowQr: true,
};

export function parseCounterPaySettings(raw: unknown): CounterPaySettings {
  if (typeof raw !== "object" || raw === null) return COUNTER_PAY_DEFAULTS;
  const data = (raw as { data?: unknown }).data;
  if (typeof data !== "object" || data === null) return COUNTER_PAY_DEFAULTS;
  const d = data as { counter_pay_enabled?: unknown; counter_pay_show_qr?: unknown };
  // Only an explicit `false` turns something off — hiding a payment channel is
  // the destructive direction, so it takes a real answer from the server, never
  // an absent or malformed one.
  return {
    counterPayEnabled: d.counter_pay_enabled !== false,
    counterPayShowQr: d.counter_pay_show_qr !== false,
  };
}

/**
 * Should CHECKOUT hand the customer over to the `/orders/[id]/pay` QR screen
 * instead of today's settle-by-hand path?
 *
 * `orderType` is part of the answer, and stays `"takeaway"` only — this is a
 * question about a ROUTE, not about PayPay. `/orders/[id]/pay` gates access on
 * the guest-order pointer, which is typed `"takeaway"` (`lib/guest-orders.ts`,
 * plan-031 scope), so routing a dine-in order there produces a "forbidden"
 * screen on the first reload.
 *
 * **Dine-in reaching PayPay is a different question** — see
 * `canRunPayPayQrPanelInline` (#1296). It got its own surface rather than a
 * widened gate here: `payment-view.tsx` imports no router at all and settles in
 * place. Widening this predicate was the plan's original T8.2 and it was wrong;
 * T8.5 caught it.
 *
 * **Signing in stopped being part of the answer (#1692).** This predicate used to
 * refuse a signed-in customer outright, because `/orders/[id]/pay` bounced one to
 * `/account/orders/{id}` on mount — a receipt with no way to pay. #1452 removed
 * that bounce and gave the pay screen an auth gate that serves both audiences ("a
 * logged-in customer needs no pointer"), so the destination has been correct for
 * signed-in customers since 2026-08-01; only this gate still stood in the way.
 *
 * What made it expensive is that `shouldShowPayPayCheckoutHint` shares this
 * predicate, so the refusal was also silent: a signed-in customer picking
 * `qr_pay` was routed to the settle-at-the-till screen with nothing on the radio
 * ever having mentioned PayPay.
 */
export function shouldUsePayPayQrFlow(input: {
  paypayEnabled: boolean;
  paymentMethod: string;
  orderType: string;
}): boolean {
  if (!input.paypayEnabled) return false;
  if (input.paymentMethod !== PAYPAY_RADIO_VALUE) return false;
  return input.orderType === "takeaway";
}

/**
 * Where checkout should send the customer after `POST /orders` succeeded.
 * `null` = "unchanged, use whatever this surface did before" — which is what
 * keeps `paypayEnabled === false` byte-identical to today.
 */
export function payPayPostOrderRoute(input: {
  paypayEnabled: boolean;
  paymentMethod: string;
  orderType: string;
  orderId: string;
}): string | null {
  return shouldUsePayPayQrFlow(input) ? payPayQrPath(input.orderId) : null;
}

/** The dine-in bill's third payment method, alongside `online` and `counter`. */
export const PAYPAY_DINE_IN_METHOD = "paypay";

/**
 * #1296 — may the dine-in bill mount the QR panel in place?
 *
 * No route, no order-type test, no login test: the panel is a component, the
 * host screen already owns the order, and nothing navigates. What is left is the
 * one thing that actually decides it — whether the branch is wired for PayPay.
 *
 * **`paypayEnabled` is load-bearing here in a way it is not on checkout.** There,
 * the `qr_pay` radio has always meant "staff settle it at the till", so an
 * unconfigured branch keeps a working button and PayPay only upgrades it
 * (plan-054 §10.2 / D-B11). Dine-in has no such fallback meaning to degrade to:
 * the guest is at the table with the bill open, and a PayPay button that cannot
 * mint a code is simply a dead button. So on this surface the option is hidden
 * unless the server said yes — which is also why the hook behind it treats
 * "could not determine" as "not yet", never as "no".
 */
export function canRunPayPayQrPanelInline(input: {
  paypayEnabled: boolean;
  paymentMethod: string;
}): boolean {
  return input.paypayEnabled && input.paymentMethod === PAYPAY_DINE_IN_METHOD;
}

/** One line of a per-dish split: how many units of this order item are being settled. */
export interface PayPayItemAllocation {
  item_id: string;
  units: number;
}

/**
 * The split fields to send with a mint, or `{}` for a plain pay-in-full.
 *
 * These are NOT money inputs — `amount` alone decides what the code collects,
 * and the server caps it at what is outstanding. What they buy is attribution:
 * without them a dine-in PayPay payment records as a bare amount, the dishes it
 * settled never disable in the bill, and the NEXT payer's by-items request is
 * refused outright (`split_by_items_mode_locked`) because the order now carries
 * a row using "another split mode".
 *
 * Deliberately the same field names the Stripe split endpoint takes, so one bill
 * reads the same whichever gateway settled which share of it.
 */
export function payPaySplitPayload(input: {
  paymentMode: "full" | "split";
  splitType: "even" | "by_items" | "by_amount";
  splitCount: number;
  itemAllocations: PayPayItemAllocation[];
}): {
  split_type?: string;
  split_count?: number;
  item_allocations?: PayPayItemAllocation[];
} {
  if (input.paymentMode !== "split") return {};

  if (input.splitType === "by_items") {
    const allocations = input.itemAllocations.filter(
      (allocation) => allocation.item_id !== "" && allocation.units > 0,
    );
    // An empty allocation list is not a by-items split. Declaring one would
    // stamp `split_mode: by_items` with nothing attributed — crediting no dish
    // AND locking every later by-items payer out. The server refuses it too;
    // this just avoids sending it.
    return allocations.length > 0
      ? { split_type: "by_items", item_allocations: allocations }
      : {};
  }

  if (input.splitType === "even") {
    return input.splitCount >= 2
      ? { split_type: "even", split_count: input.splitCount }
      : {};
  }

  return { split_type: "by_amount" };
}

/**
 * Defensive parse of the create response. A partial/garbled body must not
 * render a QR the customer cannot pay — better to show the retry CTA.
 *
 * The two fields that make a code *payable* — `qr_url` and
 * `merchant_payment_id` — are the only ones required. Everything else degrades:
 * a missing `deeplink` means scan-only, and a missing/null `expires_in_seconds`
 * means "no countdown", NOT "creation failed". The backend types that field
 * `int|null` and emits null whenever PayPay's create response carries no
 * `expiryDate`; rejecting the session there discarded a live, scannable code
 * and told a customer it had failed. What we still refuse is inventing a
 * countdown from `expires_at` — invariant 3 stands: no anchor, no timer.
 */
export function parsePayPayQrSession(raw: unknown): PayPayQrSession | null {
  if (typeof raw !== "object" || raw === null) return null;
  const data = (raw as { data?: unknown }).data;
  if (typeof data !== "object" || data === null) return null;
  const d = data as Record<string, unknown>;

  const qrUrl = typeof d.qr_url === "string" ? d.qr_url : "";
  const merchantPaymentId =
    typeof d.merchant_payment_id === "string" ? d.merchant_payment_id : "";
  const expiresIn = d.expires_in_seconds;

  if (!qrUrl || !merchantPaymentId) return null;

  return {
    qr_url: qrUrl,
    // The deeplink is a convenience CTA; the QR alone is a complete flow, so a
    // missing deeplink degrades to "scan only" rather than failing the screen.
    deeplink: typeof d.deeplink === "string" ? d.deeplink : "",
    merchant_payment_id: merchantPaymentId,
    amount: typeof d.amount === "number" ? d.amount : 0,
    expires_at: typeof d.expires_at === "string" ? d.expires_at : "",
    // Same degradation as the deeplink, for the same reason: the code is
    // payable without it.
    expires_in_seconds:
      typeof expiresIn === "number" && Number.isFinite(expiresIn)
        ? Math.max(0, Math.round(expiresIn))
        : null,
  };
}

/**
 * A create call failed — can the customer do anything about it?
 *
 * Every failure used to collapse into one "Could not create the QR code.
 * Please try again." screen behind a retry button that could only fail the same
 * way. The nastiest instance: the customer pays in the PayPay app, iOS discards
 * the tab, they come back, the page reloads and mints again, `outstanding <= 0`
 * → 422 `PAYPAY_NOT_AVAILABLE` → *a customer who just paid is shown a failure
 * screen with a retry that will 422 forever*.
 *
 *  - `not_available` — the server answered with a reason that a retry cannot
 *    change (422 `PAYPAY_NOT_AVAILABLE`: branch not configured, order already
 *    settled, amount mismatch; 404: no such order). No retry button.
 *  - `rate_limited` — 429 from the `customer-paypay-qr` limiter (10/min). Say
 *    so; the retry stays, because waiting IS the fix.
 *  - `retryable` — 5xx and network failures. Today's generic screen.
 */
export type PayPayCreateFailure = "not_available" | "rate_limited" | "retryable";

export function classifyPayPayCreateFailure(
  status: number | null | undefined,
): PayPayCreateFailure {
  if (status === 429) return "rate_limited";
  if (typeof status === "number" && status >= 400 && status < 500) {
    return "not_available";
  }
  return "retryable";
}

/**
 * A `payment-context` probe failed — is that an ANSWER or just noise?
 *
 * `false` (definitely not available) and "could not determine" are different
 * facts and were being conflated: one blip during a wifi→4G handoff pinned the
 * branch to "This shop does not accept PayPay QR" for the life of the page, on
 * a shop that does. A 4xx is the server answering (no such branch, no payment
 * context, older backend) and is cacheable; everything else — 5xx, DNS, offline
 * — is `null`, "ask again".
 */
export function classifyPayPayAvailabilityAnswer(
  status: number | null | undefined,
): boolean | null {
  if (typeof status === "number" && status >= 400 && status < 500) return false;
  return null;
}

/**
 * Server status → rendered phase.
 *
 * `is_fully_paid` wins over everything: the money is the fact. A code that
 * says EXPIRED on an order the cashier already settled must still show
 * "paid", never "expired".
 *
 * An unrecognised status stays `waiting` on purpose — a future PayPay state we
 * do not know about must not be reported to the customer as a failure while
 * realtime is still live. That default is only safe because every state the
 * server can actually name is mapped below: `waiting` renders no way out, so
 * anything terminal that lands there strands the customer for good.
 *
 *  - `CANCELED`/`CANCELLED`/`REVERTED` — the wallet said no (the customer
 *    tapped cancel in the PayPay app). Terminal, and reads as `failed`, which
 *    is the phase whose copy already says "the payment was cancelled".
 *  - `NOT_FOUND` — `syncStatus()` found no live attempt, i.e. the code is gone
 *    for one terminal reason or another. Reads as `expired` because the
 *    customer's move is identical: mint a new code.
 */
export function payPayQrPhase(payload: {
  status?: string | null;
  is_fully_paid?: boolean | null;
}): PayPayQrPhase {
  if (payload.is_fully_paid === true) return "paid";
  switch (payload.status) {
    case "COMPLETED":
      return "paid";
    case "FAILED":
    case "CANCELED":
    case "CANCELLED":
    case "REVERTED":
      return "failed";
    case "EXPIRED":
    case "NOT_FOUND":
      return "expired";
    default:
      return "waiting";
  }
}

/**
 * One status response → the phase it reports plus any fresh countdown anchor.
 *
 * Never throws and never reports failure for a shape it does not recognise: a
 * garbled poll must leave the screen exactly as it was, because realtime-free
 * polling is the only way out of this screen and "waiting" is the only safe
 * default.
 */
export function readPayPayQrStatus(raw: unknown): PayPayQrStatusReading {
  const data =
    typeof raw === "object" && raw !== null
      ? (raw as { data?: unknown }).data
      : null;
  const d =
    typeof data === "object" && data !== null ? (data as Record<string, unknown>) : {};

  const expiresIn = d.expires_in_seconds;
  const merchantPaymentId = d.merchant_payment_id;

  return {
    phase: payPayQrPhase({
      status: typeof d.status === "string" ? d.status : null,
      is_fully_paid: d.is_fully_paid === true,
    }),
    expiresInSeconds:
      typeof expiresIn === "number" && Number.isFinite(expiresIn)
        ? Math.max(0, Math.round(expiresIn))
        : null,
    merchantPaymentId:
      typeof merchantPaymentId === "string" && merchantPaymentId !== ""
        ? merchantPaymentId
        : null,
    isFullyPaid: d.is_fully_paid === true,
  };
}

/**
 * Is the code on screen no longer the one the server is answering about?
 *
 * Unknown on either side is NOT stale: an older backend that does not echo
 * `merchant_payment_id`, or a poll that landed while nothing is outstanding,
 * must leave the screen exactly as it was. Only two known, different ids mean
 * "you have been superseded" — and that verdict has to be sticky at the call
 * site, because the next poll saying nothing must not un-say it.
 */
export function isPayPayScreenSuperseded(input: {
  /** The `merchant_payment_id` of the QR this screen is rendering. */
  displayed: string | null | undefined;
  /** What the status read named. */
  reported: string | null | undefined;
}): boolean {
  if (!input.displayed || !input.reported) return false;
  return input.displayed !== input.reported;
}

/**
 * The phase the screen renders.
 *
 * **Expiry is the server's call, not ours.** `secondsLeft` reaching zero means
 * only "our copy of the clock ran out" — the code may still be live, and the
 * wallet may be mid-settlement. The status endpoint reports both `EXPIRED` and
 * a fresh `expires_in_seconds` on every read, so the client has no reason left
 * to declare death on its own; doing so used to *stop the fallback poll*
 * (`shouldPollPayPayStatus` is false for `expired`), which turned a one-second
 * race into a permanently stuck screen.
 *
 * `secondsLeft` is therefore accepted and deliberately ignored — the parameter
 * exists so the rule is stated where the next reader will look for it.
 */
export function resolvePayPayQrPhase(input: {
  /** Last definite answer from the status endpoint. */
  serverPhase: PayPayQrPhase;
  secondsLeft: number;
}): PayPayQrPhase {
  return input.serverPhase;
}

/**
 * The countdown has run out locally but the server has not yet confirmed the
 * code is dead. The screen shows "checking" rather than a stale `0:00`, and —
 * critically — keeps polling.
 */
export function isAwaitingExpiryConfirmation(input: {
  serverPhase: PayPayQrPhase;
  secondsLeft: number;
}): boolean {
  return input.serverPhase === "waiting" && input.secondsLeft <= 0;
}

/**
 * Fold a status read's `expires_in_seconds` back into the countdown anchor.
 *
 * Re-anchoring on every read keeps a screen that sat backgrounded for minutes
 * (throttled timers, a suspended tab) honest, and it is the mechanism that
 * makes the server the authority: whatever it last said outranks whatever the
 * local interval accumulated. A read that says nothing leaves the anchor alone.
 */
export interface PayPayCountdownAnchor {
  expiresInSeconds: number;
  /** Client `Date.now()` when this value was received. */
  anchoredAtMs: number;
}

export function nextCountdownAnchor(input: {
  current: PayPayCountdownAnchor;
  serverExpiresInSeconds: number | null | undefined;
  receivedAtMs: number;
}): PayPayCountdownAnchor {
  const fresh = input.serverExpiresInSeconds;
  if (typeof fresh !== "number" || !Number.isFinite(fresh)) return input.current;
  return {
    expiresInSeconds: Math.max(0, Math.round(fresh)),
    anchoredAtMs: input.receivedAtMs,
  };
}

/** Terminal for the customer — nothing left to wait for on this screen. */
export function isPayPayQrTerminal(phase: PayPayQrPhase): boolean {
  return phase === "paid";
}

/** Poll only while the code can still flip to paid. */
export function shouldPollPayPayStatus(phase: PayPayQrPhase): boolean {
  return phase === "waiting";
}

/**
 * A new code may be minted for anything that is not already paid — plan-054
 * D7: the QR lives ~5 minutes but the ORDER lives by
 * `takeaway_payment_timeout_minutes` (5–120), so an expired code on a live
 * order is a re-mint, not a dead end. The order deadline itself is enforced
 * server-side; the client never decides an order is over.
 */
export function canRefreshPayPayQr(phase: PayPayQrPhase): boolean {
  return phase !== "paid";
}

/**
 * godx-tempo#1737 — how long to keep asking about a code the customer walked
 * away from.
 *
 * Leaving the QR panel does NOT kill the code: `setPaypayMint(null)` on dine-in
 * and switching the method radio on takeaway both unmount this screen while the
 * code stays scannable at PayPay for the rest of its ~5 minutes. Nothing was
 * watching it after that, so a guest who scanned the abandoned code had their
 * money sit at PayPay until `payments:sweep-paypay-qr` noticed — a scheduled
 * job that runs every fifteen minutes.
 *
 * The status endpoint is not a read: `PayPayPaymentService::syncStatus()` asks
 * PayPay and BOOKS the money if it has moved. So simply continuing to call it
 * is the whole fix — the money lands in the ledger on the next tick instead of
 * up to a quarter of an hour later.
 *
 * Six minutes because the code lives ~5 (`CODE_LIFETIME_MINUTES`) and the last
 * scan can land in its final second; past that PayPay itself refuses the code,
 * and the sweeper remains the backstop for anything this window missed.
 */
export const PAYPAY_ORPHAN_WATCH_MS = 6 * 60_000;

/**
 * Keep watching a code nobody is looking at any more?
 *
 * Stops on any definite answer — `paid` means the money is booked, `expired` /
 * `failed` mean there is nothing left to book — and otherwise on the window
 * above. Deliberately NOT gated on the local countdown: only the server expires
 * a code (invariant 3), and a wallet that settled a second after our clock ran
 * out is exactly the payment this watch exists to catch.
 */
export function shouldWatchOrphanedPayPayQr(input: {
  phase: PayPayQrPhase;
  elapsedMs: number;
}): boolean {
  if (input.phase !== "waiting") return false;
  return input.elapsedMs < PAYPAY_ORPHAN_WATCH_MS;
}

/**
 * Consecutive failed status reads before the screen admits it has lost contact.
 *
 * Below this the poll just retries quietly — a single dropped request on a
 * phone walking out of wifi is not news. Above it the customer is staring at a
 * QR whose fate nobody is checking, so they are told, and handed the refresh
 * CTA. Three is roughly half a minute at the backed-off cadence.
 */
export const PAYPAY_STATUS_FAILURE_LIMIT = 3;

export function hasLostPayPayStatusContact(consecutiveFailures: number): boolean {
  return consecutiveFailures >= PAYPAY_STATUS_FAILURE_LIMIT;
}

/**
 * Should the "get a new QR code" button be on screen right now?
 *
 * This exists because the button used to hang off `expired || failed` alone,
 * and every other way the screen can stop making progress landed in `waiting` —
 * which renders no exit at all. Every dead end must reach the CTA:
 *
 *  - the server called it: `expired` / `failed` (now including cancelled and
 *    NOT_FOUND, see `payPayQrPhase`);
 *  - the poll cannot reach the server (`lostContact`);
 *  - this screen was superseded by a newer code (`superseded`);
 *  - the local countdown lapsed and the server has not confirmed either way
 *    (`awaitingExpiryConfirmation`) — the code is past its own expiry, so
 *    re-minting costs nothing, and this is the one dead end a *succeeding*
 *    poll can produce: a status we do not recognise, forever.
 *
 * `paid` is the single state with nothing to offer — the money already moved.
 */
export function shouldOfferPayPayRefresh(input: {
  phase: PayPayQrPhase;
  awaitingExpiryConfirmation: boolean;
  lostContact: boolean;
  superseded: boolean;
}): boolean {
  if (!canRefreshPayPayQr(input.phase)) return false;
  if (input.phase !== "waiting") return true;
  return input.awaitingExpiryConfirmation || input.lostContact || input.superseded;
}

/**
 * `messages.paypay.*` key for the instruction line above the QR.
 *
 * Kept as keys rather than strings so this stays a pure decision the tests can
 * pin — the panel is the only thing that owns `useTranslations`.
 */
export type PayPayQrHeadlineKey =
  | "scanHint"
  | "expiryUnconfirmed"
  | "expired"
  | "cancelled"
  | "superseded";

/** Pill stamped across a QR that is no longer presented as scannable. */
export type PayPayQrBadgeKey =
  | "expiryUnconfirmedBadge"
  | "expired"
  | "cancelled"
  | "superseded";

/** Label beside the still-polling spinner. `null` = no spinner row at all. */
export type PayPayQrWaitingKey = "waitingForPayment" | "stillChecking";

/** How loudly the "get a new QR code" button is rendered. */
export type PayPayRefreshProminence = "none" | "secondary" | "primary";

export interface PayPayQrPresentation {
  headlineKey: PayPayQrHeadlineKey;
  /** Dim + blur the QR so it stops reading as scannable. */
  dimQr: boolean;
  /** `null` while the QR is presented as live. */
  badgeKey: PayPayQrBadgeKey | null;
  /** The red "open the PayPay app" deep link — a PRIMARY action on a live code. */
  showDeepLink: boolean;
  showCountdown: boolean;
  waitingKey: PayPayQrWaitingKey | null;
  /** "Keep this screen open" — only true while scanning is the thing to do. */
  showKeepOpenHint: boolean;
  refreshCta: PayPayRefreshProminence;
}

/**
 * Everything the panel renders about the CODE, in one place.
 *
 * This exists because of a browser walkthrough: when the countdown lapsed the
 * screen still looked completely alive — full-opacity QR, "scan this to pay",
 * a red deep-link CTA aimed at a dead code, "waiting for payment", and not one
 * word about expiry. The only way out was a low-contrast button at the bottom.
 * A customer could not tell a dead screen from a working one and would keep
 * scanning a code PayPay refuses.
 *
 * **This is presentation ONLY.** It decides nothing about state: the phase, the
 * poll and the paid handler are untouched, which is what preserves the panel's
 * invariant that *the client never declares a lapse*. `awaitingExpiryConfirmation`
 * is by construction `serverPhase === "waiting" && secondsLeft <= 0`, so the
 * moment the server says anything — `paid`, `expired`, `failed` — it is false
 * again and the server's answer renders instead. The sandbox reporting `CREATED`
 * past the real expiry is exactly the window this dresses honestly.
 *
 * Three visual states:
 *
 *  - **live** — the QR is scannable: scan copy, deep link, countdown, and the
 *    refresh button only as a quiet secondary (it is an escape hatch from a
 *    dead poll, not the thing to do).
 *  - **lapsing** — our clock ran out, the server has not confirmed. The QR is
 *    dimmed exactly like `superseded` (one visual language for "do not scan
 *    this", not a second one invented here), the copy says *probably* expired,
 *    the deep link goes away because it points at that same code, and refresh
 *    becomes the primary action. The spinner STAYS — under different copy —
 *    because a wallet may still be settling and this screen is still watching.
 *  - **dead** — the server called it, or a newer code superseded this one.
 *
 * `phase === "paid"` never reaches here (the panel returns the paid screen
 * before rendering a QR at all); it would fall through to `live`, which renders
 * nothing untrue anyway.
 */
export function payPayQrPresentation(input: {
  /** Resolved phase — i.e. the SERVER's, see `resolvePayPayQrPhase`. */
  phase: PayPayQrPhase;
  superseded: boolean;
  awaitingExpiryConfirmation: boolean;
  /** False when PayPay issued no `expiryDate`: a payable code with no timer. */
  hasCountdown: boolean;
  lostContact: boolean;
  /** False when the create response carried no deeplink (scan-only). */
  hasDeepLink: boolean;
}): PayPayQrPresentation {
  const offerRefresh = shouldOfferPayPayRefresh({
    phase: input.phase,
    awaitingExpiryConfirmation: input.awaitingExpiryConfirmation,
    lostContact: input.lostContact,
    superseded: input.superseded,
  });

  // `superseded` outranks the phase: a newer code exists, so whatever the
  // status endpoint said is about that one, not the QR on this screen.
  const dead =
    input.superseded || input.phase === "expired" || input.phase === "failed";

  if (dead) {
    const key: PayPayQrHeadlineKey & PayPayQrBadgeKey = input.superseded
      ? "superseded"
      : input.phase === "failed"
        ? "cancelled"
        : "expired";
    return {
      headlineKey: key,
      dimQr: true,
      badgeKey: key,
      showDeepLink: false,
      showCountdown: false,
      waitingKey: null,
      showKeepOpenHint: false,
      refreshCta: offerRefresh ? "primary" : "none",
    };
  }

  if (input.awaitingExpiryConfirmation) {
    return {
      headlineKey: "expiryUnconfirmed",
      dimQr: true,
      badgeKey: "expiryUnconfirmedBadge",
      showDeepLink: false,
      showCountdown: false,
      // Still polling, so still say so — just without claiming the code works.
      waitingKey: "stillChecking",
      showKeepOpenHint: false,
      // `shouldOfferPayPayRefresh` is true for this state by construction; the
      // conditional is here so the two can never disagree.
      refreshCta: offerRefresh ? "primary" : "none",
    };
  }

  return {
    headlineKey: "scanHint",
    dimQr: false,
    badgeKey: null,
    showDeepLink: input.hasDeepLink,
    showCountdown: input.hasCountdown,
    waitingKey: "waitingForPayment",
    showKeepOpenHint: true,
    // Secondary on purpose: the QR here is still the thing to scan, and the
    // only reason the button is on screen is a poll that cannot reach the
    // server (`lostContact`).
    refreshCta: offerRefresh ? "secondary" : "none",
  };
}

/**
 * Should the checkout PayPay radio promise a QR screen?
 *
 * Only where the QR flow will actually run. `qr_pay` on a branch without PayPay
 * QR — every branch today — still means "staff settle it at the till" and must
 * keep behaving (and reading) exactly as it does now, invariant 1; so must the
 * dine-in case, which `shouldUsePayPayQrFlow` also excludes. Sub-copy describing
 * a screen those customers will never see is worse than no sub-copy at all.
 *
 * Signed-in customers are no longer among them (#1692) — deliberately still the
 * SAME predicate as the route, so the radio cannot promise a screen this customer
 * will not be sent to, nor stay silent about one they will.
 */
export function shouldShowPayPayCheckoutHint(input: {
  paypayEnabled: boolean;
  orderType: string;
}): boolean {
  return shouldUsePayPayQrFlow({
    paypayEnabled: input.paypayEnabled,
    paymentMethod: PAYPAY_RADIO_VALUE,
    orderType: input.orderType,
  });
}

/** Backoff ceiling for the status poll — a long outage still keeps a heartbeat. */
export const PAYPAY_STATUS_BACKOFF_MAX_MS = 60_000;

/**
 * Floor applied after a 429. The status route shares the `customer-order-read`
 * bucket (120/min, keyed on the ORDER) with `useOrderSettlement`'s 5s poll, and
 * settlement is the poller that actually closes the order — so when the bucket
 * tips, this one must be the one that yields. `apiFetch` does not surface
 * `Retry-After`, so this stands in for it, exactly as `use-order-settlement`
 * does.
 */
export const PAYPAY_STATUS_RATE_LIMITED_FLOOR_MS = 30_000;

/**
 * How long until the next status read.
 *
 * Same shape as plan-050's `nextPollStep` (exponential backoff, ±20% jitter,
 * `Retry-After` as a floor) rather than a second implementation of the idea:
 * a fixed `setInterval` means seven phones on one split bill sit phase-locked
 * at 119 requests/min against a 120/min bucket, and the `catch {}` that
 * swallowed 429s meant tipping it changed nothing about the cadence.
 *
 * `jitter` is a caller-supplied [0,1) — the randomness stays outside so this
 * stays a pure function the tests can pin.
 */
export function nextPayPayPollDelayMs(input: {
  consecutiveFailures: number;
  retryAfterMs?: number | null;
  jitter: number;
}): number {
  const base =
    input.consecutiveFailures > 0
      ? Math.min(
          PAYPAY_STATUS_POLL_INTERVAL_MS * 2 ** (input.consecutiveFailures - 1),
          PAYPAY_STATUS_BACKOFF_MAX_MS,
        )
      : PAYPAY_STATUS_POLL_INTERVAL_MS;

  const jitter = Number.isFinite(input.jitter) ? Math.min(Math.max(input.jitter, 0), 1) : 0.5;
  const jittered = Math.round(base * (0.8 + 0.4 * jitter));

  return input.retryAfterMs != null ? Math.max(jittered, input.retryAfterMs) : jittered;
}

/**
 * Remaining seconds on the CODE.
 *
 * Delegates to plan-031's `computeSecondsLeft` with `expires_in_seconds` as
 * the server delta and NO `paymentDueAt` fallback — passing `expires_at` here
 * would reintroduce exactly the absolute-clock subtraction invariant 3 bans.
 */
export function payPayQrSecondsLeft(input: {
  expiresInSeconds: number | null | undefined;
  /** `Date.now()` at the moment the create response was received. */
  anchoredAtMs: number;
  nowMs: number;
}): number {
  return computeSecondsLeft({
    secondsUntilDue: input.expiresInSeconds,
    anchoredAtMs: input.anchoredAtMs,
    nowMs: input.nowMs,
  });
}

/** `m:ss`, or `h:mm:ss` past an hour. Clamped at zero, never negative. */
export function formatPayPayCountdown(totalSeconds: number): string {
  const s = Math.max(0, Math.floor(Number.isFinite(totalSeconds) ? totalSeconds : 0));
  const hours = Math.floor(s / 3600);
  const minutes = Math.floor((s % 3600) / 60);
  const seconds = s % 60;
  const pad = (n: number) => String(n).padStart(2, "0");
  return hours > 0
    ? `${hours}:${pad(minutes)}:${pad(seconds)}`
    : `${minutes}:${pad(seconds)}`;
}

// ─── #1303: the dine-in "pay online" card has two levels ────────────────────

/** Which gateway the dine-in guest pays online with. */
export type DineInOnlineGateway = "paypay" | "stripe";

/** Everything the dine-in online card renders, decided in one place. */
export interface DineInOnlineSurface {
  gateway: DineInOnlineGateway;
  /** The PayPay | card tab bar. */
  showTabs: boolean;
  /** Placeholder while the branch capability is still unknown. */
  showProbeSpinner: boolean;
  showCard: boolean;
  /**
   * The PayPay tab BEFORE a code exists. Without it that tab renders a tab bar,
   * a button, and nothing between them — the code is minted on commit, not on
   * tab select, so there is genuinely no QR yet to show.
   */
  showPayPayIntro: boolean;
  showQrPanel: boolean;
  showConfirmButton: boolean;
}

/**
 * Resolve the gateway, treating "the guest has not picked" as its own state.
 *
 * PayPay is the first tab and therefore the default WHERE IT EXISTS — but the
 * answer to "does it exist" arrives a few hundred ms after mount, so the default
 * cannot be written into state on arrival. Doing that would mount the Stripe card
 * form and then tear it down; on a slow connection, where the probe lands after
 * the guest started typing, it would tear down a card form with their number in
 * it. Deriving it here instead means nothing is ever written behind the guest's
 * back, and a tap pins the choice for good.
 */
export function resolveDineInOnlineGateway(input: {
  picked: DineInOnlineGateway | null;
  paypayEnabled: boolean;
}): DineInOnlineGateway {
  return input.picked ?? (input.paypayEnabled ? "paypay" : "stripe");
}

/**
 * The whole online card as one decision, so its parts cannot contradict.
 *
 * Five separate render conditions used to read `method === "online"` directly,
 * and that expression meant two different things depending on which one you
 * looked at — "the guest is paying online" for the split-mode selector, "the
 * guest is on the card form" for the encryption notice, which is why the notice
 * rendered beside a PayPay QR it does not describe.
 *
 * `hasLiveCode` outranks `paypayEnabled` throughout. A minted code is itself
 * proof the branch could mint, so the panel must not be gated on a capability
 * that could answer `false` later: that would make the QR vanish mid-scan and
 * hand its space to the card form, while the code stayed collectable at PayPay
 * with nothing on screen watching it.
 */
export function dineInOnlineSurface(input: {
  payingOnline: boolean;
  picked: DineInOnlineGateway | null;
  paypayEnabled: boolean;
  paypayProbeLoading: boolean;
  hasLiveCode: boolean;
}): DineInOnlineSurface {
  const gateway = resolveDineInOnlineGateway(input);
  const onPayPayTab = input.payingOnline && gateway === "paypay";

  const showQrPanel = onPayPayTab && input.hasLiveCode;

  // The spinner is exclusive, and computed before everything else so it can be
  // subtracted from them. `paypayEnabled && paypayProbeLoading` cannot happen
  // with today's hook — a resolved answer is what ends the loading state — but
  // this must not quietly render two bodies if that ever changes.
  const showProbeSpinner =
    input.payingOnline && input.paypayProbeLoading && !showQrPanel;

  // A branch that can mint, on the PayPay tab. Distinct from `showQrPanel`: this
  // is "PayPay is what the confirm button will do", before any code exists.
  const payPayReady = onPayPayTab && input.paypayEnabled && !showProbeSpinner;
  const showCard =
    input.payingOnline && !payPayReady && !showQrPanel && !showProbeSpinner;

  return {
    gateway,
    // Survives while a code is live even if the capability stops saying yes —
    // otherwise the guest loses the way back from the QR.
    showTabs: input.payingOnline && (input.paypayEnabled || showQrPanel),
    showProbeSpinner,
    showCard,
    showPayPayIntro: payPayReady && !input.hasLiveCode,
    showQrPanel,
    // Gone once a code exists: the panel owns the screen from there, and a second
    // press would void the QR the guest is looking at.
    showConfirmButton: showCard || (payPayReady && !input.hasLiveCode),
  };
}
