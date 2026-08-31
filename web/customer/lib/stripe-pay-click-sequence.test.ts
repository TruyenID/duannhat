import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { describe, it } from "node:test";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * #2741 — every customer-web Pay path must keep Stripe's deferred sequence:
 *   card ref present → validate() → async work → confirm(clientSecret)
 * and must not confirm when validate failed or the Elements ref is gone.
 *
 * A missing null-check on `stripeCardRef.current?.validate()` lets the guest
 * mint a PaymentIntent (Dashboard Incomplete) and then hit `paymentFailed`
 * because `confirm` is also undefined — same leftover as ORD-2026-0237.
 */
const root = join(dirname(fileURLToPath(import.meta.url)), "..");

const CALLERS = [
  {
    name: "dine-in payment-view",
    file: "app/[locale]/dine-in/[shop]/table/[qrToken]/components/payment-view.tsx",
    handler: "async function handleConfirm()",
    intent: "full-payment-intent",
    alsoSplit: true,
    couponBetweenValidateAndIntent: true,
  },
  {
    name: "checkout desktop",
    file: "components/checkout-page.tsx",
    handler: 'if (payment === "card")',
    intent: "full-payment-intent",
    alsoSplit: false,
    couponBetweenValidateAndIntent: false,
  },
  {
    name: "checkout mobile",
    file: "components/checkout-page-mobile.tsx",
    handler: 'if (paymentMethod === "card")',
    intent: "full-payment-intent",
    alsoSplit: false,
    couponBetweenValidateAndIntent: false,
  },
  {
    name: "orders/[id]/pay",
    file: "app/[locale]/orders/[id]/pay/page.tsx",
    handler: "const handlePay = async ()",
    intent: "full-payment-intent",
    alsoSplit: false,
    couponBetweenValidateAndIntent: false,
  },
] as const;

function sliceHandler(src: string, marker: string): string {
  const start = src.indexOf(marker);
  assert.ok(start >= 0, `handler marker missing: ${marker}`);
  // Take a generous window — confirm() sits after order/coupon/intent work.
  return src.slice(start, start + 12_000);
}

describe("Stripe Pay-click caller sequence", () => {
  for (const caller of CALLERS) {
    const src = readFileSync(join(root, caller.file), "utf8");
    const body = sliceHandler(src, caller.handler);

    it(`${caller.name}: refuses to mint a PI when the card ref is null`, () => {
      // Must bail before validate()/intent when Elements is gone (remount,
      // PayPay tab, async-methods key flip). Optional chaining alone is not
      // enough: `await ref.current?.validate()` is `undefined`, which is not
      // an error, so the caller would POST full-payment-intent anyway.
      assert.match(
        body,
        /if\s*\(\s*!stripeCardRef\.current\s*\)/,
        `${caller.name} must check stripeCardRef.current before validate()`,
      );
    });

    it(`${caller.name}: validate() before ${caller.intent}`, () => {
      const validateAt = body.search(/stripeCardRef\.current(?:\?)?\.validate\(\)/);
      const intentAt = body.indexOf(caller.intent);
      assert.ok(validateAt >= 0, `${caller.name} never calls validate()`);
      assert.ok(intentAt >= 0, `${caller.name} never posts ${caller.intent}`);
      assert.ok(
        validateAt < intentAt,
        `${caller.name} posts the PaymentIntent before validate()`,
      );
    });

    it(`${caller.name}: confirm() after the intent, with that client_secret`, () => {
      const intentAt = body.indexOf(caller.intent);
      const confirmAt = body.search(/stripeCardRef\.current(?:\?)?\.confirm\(/);
      assert.ok(confirmAt > intentAt, `${caller.name} confirms before the PI exists`);
      assert.match(body, /\.confirm\(\s*\n?\s*intentRes\.data\.client_secret/);
    });

    it(`${caller.name}: validate error returns before confirm()`, () => {
      const validateAt = body.search(/stripeCardRef\.current(?:\?)?\.validate\(\)/);
      const afterValidate = body.slice(validateAt, validateAt + 800);
      assert.match(
        afterValidate,
        /if\s*\([^)]*[Ee]rror[^)]*\)\s*\{[\s\S]*?return;/,
        `${caller.name} must return on validate error before creating a PI`,
      );
    });

    if (caller.alsoSplit) {
      it(`${caller.name}: split uses split-payment-intent, not a second submit()`, () => {
        assert.match(body, /split-payment-intent/);
        assert.equal(
          (body.match(/elementsInst\.submit\(\)/g) ?? []).length,
          0,
          "callers must not submit() themselves — StripeCardSection owns it",
        );
      });
    }

    if (caller.couponBetweenValidateAndIntent) {
      it(`${caller.name}: coupon apply sits between validate() and the PI (preview amount already on Elements)`, () => {
        const validateAt = body.search(/stripeCardRef\.current(?:\?)?\.validate\(\)/);
        const couponAt = body.indexOf("applyCouponIfPending()");
        const intentAt = body.indexOf(caller.intent);
        assert.ok(couponAt > validateAt && couponAt < intentAt);
      });
    }
  }

  it("Elements remount key is showMethodTabs — a flip mid-click wipes submittedRef", () => {
    const src = readFileSync(join(root, "components/stripe-card-section.tsx"), "utf8");
    assert.match(src, /key=\{showMethodTabs \? "automatic" : "card"\}/);
  });
});
