/**
 * #2741 — when to call `elements.submit()` in a deferred Payment Element flow.
 *
 * Stripe's documented sequence (accept-a-payment-deferred):
 *
 *   1. submit() ONCE, as soon as Pay is clicked, before any async work
 *   2. create the PaymentIntent on the server
 *   3. confirmPayment({ elements, clientSecret }) — do NOT submit again
 *
 * A second submit() after the PI create left live intents at
 * `requires_payment_method` with no payment_method (ORD-2026-0237).
 *
 * Pure state machine so every edge can be unit-tested without Stripe.js.
 */
export type ConfirmOutcome = "succeeded" | "pending" | "error";

export function createDeferredSubmitGate() {
  let submitted = false;

  return {
    /** True when confirm() must call elements.submit() itself. */
    shouldSubmitOnConfirm(): boolean {
      return !submitted;
    },

    onValidateOk(): void {
      submitted = true;
    },

    onValidateError(): void {
      submitted = false;
    },

    onFallbackSubmitOk(): void {
      submitted = true;
    },

    onFallbackSubmitError(): void {
      submitted = false;
    },

    /**
     * `pending` = 3DS / async voucher still in this click — keep the
     * collected method. `error` = Stripe.js rejected; next click must
     * submit again. `succeeded` stays submitted (no further confirm).
     */
    onConfirmOutcome(outcome: ConfirmOutcome): void {
      if (outcome === "error") {
        submitted = false;
      }
    },
  };
}

export type DeferredSubmitGate = ReturnType<typeof createDeferredSubmitGate>;

/**
 * One Pay click as the four customer-web callers actually run it:
 * validate (submit) → async PI create → confirm (submit only if needed).
 */
export async function runPayClick(opts: {
  gate: DeferredSubmitGate;
  validate?: "ok" | "error" | "skip";
  fallbackSubmit?: "ok" | "error";
  confirmPayment?: ConfirmOutcome;
}): Promise<{ submitCount: number; confirmCalled: boolean }> {
  const validate = opts.validate ?? "ok";
  const confirmPayment = opts.confirmPayment ?? "succeeded";
  let submitCount = 0;
  let confirmCalled = false;

  if (validate === "ok") {
    submitCount += 1;
    opts.gate.onValidateOk();
  } else if (validate === "error") {
    submitCount += 1;
    opts.gate.onValidateError();
    return { submitCount, confirmCalled };
  }

  // Callers create the PaymentIntent here (async). No submit.

  if (opts.gate.shouldSubmitOnConfirm()) {
    submitCount += 1;
    if (opts.fallbackSubmit === "error") {
      opts.gate.onFallbackSubmitError();
      return { submitCount, confirmCalled };
    }
    opts.gate.onFallbackSubmitOk();
  }

  confirmCalled = true;
  opts.gate.onConfirmOutcome(confirmPayment);
  return { submitCount, confirmCalled };
}
