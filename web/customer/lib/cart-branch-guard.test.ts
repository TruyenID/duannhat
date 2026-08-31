import { describe, it } from "node:test";
import assert from "node:assert/strict";

import { shouldClearCartOnBranchChange } from "./cart-branch-guard.ts";

describe("shouldClearCartOnBranchChange", () => {
  it("clears when a non-empty takeaway cart switches to a different branch", () => {
    assert.equal(
      shouldClearCartOnBranchChange({
        orderType: "takeaway",
        previousBranchSlug: "hongo",
        nextBranchSlug: "sakura",
        itemCount: 2,
      }),
      true,
    );
  });

  it("does NOT clear when the branch is unchanged", () => {
    assert.equal(
      shouldClearCartOnBranchChange({
        orderType: "takeaway",
        previousBranchSlug: "hongo",
        nextBranchSlug: "hongo",
        itemCount: 3,
      }),
      false,
    );
  });

  it("does NOT clear when the takeaway cart is empty", () => {
    assert.equal(
      shouldClearCartOnBranchChange({
        orderType: "takeaway",
        previousBranchSlug: "hongo",
        nextBranchSlug: "sakura",
        itemCount: 0,
      }),
      false,
    );
  });

  it("does NOT clear on the initial resolve (no previous branch yet)", () => {
    assert.equal(
      shouldClearCartOnBranchChange({
        orderType: "takeaway",
        previousBranchSlug: null,
        nextBranchSlug: "hongo",
        itemCount: 2,
      }),
      false,
    );
  });

  it("does NOT clear when the next branch slug is transiently empty", () => {
    assert.equal(
      shouldClearCartOnBranchChange({
        orderType: "takeaway",
        previousBranchSlug: "hongo",
        nextBranchSlug: "",
        itemCount: 2,
      }),
      false,
    );
  });

  it("does NOT touch dine-in carts (already isolated per table)", () => {
    assert.equal(
      shouldClearCartOnBranchChange({
        orderType: "dine_in",
        previousBranchSlug: "hongo",
        nextBranchSlug: "sakura",
        itemCount: 2,
      }),
      false,
    );
  });
});
