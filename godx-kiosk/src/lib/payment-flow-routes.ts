/**
 * Routes where an auto-logout would interrupt a payment in progress
 * (cash physically inserted, terminal awaiting confirmation, receipt printing).
 *
 * Lives in its own file (no React/Expo imports) so it can be unit-tested in
 * isolation. AuthProvider's drain effect uses this to decide whether a 401
 * should log out immediately or wait until the user leaves the flow.
 */

/**
 * Trailing slashes are intentional: `/payment/` matches `/payment/cash` but
 * NOT `/payment-method` (the picker screen — no money in motion yet). Same
 * for `/split/` vs `/split-options`.
 */
export const PAYMENT_FLOW_PREFIXES = [
  "/payment/",
  "/custom/",
  "/split/",
] as const;

/**
 * Exact-match routes. `/success` is the receipt-print screen — defer logout
 * so the print finishes before the user is bounced.
 */
export const PAYMENT_FLOW_EXACT = new Set<string>(["/success"]);

export function isInPaymentFlow(pathname: string): boolean {
  if (PAYMENT_FLOW_EXACT.has(pathname)) return true;
  return PAYMENT_FLOW_PREFIXES.some((prefix) => pathname.startsWith(prefix));
}
