/**
 * Shop Service — read-only detail for the cashier POS.
 *
 * Only the detail endpoint is needed here (the POS operates on a single,
 * env-configured shop slug). Mirrors the read half of
 * godx-tempo-frontend/src/services/shop-service.ts.
 *
 *   GET /api/v1/pos/shop  → { data: ShopDetail }
 */

import { apiFetch } from "@/lib/api";

export interface ShopDetail {
  id: string;
  slug: string;
  name: string;
  code: string | null;
  is_headquarters: boolean;
}

export const shopService = {
  // shopSlug param retained for caller compatibility + TanStack Query cache
  // keys; the request resolves the shop server-side via X-Shop-Slug header.
  getBySlug: (_shopSlug: string) =>
    apiFetch<{ data: ShopDetail }>(`/api/v1/pos/shop`),
};
