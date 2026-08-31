"use client";

/**
 * Plan-048 T2.5 (#1121) — the payment-policy identity this client SAW.
 *
 * On checkout/pay mount we prime the branch's published policy revision +
 * effective customer_web gateway option id, then echo the pair back on the
 * `*-payment-intent` body. The server resolves policy itself (authoritative) —
 * the echo only lets it log `customer_web_policy_drift` when an admin
 * republished the policy between mount and pay.
 *
 * Everything here is fail-open by design: a missing/failed context fetch means
 * the intent call simply carries no echo. Payment must never block on this.
 *
 * No static `@/lib/api` import: the module stays alias-free so `node --test`
 * can import it directly (the app path resolves apiFetch lazily instead).
 */
export interface PaymentPolicyContext {
  policy_revision: number | null;
  gateway_option_id: string | null;
  /** #1125 option B — server flag: show Stripe dynamic method tabs (Konbini…). */
  async_payment_methods_enabled?: boolean;
}

type ContextFetcher = (branchSlug: string) => Promise<PaymentPolicyContext>;

const defaultFetcher: ContextFetcher = async (branchSlug) => {
  const { apiFetch } = await import("@/lib/api");
  const res = await apiFetch<{ data: PaymentPolicyContext }>(
    `/api/v1/customer/branches/${branchSlug}/payment-context`,
  );
  return res.data;
};

const cache = new Map<string, PaymentPolicyContext>();
const inflight = new Map<string, Promise<void>>();
const listeners = new Set<() => void>();

/**
 * #1125 — notify readers that a prime landed.
 *
 * The prime is fire-and-forget, so a component that read the flag during its
 * first render holds a value from BEFORE the fetch resolved and would never
 * re-render on its own. Stripe Elements locks its payment-method configuration
 * at mount, so that stale read is not cosmetic — it decides which method set
 * the Element is built for. React-free on purpose: this module stays importable
 * by `node --test`.
 */
export function subscribePaymentPolicyContext(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

/** Fire-and-forget prime on mount. Safe to call repeatedly per slug. */
export function primePaymentPolicyContext(
  branchSlug: string | null | undefined,
  fetcher: ContextFetcher = defaultFetcher,
): void {
  if (!branchSlug || cache.has(branchSlug) || inflight.has(branchSlug)) return;
  const p = fetcher(branchSlug)
    .then((ctx) => {
      cache.set(branchSlug, ctx);
      listeners.forEach((listener) => listener());
    })
    .catch(() => {
      // Fail-open: no context → no echo. Allow a later retry.
    })
    .finally(() => {
      inflight.delete(branchSlug);
    });
  inflight.set(branchSlug, p);
}

/**
 * Synchronous read at intent time → fields to spread into the intent body.
 * Empty object when the prime hasn't landed (or found no policy-backed
 * option) so the request shape is unchanged for legacy branches.
 */
export function paymentPolicyEcho(
  branchSlug: string | null | undefined,
): Partial<{ policy_revision: number; gateway_option_id: string }> {
  const ctx = branchSlug ? cache.get(branchSlug) : undefined;
  if (!ctx) return {};
  return {
    ...(ctx.policy_revision != null ? { policy_revision: ctx.policy_revision } : {}),
    ...(ctx.gateway_option_id != null ? { gateway_option_id: ctx.gateway_option_id } : {}),
  };
}

/**
 * #1125 option B — whether the branch accepts ASYNC methods (Konbini, bank
 * transfer). Drives the Payment Element tab visibility + awaiting-state UX.
 * Fail-closed to card-only when the prime hasn't landed.
 */
export function asyncPaymentMethodsEnabled(
  branchSlug: string | null | undefined,
): boolean {
  const ctx = branchSlug ? cache.get(branchSlug) : undefined;
  return ctx?.async_payment_methods_enabled === true;
}

/** Test hook — clears module state between cases. */
export function __resetPaymentPolicyContext(): void {
  cache.clear();
  inflight.clear();
  listeners.clear();
}
