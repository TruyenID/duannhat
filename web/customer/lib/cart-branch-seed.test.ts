import { describe, it } from "node:test";
import assert from "node:assert/strict";

import { resolveSeededBranchSlug } from "./cart-branch-seed.ts";

describe("resolveSeededBranchSlug (cross-branch guard cold-load seed)", () => {
  it("REGRESSION: seeds from the durable takeaway-branch key when cart metadata is absent", () => {
    // The bug: menus without a cart deadline never persist CART_METADATA_KEY,
    // so the old seed (metadata-only) was null and the guard failed to fire
    // after a reload → cross-branch cart survived. The durable branch key must
    // still seed the guard.
    assert.equal(
      resolveSeededBranchSlug({
        persistedTakeawayBranchSlug: "hongo",
        cartMetadataBranchSlug: null,
      }),
      "hongo",
    );
  });

  it("falls back to the cart-metadata slug for carts persisted before the durable key existed", () => {
    assert.equal(
      resolveSeededBranchSlug({
        persistedTakeawayBranchSlug: null,
        cartMetadataBranchSlug: "sakura",
      }),
      "sakura",
    );
  });

  it("prefers the durable takeaway-branch key over the metadata slug", () => {
    assert.equal(
      resolveSeededBranchSlug({
        persistedTakeawayBranchSlug: "viet-kitchen",
        cartMetadataBranchSlug: "hongo",
      }),
      "viet-kitchen",
    );
  });

  it("returns null when neither source is present (no cart to protect)", () => {
    assert.equal(
      resolveSeededBranchSlug({
        persistedTakeawayBranchSlug: null,
        cartMetadataBranchSlug: null,
      }),
      null,
    );
  });

  it("treats an empty-string slug as absent", () => {
    assert.equal(
      resolveSeededBranchSlug({
        persistedTakeawayBranchSlug: "",
        cartMetadataBranchSlug: "",
      }),
      null,
    );
  });
});
