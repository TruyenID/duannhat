/**
 * Till Tender Activation Service — #1156 per-branch tender activation.
 *
 *   GET   /api/v1/shops/{shopSlug}/till/tender-types
 *   PATCH /api/v1/shops/{shopSlug}/till/tender-types/{tenderKey}  { is_active }
 *
 * Distinct from the raw-row vocabulary CRUD at /shops/{shopSlug}/tender-types
 * (till-tender-type-service): this surface answers "which tenders does THIS
 * branch settle with?". The GET returns the EFFECTIVE list (org vocabulary
 * with this branch's overrides applied); the PATCH materializes (or updates)
 * a branch-scoped override row for the given tender_key — org-wide seeded
 * rows are never mutated, so other branches are untouched.
 */

import { apiFetch } from "@/lib/api";
import type { TillTenderType } from "@/services/till-tender-type-service";

const base = (shopSlug: string) => `/api/v1/shops/${shopSlug}/till/tender-types`;

export const tillTenderActivationService = {
  /** Effective tender list for the branch (org vocabulary + branch overrides). */
  list: (shopSlug: string) => apiFetch<{ data: TillTenderType[] }>(base(shopSlug)),

  /** Flip one tender on/off for this branch (creates the override on first flip). */
  update: (shopSlug: string, tenderKey: string, isActive: boolean) =>
    apiFetch<{ data: TillTenderType }>(`${base(shopSlug)}/${encodeURIComponent(tenderKey)}`, {
      method: "PATCH",
      body: JSON.stringify({ is_active: isActive }),
    }),
};
