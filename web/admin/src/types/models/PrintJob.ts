/**
 * Print job ledger — plan-052 M2 / T2.2 (#1166).
 *
 * The omnify TS target emits this model now (the schema move), so ./base/PrintJob
 * exists and the generated barrel expects the usual model exports from here —
 * the bridge block at the bottom provides them. The interface below stays
 * hand-written on purpose: the ledger is a read-mostly operational
 * surface whose shape is defined by `PrintJobResource` /
 * `PrintJobDetailResource` on the backend, not by a schema domain.
 *
 * The one field that carries real weight here is `confidence`. A cheap ESC/POS
 * machine on a raw TCP socket can only ever report `sent_only` — "the bytes
 * left this process, and the machine cannot tell us more". A CloudPRNT /
 * ePOS / ASB-capable machine reports `confirmed`. Rendering the two the same
 * way teaches an operator to trust a fact nobody measured, so the API ships a
 * pre-computed `confidence_label` and this UI keeps them visually distinct
 * everywhere (printing.md §5, EDGE-CASES P-33).
 */

/** IPP/RFC 8011 vocabulary plus the one addition `needs_attention` (ACK-lost). */
export type PrintJobStatus =
  | "queued"
  | "delivering"
  | "printed"
  | "failed"
  | "needs_attention"
  | "expired";

export type PrintJobKind =
  | "receipt"
  | "kitchen"
  | "bar"
  | "red_invoice"
  | "debt_slip"
  | "report"
  | "label"
  | "diagnostic";

export type PrintTransport = "ws_lan" | "epos_http" | "webprnt" | "cloudprnt";

export type PrintConfidence = "sent_only" | "confirmed";

/**
 * The API's own phrase for the row. For a non-printed job it is simply the
 * status; for a printed one it splits into `printed_confirmed` vs
 * `printed_sent_only`. Never re-derive this client-side — the split is the
 * whole point.
 */
export type PrintJobConfidenceLabel =
  | "queued"
  | "delivering"
  | "failed"
  | "needs_attention"
  | "expired"
  | "printed_confirmed"
  | "printed_sent_only";

/** Exactly two honest answers, and neither of them is "try again". */
export type PrintJobResolutionKind = "printed_by_hand" | "discarded";

/** Whichever tier owns the queue is the only tier that may act on the job. */
export type PrintJobQueueOwner = "workstation" | "cloud";

export interface PrintJobResolution {
  resolution: PrintJobResolutionKind | null;
  reason: string | null;
  resolved_by_id: string | null;
  resolved_at: string | null;
}

/** One row of the list. */
export interface PrintJob {
  id: string;
  branch_id: string;
  printer_id: string | null;
  printer_name?: string | null;
  transport: PrintTransport | null;
  kind: PrintJobKind | null;
  is_money_document: boolean;
  status: PrintJobStatus | null;
  is_terminal: boolean;
  confidence: PrintConfidence | null;
  confidence_label: PrintJobConfidenceLabel | null;
  order_id: string | null;
  payment_id: string | null;
  reprint_no: number | null;
  reprint_reason: string | null;
  requested_via: string | null;
  requested_by_id: string | null;
  attempts: number | null;
  last_error: string | null;
  /** The job's REAL event time (printed_reported_at ?? created_at) — never the sync time. */
  event_at: string | null;
  printed_reported_at: string | null;
  acked_at: string | null;
  expires_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  resolution?: PrintJobResolution | null;
}

export interface PrintJobDeliveryInfo {
  attempts: number | null;
  max_attempts: number;
  /** Right now, for THIS job. Money documents are false from every state. */
  auto_retry_allowed: boolean;
  /** The registry's answer for the KIND, regardless of the job's state. */
  auto_retry_allowed_for_kind: boolean;
  ttl_seconds: number;
  last_error: string | null;
  queue_owner: PrintJobQueueOwner;
}

export interface PrintJobTimelineEntry {
  event: "recorded" | "acked" | "printed_reported" | "expires" | "resolved";
  at: string | null;
}

export interface PrintJobDetail extends PrintJob {
  payload: Record<string, unknown> | null;
  delivery: PrintJobDeliveryInfo;
  timeline: PrintJobTimelineEntry[];
}

/** `meta.aging` — age is a DURATION, so the buckets read the same in Tokyo and Hanoi. */
export interface PrintJobAging {
  branch_id: string;
  generated_at: string;
  total: number;
  buckets: Record<string, number>;
  by_status: Record<string, Record<string, number>>;
  by_printer: Array<{
    printer_id: string | null;
    total: number;
    oldest_age_days: number;
    needs_attention: number;
  }>;
  needs_attention: number;
  money_document_open: number;
}

/** `meta.silent_printers` — INFERRED silence, never a probe (P-38). */
export interface SilentPrinter {
  printer_id: string;
  printer_name: string;
  transport: PrintTransport;
  detection: "journal_silence" | "poll_silence" | "ack_silence";
  last_seen_at: string | null;
  silent_for_seconds: number;
  threshold_seconds: number;
  last_status: string | null;
}

export interface PrintJobListMeta {
  statuses?: PrintJobStatus[];
  kinds?: PrintJobKind[];
  aging?: PrintJobAging;
  silent_printers?: SilentPrinter[];
}

// ============================================================================
// Bridge to the generated base
// ----------------------------------------------------------------------------
// `printjobs` became an Omnify schema, so the generated barrel
// (types/models/index.ts) re-exports the standard model surface from HERE. Without
// this block `pnpm typecheck` fails on every one of those names — the same break
// #1279 hit on PeripheralDevice. Pattern copied from Printer.ts.
// ============================================================================

import { z } from 'zod';
import type { PrintJob as PrintJobBase } from './base/PrintJob';
import {
  basePrintJobSchemas,
  basePrintJobCreateSchema,
  basePrintJobUpdateSchema,
  printJobI18n,
  getPrintJobLabel,
  getPrintJobFieldLabel,
  getPrintJobFieldPlaceholder,
} from './base/PrintJob';

export const printJobSchemas = { ...basePrintJobSchemas };
export const printJobCreateSchema = basePrintJobCreateSchema;
export const printJobUpdateSchema = basePrintJobUpdateSchema;

export type PrintJobCreate = z.infer<typeof printJobCreateSchema>;
export type PrintJobUpdate = z.infer<typeof printJobUpdateSchema>;

export {
  printJobI18n,
  getPrintJobLabel,
  getPrintJobFieldLabel,
  getPrintJobFieldPlaceholder,
};

export type { PrintJobBase };
