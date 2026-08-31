import { apiFetch } from "./api";
import { reportError } from "./error-reporter";

/**
 * Audit-log client for the kiosk lifecycle trail (PCI-DSS Req 10.2).
 *
 * Backend endpoint: `POST /api/v1/kiosk/audit-logs` — accepts
 * { event, auditable_type, auditable_id, metadata? } and stores into
 * the cloud `audit_logs` table with device_id/branch_id captured into
 * metadata server-side.
 *
 * Failure mode: NEVER block the calling flow. If the network is down
 * or the cloud is unreachable, log the failure via reportError and
 * return — the kiosk still has to complete the customer's payment.
 * The cloud audit row simply doesn't get written; later forensic
 * reconciliation has the gap. (A future enhancement: queue events in
 * AsyncStorage + drain on reconnect.)
 *
 * Recipe — call sites should use the named helpers below, not the
 * raw `recordAudit` function, so the event vocabulary stays uniform:
 *
 *   import { auditPaymentInitiated, auditPaymentConfirmed } from "@/lib/audit-log";
 *   auditPaymentInitiated({ payment_id, amount, method });
 *   auditPaymentConfirmed({ payment_id, reference_no });
 */

export type AuditableType = "Payment" | "Order" | "Device" | "Terminal";

export interface AuditEvent {
  event: string;
  auditable_type: AuditableType;
  auditable_id: string;
  metadata?: Record<string, unknown>;
}

interface AuditResponse {
  data: {
    id: string;
    recorded_at: string;
  };
}

/**
 * Fire-and-forget audit log POST. Returns a promise so tests can
 * await + assert, but production call sites should NOT await — the
 * payment flow must not block on the audit log network call.
 */
export async function recordAudit(event: AuditEvent): Promise<void> {
  try {
    await apiFetch<AuditResponse>("/api/v1/kiosk/audit-logs", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(event),
      // Fire-and-forget: a 401 here must NOT re-enter the unauthorized
      // handler chain. The original 401 (from polling / confirm) already
      // tripped the module-level guard; audit-log POSTs should stay silent
      // about auth state.
      suppressUnauthorizedHandler: true,
    });
  } catch (err) {
    // Stay silent in the UI; surface in reportError so Sentry + the
    // device log get a breadcrumb. Don't re-throw — this is best-effort.
    reportError("audit-log-post", err);
  }
}

// =========================================================================
// Named event helpers — keep the vocabulary uniform across call sites
// =========================================================================

export function auditPaymentInitiated(payload: {
  payment_id?: string;
  order_id: string;
  amount: number;
  method: string;
  idempotency_key?: string;
}): void {
  void recordAudit({
    event: "payment.initiated",
    auditable_type: "Payment",
    auditable_id: payload.payment_id ?? payload.order_id,
    metadata: payload,
  });
}

export function auditPaymentSubmitted(payload: {
  payment_id: string;
  status: string;
}): void {
  void recordAudit({
    event: "payment.submitted",
    auditable_type: "Payment",
    auditable_id: payload.payment_id,
    metadata: payload,
  });
}

export function auditPaymentConfirmed(payload: {
  payment_id: string;
  reference_no?: string;
}): void {
  void recordAudit({
    event: "payment.confirmed",
    auditable_type: "Payment",
    auditable_id: payload.payment_id,
    metadata: payload,
  });
}

export function auditPaymentFailed(payload: {
  payment_id: string;
  reason?: string;
  error_code?: string;
}): void {
  void recordAudit({
    event: "payment.failed",
    auditable_type: "Payment",
    auditable_id: payload.payment_id,
    metadata: payload,
  });
}

export function auditCrash(payload: {
  payment_id?: string;
  error_message: string;
  error_stack?: string;
}): void {
  void recordAudit({
    event: "payment.crash",
    auditable_type: "Payment",
    auditable_id: payload.payment_id ?? "unknown",
    metadata: payload,
  });
}
