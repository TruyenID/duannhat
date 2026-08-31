/**
 * plan-031 — server-authoritative takeaway payment-expiry helpers.
 *
 * The countdown UI on /order-success and /orders is purely cosmetic. The
 * *decision* to hide the QR / drop the guest-order pointer from localStorage
 * must NEVER be made on the client wall clock alone: a device whose clock is
 * skewed fast would count down to 00:00 while the order is still fully payable
 * at the counter, and — because the pointer is the guest's only handle to the
 * order — permanently strand it.
 *
 * Two guards live here:
 *   1. `computeSecondsLeft` — anchors the countdown to the server's
 *      `seconds_until_due` and advances it with a CLIENT DELTA (elapsed since
 *      the value was fetched), so absolute clock offset cancels out. Only when
 *      the server never sent `seconds_until_due` do we fall back to the raw
 *      `payment_due_at - now` subtraction (legacy behaviour).
 *   2. `isServerConfirmedExpired` — the authoritative "is this order actually
 *      gone?" check, driven by fresh server fields (`status` in
 *      {`cancelled`, `expired`} or `is_payment_overdue === true`), used to gate
 *      every destructive hide/remove action.
 */

export interface ServerOrderExpiry {
  status?: string | null;
  /** BE `CustomerOrderResource.is_payment_overdue` — payment_due_at is past. */
  is_payment_overdue?: boolean | null;
  /** BE `CustomerOrderResource.seconds_until_due` — clamped remaining seconds. */
  seconds_until_due?: number | null;
  payment_due_at?: string | null;
}

/**
 * Server-authoritative expiry decision. Returns true only when FRESH server
 * data says the order is genuinely no longer payable. A skewed client clock
 * can never make this return true.
 */
export function isServerConfirmedExpired(
  order: ServerOrderExpiry | null | undefined,
): boolean {
  if (!order) return false;
  // Terminal server-set states (BE timeout/auto-expiry job already ran) are
  // authoritative — `cancelled` and `expired` both mean "no longer payable".
  // These are set by the server, never the client clock.
  if (order.status === "cancelled" || order.status === "expired") return true;
  // Explicit server overdue flag beats any client-clock guess.
  if (order.is_payment_overdue === true) return true;
  return false;
}

export interface SecondsLeftParams {
  /** Server-provided remaining seconds captured at `anchoredAtMs`. */
  secondsUntilDue?: number | null;
  /** Fallback absolute deadline (ISO 8601) when the server sent no delta. */
  paymentDueAt?: string | null;
  /** Client `Date.now()` when the server value was captured. */
  anchoredAtMs: number;
  /** Current client `Date.now()`. */
  nowMs: number;
}

/**
 * Remaining seconds for the countdown, clamped at 0.
 *
 * Prefers the server delta (`secondsUntilDue`) advanced by elapsed client
 * time — immune to absolute clock skew because it only ever subtracts a
 * client-measured *interval*. Falls back to `paymentDueAt - now` (skew-prone,
 * legacy) only when the server sent no `secondsUntilDue`.
 */
export function computeSecondsLeft(params: SecondsLeftParams): number {
  const { secondsUntilDue, paymentDueAt, anchoredAtMs, nowMs } = params;

  if (typeof secondsUntilDue === "number" && Number.isFinite(secondsUntilDue)) {
    const elapsedSec = Math.max(0, (nowMs - anchoredAtMs) / 1000);
    return Math.max(0, Math.round(secondsUntilDue - elapsedSec));
  }

  if (paymentDueAt) {
    const dueMs = Date.parse(paymentDueAt);
    if (!Number.isNaN(dueMs)) {
      return Math.max(0, Math.round((dueMs - nowMs) / 1000));
    }
  }

  return 0;
}

/**
 * plan-054 T6.10 — the single "can this order still be settled?" gate.
 *
 * `/orders/[id]` (the Pay-now CTA and the corner Pay button) and
 * `/orders/[id]/pay` (the pay screen) each carried their own copy of this list
 * with a comment telling the next reader to keep them in sync; they had
 * already drifted — the Pay-now CTA was missing `confirmed` and `checkout`, so
 * a placed-but-unpaid takeaway order showed no Pay button on the order detail
 * page yet was perfectly payable if you typed the URL. One exported constant
 * removes the way to drift again.
 *
 *  - `pending` / `open`   — prep-before-payment and prep-on-order, unpaid
 *  - `paying`             — mid-gateway retry; a failed first attempt is not a
 *                           dead end
 *  - `confirmed`          — placed-but-unpaid takeaway (plan-037 commit step)
 *  - `checkout`           — pre-payment state the backend kiosk lookup accepts
 *
 * The payment deadline is deliberately NOT part of this gate: a customer
 * returning to settle an order whose on-screen countdown lapsed must still get
 * a way in. Only a server-confirmed terminal state (see
 * `isServerConfirmedExpired`) closes the door.
 */
export const PAYABLE_ORDER_STATUSES = [
  "pending",
  "open",
  "paying",
  "confirmed",
  "checkout",
] as const;

export interface PayableOrder {
  status: string;
  is_fully_paid: boolean;
  order_type: string;
}

/**
 * Takeaway-only, per plan-031 scope: the guest-order pointer that guards both
 * screens is itself typed `"takeaway"`. Dine-in settles on its own surface.
 */
export function isOrderStillPayable(order: PayableOrder): boolean {
  return (
    (PAYABLE_ORDER_STATUSES as readonly string[]).includes(order.status) &&
    !order.is_fully_paid &&
    order.order_type === "takeaway"
  );
}
