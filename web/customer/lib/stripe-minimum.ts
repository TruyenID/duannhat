/**
 * #815 / #1427 — approximate Stripe minimum charge amount per currency
 * (~US$0.50 equivalent). Stripe converts the minimum at request time, so these
 * are GUIDANCE values, not exact thresholds.
 * https://stripe.com/docs/currencies#minimum-and-maximum-charge-amounts
 *
 * Lived inside `stripe-card-section.tsx` until #1427, where it was needed a
 * second time: the pay screen now separates "pay by Stripe" from "pay by
 * PayPay", and it can only disable the Stripe choice up front if it can answer
 * "is this order too small for a card?" BEFORE mounting Elements. Keeping one
 * table means the radio and the card frame can never disagree about the limit.
 *
 * Deliberately guidance-only, in one direction: an unknown currency returns
 * `undefined` → `isBelowStripeMinimum` is false → the choice stays OPEN and the
 * customer finds out from Stripe itself (the existing `onLoadError` path). We
 * never take a payment method away on a guess.
 */
export const STRIPE_MIN_CHARGE: Record<string, number> = {
  JPY: 50,
  VND: 13000,
  USD: 0.5,
  EUR: 0.5,
  KRW: 650,
  CNY: 4,
  THB: 18,
  IDR: 8000,
};

/** The guidance minimum for a currency, or undefined when we have no figure. */
export function stripeMinimumFor(currency?: string | null): number | undefined {
  if (!currency) return undefined;
  return STRIPE_MIN_CHARGE[currency.toUpperCase()];
}

/**
 * True only when we KNOW the amount is under the card minimum. Unknown
 * currency, missing amount or a non-finite figure all answer false — see the
 * one-directional rule in the module comment.
 */
export function isBelowStripeMinimum(
  amount: number | null | undefined,
  currency?: string | null,
): boolean {
  const min = stripeMinimumFor(currency);
  if (min === undefined) return false;
  if (typeof amount !== "number" || !Number.isFinite(amount)) return false;
  return amount < min;
}
