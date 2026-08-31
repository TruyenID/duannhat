/**
 * Customer Service — POS-side lookup helpers.
 *
 *   POST /api/v1/pos/customers/find-or-create
 *     Body: { phone }
 *     → { data: Customer, created: boolean }
 *     Used by CreateOrderDialog to resolve a typed phone into a customer_id
 *     before posting the order.
 */

import { apiFetch } from "@/lib/api";
import type { CustomerOrder } from "@/app/pos/types";

/** Narrow Customer projection — only the fields POS actually reads. */
export interface CustomerSummary {
  id: string;
  first_name: string;
  last_name: string | null;
  full_name: string;
  phone: string | null;
  email: string | null;
}

export interface FindOrCreateResponse {
  data: CustomerSummary;
  created: boolean;
}

/**
 * Response for GET /customers/{id}/outstanding. `data` is the list of
 * orders in `paying` status with paid < total, scoped to the current
 * shop branch. `total_owed` is a precomputed decimal string sum.
 */
export interface OutstandingResponse {
  data: CustomerOrder[];
  total_owed: string;
}

// shopSlug params retained for caller compatibility + TanStack Query cache
// keys; the request resolves the shop server-side via X-Shop-Slug header.
export const customerService = {
  findOrCreateByPhone: (_shopSlug: string, phone: string) =>
    apiFetch<FindOrCreateResponse>(
      `/api/v1/pos/customers/find-or-create`,
      {
        method: "POST",
        body: JSON.stringify({ phone: phone.trim() }),
      },
    ),

  getOutstanding: (_shopSlug: string, customerId: string) =>
    apiFetch<OutstandingResponse>(
      `/api/v1/pos/customers/${customerId}/outstanding`,
    ),
};
