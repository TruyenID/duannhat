/**
 * debt-service — "Tra cứu nợ" (open on-account balances).
 *
 * POS namespace, NOT `/shops/{slug}/debts`. That route sits behind Platform SSO
 * and needs an `x-principal-id` the gateway injects after a user SSO login;
 * pos-web authenticates with a device token and can never produce one, so it
 * answered `401 missing principal`. The shop comes from the `X-Shop-Slug`
 * header apiFetch already stamps.
 */

import { apiFetch } from "@/lib/api";

/** One debtor, aggregated across all their open debts. */
export interface DebtCustomerRow {
  customer_id: string;
  customer_name: string | null;
  /** POS attaches customers by phone, so this is usually the only thing that
   *  identifies a debtor — name and tax_code are typically empty. */
  customer_phone: string | null;
  customer_tax_code: string | null;
  open_debt_count: number;
  open_debt_total: string;
  oldest_debt_at: string;
  latest_debt_at: string;
}

/** One individual open debt — the row a settlement actually references. */
export interface DebtDetailRow {
  /** What `metadata.settles_payment_id` must be set to. */
  payment_id: string;
  /** The order the debt was recorded on. Closed — see the dialog's comment on
   *  why a settlement cannot be posted back to it. */
  order_id: string;
  order_code: string | null;
  /** The ORIGINAL on-account amount. A settlement must equal this exactly. */
  amount: string;
  /** The same debt after any refunds — what the customer still owes. */
  net_amount: string;
  /**
   * False when refunds moved `net_amount` away from `amount`. Such a debt
   * cannot be settled through the payment path at all: paying the net trips
   * the backend's `settles_amount_mismatch` guard, and paying the original
   * over-collects. Surfaced per row so the cashier learns it from the screen
   * rather than from a 422 with the customer standing there.
   */
  is_settleable: boolean;
  created_at: string;
  note: string | null;
}

/** One order left part-paid. Not a debt — nothing was charged to the account. */
export interface PartPaidOrder {
  order_id: string;
  order_code: string | null;
  total_amount: string;
  paid_amount: string;
  unpaid_amount: string;
  opened_at: string;
}

/**
 * A customer with orders nobody finished paying.
 *
 * Kept apart from {@link DebtCustomerRow} because the two are different
 * obligations, and merging them into one figure would make it impossible to
 * tell "we extended credit on purpose" from "this walked out". The server keeps
 * them apart for the same reason: the grouped /debts total is what admin's
 * "Công nợ khách hàng" panel sums, and it is deliberately unchanged.
 */
export interface PartPaidCustomerRow {
  customer_id: string;
  customer_name: string | null;
  customer_phone: string | null;
  customer_tax_code: string | null;
  order_count: number;
  total_unpaid: string;
  oldest_at: string;
  latest_at: string;
  orders: PartPaidOrder[];
}

export const debtService = {
  /** Debtors, grouped. `limit` is capped at 200 server-side. */
  list: (limit = 100) =>
    apiFetch<{ data: DebtCustomerRow[]; next_cursor: string | null }>(
      `/api/v1/pos/debts?limit=${limit}`,
    ),

  /** One debtor's individual open debts. */
  listForCustomer: (customerId: string) =>
    apiFetch<{ data: DebtDetailRow[] }>(
      `/api/v1/pos/debts/${encodeURIComponent(customerId)}`,
    ),

  /**
   * Orders left part-paid, grouped by customer, each with its order breakdown.
   *
   * No time filter, by design: an order being served right now is also `paying`
   * with paid < total, so any cutoff would be a guess about when a customer has
   * "left". Every row carries its order code and opened_at instead — a cashier
   * reading the list can tell a table they are serving from one that walked
   * out, and a rule on the server could not.
   */
  listPartPaid: () =>
    apiFetch<{ data: PartPaidCustomerRow[] }>("/api/v1/pos/debts/part-paid"),
};
