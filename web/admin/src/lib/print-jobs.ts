/**
 * Print jobs — pure display logic (plan-052 M2 / T2.2, #1166).
 *
 * Everything here is a total function of API data: no React, no i18n
 * dictionary, no fetch. The screen imports it; the unit tests lock it. That
 * split exists because the two rules this file encodes are the ones that must
 * never regress silently:
 *
 *  1. **`printed_sent_only` and `printed_confirmed` must never render the
 *     same** (P-33). Two different tones, two different labels, always.
 *  2. **A `ws_lan` job belongs to the workstation** (DESIGN §1b). The Cloud UI
 *     may describe it, never offer to re-send it — and `resolve` is a note in
 *     the record, not a reprint.
 */

import { ApiError } from "@/lib/api";
import type {
  PrintJobConfidenceLabel,
  PrintJobKind,
  PrintJobQueueOwner,
  PrintJobStatus,
  PrintTransport,
} from "@/types/models/PrintJob";

/** Semantic colours accepted by `@godxjp/ui`'s Badge. */
export type BadgeTone = "primary" | "destructive" | "success" | "warning" | "info";

/** Filter menus and the summary strip iterate these, in operational order. */
export const PRINT_JOB_STATUSES: PrintJobStatus[] = [
  "needs_attention",
  "failed",
  "queued",
  "delivering",
  "printed",
  "expired",
];

export const PRINT_JOB_KINDS: PrintJobKind[] = [
  "receipt",
  "red_invoice",
  "debt_slip",
  "kitchen",
  "bar",
  "label",
  "report",
  "diagnostic",
];

export const PRINT_TRANSPORTS: PrintTransport[] = ["ws_lan", "cloudprnt", "epos_http", "webprnt"];

/** Mirrors `PrintJobKind::isMoneyDocument()` — the kinds that may never auto-retry. */
const MONEY_DOCUMENT_KINDS: ReadonlySet<string> = new Set(["receipt", "red_invoice", "debt_slip"]);

export function isMoneyDocumentKind(kind: PrintJobKind | string | null | undefined): boolean {
  return kind !== null && kind !== undefined && MONEY_DOCUMENT_KINDS.has(kind);
}

/**
 * Status → badge tone.
 *
 * `needs_attention` is destructive, not warning: it is the one state where a
 * human MUST act, and nothing else on the screen may compete with it for the
 * eye. `printed` stays neutral-positive here — the honesty about *how well* we
 * know it printed is carried by the confidence badge beside it, not by this one.
 */
export function printJobStatusTone(status: PrintJobStatus | null | undefined): BadgeTone {
  switch (status) {
    case "needs_attention":
      return "destructive";
    case "failed":
      return "destructive";
    case "queued":
      return "info";
    case "delivering":
      return "info";
    case "printed":
      return "success";
    case "expired":
      return "warning";
    default:
      return "primary";
  }
}

/**
 * Confidence label → badge tone.
 *
 * `printed_confirmed` is success. `printed_sent_only` is deliberately WARNING,
 * not success: the paper may well have come out, but nobody measured it, and a
 * green tick on an unmeasured fact is exactly the lie P-33 forbids. Every
 * non-printed status returns null — there is no confidence question to answer
 * about a job that has not claimed to print yet.
 */
export function confidenceTone(label: PrintJobConfidenceLabel | null | undefined): BadgeTone | null {
  if (label === "printed_confirmed") return "success";
  if (label === "printed_sent_only") return "warning";
  return null;
}

/** True only for the two labels that describe a printed job. */
export function isPrintedLabel(
  label: PrintJobConfidenceLabel | null | undefined
): label is "printed_confirmed" | "printed_sent_only" {
  return label === "printed_confirmed" || label === "printed_sent_only";
}

/**
 * i18n key for a confidence label. Only printed jobs get one; for anything
 * else the caller shows the status badge alone.
 */
export function confidenceLabelKey(label: PrintJobConfidenceLabel | null | undefined): string | null {
  return isPrintedLabel(label) ? `print_jobs.confidence.${label}` : null;
}

/**
 * Who may act on this job. Derived from the transport exactly as
 * `PrintJobDetailResource` does, so a list row and a detail page never
 * disagree while the detail request is still in flight.
 */
export function queueOwnerFor(transport: PrintTransport | null | undefined): PrintJobQueueOwner {
  return transport === "ws_lan" ? "workstation" : "cloud";
}

/**
 * The list shows a device error at a glance, not a stack trace. Cut at the
 * first line and clamp: the full string is on the detail page.
 */
export function shortenError(message: string | null | undefined, max = 60): string | null {
  if (!message) return null;
  const firstLine = message.split("\n")[0].trim();
  if (firstLine.length <= max) return firstLine;
  return `${firstLine.slice(0, max - 1)}…`;
}

/** Age of an open job, in whole minutes/hours — used for the "stuck since" hint. */
export function ageInSeconds(eventAt: string | null | undefined, now: Date = new Date()): number | null {
  if (!eventAt) return null;
  const then = Date.parse(eventAt);
  if (Number.isNaN(then)) return null;
  return Math.max(0, Math.round((now.getTime() - then) / 1000));
}

