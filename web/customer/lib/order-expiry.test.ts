import { test } from "node:test";
import assert from "node:assert/strict";

import {
  isServerConfirmedExpired,
  computeSecondsLeft,
  isOrderStillPayable,
  PAYABLE_ORDER_STATUSES,
} from "./order-expiry.ts";

// ---------------------------------------------------------------------------
// plan-031 — expiry must be reconciled with the SERVER before the QR is
// hidden or the guest-order pointer is dropped from localStorage. A device
// with a fast-skewed clock must never be able to strand a still-payable
// order. These lock the two guards that make that true.
// ---------------------------------------------------------------------------

test("isServerConfirmedExpired: null/undefined order is never expired", () => {
  assert.equal(isServerConfirmedExpired(null), false);
  assert.equal(isServerConfirmedExpired(undefined), false);
});

test("isServerConfirmedExpired: cancelled status is authoritative", () => {
  assert.equal(isServerConfirmedExpired({ status: "cancelled" }), true);
});

test("isServerConfirmedExpired: expired status is authoritative", () => {
  // Server auto-expiry job set status=expired → order is no longer payable,
  // action must swap to Đặt lại even before is_payment_overdue is read.
  assert.equal(isServerConfirmedExpired({ status: "expired" }), true);
});

test("isServerConfirmedExpired: server is_payment_overdue flag drives expiry", () => {
  assert.equal(isServerConfirmedExpired({ is_payment_overdue: true }), true);
});

test("isServerConfirmedExpired: still-open order is NOT expired even with a past-looking due time", () => {
  // The order is still payable per the server; a client-clock reading of the
  // deadline must NOT flip this to expired.
  assert.equal(
    isServerConfirmedExpired({
      status: "open",
      is_payment_overdue: false,
      payment_due_at: "2000-01-01T00:00:00.000Z", // long past in absolute terms
    }),
    false,
  );
});

test("computeSecondsLeft: server delta is immune to a fast-skewed client clock", () => {
  // Server said 600s remain, captured 5s (client-measured) ago. Even though
  // the client's absolute clock is 30 min ahead of the world, the answer is a
  // pure delta: 600 - 5 = 595 — the order is clearly still payable.
  const anchoredAtMs = 10_000_000;
  const nowMs = anchoredAtMs + 5_000; // 5s of client-measured elapse
  assert.equal(
    computeSecondsLeft({ secondsUntilDue: 600, anchoredAtMs, nowMs }),
    595,
  );
});

test("computeSecondsLeft: server delta prevents premature 0 that legacy math produced", () => {
  // Reproduces the bug: client clock is 20 min fast, so payment_due_at is
  // already in the client past → legacy `dueMs - now` yields 0 (would trigger
  // remove/hide). The server delta path keeps ~10 min on the clock instead.
  const clockSkewMs = 20 * 60 * 1000; // client is 20 min ahead
  const serverNowMs = 1_000_000_000_000;
  const clientNowMs = serverNowMs + clockSkewMs;
  const paymentDueAt = new Date(serverNowMs + 10 * 60 * 1000).toISOString();

  // Legacy client-clock-only path → prematurely expired.
  assert.equal(
    computeSecondsLeft({ paymentDueAt, anchoredAtMs: clientNowMs, nowMs: clientNowMs }),
    0,
  );

  // Server-anchored path → still ~600s left, not stranded.
  assert.equal(
    computeSecondsLeft({
      secondsUntilDue: 600,
      anchoredAtMs: clientNowMs,
      nowMs: clientNowMs,
    }),
    600,
  );
});

test("computeSecondsLeft: clamps at 0 and never goes negative", () => {
  assert.equal(
    computeSecondsLeft({ secondsUntilDue: 3, anchoredAtMs: 0, nowMs: 60_000 }),
    0,
  );
});

test("computeSecondsLeft: falls back to payment_due_at when no server delta", () => {
  const nowMs = 1_000_000;
  const paymentDueAt = new Date(nowMs + 120_000).toISOString();
  assert.equal(
    computeSecondsLeft({ paymentDueAt, anchoredAtMs: nowMs, nowMs }),
    120,
  );
});

// ---------------------------------------------------------------------------
// plan-054 T6.10 — /orders/[id] and /orders/[id]/pay each carried their own
// copy of this gate, with a comment telling the next reader to keep them in
// sync. They had drifted: the Pay-now CTA was missing `confirmed` and
// `checkout`, so an order that WAS payable rendered no Pay button. One
// predicate, one set of tests.
// ---------------------------------------------------------------------------

test("isOrderStillPayable: every payable status is accepted", () => {
  for (const status of PAYABLE_ORDER_STATUSES) {
    assert.equal(
      isOrderStillPayable({ status, is_fully_paid: false, order_type: "takeaway" }),
      true,
      status,
    );
  }
});

test("isOrderStillPayable: `confirmed` and `checkout` are in the list", () => {
  // The two the detail page's CTA used to be missing — this is the regression.
  assert.ok((PAYABLE_ORDER_STATUSES as readonly string[]).includes("confirmed"));
  assert.ok((PAYABLE_ORDER_STATUSES as readonly string[]).includes("checkout"));
});

test("isOrderStillPayable: a settled or terminal order is never payable", () => {
  assert.equal(
    isOrderStillPayable({ status: "open", is_fully_paid: true, order_type: "takeaway" }),
    false,
  );
  assert.equal(
    isOrderStillPayable({ status: "cancelled", is_fully_paid: false, order_type: "takeaway" }),
    false,
  );
  assert.equal(
    isOrderStillPayable({ status: "closed", is_fully_paid: false, order_type: "takeaway" }),
    false,
  );
});

test("isOrderStillPayable: dine-in settles on its own surface, not here", () => {
  assert.equal(
    isOrderStillPayable({ status: "open", is_fully_paid: false, order_type: "dine_in" }),
    false,
  );
});
