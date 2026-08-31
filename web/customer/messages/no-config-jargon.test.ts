import assert from "node:assert/strict";
import { describe, it } from "node:test";

import en from "./en.json" with { type: "json" };
import ja from "./ja.json" with { type: "json" };
import vi from "./vi.json" with { type: "json" };

/**
 * Customer-facing copy must not name a configuration variable.
 *
 * `checkout.stripeNotConfigured` and `dineInPayment.stripeNotConfigured` read
 * "Stripe is not configured. Missing NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY." — a
 * sentence with two victims. The customer, who is trying to pay, can do nothing
 * with it. And whoever debugs it is sent to the wrong repo entirely: nothing in
 * customer-web has read a NEXT_PUBLIC_STRIPE_* variable since #815 moved the
 * publishable key to GET /api/v1/customer/stripe/config, so the fix has been
 * `STRIPE_KEY` in the BACKEND env all along. The string outlived the mechanism
 * it described and then actively misdirected the people it was written for
 * (#1703).
 *
 * Diagnostics belong in console.warn (see lib/stripe-config.ts), where an
 * operator finds them and a customer never does.
 */
const locales = { ja, en, vi } as const;

/**
 * SCREAMING_SNAKE tokens: `NEXT_PUBLIC_*` explicitly, plus any word of three or
 * more uppercase segments. Three, not two, so ordinary product copy survives —
 * "QR_CODE"-shaped brand names and initialisms like `PAY_PAY` are not what this
 * is hunting. It wants `STRIPE_WEBHOOK_SECRET`, `APP_URL_BASE`, and friends.
 */
const CONFIG_TOKEN =
  /\b(?:NEXT_PUBLIC_[A-Z0-9_]+|[A-Z][A-Z0-9]*(?:_[A-Z0-9]+){2,})\b/;

function entries(value: unknown, prefix = ""): Array<[string, string]> {
  if (typeof value === "string") return [[prefix, value]];
  if (value === null || typeof value !== "object" || Array.isArray(value)) return [];

  return Object.entries(value as Record<string, unknown>).flatMap(([key, child]) =>
    entries(child, prefix ? `${prefix}.${key}` : key)
  );
}

describe("customer-facing copy", () => {
  it("never names an environment variable", () => {
    const offenders: string[] = [];

    for (const [locale, messages] of Object.entries(locales)) {
      for (const [key, text] of entries(messages)) {
        const hit = CONFIG_TOKEN.exec(text);
        if (hit) offenders.push(`${locale}: ${key} — "${hit[0]}"`);
      }
    }

    assert.deepEqual(
      offenders,
      [],
      `Config variable names leaked into customer copy — move the detail to a ` +
        `console warning and tell the customer what to DO:\n  ${offenders.join("\n  ")}`
    );
  });

  it("offers the customer a way forward when card payment is off", () => {
    // The three strings shown when the publishable key is absent. Whatever they
    // say, they must not dead-end: a customer staring at a payment screen needs
    // an alternative, which in every locale means naming another method or the
    // shop. Asserting non-emptiness alone would have passed the old jargon too.
    const keys = [
      "checkout.stripeNotConfigured",
      "dineInPayment.stripeNotConfigured",
      "guestOrders.cardPaymentUnavailable",
    ];

    const dead: string[] = [];

    for (const [locale, messages] of Object.entries(locales)) {
      const flat = new Map(entries(messages));
      for (const key of keys) {
        const text = flat.get(key);
        if (!text) {
          dead.push(`${locale}: ${key} is missing`);
          continue;
        }
        // "another method" / "the shop" — in whichever language.
        const wayOut =
          /別のお支払い|店舗|another payment method|contact the shop|phương thức khác|liên hệ cửa hàng/.test(
            text
          );
        if (!wayOut) dead.push(`${locale}: ${key} dead-ends — "${text}"`);
      }
    }

    assert.deepEqual(dead, [], `Card-unavailable copy with no way forward:\n  ${dead.join("\n  ")}`);
  });
});