/**
 * Why no machine will re-send this job, as an i18n key — or null when one
 * still may.
 *
 * The ORDER of these tests is the message's honesty. A printed money document
 * is not waiting on the "money documents are never auto-reprinted" rule; it is
 * simply done, and saying otherwise sends a manager looking for a decision
 * nobody has to make. Terminal state therefore wins. Below that, the money rule
 * outranks "budget spent", because for a receipt the budget was never the
 * reason (RISKS PR1) and telling someone "retries exhausted" invites them to
 * hunt for a setting that must not exist.
 */
export function noAutoRetryReasonKey(job: {
  status?: PrintJobStatus | null;
  is_money_document?: boolean;
  delivery: {
    attempts: number | null;
    max_attempts: number;
    auto_retry_allowed: boolean;
    auto_retry_allowed_for_kind: boolean;
  };
}): string | null {
  if (job.delivery.auto_retry_allowed) return null;

  if (job.status === "printed" || job.status === "expired") {
    return "print_jobs.detail.no_retry_terminal";
  }
  if (job.is_money_document) return "print_jobs.detail.no_retry_money";
  if (!job.delivery.auto_retry_allowed_for_kind) return "print_jobs.detail.no_retry_kind";
  if ((job.delivery.attempts ?? 0) >= job.delivery.max_attempts) {
    return "print_jobs.detail.no_retry_exhausted";
  }
  return "print_jobs.detail.no_retry_generic";
}

/**
 * TTL as a phrase a human reads without arithmetic. "1440 分" is technically
 * the truth and practically useless; a kitchen ticket's 15 minutes, however,
 * must stay in minutes because that number IS the operational point.
 */
export function formatTtl(seconds: number): { key: string; value: number } {
  if (seconds < 7200) {
    return { key: "print_jobs.detail.ttl_value_minutes", value: Math.round(seconds / 60) };
  }
  if (seconds < 86400 * 2) {
    return { key: "print_jobs.detail.ttl_value_hours", value: Math.round(seconds / 3600) };
  }
  return { key: "print_jobs.detail.ttl_value_days", value: Math.round(seconds / 86400) };
}

// =========================================================================
//  Error mapping
// =========================================================================

/**
 * What the resolve dialog should say when the server says no.
 *
 * The three cases that matter operationally:
 *
 *  - **409 `PRINT_JOB_ALREADY_PRINTED`** — the ledger already records paper.
 *    This is not an error the user caused; it is the record disagreeing with
 *    what they were looking at, usually because the journal arrived while the
 *    dialog was open. The message says so in plain words instead of echoing
 *    the server's code.
 *  - **403** — resolve is manager-only. A cashier sees the whole ledger (they
 *    are the one standing at the machine) but may not declare a matter closed.
 *  - **422** — `reason` is required and means required; a space bar does not
 *    satisfy an audit field.
 */
export type ResolveErrorKind = "already_printed" | "forbidden" | "validation" | "unknown";

export interface ResolveErrorView {
  kind: ResolveErrorKind;
  /** i18n key for the human explanation. */
  messageKey: string;
  /** Per-field messages straight from Laravel, when it sent any. */
  fieldErrors: Record<string, string>;
  /** True when the job is settled and the dialog should stop offering submit. */
  terminal: boolean;
}

function firstFieldErrors(body: unknown): Record<string, string> {
  const errors = (body as { errors?: Record<string, string[] | string> } | null)?.errors;
  if (!errors || typeof errors !== "object") return {};
  const out: Record<string, string> = {};
  for (const [field, value] of Object.entries(errors)) {
    const message = Array.isArray(value) ? value[0] : value;
    if (typeof message === "string" && message !== "") out[field] = message;
  }
  return out;
}

export function describeResolveError(error: unknown): ResolveErrorView {
  if (!(error instanceof ApiError)) {
    return {
      kind: "unknown",
      messageKey: "print_jobs.resolve.error.unknown",
      fieldErrors: {},
      terminal: false,
    };
  }

  const code = typeof error.body?.code === "string" ? error.body.code : null;

  if (error.status === 409 || code === "PRINT_JOB_ALREADY_PRINTED") {
    return {
      kind: "already_printed",
      messageKey: "print_jobs.resolve.error.already_printed",
      fieldErrors: {},
      // Nothing the user can retype will make this succeed — hide submit.
      terminal: true,
    };
  }

  if (error.status === 403) {
    return {
      kind: "forbidden",
      messageKey: "print_jobs.resolve.error.forbidden",
      fieldErrors: {},
      terminal: true,
    };
  }

  if (error.status === 422) {
    return {
      kind: "validation",
      messageKey: "print_jobs.resolve.error.validation",
      fieldErrors: firstFieldErrors(error.body),
      terminal: false,
    };
  }

  return {
    kind: "unknown",
    messageKey: "print_jobs.resolve.error.unknown",
    fieldErrors: {},
    terminal: false,
  };
}

/**
 * Client-side mirror of `ResolvePrintJobRequest`: trim, then require ≥3 chars.
 * Duplicated on purpose — the server stays authoritative, but a manager should
 * not have to round-trip to learn that a blank reason is not a reason.
 */
export function validateResolveReason(reason: string): string | null {
  const trimmed = reason.trim();
  if (trimmed.length === 0) return "print_jobs.resolve.error.reason_required";
  if (trimmed.length < 3) return "print_jobs.resolve.error.reason_too_short";
  if (trimmed.length > 255) return "print_jobs.resolve.error.reason_too_long";
  return null;
}
