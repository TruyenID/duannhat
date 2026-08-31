/**
 * Print job service — plan-052 M2 / T2.2 (#1166). Pure TypeScript, no React.
 *
 * Three calls, and one of them is deliberately NOT a retry: `resolve` records
 * a human's decision about a job the pipeline could not settle. Reprinting a
 * money document is a different action behind a different gate
 * (`POST /api/v1/pos/print-jobs/reprint-authorization`), so it earns 「Bản in #N」
 * and an actor — see printing.md §8 / §10.3.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type {
  PrintJob,
  PrintJobConfidenceLabel,
  PrintJobDetail,
  PrintJobKind,
  PrintJobListMeta,
  PrintJobResolutionKind,
  PrintJobStatus,
  PrintTransport,
} from "@/types/models/PrintJob";

export interface PrintJobFilters {
  page?: number;
  per_page?: number;
  /** Multi-select. Sent as a comma list — the backend accepts either form. */
  status?: PrintJobStatus[];
  kind?: PrintJobKind[];
  transport?: PrintTransport;
  confidence?: "sent_only" | "confirmed";
  printer_id?: string;
  money_documents_only?: boolean;
  unresolved_only?: boolean;
  /**
   * BRANCH-LOCAL dates (YYYY-MM-DD), not the viewer's. The backend resolves
   * them against the branch timezone and applies them to the job's REAL event
   * time, so an offline evening in Tokyo does not slide onto the next morning
   * (#1091). The UI just forwards the two strings.
   */
  from?: string;
  to?: string;
}

export type PrintJobListResponse = PaginatedResponse<PrintJob> & {
  meta: PaginatedResponse<PrintJob>["meta"] & PrintJobListMeta;
};

export interface ResolvePrintJobInput {
  resolution: PrintJobResolutionKind;
  /** REQUIRED by the API — a resolution without a why is a checkbox. */
  reason: string;
}

function shopUrl(shopSlug: string, path = ""): string {
  return `/api/v1/shops/${shopSlug}/print-jobs${path}`;
}

function toQuery(filters: PrintJobFilters): string {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(filters)) {
    if (value === undefined || value === null || value === "") continue;
    if (Array.isArray(value)) {
      if (value.length === 0) continue;
      params.set(key, value.join(","));
      continue;
    }
    if (typeof value === "boolean") {
      if (!value) continue;
      params.set(key, "1");
      continue;
    }
    params.set(key, String(value));
  }

  return params.toString();
}

export const printJobService = {
  list: (shopSlug: string, filters: PrintJobFilters = {}) => {
    const qs = toQuery(filters);
    return apiFetch<PrintJobListResponse>(`${shopUrl(shopSlug)}${qs ? `?${qs}` : ""}`);
  },

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: PrintJobDetail }>(shopUrl(shopSlug, `/${id}`)),

  /**
   * Manager-only. Idempotent server-side: a second call returns the FIRST
   * decision rather than rewriting who decided and why.
   */
  resolve: (shopSlug: string, id: string, input: ResolvePrintJobInput) =>
    apiFetch<{ data: PrintJobDetail }>(shopUrl(shopSlug, `/${id}/resolve`), {
      method: "POST",
      body: JSON.stringify(input),
    }),
};

export type { PrintJobConfidenceLabel };
