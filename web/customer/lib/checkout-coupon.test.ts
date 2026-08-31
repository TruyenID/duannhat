import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { describe, it } from "node:test";

import {
  activeCouponPreview,
  hasUnappliedCouponEdit,
  isAppliedCouponInForce,
  normalizeCouponCode,
  shouldPromptCouponApply,
} from "./checkout-coupon.ts";

const VALID = { data: { is_valid: true, discount_applied_amount: 306 } };

describe("#1763 — the prompt cannot outlive the action it asks for", () => {
  // The reported bug, step for step: type a code, press Pay, get told to press
  // Apply, press Apply — and be told again that you have not.
  it("stops asking once Apply has been pressed", () => {
    const typed = "UITEST10";

    assert.equal(
      shouldPromptCouponApply({ input: typed, applied: "", submitAttempted: true }),
      true,
      "blocked submit must explain why",
    );

    // Pressing Apply is exactly `applied := normalize(input)`.
    assert.equal(
      shouldPromptCouponApply({
        input: typed,
        applied: normalizeCouponCode(typed),
        submitAttempted: true,
      }),
      false,
      "the prompt must not survive the press it asked for",
    );
  });

  it("does not nag before the customer has tried to submit", () => {
    assert.equal(
      shouldPromptCouponApply({ input: "UITEST10", applied: "", submitAttempted: false }),
      false,
    );
  });

  it("prompts immediately once a code is applied and then edited", () => {
    // The green badge disappears at this moment (see below); saying nothing
    // would read as the discount having vanished for no reason.
    assert.equal(
      shouldPromptCouponApply({
        input: "OTHER20",
        applied: "UITEST10",
        submitAttempted: false,
      }),
      true,
    );
  });

  it("treats a case-only difference as already applied", () => {
    assert.equal(hasUnappliedCouponEdit("uitest10", "UITEST10"), false);
    assert.equal(hasUnappliedCouponEdit("  UITEST10 ", "UITEST10"), false);
    assert.equal(hasUnappliedCouponEdit("UITEST11", "UITEST10"), true);
  });

  it("an empty field is not an unapplied edit — it is a removal", () => {
    assert.equal(hasUnappliedCouponEdit("", "UITEST10"), false);
    assert.equal(hasUnappliedCouponEdit("   ", "UITEST10"), false);
  });
});

describe("#1763 — an applied coupon lasts only while the field shows it", () => {
  it("keeps the result while the field still holds the applied code", () => {
    assert.equal(isAppliedCouponInForce("UITEST10", "UITEST10"), true);
    assert.deepEqual(activeCouponPreview(VALID, "UITEST10", "UITEST10"), VALID);
  });

  it("withdraws the result when the customer types a different code", () => {
    assert.equal(isAppliedCouponInForce("OTHER20", "UITEST10"), false);
    assert.equal(activeCouponPreview(VALID, "OTHER20", "UITEST10"), null);
  });

  // The reported bug: clearing the field left the green badge, the −￥306 and
  // the reduced total standing, and the order still went out with the coupon.
  it("withdraws the result when the customer empties the field", () => {
    assert.equal(activeCouponPreview(VALID, "", "UITEST10"), null);
    assert.equal(activeCouponPreview(VALID, "   ", "UITEST10"), null);
  });

  it("has nothing in force when no code was ever applied", () => {
    assert.equal(isAppliedCouponInForce("", ""), false);
    assert.equal(activeCouponPreview(VALID, "", ""), null);
  });
});

/**
 * Source-level guards. `/checkout` is built twice — `checkout-page.tsx` for
 * desktop and `checkout-page-mobile.tsx` for mobile, picked by `isMobile` —
 * and every bug in #1763 was one build disagreeing with the other about the
 * same cart. Unit tests above cannot see that divergence, so these read the
 * two files and assert the shapes that made the disagreement possible.
 */
describe("#1763 — the two builds of /checkout must not drift apart", () => {
  const sources = {
    desktop: readFileSync(new URL("../components/checkout-page.tsx", import.meta.url), "utf8"),
    mobile: readFileSync(new URL("../components/checkout-page-mobile.tsx", import.meta.url), "utf8"),
  };

  // Bug 3: mobile counted CART LINES where every other surface in the app
  // (desktop checkout, review page, order confirm, cart drawer) counts
  // QUANTITY — one line of three showed "1 món" beside a visible "x3".
  it("counts quantity, not cart lines, in the subtotal label", () => {
    for (const [build, src] of Object.entries(sources)) {
      assert.match(
        src,
        /subtotalInline',\s*\{\s*count:\s*totalItems\s*\}/,
        `${build}: subtotalInline must be given totalItems`,
      );
      assert.doesNotMatch(
        src,
        /subtotalInline',\s*\{\s*count:\s*items\.length\s*\}/,
        `${build}: subtotalInline must not count cart lines`,
      );
    }
  });

  // Bug 1: the same sentence was stored twice — a boolean beside the generic
  // `orderError` string — so clearing one revealed the other.
  it("never routes the unapplied-coupon prompt through orderError", () => {
    for (const [build, src] of Object.entries(sources)) {
      assert.doesNotMatch(
        src,
        /setOrderError\(\s*t\('couponUnappliedWarning'\)/,
        `${build}: the prompt belongs under the coupon field, not in the error box`,
      );
    }
  });

  // Bug 2: the preview answers for the APPLIED code, so every consumer — the
  // badge, the discount, the order body — must read it through the guard.
  it("reads the coupon preview through the in-force guard everywhere", () => {
    for (const [build, src] of Object.entries(sources)) {
      // Reads of the raw state, i.e. `couponPreview.` / `couponPreview?.` —
      // the declaration `useState`, the `setCouponPreview` writes and the
      // guard's own argument do not match. Exactly one is legitimate: mobile
      // persists a valid code to sessionStorage. Every other read is UI or an
      // order payload and must go through `livePreview`, so this is pinned
      // tight — one stray read is the whole of bug 2 coming back.
      const raw = src.match(/couponPreview[?.]/g) ?? [];
      const allowed = build === "mobile" ? 1 : 0;
      assert.ok(
        raw.length <= allowed,
        `${build}: ${raw.length} raw couponPreview reads (max ${allowed}) — render/payload must use livePreview`,
      );
      assert.match(
        src,
        /const livePreview = activeCouponPreview\(couponPreview, couponCode, couponDebounced\)/,
        `${build}: missing the in-force guard`,
      );
    }
  });
});
