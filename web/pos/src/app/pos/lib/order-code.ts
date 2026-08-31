/**
 * plan-041 — order code helpers.
 *
 * Cloud is the single authority for the canonical `ORD-{year}-{NNNN}` code.
 * An order created on the workstation in LAN mode opens with a *provisional*
 * code (e.g. `WS-A1B2-20260625-014`) and adopts the real `ORD-…` once the
 * workstation syncs it up and reconciles the response. Anything that is not an
 * `ORD-` code is therefore "not yet finalized".
 */

export const FINAL_CODE_PREFIX = "ORD-";

/**
 * True when the order code has not yet been finalized by Cloud — i.e. it is
 * empty or still carries the workstation's provisional `WS-…` value.
 */
export function isProvisionalCode(code: string | null | undefined): boolean {
  return !code || !code.startsWith(FINAL_CODE_PREFIX);
}
