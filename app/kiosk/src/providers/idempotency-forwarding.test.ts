import { existsSync, readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

/**
 * #19 — the MIDDLE link of the duplicate-charge guard.
 *
 * The chain that stops a network retry charging a customer twice has three
 * links:
 *
 *   1. app/payment-method.tsx mints a key   — pinned by app/payment-method.test.tsx
 *   2. the payment screen carries it to submit()   — THIS FILE
 *   3. usePayment turns it into a header    — pinned by src/hooks/use-payment.test.ts
 *
 * Link 2 was the only one with nothing watching it, and it is the one that
 * breaks by omission: a screen that simply forgets the field still compiles,
 * still renders, still takes the money. The request just goes out without an
 * Idempotency-Key and the backend stops de-duplicating it
 * (OrderPaymentService skips dedupe when the key is null).
 *
 * Rendering four expo-router screens to assert one argument would mean mocking
 * the router, the UI kit and the payment hook — a lot of scaffolding whose own
 * correctness then needs trusting. The invariant is a source-level one, so it
 * is asserted at source level, the same way payment-option-utils.test.ts pins
 * "no screen hardcodes a payment-method list".
 */

const APP_DIR = join(import.meta.dirname, "..", "..", "app");

/** Every screen that POSTs a payment. Add to this list when a fifth appears. */
const PAYMENT_SCREENS = ["card.tsx", "qr.tsx", "emoney.tsx", "cash.tsx"];

function readScreen(name: string): string {
  const path = join(APP_DIR, "payment", name);
  expect(existsSync(path)).toBe(true);

  return readFileSync(path, "utf8");
}

describe("payment screens forward the idempotency key", () => {
  it.each(PAYMENT_SCREENS)("%s passes a key to submit()", (screen) => {
    const source = readScreen(screen);

    expect(source).toContain("idempotency_key:");
    expect(source).toContain("currentIdempotencyKey");
  });

  it.each(PAYMENT_SCREENS)("%s takes the key from payment-flow state, not from itself", (screen) => {
    const source = readScreen(screen);

    // A screen minting its own key would defeat the whole point: retries of the
    // SAME attempt must reuse one key, and only the flow provider knows when an
    // attempt begins.
    expect(source).not.toContain("generateIdempotencyKey");
    expect(source).not.toContain("newAttempt");
  });

  it("no screen rebuilds the null-to-undefined conversion by hand", () => {
    // #20 — five copies of `paymentFlowState.idempotencyKey ?? undefined` used
    // to sit here. The conversion now lives on the provider precisely so a new
    // screen cannot get it subtly wrong, e.g. sending the string "null".
    for (const screen of PAYMENT_SCREENS) {
      expect(readScreen(screen)).not.toContain("idempotencyKey ?? undefined");
    }
  });

  it("every payment screen is covered by this file", () => {
    // Guards the list above: a fifth screen added under app/payment/ must be
    // added here too, or this test names it.
    const actual = readdirSync(join(APP_DIR, "payment"))
      .filter((f) => f.endsWith(".tsx") && f !== "_layout.tsx")
      .sort();

    expect(actual).toEqual([...PAYMENT_SCREENS].sort());
  });
});
