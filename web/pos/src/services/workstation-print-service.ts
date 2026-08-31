/**
 * workstation-print-service — thin client for the LAN workstation app's
 * thermal-printer endpoints. The workstation runs `workstation-app` (Go +
 * Wails) on the shop's local PC and exposes an HTTP server with the
 * receipt + kitchen printer plumbed in.
 *
 * Plan-038 rewrote this from the broken legacy `/print/*` path to the
 * proper `/api/lan/print/*` namespace + Bearer auth + ApiError envelope.
 * URL comes from base-url-resolver's `getWorkstationUrl()` — operator-paired
 * per device (Settings → Connection), falling back to `VITE_WORKSTATION_API_URL`.
 *
 * Workstation print is always LAN — never falls back to Cloud. When the
 * workstation is unreachable the calls throw ApiError(status=0) and the
 * caller surfaces a "printer offline" toast.
 */

import { ApiError } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { LOCALE_STORAGE_KEY } from "@/i18n";
import { getWorkstationUrl, hasWorkstation } from "./workstation/base-url-resolver";

// Treat "no workstation configured" (VITE_WORKSTATION_API_URL=none / empty)
// as disabled so callers silent-no-op instead of fetching the "none" host
// (which the browser resolves relative to the POS tunnel) — see #422.
//
// Read live rather than cached at module load: the operator can pair/repair
// a workstation URL from Settings mid-session (localStorage), and a stale
// BASE captured at import time would keep hitting the old address.
function currentBase(): string {
  return hasWorkstation() ? getWorkstationUrl().replace(/\/+$/, "") : "";
}

// Read the operator's selected pos-web locale — the SAME key AppProvider
// persists (LOCALE_STORAGE_KEY = "pos_locale"). The old "app_locale" key never
// existed, so getLocale() always fell back to VITE_DEFAULT_LOCALE and the
// printer ignored the language switch (printed Vietnamese for a JA operator).
function getLocale(): string {
  if (typeof document === "undefined") return "ja";
  return (
    localStorage.getItem(LOCALE_STORAGE_KEY) ||
    import.meta.env.VITE_DEFAULT_LOCALE ||
    "ja"
  );
}

