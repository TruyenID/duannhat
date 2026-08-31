import { describe, it, test } from "node:test";
import assert from "node:assert/strict";

import { shouldClearCartOnBranchChange } from "./cart-branch-guard.ts";
import { branches } from "../data/brands.ts";

// ---------------------------------------------------------------------------
// plan-002 audit — Brand Switcher / Static Multi-Store Navigation.
//
// `shouldClearCartOnBranchChange` is the ONE pure predicate that
// `CartProvider` runs on EVERY branch resolution, so it centrally governs all
// setCurrentBranch / switchBranch navigation paths documented in
// cart-branch-guard.ts:
//   - BrandSwitcherSheet confirmation
//   - /select-branch handlePick
//   - /takeaway/[shop] mount effect
//   - /stores/[slug] mount effect
//   - deep links / back-forward navigation
//
// cart-branch-guard.test.ts already locks the six primary rows. The cases
// below close the *edge* paths that a real navigation event can still produce
// — transient/blank slugs on the OTHER side, non-takeaway order types beyond
// dine_in, count boundaries, and case/whitespace-sensitive slug identity —
// the exact inputs an unlucky nav sequence or a future order type can smuggle
// in. All pure, no DOM.
// ---------------------------------------------------------------------------

describe("shouldClearCartOnBranchChange — nav-path edge cases", () => {
  it("does NOT clear when the PREVIOUS slug is transiently empty (mirror of the empty-next case)", () => {
    // Initial branches load resolves currentBranch.slug "" → real branch. A
    // stale-empty previous side must not be read as a branch-to-branch switch.
    assert.equal(
      shouldClearCartOnBranchChange({
        orderType: "takeaway",
        previousBranchSlug: "",
        nextBranchSlug: "sakura",
        itemCount: 2,
      }),
      false,
    );
  });

  it("does NOT clear when BOTH slugs are null (cold mount before any resolve)", () => {
    assert.equal(
      shouldClearCartOnBranchChange({
        orderType: "takeaway",
        previousBranchSlug: null,
        nextBranchSlug: null,
        itemCount: 5,
      }),
      false,
    );
  });

  it("does NOT clear on a negative itemCount (defensive: never a real cart, must not clear)", () => {
    assert.equal(
      shouldClearCartOnBranchChange({
        orderType: "takeaway",
        previousBranchSlug: "hongo",
        nextBranchSlug: "sakura",
        itemCount: -1,
      }),
      false,
    );
  });

  it("clears at the itemCount === 1 boundary (smallest non-empty takeaway cart)", () => {
    assert.equal(
      shouldClearCartOnBranchChange({
        orderType: "takeaway",
        previousBranchSlug: "hongo",
        nextBranchSlug: "sakura",
        itemCount: 1,
      }),
      true,
    );
  });

  it("does NOT clear for a non-takeaway order type other than dine_in (e.g. an unknown/future 'delivery' mode)", () => {
    // Guard is takeaway-ONLY (`orderType !== "takeaway"`). Any other value —
    // dine_in, delivery, or an empty/garbage string — must be a no-op so a new
    // order type can never trip the cross-branch clear before it is wired up.
    for (const orderType of ["delivery", "", "DINE_IN", "unknown"]) {
      assert.equal(
        shouldClearCartOnBranchChange({
          orderType,
          previousBranchSlug: "hongo",
          nextBranchSlug: "sakura",
          itemCount: 3,
        }),
        false,
        `expected no clear for orderType="${orderType}"`,
      );
    }
  });

  it("treats slug identity as case-sensitive — 'hongo' → 'Hongo' is a DIFFERENT branch and clears", () => {
    // Documents (locks) that comparison is a raw string !==, no normalisation.
    // Slugs are canonical/lowercase from the API, so a case difference is a
    // genuinely different key — carrying a cart across it would be wrong.
    assert.equal(
      shouldClearCartOnBranchChange({
        orderType: "takeaway",
        previousBranchSlug: "hongo",
        nextBranchSlug: "Hongo",
        itemCount: 2,
      }),
      true,
    );
  });

  it("treats a whitespace-padded slug as a DIFFERENT branch (no trimming) and clears", () => {
    assert.equal(
      shouldClearCartOnBranchChange({
        orderType: "takeaway",
        previousBranchSlug: "hongo",
        nextBranchSlug: " hongo",
        itemCount: 2,
      }),
      true,
    );
  });

  it("does NOT clear when an identical non-empty slug is re-resolved (idempotent nav, e.g. back/forward to same branch)", () => {
    // Repeated resolution of the SAME branch (refetch, remount, back-forward)
    // must be a no-op — the ref advances to the same value, cart survives.
    assert.equal(
      shouldClearCartOnBranchChange({
        orderType: "takeaway",
        previousBranchSlug: "sakura",
        nextBranchSlug: "sakura",
        itemCount: 4,
      }),
      false,
    );
  });
});

