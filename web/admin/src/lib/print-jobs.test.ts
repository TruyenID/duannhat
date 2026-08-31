/**
 * plan-052 M2 / T2.2 (#1166) — the two invariants of the Print jobs screen,
 * locked.
 *
 * The first is P-33: `printed_sent_only` and `printed_confirmed` may never
 * render the same. A regression there is invisible on screen (both look like
 * "printed") and teaches ops to trust a fact nobody measured, so it gets a test
 * that fails loudly instead of a code comment.
 *
 * The second is the error grammar of `resolve` — 409 / 403 / 422 mean three
 * genuinely different things to the person holding the receipt, and collapsing
 * them into "something went wrong" is how a manager decides the screen is
 * broken and stops using it.
 */

import { describe, expect, it } from "vitest";
import { ApiError } from "@/lib/api";
import {
  ageInSeconds,
  confidenceLabelKey,
  confidenceTone,
  describeResolveError,
  formatTtl,
  isMoneyDocumentKind,
  isPrintedLabel,
  noAutoRetryReasonKey,
  printJobStatusTone,
  queueOwnerFor,
  shortenError,
  validateResolveReason,
  PRINT_JOB_KINDS,
  PRINT_JOB_STATUSES,
} from "@/lib/print-jobs";

describe("confidence (P-33)", () => {
  it("never gives the two printed labels the same tone", () => {
    const confirmed = confidenceTone("printed_confirmed");
    const sentOnly = confidenceTone("printed_sent_only");

    expect(confirmed).toBe("success");
    expect(sentOnly).toBe("warning");
    expect(confirmed).not.toBe(sentOnly);
  });

  it("refuses to paint sent_only as success", () => {
    // The whole failure mode this rule exists to prevent, stated directly.
    expect(confidenceTone("printed_sent_only")).not.toBe("success");
  });

  it("has no confidence answer for a job that has not printed", () => {
    for (const label of ["queued", "delivering", "failed", "needs_attention", "expired"] as const) {
      expect(confidenceTone(label)).toBeNull();
      expect(isPrintedLabel(label)).toBe(false);
      expect(confidenceLabelKey(label)).toBeNull();
    }
    expect(confidenceTone(null)).toBeNull();
  });

  it("maps each printed label to its own i18n key", () => {
    expect(confidenceLabelKey("printed_confirmed")).toBe("print_jobs.confidence.printed_confirmed");
    expect(confidenceLabelKey("printed_sent_only")).toBe("print_jobs.confidence.printed_sent_only");
    expect(confidenceLabelKey("printed_confirmed")).not.toBe(
      confidenceLabelKey("printed_sent_only")
    );
  });
});

describe("status tone", () => {
  it("gives needs_attention the loudest tone — it is the only state that needs a human", () => {
    expect(printJobStatusTone("needs_attention")).toBe("destructive");
  });

  it("assigns a tone to every status the backend can send", () => {
    for (const status of PRINT_JOB_STATUSES) {
      expect(printJobStatusTone(status)).toBeTruthy();
    }
  });

  it("does not colour queued/delivering as a problem", () => {
    expect(printJobStatusTone("queued")).toBe("info");
    expect(printJobStatusTone("delivering")).toBe("info");
  });
});

describe("money documents", () => {
  it("matches the backend's isMoneyDocument() set exactly", () => {
    const money = PRINT_JOB_KINDS.filter(isMoneyDocumentKind);
    expect(money.sort()).toEqual(["debt_slip", "receipt", "red_invoice"]);
  });

  it("does not treat a diagnostic sheet as money", () => {
    expect(isMoneyDocumentKind("diagnostic")).toBe(false);
    expect(isMoneyDocumentKind(null)).toBe(false);
  });
});

describe("queue ownership (DESIGN §1b)", () => {
  it("hands ws_lan to the workstation and everything else to cloud", () => {
    expect(queueOwnerFor("ws_lan")).toBe("workstation");
    expect(queueOwnerFor("cloudprnt")).toBe("cloud");
    expect(queueOwnerFor("epos_http")).toBe("cloud");
    expect(queueOwnerFor("webprnt")).toBe("cloud");
    expect(queueOwnerFor(null)).toBe("cloud");
  });
});

describe("shortenError", () => {
  it("keeps a short single-line error intact", () => {
    expect(shortenError("cover_open")).toBe("cover_open");
  });

  it("cuts at the first line", () => {
    expect(shortenError("paper_end\n\tat socket.write")).toBe("paper_end");
  });

  it("ellipsises past the limit without exceeding it", () => {
    const long = "x".repeat(200);
    const out = shortenError(long, 20)!;
    expect(out).toHaveLength(20);
    expect(out.endsWith("…")).toBe(true);
  });

  it("returns null for nothing", () => {
    expect(shortenError(null)).toBeNull();
    expect(shortenError("")).toBeNull();
  });
});

describe("ageInSeconds", () => {
  it("measures a duration, not a calendar difference", () => {
    const now = new Date("2026-07-28T10:00:00Z");
    expect(ageInSeconds("2026-07-28T09:00:00Z", now)).toBe(3600);
  });

  it("never returns a negative age for a clock-skewed row", () => {
    const now = new Date("2026-07-28T10:00:00Z");
    expect(ageInSeconds("2026-07-28T10:05:00Z", now)).toBe(0);
  });

  it("returns null when there is no timestamp", () => {
    expect(ageInSeconds(null)).toBeNull();
    expect(ageInSeconds("not-a-date")).toBeNull();
  });
});

