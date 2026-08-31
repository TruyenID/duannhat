import { ApiError } from "./api";

type ErrorBody = Record<string, unknown>;

function asBody(value: unknown): ErrorBody {
  if (typeof value === "object" && value !== null && !Array.isArray(value)) {
    return value as ErrorBody;
  }
  return {};
}

function fieldErrorsFrom(body: ErrorBody): Record<string, string[]> {
  const errors = body.errors;
  if (typeof errors !== "object" || errors === null || Array.isArray(errors)) return {};

  const out: Record<string, string[]> = {};
  for (const [field, value] of Object.entries(errors as Record<string, unknown>)) {
    if (Array.isArray(value)) {
      const messages = value.filter((m): m is string => typeof m === "string");
      if (messages.length > 0) out[field] = messages;
    } else if (typeof value === "string") {
      out[field] = [value];
    }
  }
  return out;
}

function primaryMessage(body: ErrorBody, fallbackMessage: string): string {
  const fieldErrors = fieldErrorsFrom(body);
  for (const messages of Object.values(fieldErrors)) {
    if (messages.length > 0 && messages[0].trim() !== "") return messages[0];
  }
  for (const key of ["message", "detail", "title"] as const) {
    const v = body[key];
    if (typeof v === "string" && v.trim() !== "") return v.trim();
  }
  if (fallbackMessage.trim() !== "") return fallbackMessage.trim();
  return "";
}

/**
 * Build a cashier-facing detail string: server message + HTTP status + code +
 * every validation field. Generic "something failed" alone is not enough on a
 * till — staff need the exact Cloud/workstation reason to act.
 */
export function formatErrorDetail(
  status: number | null | undefined,
  body: unknown,
  fallbackMessage: string,
): string {
  const b = asBody(body);
  const main = primaryMessage(b, fallbackMessage);
  const fieldErrors = fieldErrorsFrom(b);

  const meta: string[] = [];
  if (typeof status === "number" && status > 0) meta.push(`HTTP ${status}`);
  if (typeof b.code === "string" && b.code.trim() !== "") meta.push(`code=${b.code}`);

  // Drop whichever message is already shown as `main`, MESSAGE BY MESSAGE.
  // Comparing the joined line instead let a field carrying two or more
  // messages repeat its first one verbatim ("Invalid. pairing_code: Invalid.;
  // Expired."), because "Invalid.; Expired." never equals "Invalid.".
  const fieldLines: string[] = [];
  for (const [field, messages] of Object.entries(fieldErrors)) {
    const rest = messages.filter((m) => m.trim() !== "" && m !== main);
    if (rest.length > 0) fieldLines.push(`${field}: ${rest.join("; ")}`);
  }

  const parts: string[] = [];
  if (main) parts.push(main);
  else if (fallbackMessage.trim()) parts.push(fallbackMessage.trim());
  else parts.push("Unknown error");

  if (fieldLines.length > 0) parts.push(fieldLines.join(" · "));
  if (meta.length > 0) parts.push(`(${meta.join(" · ")})`);

  return parts.join(" ");
}

/**
 * Frozen backend error code — `Shop/OrderPaymentController::refund` answers
 * 409 with it when the shop's workstation still holds an OPEN cashier shift,
 * so the money has to be given back on the workstation-backed till instead of
 * through Cloud.
 *
 * Match on this code, never on a suffix pattern such as `_OPEN_SHIFT`: these
 * codes are a frozen contract (same family as
 * `CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT`, see
 * `docs/guide/cashier-shift-recovery.md`) and a pattern match would quietly
 * swallow a sibling code that means something else entirely.
 */
export const REFUND_BLOCKED_WORKSTATION_OPEN_SHIFT =
  "REFUND_BLOCKED_WORKSTATION_OPEN_SHIFT";

/**
 * True when a refund was refused because the workstation shift is still open.
 *
 * This remains relevant when Cloud is the selected transport (or the request
 * was explicitly forced there). Auto mode no longer fails over writes after a
 * LAN timeout: their commit outcome is ambiguous and replaying a refund on a
 * second tier is unsafe.
 */
export function isRefundBlockedByWorkstationShift(e: unknown): boolean {
  return (
    e instanceof ApiError && e.body?.code === REFUND_BLOCKED_WORKSTATION_OPEN_SHIFT
  );
}

/**
 * Extract a user-facing message from any thrown value — for TOASTS.
 *
 * POS mutations move money (orders, payments, split-bill, voids). A caught
 * error that produces no visible message leaves staff believing an operation
 * succeeded when it did not — the worst failure mode on a till. This helper
 * guarantees a non-empty string for *every* input so an error is never
 * swallowed silently.
 *
 * It deliberately returns the backend envelope message VERBATIM. That string
 * is already locale-negotiated (apiFetch sends `Accept-Language`), so a
 * Japanese cashier reads 「在庫が不足しています」. Appending `(HTTP 409 ·
 * code=…)` here once looked like "more detail" and instead stapled untranslated
 * debug metadata onto every money-path toast in the app. When a screen genuinely
 * needs the diagnostic form — the pairing screen, which is pre-auth and where
 * the exact Cloud reason is the whole point — it calls `formatErrorDetail`
 * itself.
 *
 * Resolution order:
 *  1. `ApiError` → the envelope `message`, then the ApiError's own
 *     `API Error {status}`.
 *  2. Any other `Error` → its `message`.
 *  3. Anything else (string throw, `undefined`, a non-Error object) → the
 *     caller-supplied generic `fallback`. A bare thrown string is an internal
 *     sentinel ("AbortError"), never cashier-facing copy.
 *
 * Empty / whitespace-only messages are treated as absent (a blank error banner
 * is as useless as none), so resolution falls through to the next source.
 */
export function getApiErrorMessage(e: unknown, fallback: string): string {
  if (e instanceof ApiError) {
    const envelope = e.body?.message;
    if (typeof envelope === "string" && envelope.trim() !== "") return envelope;
    if (e.message.trim() !== "") return e.message;
    return fallback;
  }
  if (e instanceof Error && e.message.trim() !== "") return e.message;
  return fallback;
}

/**
 * Diagnostic form of the above: server message + HTTP status + code + every
 * validation field. Use ONLY where the technical detail is what the operator
 * must act on (the pairing screen). Handles `ApiError` and duck-typed
 * `{status, body}` errors such as `PairError`.
 */
export function getApiErrorDetail(e: unknown, fallback: string): string {
  if (e instanceof ApiError) {
    const seed = e.message.trim() !== "" ? e.message : fallback;
    return formatErrorDetail(e.status, e.body, seed);
  }
  if (e instanceof Error && "body" in e) {
    const statusUnknown = (e as { status?: unknown }).status;
    if (typeof statusUnknown === "number") {
      const body = (e as { body: unknown }).body;
      const seed = e.message.trim() !== "" ? e.message : fallback;
      return formatErrorDetail(statusUnknown, body, seed);
    }
  }
  if (e instanceof Error && e.message.trim() !== "") return e.message;
  return fallback;
}
