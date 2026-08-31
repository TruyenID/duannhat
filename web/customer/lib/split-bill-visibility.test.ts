import { test } from "node:test";
import assert from "node:assert/strict";

import { shouldShowSplitBillCard } from "./split-bill-visibility.ts";

const base = {
  orderId: "ord_1",
  isFullyPaidUI: false,
  isExpired: false,
} as const;

test("shows for a dine-in counter-pay order", () => {
  assert.equal(
    shouldShowSplitBillCard({
      ...base,
      order: { order_type: "dine_in", is_counter_pay: true },
    }),
    true,
  );
});

test("HIDES for a takeaway counter-pay order (plan-039 dine-in guard)", () => {
  // Regression: before the guard, a takeaway counter order rendered the
  // "Chia hóa đơn?" card and could persist split_mode on the wrong row.
  assert.equal(
    shouldShowSplitBillCard({
      ...base,
      order: { order_type: "takeaway", is_counter_pay: true },
    }),
    false,
  );
});

test("HIDES for a spot counter-pay order", () => {
  assert.equal(
    shouldShowSplitBillCard({
      ...base,
      order: { order_type: "spot", is_counter_pay: true },
    }),
    false,
  );
});

test("HIDES when order_type is missing", () => {
  assert.equal(
    shouldShowSplitBillCard({
      ...base,
      order: { is_counter_pay: true },
    }),
    false,
  );
});

test("HIDES for a non-counter-pay dine-in order", () => {
  assert.equal(
    shouldShowSplitBillCard({
      ...base,
      order: { order_type: "dine_in", is_counter_pay: false },
    }),
    false,
  );
});

test("HIDES when fully paid, expired, or missing order id", () => {
  const order = { order_type: "dine_in", is_counter_pay: true };
  assert.equal(
    shouldShowSplitBillCard({ ...base, order, isFullyPaidUI: true }),
    false,
  );
  assert.equal(
    shouldShowSplitBillCard({ ...base, order, isExpired: true }),
    false,
  );
  assert.equal(
    shouldShowSplitBillCard({ ...base, order, orderId: null }),
    false,
  );
});

test("HIDES for null / undefined order", () => {
  assert.equal(shouldShowSplitBillCard({ ...base, order: null }), false);
  assert.equal(shouldShowSplitBillCard({ ...base, order: undefined }), false);
});
