/**
 * Recall Drill Service — pure TypeScript, no React dependency.
 *
 * Plan-018 TC2.4 backend lives at /api/v1/hq/{brandSlug}/recalls/drill(s).
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

export interface RecallDrill {
  id: string;
  brand_id: string;
  triggered_by_id: string;
  selected_lot_id: string;
  started_at: string;
  affected_lots_at: string | null;
  affected_orders_at: string | null;
  completed_at: string | null;
  elapsed_seconds: number | null;
  affected_lots_count: number;
  affected_orders_count: number;
  completeness_percent: number | string;
  notes: string | null;
  created_at: string;
  selected_lot?: { id: string; lot_code: string };
}

export interface RunDrillInput {
  selected_lot_id?: string | null;
}

function hqUrl(brandSlug: string, path = ""): string {
  return `/api/v1/hq/${brandSlug}/recalls${path}`;
}

export const recallDrillService = {
  list: (brandSlug: string, filters: { page?: number; per_page?: number } = {}) => {
    const params = new URLSearchParams();
    if (filters.page) params.set("page", String(filters.page));
    if (filters.per_page) params.set("per_page", String(filters.per_page));
    return apiFetch<PaginatedResponse<RecallDrill>>(
      `${hqUrl(brandSlug, "/drills")}?${params.toString()}`
    );
  },

  run: (brandSlug: string, input: RunDrillInput = {}) =>
    apiFetch<{ data: RecallDrill }>(hqUrl(brandSlug, "/drill"), {
      method: "POST",
      body: JSON.stringify(input),
    }),
};