describe("no-auto-retry reason (the sentence a manager acts on)", () => {
  const base = {
    status: "needs_attention" as const,
    is_money_document: false,
    delivery: {
      attempts: 1,
      max_attempts: 4,
      auto_retry_allowed: false,
      auto_retry_allowed_for_kind: true,
    },
  };

  it("says nothing when a retry may still happen", () => {
    expect(
      noAutoRetryReasonKey({ ...base, delivery: { ...base.delivery, auto_retry_allowed: true } })
    ).toBeNull();
  });

  it("names the money rule for an open money document", () => {
    expect(noAutoRetryReasonKey({ ...base, is_money_document: true })).toBe(
      "print_jobs.detail.no_retry_money"
    );
  });

  it("prefers 'terminal' over the money rule once the slip is printed", () => {
    // A printed receipt is not waiting on a human decision. Repeating the
    // money warning here would send a manager hunting for a call to make.
    expect(
      noAutoRetryReasonKey({ ...base, status: "printed", is_money_document: true })
    ).toBe("print_jobs.detail.no_retry_terminal");
    expect(
      noAutoRetryReasonKey({ ...base, status: "expired", is_money_document: true })
    ).toBe("print_jobs.detail.no_retry_terminal");
  });

  it("prefers the money rule over 'budget spent' — the budget was never the reason", () => {
    expect(
      noAutoRetryReasonKey({
        ...base,
        is_money_document: true,
        delivery: { ...base.delivery, attempts: 4, auto_retry_allowed_for_kind: false },
      })
    ).toBe("print_jobs.detail.no_retry_money");
  });

  it("reports an exhausted budget for a retryable kind", () => {
    expect(
      noAutoRetryReasonKey({ ...base, delivery: { ...base.delivery, attempts: 4 } })
    ).toBe("print_jobs.detail.no_retry_exhausted");
  });

  it("reports an ineligible kind before an exhausted budget", () => {
    expect(
      noAutoRetryReasonKey({
        ...base,
        delivery: { ...base.delivery, attempts: 0, auto_retry_allowed_for_kind: false },
      })
    ).toBe("print_jobs.detail.no_retry_kind");
  });
});

describe("formatTtl", () => {
  it("keeps a kitchen ticket in minutes — 15 IS the operational point", () => {
    expect(formatTtl(900)).toEqual({ key: "print_jobs.detail.ttl_value_minutes", value: 15 });
  });

  it("turns a receipt's 86400s into hours, not 1440 minutes", () => {
    expect(formatTtl(86400)).toEqual({ key: "print_jobs.detail.ttl_value_hours", value: 24 });
  });

  it("uses days past two", () => {
    expect(formatTtl(86400 * 7)).toEqual({ key: "print_jobs.detail.ttl_value_days", value: 7 });
  });
});

describe("resolve error grammar", () => {
  it("explains a 409 as 'the ledger already says printed', and stops offering submit", () => {
    const view = describeResolveError(
      new ApiError(409, {
        message: "PRINT_JOB_ALREADY_PRINTED: …",
        code: "PRINT_JOB_ALREADY_PRINTED",
      })
    );

    expect(view.kind).toBe("already_printed");
    expect(view.messageKey).toBe("print_jobs.resolve.error.already_printed");
    // Retyping the reason cannot make this succeed — the dialog must not invite it.
    expect(view.terminal).toBe(true);
  });

  it("recognises the 409 from its code even if the status were re-mapped", () => {
    const view = describeResolveError(
      new ApiError(400, { code: "PRINT_JOB_ALREADY_PRINTED" })
    );
    expect(view.kind).toBe("already_printed");
  });

  it("explains a 403 as manager-only rather than a generic failure", () => {
    const view = describeResolveError(new ApiError(403, { message: "This action is unauthorized." }));
    expect(view.kind).toBe("forbidden");
    expect(view.messageKey).toBe("print_jobs.resolve.error.forbidden");
    expect(view.terminal).toBe(true);
  });

  it("surfaces the 422 field messages verbatim so the manager fixes the right box", () => {
    const view = describeResolveError(
      new ApiError(422, {
        message: "The given data was invalid.",
        errors: { reason: ["RESOLUTION_REASON_REQUIRED: a print-job resolution must say why."] },
      })
    );

    expect(view.kind).toBe("validation");
    expect(view.fieldErrors.reason).toContain("RESOLUTION_REASON_REQUIRED");
    // A validation error IS retryable — submit stays.
    expect(view.terminal).toBe(false);
  });

  it("tolerates a 422 with no errors object", () => {
    const view = describeResolveError(new ApiError(422, { message: "nope" }));
    expect(view.kind).toBe("validation");
    expect(view.fieldErrors).toEqual({});
  });

  it("falls back to unknown for a non-ApiError", () => {
    expect(describeResolveError(new Error("network down")).kind).toBe("unknown");
    expect(describeResolveError(new ApiError(500, {})).kind).toBe("unknown");
  });
});

describe("reason validation (mirrors ResolvePrintJobRequest)", () => {
  it("rejects an empty reason", () => {
    expect(validateResolveReason("")).toBe("print_jobs.resolve.error.reason_required");
  });

  it("rejects whitespace — a space bar does not satisfy an audit field", () => {
    expect(validateResolveReason("    ")).toBe("print_jobs.resolve.error.reason_required");
  });

  it("rejects a reason under the server's 3-character floor", () => {
    expect(validateResolveReason("ok")).toBe("print_jobs.resolve.error.reason_too_short");
  });

  it("rejects a reason over 255 characters", () => {
    expect(validateResolveReason("x".repeat(256))).toBe("print_jobs.resolve.error.reason_too_long");
  });

  it("accepts a real sentence", () => {
    expect(validateResolveReason("手書き領収書をお渡ししました")).toBeNull();
  });
});
