import assert from "node:assert/strict";
import { beforeEach, describe, it } from "node:test";

import {
  __resetPaymentPolicyContext,
  asyncPaymentMethodsEnabled,
  paymentPolicyEcho,
  primePaymentPolicyContext,
  subscribePaymentPolicyContext,
  type PaymentPolicyContext,
} from "./payment-policy-context.ts";

const settle = () => new Promise((r) => setImmediate(r));

describe("payment-policy-context (plan-048 T2.5)", () => {
  beforeEach(() => {
    __resetPaymentPolicyContext();
  });

  it("echoes the primed identity for the slug", async () => {
    primePaymentPolicyContext("shop-a", async () => ({
      policy_revision: 7,
      gateway_option_id: "opt-1",
    }));
    await settle();

    assert.deepEqual(paymentPolicyEcho("shop-a"), {
      policy_revision: 7,
      gateway_option_id: "opt-1",
    });
  });

  it("echoes nothing before the prime lands, for unknown slugs, and for null slugs", () => {
    assert.deepEqual(paymentPolicyEcho("never-primed"), {});
    assert.deepEqual(paymentPolicyEcho(null), {});
    assert.deepEqual(paymentPolicyEcho(undefined), {});
  });

  it("drops null fields so legacy branches send no echo keys", async () => {
    primePaymentPolicyContext("legacy-shop", async () => ({
      policy_revision: null,
      gateway_option_id: null,
    }));
    await settle();

    assert.deepEqual(paymentPolicyEcho("legacy-shop"), {});
  });

  it("fails open on fetch errors and allows a retry", async () => {
    let calls = 0;
    const failing = async (): Promise<PaymentPolicyContext> => {
      calls += 1;
      throw new Error("network down");
    };
    primePaymentPolicyContext("flaky-shop", failing);
    await settle();
    assert.deepEqual(paymentPolicyEcho("flaky-shop"), {});

    // In-flight failure cleared → a later prime retries.
    primePaymentPolicyContext("flaky-shop", async () => ({
      policy_revision: 2,
      gateway_option_id: "opt-2",
    }));
    await settle();
    assert.equal(calls, 1);
    assert.deepEqual(paymentPolicyEcho("flaky-shop"), {
      policy_revision: 2,
      gateway_option_id: "opt-2",
    });
  });

  it("dedupes concurrent primes per slug", async () => {
    let calls = 0;
    const slow = async (): Promise<PaymentPolicyContext> => {
      calls += 1;
      await settle();
      return { policy_revision: 1, gateway_option_id: "opt-x" };
    };
    primePaymentPolicyContext("shop-b", slow);
    primePaymentPolicyContext("shop-b", slow);
    await settle();
    await settle();

    assert.equal(calls, 1);
  });

  it("#1125 asyncPaymentMethodsEnabled reflects the primed server flag", async () => {
    primePaymentPolicyContext("async-shop", async () => ({
      policy_revision: 1,
      gateway_option_id: "opt-1",
      async_payment_methods_enabled: true,
    }));
    await settle();
    assert.equal(asyncPaymentMethodsEnabled("async-shop"), true);
  });

  it("#1125 asyncPaymentMethodsEnabled fails CLOSED (card-only) when unprimed or flag absent", async () => {
    assert.equal(asyncPaymentMethodsEnabled("never-primed"), false);
    assert.equal(asyncPaymentMethodsEnabled(null), false);

    primePaymentPolicyContext("legacy-shop", async () => ({
      policy_revision: 3,
      gateway_option_id: "opt-3",
    }));
    await settle();
    assert.equal(asyncPaymentMethodsEnabled("legacy-shop"), false);
  });

  it("#1125 notifies subscribers when a prime lands, so a stale card-only read cannot stick", async () => {
    let notified = 0;
    const unsubscribe = subscribePaymentPolicyContext(() => {
      notified += 1;
    });

    // The read BEFORE the prime resolves is the one that used to be final.
    assert.equal(asyncPaymentMethodsEnabled("late-shop"), false);

    primePaymentPolicyContext("late-shop", async () => ({
      policy_revision: 4,
      gateway_option_id: "opt-4",
      async_payment_methods_enabled: true,
    }));
    await settle();

    assert.equal(notified, 1);
    assert.equal(asyncPaymentMethodsEnabled("late-shop"), true);

    unsubscribe();
    primePaymentPolicyContext("other-shop", async () => ({
      policy_revision: 5,
      gateway_option_id: "opt-5",
    }));
    await settle();
    assert.equal(notified, 1);
  });

  it("#1125 a failed prime notifies nobody — fail-open leaves the card-only read standing", async () => {
    let notified = 0;
    subscribePaymentPolicyContext(() => {
      notified += 1;
    });

    primePaymentPolicyContext("offline-shop", async () => {
      throw new Error("network down");
    });
    await settle();

    assert.equal(notified, 0);
    assert.equal(asyncPaymentMethodsEnabled("offline-shop"), false);
  });
});
