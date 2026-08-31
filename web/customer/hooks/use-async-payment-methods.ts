"use client";

/**
 * #1125 option B — does this branch accept ASYNC payment methods (Konbini,
 * 銀行振込)? Reactive wrapper over the payment-policy context cache.
 *
 * Why a hook rather than the bare `asyncPaymentMethodsEnabled()` read: the
 * prime is fire-and-forget, so the first render almost always lands BEFORE the
 * fetch resolves and reads `false`. A plain call never re-renders when the
 * answer arrives, which silently pinned every branch to card-only.
 *
 * That mattered more than a hidden tab. Stripe Elements freezes its
 * payment-method configuration at mount: the same flag decides whether the
 * Element is built for `card` only or for automatic payment methods, and that
 * choice must match how the backend created the PaymentIntent
 * (`StripePaymentService::browserIntentMethodParams`). A stale `false` against
 * an automatic-methods intent is a hard confirm failure, not a cosmetic one.
 *
 * Still fail-closed to card-only: an unresolved or failed prime answers
 * `false`, which matches the shipped default (`payments.async_payment_methods`
 * OFF). The hook only makes the LATER truth visible.
 */

import { useCallback, useSyncExternalStore } from "react";

import {
  asyncPaymentMethodsEnabled,
  subscribePaymentPolicyContext,
} from "@/lib/payment-policy-context";

export function useAsyncPaymentMethods(
  branchSlug: string | null | undefined,
): boolean {
  const getSnapshot = useCallback(
    () => asyncPaymentMethodsEnabled(branchSlug),
    [branchSlug],
  );

  return useSyncExternalStore(
    subscribePaymentPolicyContext,
    getSnapshot,
    // Server render has no primed context — card-only, same as the client's
    // first paint, so hydration matches.
    () => false,
  );
}
