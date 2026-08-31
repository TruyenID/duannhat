/**
 * Plan-039 — Split-bill card visibility predicate (pure, dependency-free).
 *
 * The "Chia hóa đơn?" card on /order-success lets a customer pre-declare a
 * share-bill mode so the kiosk can skip its chooser. Share-bill is a
 * **dine-in** concept: the kiosk /split-options flow only exists for dine-in
 * table orders. Takeaway counter-pay orders have no table / no per-seat split
 * — offering (and persisting) a `split_mode` for them is meaningless and
 * pollutes `customer_orders.split_mode` on the wrong set of rows.
 *
 * Extracted here (out of the "use client" page component) so the gating logic
 * can be unit-tested in isolation with the repo's node:test runner.
 */

export type SplitBillOrderLike =
  | {
      /** CustomerOrderResource.order_type — 'spot' | 'dine_in' | 'takeaway'. */
      order_type?: string | null;
      /** CustomerOrderResource.is_counter_pay — true when no Stripe intent. */
      is_counter_pay?: boolean | null;
    }
  | null
  | undefined;

export interface SplitBillVisibilityArgs {
  order: SplitBillOrderLike;
  orderId: string | null;
  /** Order is fully paid (Stripe confirmed or kiosk-closed) — success view. */
  isFullyPaidUI: boolean;
  /** Countdown elapsed — QR + card must disappear. */
  isExpired: boolean;
}

/**
 * True only when the split-bill card should render:
 *   - order not yet fully paid,
 *   - we have an order id,
 *   - the payment window hasn't expired,
 *   - the order is counter-pay (kiosk QR is being shown), AND
 *   - the order is **dine-in** (share-bill is dine-in only — takeaway
 *     counter-pay orders must never offer it).
 */
export function shouldShowSplitBillCard({
  order,
  orderId,
  isFullyPaidUI,
  isExpired,
}: SplitBillVisibilityArgs): boolean {
  if (isFullyPaidUI) return false;
  if (!orderId) return false;
  if (isExpired) return false;
  if (order?.is_counter_pay !== true) return false;
  // plan-039 finding: dine-in guard — takeaway counter orders are excluded.
  if (order?.order_type !== "dine_in") return false;
  return true;
}