async function lanFetch<T>(
  method: string,
  path: string,
  body?: unknown,
): Promise<T> {
  const base = currentBase();
  if (!base) {
    throw new ApiError(0, { message: "workstation URL not configured" });
  }
  const token = getToken();
  let response: Response;
  try {
    // eslint-disable-next-line no-restricted-globals -- LAN-only endpoint: apiFetch resolves a Cloud base URL, and /api/lan/print/* exists ONLY on the workstation
    response = await fetch(`${base}${path}`, {
      method,
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "Accept-Language": getLocale(),
        ...(token && { Authorization: `Bearer ${token}` }),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch (err) {
    throw new ApiError(0, {
      message: "workstation unreachable",
      cause: err instanceof Error ? err.message : String(err),
    });
  }

  if (!response.ok) {
    const errBody = (await response.json().catch(() => ({}))) as Record<
      string,
      unknown
    >;
    throw new ApiError(response.status, errBody);
  }

  if (response.status === 204) return null as T;
  return (await response.json()) as T;
}

// ─── Public API ─────────────────────────────────────────────────────────

export interface PrintKitchenTicketInput {
  orderId: string;
  idempotencyKey?: string;
}

export interface PrintKitchenTicketResult {
  status: "ok" | "partial" | "no_printer";
  printed: number;
  groups?: Array<{
    printer_group: string;
    ticket_no: number;
    items: number;
  }>;
  errors?: Array<{ printer_group: string; reason: string; detail?: string }>;
  detail?: string;
}

export interface PrintPaymentReceiptInput {
  orderId: string;
  paymentId?: string;
  reprintReason?: string;
  idempotencyKey?: string;
  /** Legacy (plan-021 split-bill). Tolerated for caller compat. */
  shopSlug?: string;
}

export interface PrintPaymentReceiptResult {
  status: "ok";
  slips_printed: number;
  reprint_no: number;
  remaining_amount: string;
}

export interface PrintStatusResult {
  printer_roles: Record<
    // `hall_printer` is the ホール伝票 (front-of-house) role; `hold_printer` is
    // its legacy spelling, still emitted by the workstation as an alias so this
    // client keeps working across the rename.
    "kitchen_printer" | "bar_printer" | "hall_printer" | "receipt_printer",
    { configured: boolean; online?: boolean; last_error?: string }
  >;
  sync: { last_pulled_at: string; cursor_age_s?: number };
  order?: {
    id: string;
    in_local: boolean;
    open_items_pending_print?: number;
    /**
     * Mode A ("thanh toán trước khi chuẩn bị món"): the shop declared the
     * kitchen must not start before the money is in, and this takeaway order
     * has not been settled yet.
     *
     * Firing anyway is what stamps CHUA TRA on the sheet that travels WITH THE
     * FOOD — the early fire closes the unprinted delta, so the fire that runs
     * when payment lands finds nothing left to send and no corrected sheet is
     * ever produced.
     *
     * ABSENT MEANS "cannot tell", never "go ahead" — same rule as
     * `red_invoice` and `untargeted_scope` above. An older workstation omits
     * it, a dine-in order omits it, and a status probe that failed leaves the
     * previous value in place; none of those may grey out the fire button.
     *
     * Advisory, not a gate. The workstation still prints whatever it is asked
     * to: one that refuses is a kitchen that silently stops cooking, which is
     * worse than a wrong word on paper.
     */
    awaiting_prepayment?: boolean;
  };
  /**
   * #1875 — how many copies of each money document this order already has, and
   * for WHICH payer.
   *
   * Optional on purpose: a workstation older than #1875 omits these entirely, so
   * the field being absent must read as "this workstation cannot tell me",
   * never as "nothing has been printed". Rendering a confident "chưa in" from a
   * missing field is how a cashier prints a second original.
   *
   * `count` is the highest copy number ISSUED, so the next sheet is `count + 1`
   * — including after a failed print, which still burns its number.
   */
  red_invoice?: PrintKindCounts;
  receipt?: PrintKindCounts;
  debt_slip?: PrintKindCounts;
  /**
   * #2535 A7 — which scope an UNTARGETED print (no `payment_id`) lands on.
   *
   * Never derive this locally. `resolvePrintScope` branch ② puts a one-payer
   * order's untargeted print on THE PAYMENT, not on `order_scope`, and one of
   * its branches reads payment metadata this client never sees. Reading
   * `order_scope` for a single-payment order yields a permanent 0 — which is
   * exactly the bug where "In lại" never lights up.
   *
   * Absent means an older workstation: treat as UNKNOWN, never as "the order
   * scope". Guessing here relights the same bug silently.
   */
  untargeted_scope?: { payment_id: string | null };
}

/** Per-scope tally of one document kind on one order (#1875). */
export interface PrintKindCounts {
  printed: boolean;
  /** The whole-order slip's own counter — NOT the sum across payers. */
  order_scope: PrintScopeCount;
  /** One entry per payer that has paper. A split bill can have some and not others. */
  by_payment: Array<PrintScopeCount & { payment_id: string }>;
}

export interface PrintScopeCount {
  count: number;
  last_printed_at?: string;
  last_status?: "printed" | "failed" | "needs_attention" | "queued";
}

/**
 * Outcome of a shift-report print attempt. `ok` means the slip printed;
 * every other value is a non-fatal condition the caller can surface as a
 * gentle warning (the shift is already settled regardless).
 */
export interface PrintShiftReportResult {
  status: "ok" | "no_printer" | "offline" | "unsupported" | "disabled";
  slips_printed?: number;
  detail?: string;
}

export const workstationPrintService = {
  get enabled(): boolean {
    return Boolean(currentBase());
  },

  /** Plan-038 T3.1 — kitchen ticket fire. */
  async printKitchenTicket(
    input: PrintKitchenTicketInput,
  ): Promise<PrintKitchenTicketResult> {
    return lanFetch<PrintKitchenTicketResult>(
      "POST",
      "/api/lan/print/kitchen-ticket",
      {
        order_id: input.orderId,
        idempotency_key: input.idempotencyKey,
      },
    );
  },

  /**
   * "In lại phiếu bếp" — re-prints an order's kitchen ticket(s) without firing.
   *
   * A DIFFERENT endpoint from `printKitchenTicket`, not the same one twice.
   * That one is dispatch: it sends the unprinted delta, closes it, and makes
   * every KDS re-fetch. On a finished order the delta is 0, so it answers 422
   * "no unprinted items" — and if it did print, it would put the order back on
   * the kitchen display as new work.
   *
   * Error shapes worth handling: 422 `no items to print` (every line voided),
   * 503 `{status:"no_printer"}` — no KDS fallback makes a paperless reprint
   * mean anything here, so that one really is a failure.
   */
  async printKitchenReprint(input: {
    orderId: string;
  }): Promise<PrintKitchenTicketResult> {
    return lanFetch<PrintKitchenTicketResult>(
      "POST",
      "/api/lan/print/kitchen-reprint",
      { order_id: input.orderId },
    );
  },

  /**
   * Full-order bill + QR, on-demand, NO reprint limit ("in phiếu order").
   * Prints every item added to the order so far (not the kitchen delta), with
   * a QR of the order id, to the receipt printer. Can be printed any number of
   * times.
   */
  async printOrderBill(input: {
    orderId: string;
  }): Promise<{ status: "ok" | "no_printer"; items?: number; detail?: string }> {
    return lanFetch("POST", "/api/lan/print/order-bill", {
      order_id: input.orderId,
    });
  },

  /** Plan-038 T3.1 — payment receipt (initial or reprint). */
  async printPaymentReceipt(
    input: PrintPaymentReceiptInput,
  ): Promise<PrintPaymentReceiptResult> {
    return lanFetch<PrintPaymentReceiptResult>(
      "POST",
      "/api/lan/print/payment-receipt",
      {
        order_id: input.orderId,
        payment_id: input.paymentId,
        reprint_reason: input.reprintReason,
        idempotency_key: input.idempotencyKey,
      },
    );
  },

  /**
   * Red invoice (hoá đơn đỏ) — the paid receipt (items, totals, tendered/change)
   * plus a named-customer line. `customerName` is optional: when blank the slip
   * prints an underline for the cashier to hand-write it.
   */
  async printRedInvoice(input: {
    orderId: string;
    customerName?: string;
    /** #1779 — target ONE split payer (chia bill). Absent → the whole order. */
    paymentId?: string;
    /**
     * plan-052 §4 — asked for, NEVER required. The workstation has accepted this
     * field since #1166; pos-web simply never sent it, so every red invoice
     * reached the ledger with no reason at all. Only the `In lại` branch fills
     * it — an original has nothing to explain.
     */
    reprintReason?: string;
  }): Promise<{ status: string }> {
    return lanFetch("POST", "/api/lan/print/red-invoice", {
      order_id: input.orderId,
      customer_name: input.customerName ?? "",
      payment_id: input.paymentId ?? undefined,
      reprint_reason: input.reprintReason,
    });
  },

  /** Plan-038 T3.1 — printer + sync probe. */
  async getPrintStatus(input?: {
    orderId?: string;
  }): Promise<PrintStatusResult> {
    const query = input?.orderId
      ? "?order_id=" + encodeURIComponent(input.orderId)
      : "";
    return lanFetch<PrintStatusResult>(
      "GET",
      "/api/lan/print/status" + query,
    );
  },

  /** Plan-038 T10.6 — debt slip ("PHIEU GHI NO") for an on_account payment. */
  async printDebtSlip(input: {
    orderId: string;
    paymentId: string;
    reprintReason?: string;
  }): Promise<{ status: "ok"; slips_printed: number; reprint_no: number }> {
    return lanFetch("POST", "/api/lan/print/debt-slip", {
      order_id: input.orderId,
      payment_id: input.paymentId,
      reprint_reason: input.reprintReason,
    });
  },


  /**
   * Plan 030 — 精算 (cashier-shift settlement / Z) report print. The
   * workstation renders an 80mm thermal slip from local SQLite (orders +
   * payments + till_* tables) for the just-closed session — see
   * `workstation-app/internal/handler/lan_shift_report.go`.
   *
   * Best-effort by contract: the caller (shift close-page) fires this after
   * the shift is already settled, so a printer being offline must never
   * unwind the close. We resolve (never throw) on the "expected" failure
   * modes — workstation unreachable, no printer configured, or an
   * older workstation build without the route (404). Genuine 5xx bubble up
   * so the caller can surface a warning toast.
   */
  async printShiftReport(input: {
    shopSlug: string;
    sessionId: string;
    /** plan-046 — "handover" prints a 引き継ぎ header; default 精算. */
    reportKind?: "handover" | "settlement";
  }): Promise<PrintShiftReportResult> {
    if (!currentBase()) return { status: "disabled" };
    try {
      return await lanFetch<PrintShiftReportResult>(
        "POST",
        "/api/lan/print/shift-report",
        {
          shop_slug: input.shopSlug,
          session_id: input.sessionId,
          report_kind: input.reportKind ?? "settlement",
        },
      );
    } catch (err) {
      if (err instanceof ApiError) {
        // 404 → workstation predates the route; 503 → no receipt printer;
        // 0 → workstation unreachable. All non-fatal for a settled shift.
        if (err.status === 404) return { status: "unsupported" };
        if (err.status === 503) return { status: "no_printer" };
        if (err.status === 0) return { status: "offline" };
      }
      throw err;
    }
  },

  /**
   * Plan 046 — chain aggregate (kết ca cuối) slip. Sums every shift of the chain
   * into per-shift blocks + a grand total. Best-effort, same contract as
   * printShiftReport — see workstation-app `internal/handler/lan_chain_report.go`.
   */
  async printChainReport(input: {
    shopSlug: string;
    chainId: string;
  }): Promise<PrintShiftReportResult> {
    if (!currentBase()) return { status: "disabled" };
    try {
      return await lanFetch<PrintShiftReportResult>(
        "POST",
        "/api/lan/print/chain-report",
        {
          shop_slug: input.shopSlug,
          chain_id: input.chainId,
        },
      );
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.status === 404) return { status: "unsupported" };
        if (err.status === 503) return { status: "no_printer" };
        if (err.status === 0) return { status: "offline" };
      }
      throw err;
    }
  },

  /**
   * Plan 030 — レジ開け (shift-open / opening cash count) report print. The
   * workstation renders an 80mm thermal slip from local SQLite (till_session +
   * opening-phase denomination counts) for the just-OPENED session — see
   * `workstation-app/internal/handler/lan_shift_open_report.go`.
   *
   * Distinct endpoint from printShiftReport: the open slip (レジ開け) is NOT the
   * 精算/Z settlement slip. Best-effort by the same contract — fired AFTER the
   * shift is already open, so a printer offline / workstation unreachable / an
   * older build without the route (404) must never unwind the open. Those
   * resolve to a non-`ok` status; genuine 5xx bubble up so the caller can warn.
   */
  async printShiftOpenReport(input: {
    shopSlug: string;
    sessionId: string;
    deviceName?: string;
  }): Promise<PrintShiftReportResult> {
    if (!currentBase()) return { status: "disabled" };
    try {
      return await lanFetch<PrintShiftReportResult>(
        "POST",
        "/api/lan/print/shift-open-report",
        {
          shop_slug: input.shopSlug,
          session_id: input.sessionId,
          device_name: input.deviceName,
        },
      );
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.status === 404) return { status: "unsupported" };
        if (err.status === 503) return { status: "no_printer" };
        if (err.status === 0) return { status: "offline" };
      }
      throw err;
    }
  },
};
