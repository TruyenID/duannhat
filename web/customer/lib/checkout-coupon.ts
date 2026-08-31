/**
 * #1763 — the coupon field at checkout has two values that are easy to confuse:
 * what the customer has TYPED (`input`) and what was last sent to the preview
 * API by pressing Apply (`applied`). Every bug in that issue came from letting
 * those two drift apart while some piece of UI kept answering for the old one:
 *
 *  - the "press Apply" prompt was stored twice (a boolean beside the generic
 *    `orderError` string), so clearing one revealed the other and the customer
 *    was told to press Apply immediately AFTER pressing it;
 *  - the green "coupon applied −¥306" badge and its discount survived the
 *    customer typing a different code or emptying the field entirely, so the
 *    screen showed a discount for a code that was no longer there.
 *
 * The functions here are the single place that decides what the pair means, so
 * the answer cannot differ between the desktop and the mobile build of the same
 * screen — that divergence is itself one of the three bugs in the issue.
 */

/**
 * Canonical form of a typed coupon code. The inputs already uppercase on
 * change, but a code auto-filled from the cart (persisted at login) has not
 * been through that, so comparisons must normalise rather than assume.
 */
export function normalizeCouponCode(raw: string): string {
  return raw.trim().toUpperCase();
}

/**
 * The field holds a code that has not been applied. This is the ONE predicate
 * that both blocks submit and drives the "press Apply" prompt — deriving the
 * prompt from the same test that blocks the button is what makes it impossible
 * for the prompt to outlive the action it asks for.
 */
export function hasUnappliedCouponEdit(input: string, applied: string): boolean {
  const typed = normalizeCouponCode(input);

  return typed.length > 0 && typed !== normalizeCouponCode(applied);
}

/**
 * An applied coupon is in force only while the field still shows it. Emptying
 * the field or typing over it withdraws the coupon — from the badge, from the
 * displayed total, and from the order payload alike, so the customer is never
 * shown a discount that would not be charged (or vice versa).
 */
export function isAppliedCouponInForce(input: string, applied: string): boolean {
  const code = normalizeCouponCode(applied);

  return code.length > 0 && normalizeCouponCode(input) === code;
}

/**
 * The preview result describes `applied`, so it is stale the instant the field
 * diverges. Callers read the return value instead of the raw state — for the
 * badge, the error block, the discount and the order body — which keeps the
 * money shown and the money charged on the same side of this rule.
 */
export function activeCouponPreview<T>(
  preview: T | null | undefined,
  input: string,
  applied: string,
): T | null {
  return isAppliedCouponInForce(input, applied) ? (preview ?? null) : null;
}

/**
 * Whether to show "press Apply to use this code".
 *
 * Two ways in, one message. Before anything has been applied it waits for a
 * blocked submit, so a half-typed code is not nagged at on the first keystroke.
 * Once a code IS applied, any divergence prompts at once — that is the moment
 * the badge above it disappears, and saying nothing there would read as the
 * discount having been lost for no reason.
 */
export function shouldPromptCouponApply(args: {
  input: string;
  applied: string;
  submitAttempted: boolean;
}): boolean {
  if (!hasUnappliedCouponEdit(args.input, args.applied)) return false;

  return args.submitAttempted || normalizeCouponCode(args.applied).length > 0;
}
