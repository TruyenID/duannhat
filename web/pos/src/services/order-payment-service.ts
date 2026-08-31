/**
 * Order Payment Service — cash-only happy path for plan-007.
 *
 *   GET  /api/v1/pos/orders/{id}/payments
 *   POST /api/v1/pos/orders/{id}/payments
 *
 * Cash auto-confirm + auto-close is handled server-side when the payment
 * method's is_auto_confirm + the resulting paid_amount covers total_amount.
 * POS just POSTs and refetches the order.
 */

import { apiFetch } from "@/lib/api";
import type { OrderPayment } from "@/app/pos/types";

export interface OrderPaymentCreateInput {
  payment_method_id: string;
  gateway_option_id?: string;
  gateway_connection_id?: string | null;
  policy_revision?: number;
  amount: number;
  tip_amount?: number;
  tendered_amount?: number;
  reference_no?: string;
  note?: string;
  /**
   * Idempotency key — when present, the backend looks up any existing
   * payment for this order with the same key and returns it instead of
   * creating a duplicate. Lets the client retry safely after a network
   * glitch without leaving orphan pending payments behind.
   */
  idempotency_key?: string;
  /**
   * #1156 — brand/scheme attribution (`till_tender_types.tender_key`, e.g.
   * "paypay" / "credit" / "id") for payments taken on a physical multi-brand
   * terminal. Optional + validated server-side against the org's active
   * vocabulary; never changes any amount. Feeds per-brand 精算 expected.
   */
  tender_key?: string;
  /**
   * Total amount the client computed its row amount against. Backend
   * compares to current `order.total_amount` and rejects with code
   * `split_bill_total_drift` (422) when they no longer match — typically
   * means staff voided an item or applied a discount after the split-bill
   * snapshot was taken, so the snapshot is stale and would otherwise
   * silently cause an "exceeds outstanding balance" failure on the last
   * row.
   */
  expected_total_amount?: number;
  /**
   * Plan-021 — split-bill audit trail. Equal mode sends
   *   {split_mode: 'even', bill_index, total_bills}
   * By-items mode sends
   *   {split_mode: 'by_items', bill_index, total_bills, label, item_allocations}
   * Backend persists the JSON as-is and the receipt/audit flow re-projects
   * it on read. Single (non-split) payments leave the field undefined.
   */
  metadata?: SplitBillPaymentMetadata;
}

/** Plan-021 — shape persisted onto `order_payments.metadata`. Plan-038
 * extends with `"by_amount"` (free-form per-person amount, sum-to-total
 * validated client-side) and `settles_payment_id` (debt-settlement link). */
export type SplitBillPaymentMetadata =
  | {
      split_mode: "even";
      bill_index: number;
      total_bills: number;
    }
  | {
      split_mode: "by_items";
      bill_index: number;
      total_bills: number;
      label: string;
      item_allocations: Array<{ item_id: string; units: number }>;
    }
  | {
      split_mode: "by_amount";
      bill_index: number;
      total_bills: number;
      label: string;
      /** Per-person amount; sum across rows must equal order.total. */
      amount: number;
    }
  | {
      /** Plan-038 T10.x — debt settlement linkage. */
      settles_payment_id: string;
    };

export interface OrderPaymentRefundInput {
  /** Stable across an explicit retry; dedupes the refund on LAN and Cloud. */
  idempotency_key: string;
  /** Optional partial refund amount — defaults to full payment amount. */
  amount?: number;
  note?: string;
}

function paymentsUrl(_shopSlug: string, orderId: string, path = ""): string {
  // shopSlug is now resolved server-side from the X-Shop-Slug header
  // (set by setCurrentShopSlug); kept as a param so callers don't churn
  // and so it stays in TanStack Query cache keys.
  return `/api/v1/pos/orders/${orderId}/payments${path}`;
}

export const orderPaymentService = {
  list: (shopSlug: string, orderId: string) =>
    apiFetch<{ data: OrderPayment[] }>(paymentsUrl(shopSlug, orderId)),

  create: (shopSlug: string, orderId: string, body: OrderPaymentCreateInput) =>
    apiFetch<{ data: OrderPayment }>(paymentsUrl(shopSlug, orderId), {
      method: "POST",
      body: JSON.stringify(body),
    }),

  refund: (
    shopSlug: string,
    orderId: string,
    paymentId: string,
    body: OrderPaymentRefundInput,
  ) =>
    apiFetch<{ data: OrderPayment }>(paymentsUrl(shopSlug, orderId, `/${paymentId}/refund`), {
      method: "POST",
      headers: { "Idempotency-Key": body.idempotency_key },
      body: JSON.stringify(body),
    }),
};
