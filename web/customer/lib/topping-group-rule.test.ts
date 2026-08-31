import { test } from "node:test";
import assert from "node:assert/strict";

import {
  describeToppingGroupRule,
  toppingGroupShortfall,
  type ToppingGroupRuleInput,
} from "./topping-group-rule.ts";

// ---------------------------------------------------------------------------
// #1429 — the product modal printed one badge off `min_select` alone, so a
// `min=1, max=3` group told the customer "Chọn 1" and an optional group told
// them nothing at all. These cases pin what each shape must now say.
// ---------------------------------------------------------------------------

function group(
  over: Partial<ToppingGroupRuleInput> & { itemCount?: number } = {},
): ToppingGroupRuleInput {
  const { itemCount = 6, ...rest } = over;
  return {
    min_select: 0,
    max_select: null,
    max_qty_per_item: 1,
    items: Array.from({ length: itemCount }, (_, i) => i),
    ...rest,
  };
}

test("required exact-one — the ジュース group in the report", () => {
  const rule = describeToppingGroupRule(group({ min_select: 1, max_select: 1 }));
  assert.deepEqual(rule, { required: true, kind: "exact", count: 1, perItem: undefined });
});

test("required with a real range no longer under-reports as 'choose 1'", () => {
  const rule = describeToppingGroupRule(group({ min_select: 1, max_select: 3 }));
  assert.equal(rule.kind, "range");
  assert.equal(rule.min, 1);
  assert.equal(rule.max, 3);
});

test("required with no meaningful cap → at least", () => {
  const rule = describeToppingGroupRule(group({ min_select: 2, max_select: null }));
  assert.equal(rule.kind, "atLeast");
  assert.equal(rule.min, 2);
});

test("optional and uncapped is still announced — it used to render nothing", () => {
  const rule = describeToppingGroupRule(group({ min_select: 0, max_select: null }));
  assert.deepEqual(rule, { required: false, kind: "any", perItem: undefined });
});

test("optional with a real cap → up to", () => {
  const rule = describeToppingGroupRule(group({ min_select: 0, max_select: 3, itemCount: 6 }));
  assert.equal(rule.kind, "upTo");
  assert.equal(rule.max, 3);
});

test("a cap that cannot bite is not announced (kept from the old badge)", () => {
  // max === item count: taking everything still never trips it.
  assert.equal(describeToppingGroupRule(group({ max_select: 3, itemCount: 3 })).kind, "any");
  // max above the item count: same.
  assert.equal(describeToppingGroupRule(group({ max_select: 9, itemCount: 3 })).kind, "any");
  // …and it collapses a required group to "at least", not a fake range.
  assert.equal(
    describeToppingGroupRule(group({ min_select: 1, max_select: 3, itemCount: 3 })).kind,
    "atLeast",
  );
});

test("per-item stacking is surfaced only when it actually stacks", () => {
  assert.equal(describeToppingGroupRule(group({ max_qty_per_item: 2 })).perItem, 2);
  assert.equal(describeToppingGroupRule(group({ max_qty_per_item: 1 })).perItem, undefined);
  assert.equal(describeToppingGroupRule(group({ max_qty_per_item: 0 })).perItem, undefined);
  assert.equal(describeToppingGroupRule(group({})).perItem, undefined);
});

test("min === max reads as exact, not as a one-wide range", () => {
  const rule = describeToppingGroupRule(group({ min_select: 2, max_select: 2 }));
  assert.equal(rule.kind, "exact");
  assert.equal(rule.count, 2);
});

test("a contradictory catalogue (max < min) never prints an impossible range", () => {
  const rule = describeToppingGroupRule(group({ min_select: 3, max_select: 1 }));
  assert.equal(rule.kind, "exact");
  assert.equal(rule.count, 1);
});

test("junk numbers are clamped instead of reaching the customer", () => {
  assert.equal(describeToppingGroupRule(group({ min_select: -2 })).required, false);
  const fractional = describeToppingGroupRule(group({ min_select: 1.7, max_select: 1 }));
  assert.equal(fractional.kind, "exact");
  assert.equal(fractional.count, 1);
});

test("shortfall counts what is still missing, never below zero", () => {
  const required = group({ min_select: 2, max_select: 3 });
  assert.equal(toppingGroupShortfall(required, 0), 2);
  assert.equal(toppingGroupShortfall(required, 1), 1);
  assert.equal(toppingGroupShortfall(required, 2), 0);
  assert.equal(toppingGroupShortfall(required, 5), 0);
  assert.equal(toppingGroupShortfall(group({ min_select: 0 }), 0), 0);
});