// ---------------------------------------------------------------------------
// branches static data — the multi-tenant invariants switchBranch /
// setCurrentBranch resolution depends on.
//
// switchBranch(slug) does `branches.find(b => b.slug === slug)` and no-ops if
// not found. That contract only holds if slugs are UNIQUE and non-empty — a
// duplicate slug would silently resolve to the first branch (wrong tenant), an
// empty slug would collide with the FALLBACK_BRANCH sentinel ("") and let the
// cross-branch guard mis-fire. These tests lock the data guarantees so a new
// seed row can't quietly break switch/currency/cart isolation.
// (Adapts the stale plan-002 unit scenario "every brand slug maps to valid
//  data" to the current API-backed `branches` model — brandMenus no longer
//  exists.)
// ---------------------------------------------------------------------------

describe("branches data integrity (multi-tenant isolation)", () => {
  it("has at least one branch", () => {
    assert.ok(branches.length >= 1);
  });

  it("every branch has a non-empty, string slug (empty '' collides with the FALLBACK_BRANCH sentinel)", () => {
    for (const b of branches) {
      assert.equal(typeof b.slug, "string", `slug not a string for ${b.id}`);
      assert.ok(b.slug.length > 0, `empty slug for branch ${b.id}`);
      assert.equal(b.slug, b.slug.trim(), `slug has surrounding whitespace: "${b.slug}"`);
    }
  });

  it("branch slugs are UNIQUE — switchBranch(slug) must resolve to exactly one branch", () => {
    const slugs = branches.map((b) => b.slug);
    const unique = new Set(slugs);
    assert.equal(
      unique.size,
      slugs.length,
      `duplicate slug(s): ${slugs.filter((s, i) => slugs.indexOf(s) !== i).join(", ")}`,
    );
  });

  it("branch ids are UNIQUE", () => {
    const ids = branches.map((b) => b.id);
    assert.equal(new Set(ids).size, ids.length, "duplicate branch id(s)");
  });

  it("every branch has a non-empty display name (rendered in Header + switcher rows)", () => {
    for (const b of branches) {
      assert.equal(typeof b.name, "string");
      assert.ok(b.name.trim().length > 0, `empty name for branch ${b.slug}`);
    }
  });

  it("every branch carries a brand with a non-empty slug (grouping/tenant key)", () => {
    for (const b of branches) {
      assert.ok(b.brand, `no brand on branch ${b.slug}`);
      assert.equal(typeof b.brand.slug, "string");
      assert.ok(b.brand.slug.length > 0, `empty brand.slug on branch ${b.slug}`);
    }
  });

  it("switchBranch-style lookup resolves each real slug to exactly that branch, and no-ops on an unknown slug", () => {
    // Mirrors brand-context.tsx switchBranch: branches.find(b => b.slug === slug).
    const resolve = (slug: string) => branches.filter((b) => b.slug === slug);
    for (const b of branches) {
      const hits = resolve(b.slug);
      assert.equal(hits.length, 1, `slug "${b.slug}" resolved to ${hits.length} branches`);
      assert.equal(hits[0].id, b.id);
    }
    // Unknown slug → no match → switchBranch is a documented no-op.
    assert.equal(resolve("does-not-exist").length, 0);
  });
});

// A tiny sanity anchor so a mis-imported module surfaces as a failing test
// rather than a silent zero-assertion pass.
test("module wiring: predicate and data both import", () => {
  assert.equal(typeof shouldClearCartOnBranchChange, "function");
  assert.ok(Array.isArray(branches));
});
