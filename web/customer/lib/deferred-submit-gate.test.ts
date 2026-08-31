import assert from "node:assert/strict";
import { describe, it } from "node:test";

import {
  createDeferredSubmitGate,
  runPayClick,
} from "./deferred-submit-gate.ts";

/**
 * #2741 — every Pay-click shape that left live Stripe intents Incomplete
 * (ORD-2026-0237 / ORD-2026-0244) or would do so on retry / 3DS / a caller
 * that skips validate().
 */
describe("deferred submit gate — one Pay click", () => {
  it("happy path: validate then confirmPayment → exactly one submit()", async () => {
    const gate = createDeferredSubmitGate();
    const click = await runPayClick({ gate, validate: "ok", confirmPayment: "succeeded" });
    assert.equal(click.submitCount, 1);
    assert.equal(click.confirmCalled, true);
    assert.equal(gate.shouldSubmitOnConfirm(), false);
  });

  it("validate error aborts before confirmPayment (no leftover PI confirm)", async () => {
    const gate = createDeferredSubmitGate();
    const click = await runPayClick({ gate, validate: "error" });
    assert.equal(click.submitCount, 1);
    assert.equal(click.confirmCalled, false);
    assert.equal(gate.shouldSubmitOnConfirm(), true);
  });

  it("confirm() without validate() still submits once (fallback)", async () => {
    const gate = createDeferredSubmitGate();
    const click = await runPayClick({
      gate,
      validate: "skip",
      fallbackSubmit: "ok",
      confirmPayment: "succeeded",
    });
    assert.equal(click.submitCount, 1);
    assert.equal(click.confirmCalled, true);
  });

  it("confirm() without validate() surfaces a fallback submit error", async () => {
    const gate = createDeferredSubmitGate();
    const click = await runPayClick({
      gate,
      validate: "skip",
      fallbackSubmit: "error",
    });
    assert.equal(click.submitCount, 1);
    assert.equal(click.confirmCalled, false);
    assert.equal(gate.shouldSubmitOnConfirm(), true);
  });
});

describe("deferred submit gate — retry / 3DS / double click", () => {
  it("confirmPayment error resets the gate so the next click submits again", async () => {
    const gate = createDeferredSubmitGate();
    const first = await runPayClick({ gate, confirmPayment: "error" });
    assert.equal(first.submitCount, 1);
    assert.equal(gate.shouldSubmitOnConfirm(), true);

    const second = await runPayClick({ gate, confirmPayment: "succeeded" });
    assert.equal(second.submitCount, 1, "retry must submit once more, not zero and not two");
    assert.equal(second.confirmCalled, true);
    assert.equal(gate.shouldSubmitOnConfirm(), false);
  });

  it("3DS / processing pending does NOT reset — confirm() must not submit again mid-click", async () => {
    const gate = createDeferredSubmitGate();
    const click = await runPayClick({ gate, confirmPayment: "pending" });
    assert.equal(click.submitCount, 1);
    assert.equal(gate.shouldSubmitOnConfirm(), false);
  });

  it("two successful clicks (guest pays, comes back) each submit once", async () => {
    const gate = createDeferredSubmitGate();
    const first = await runPayClick({ gate, confirmPayment: "succeeded" });
    // A remount (new Elements) is a new gate. Same-instance retry after success
    // is not a real caller path; validate() always submit()s on the next click
    // in StripeCardSection, which is modelled by a fresh gate:
    const next = createDeferredSubmitGate();
    const second = await runPayClick({ gate: next, confirmPayment: "succeeded" });
    assert.equal(first.submitCount, 1);
    assert.equal(second.submitCount, 1);
  });

  it("double validate() on one click still leaves confirm() with nothing to submit", () => {
    const gate = createDeferredSubmitGate();
    gate.onValidateOk();
    gate.onValidateOk();
    assert.equal(gate.shouldSubmitOnConfirm(), false);
  });

  it("validate error then a later confirm() (caller bug) falls back to submit", async () => {
    const gate = createDeferredSubmitGate();
    await runPayClick({ gate, validate: "error" });
    const stray = await runPayClick({
      gate,
      validate: "skip",
      fallbackSubmit: "ok",
      confirmPayment: "succeeded",
    });
    assert.equal(stray.submitCount, 1);
    assert.equal(stray.confirmCalled, true);
  });
});

describe("deferred submit gate — regression of the production bug", () => {
  it("must not submit a second time after a successful validate (ORD-2026-0237)", async () => {
    const gate = createDeferredSubmitGate();
    gate.onValidateOk();
    // Async gap: POST full-payment-intent (creates Incomplete PI).
    assert.equal(
      gate.shouldSubmitOnConfirm(),
      false,
      "confirm() after validate() must not call elements.submit() again",
    );
    const click = await runPayClick({
      gate,
      validate: "skip",
      confirmPayment: "succeeded",
    });
    assert.equal(click.submitCount, 0);
    assert.equal(click.confirmCalled, true);
  });

  it("five retries of the same Incomplete PI each submit once (ORD-2026-0244)", async () => {
    const gate = createDeferredSubmitGate();
    for (let i = 0; i < 5; i++) {
      const click = await runPayClick({ gate, confirmPayment: "error" });
      assert.equal(click.submitCount, 1, `retry ${i + 1} submitted ${click.submitCount} times`);
    }
  });
});
