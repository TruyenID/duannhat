/**
 * Void Reason Service — plan-051 (#1149).
 *
 *   GET /api/v1/pos/void-reasons
 *
 * Read-only list of the brand's ACTIVE void reasons (localized label via
 * Accept-Language, sorted by sort_order). Rendered by the void-item dialog
 * as a reason picker. The master is edited at HQ (admin-web); pos-web only
 * picks.
 *
 * Availability note: this is a Cloud shops-domain endpoint. When pos-web is
 * running against a workstation LAN that has no mirror for it, the request
 * fails — callers treat "unreachable" the same as "empty list" and fall back
 * to the legacy free-text reason input.
 */

import { apiFetch } from "@/lib/api";

export type VoidStockEffect = "waste" | "restock" | "none";

export interface VoidReason {
  id: string;
  /** Localized label (Accept-Language resolved server-side). */
  label: string;
  /** What happens to already-deducted stock when this reason drives a void. */
  stock_effect: VoidStockEffect;
  /** When true the operator must add a free-text note on top of the reason. */
  requires_note: boolean;
  sort_order: number;
}

export const voidReasonService = {
  // plan-051 — the /pos path exists on BOTH the workstation LAN mirror and
  // Cloud, so the base-url resolver gives LAN-first with Cloud fallback
  // (X-Shop-Slug is stamped by apiFetch). The shops-domain path would skip
  // the LAN mirror entirely.
  list: (_shopSlug: string) =>
    apiFetch<{ data: VoidReason[] }>(`/api/v1/pos/void-reasons`),
};
