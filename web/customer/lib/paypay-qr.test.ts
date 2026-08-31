import { test } from "node:test";
import assert from "node:assert/strict";

import {
  PAYPAY_DINE_IN_METHOD,
  PAYPAY_RADIO_VALUE,
  PAYPAY_STATUS_BACKOFF_MAX_MS,
  PAYPAY_STATUS_FAILURE_LIMIT,
  PAYPAY_STATUS_POLL_INTERVAL_MS,
  PAYPAY_STATUS_RATE_LIMITED_FLOOR_MS,
  canRefreshPayPayQr,
  canRunPayPayQrPanelInline,
  cancelPayPayQr,
  classifyPayPayAvailabilityAnswer,
  classifyPayPayCreateFailure,
  dineInOnlineSurface,
  formatPayPayCountdown,
  hasLostPayPayStatusContact,
  isAwaitingExpiryConfirmation,
  isPayPayQrRequested,
  isPayPayQrTerminal,
  isPayPayScreenSuperseded,
  nextCountdownAnchor,
  nextPayPayPollDelayMs,
  parsePayPayAvailability,
  parsePayPayQrSession,
  payPayPostOrderRoute,
  payPayQrCancelPath,
  payPayQrCreatePath,
  payPayQrPath,
  payPayQrPhase,
  payPayQrPresentation,
  payPayQrSecondsLeft,
  payPayQrStatusPath,
  payPaySplitPayload,
  paymentContextPath,
  readPayPayQrStatus,
  resolveDineInOnlineGateway,
  resolvePayPayQrPhase,
  shouldOfferPayPayRefresh,
  shouldPollPayPayStatus,
  shouldWatchOrphanedPayPayQr,
  shouldShowPayMethodChooser,
  shouldShowPayPayCheckoutHint,
  shouldUsePayPayQrFlow,
  PAYPAY_ORPHAN_WATCH_MS,
} from "./paypay-qr.ts";

// ---------------------------------------------------------------------------
// plan-054 — these lock the three rules that each came from a review catching
// a real defect: the PayPay radio must never disappear, the wire value must
// stay `qr_pay`, and the countdown must never be derived from `expires_at`.
// ---------------------------------------------------------------------------

// ─── Invariant 1: paypay_enabled=false keeps EXACTLY today's behaviour ──────

test("disabled branch never upgrades the flow — today's manual path survives", () => {
  assert.equal(
    shouldUsePayPayQrFlow({
      paypayEnabled: false,
      paymentMethod: "qr_pay",
      orderType: "takeaway",
    }),
    false,
  );
  assert.equal(
    payPayPostOrderRoute({
      paypayEnabled: false,
      paymentMethod: "qr_pay",
      orderType: "takeaway",
      orderId: "01920000-0000-7000-8000-000000000000",
    }),
    null,
    "null = leave the surface's existing navigation untouched",
  );
});

test("enabled branch upgrades only the PayPay radio, not card/counter", () => {
  const base = { paypayEnabled: true, orderType: "takeaway" };
  assert.equal(shouldUsePayPayQrFlow({ ...base, paymentMethod: "qr_pay" }), true);
  assert.equal(shouldUsePayPayQrFlow({ ...base, paymentMethod: "card" }), false);
  assert.equal(shouldUsePayPayQrFlow({ ...base, paymentMethod: "counter" }), false);
  assert.equal(shouldUsePayPayQrFlow({ ...base, paymentMethod: "call_staff" }), false);
  assert.equal(shouldUsePayPayQrFlow({ ...base, paymentMethod: "transfer" }), false);
});

test("signing in does not change the route — #1692", () => {
  // This used to assert the opposite, and the reason it gave was real at the
  // time: /orders/[id]/pay replaced itself with /account/orders/{id} for a
  // signed-in customer, so handing one over swapped their /order-success for a
  // receipt page with no way to pay.
  //
  // #1452 removed that redirect and gave the pay screen an auth gate that serves
  // both audiences ("a logged-in customer needs no pointer"). This predicate kept
  // refusing them for two more days, which meant an account holder could not pay
  // by PayPay at all — and, because `shouldShowPayPayCheckoutHint` shares this
  // predicate, was never told PayPay existed.
  //
  // The predicate no longer takes `isLoggedIn` at all, so the property under test
  // is that login state is not reachable from here: same input, same answer.
  const signedIn = {
    paypayEnabled: true,
    paymentMethod: "qr_pay",
    orderType: "takeaway",
  };
  assert.equal(shouldUsePayPayQrFlow(signedIn), true);
  assert.equal(
    payPayPostOrderRoute({ ...signedIn, orderId: "ord-1" }),
    "/orders/ord-1/pay?method=paypay",
    "a signed-in customer reaches the same QR screen as a guest",
  );
});

test("dine-in is not routed to the takeaway-gated pay screen (D6 / M8)", () => {
  // /orders/[id]/pay is guarded by the guest-order pointer, which is typed
  // "takeaway" only. Routing dine_in there is a forbidden screen on reload.
  assert.equal(
    shouldUsePayPayQrFlow({
      paypayEnabled: true,
      paymentMethod: "qr_pay",
      orderType: "dine_in",
    }),
    false,
  );
});

// ─── Invariant 2: the wire value is `qr_pay`, `paypay` is only a URL hint ───

test("radio value stays the backend enum member — `paypay` would 422", () => {
  assert.equal(PAYPAY_RADIO_VALUE, "qr_pay");
});

test("route + api paths", () => {
  assert.equal(payPayQrPath("abc"), "/orders/abc/pay?method=paypay");
  assert.equal(payPayQrCreatePath("abc"), "/api/v1/customer/orders/abc/paypay-qr");
  assert.equal(
    payPayQrStatusPath("abc"),
    "/api/v1/customer/orders/abc/paypay-qr/status",
  );
  assert.equal(
    paymentContextPath("hongo"),
    "/api/v1/customer/branches/hongo/payment-context",
  );
});

test("isPayPayQrRequested only matches the exact hint", () => {
  assert.equal(isPayPayQrRequested("paypay"), true);
  assert.equal(isPayPayQrRequested("qr_pay"), false);
  assert.equal(isPayPayQrRequested(null), false);
  assert.equal(isPayPayQrRequested(undefined), false);
  assert.equal(isPayPayQrRequested(""), false);
});

test("the checkout handover hides the method chooser; the Pay-now CTA keeps it", () => {
  // Handover: the customer picked PayPay seconds ago. Asking again is asking
  // twice, and the answer the radios invite voids the code on screen.
  assert.equal(shouldShowPayMethodChooser({ hintedMethod: "paypay" }), false);
  // Returning customer via `/orders/[id]` — no hint, nothing chosen yet, so
  // this arrival must stay byte-identical to today.
  assert.equal(shouldShowPayMethodChooser({ hintedMethod: null }), true);
  assert.equal(shouldShowPayMethodChooser({ hintedMethod: undefined }), true);
  // Not the hint. `qr_pay` is the ORDER enum value, never a URL hint
  // (invariant 2), and a typo must not silently lock the screen.
  assert.equal(shouldShowPayMethodChooser({ hintedMethod: "qr_pay" }), true);
  assert.equal(shouldShowPayMethodChooser({ hintedMethod: "" }), true);
});

test("opting out re-opens the chooser, so a hinted URL is never a dead end", () => {
  // The hint is in the URL and survives the not-available card, so without
  // this the one CTA on screen would switch to a method whose controls are
  // still hidden — a screen with nothing payable and nothing to press.
  assert.equal(
    shouldShowPayMethodChooser({ hintedMethod: "paypay", optedOut: true }),
    true,
  );
  // Explicit `false` is not opting out; only `true` unlocks.
  assert.equal(
    shouldShowPayMethodChooser({ hintedMethod: "paypay", optedOut: false }),
    false,
  );
});

// ─── Invariant 3: countdown anchors to expires_in_seconds, never expires_at ─

test("countdown advances by CLIENT DELTA, so absolute clock skew cancels out", () => {
  // Device clock is 6 hours fast. `expires_at` arithmetic would render an
  // already-dead QR; the server delta renders the truth.
  const anchoredAtMs = 1_800_000_000_000;
  assert.equal(
    payPayQrSecondsLeft({ expiresInSeconds: 301, anchoredAtMs, nowMs: anchoredAtMs }),
    301,
  );
  assert.equal(
    payPayQrSecondsLeft({
      expiresInSeconds: 301,
      anchoredAtMs,
      nowMs: anchoredAtMs + 60_000,
    }),
    241,
  );
});

test("countdown clamps at zero and never goes negative", () => {
  const anchoredAtMs = 1_800_000_000_000;
  assert.equal(
    payPayQrSecondsLeft({
      expiresInSeconds: 10,
      anchoredAtMs,
      nowMs: anchoredAtMs + 999_000,
    }),
    0,
  );
});

test("countdown ignores a backwards client clock instead of inflating", () => {
  const anchoredAtMs = 1_800_000_000_000;
  assert.equal(
    payPayQrSecondsLeft({
      expiresInSeconds: 301,
      anchoredAtMs,
      nowMs: anchoredAtMs - 120_000,
    }),
    301,
  );
});

test("missing expires_in_seconds yields 0 — never an expires_at subtraction", () => {
  const anchoredAtMs = 1_800_000_000_000;
  assert.equal(
    payPayQrSecondsLeft({ expiresInSeconds: null, anchoredAtMs, nowMs: anchoredAtMs }),
    0,
  );
  assert.equal(
    payPayQrSecondsLeft({
      expiresInSeconds: undefined,
      anchoredAtMs,
      nowMs: anchoredAtMs,
    }),
    0,
  );
});

test("formatPayPayCountdown renders m:ss, h:mm:ss, and clamps", () => {
  assert.equal(formatPayPayCountdown(301), "5:01");
  assert.equal(formatPayPayCountdown(59), "0:59");
  assert.equal(formatPayPayCountdown(0), "0:00");
  assert.equal(formatPayPayCountdown(-30), "0:00");
  assert.equal(formatPayPayCountdown(3661), "1:01:01");
  assert.equal(formatPayPayCountdown(Number.NaN), "0:00");
});

// ─── Response parsing ───────────────────────────────────────────────────────

test("parsePayPayQrSession accepts the documented 201 body", () => {
  const parsed = parsePayPayQrSession({
    data: {
      qr_url: "https://qr-stg.sandbox.paypay.ne.jp/abc",
      deeplink: "paypay://payment?link_key=abc",
      merchant_payment_id: "mp_123",
      amount: 1200,
      expires_at: "2026-07-29T12:05:01+00:00",
      expires_in_seconds: 301,
    },
  });
  assert.deepEqual(parsed, {
    qr_url: "https://qr-stg.sandbox.paypay.ne.jp/abc",
    deeplink: "paypay://payment?link_key=abc",
    merchant_payment_id: "mp_123",
    amount: 1200,
    expires_at: "2026-07-29T12:05:01+00:00",
    expires_in_seconds: 301,
  });
});

test("a null countdown degrades to no timer, it does NOT fail the session", () => {
  // The backend types `expires_in_seconds` as `int|null` and legitimately emits
  // null when PayPay's create response has no `expiryDate`. Rejecting the body
  // there threw away a live, scannable, already-minted code and told a customer
  // it had failed. Render the QR, omit the countdown — same degradation the
  // missing deeplink already gets.
  const parsed = parsePayPayQrSession({
    data: {
      qr_url: "https://qr-stg.sandbox.paypay.ne.jp/abc",
      merchant_payment_id: "mp_1",
      expires_at: "2026-07-29T12:05:01+00:00",
      expires_in_seconds: null,
    },
  });
  assert.equal(parsed?.qr_url, "https://qr-stg.sandbox.paypay.ne.jp/abc");
  assert.equal(parsed?.expires_in_seconds, null);
  // Absent entirely, and non-numeric junk, degrade the same way — what we still
  // refuse is deriving a timer from `expires_at` (invariant 3).
  assert.equal(
    parsePayPayQrSession({ data: { qr_url: "https://x", merchant_payment_id: "mp_1" } })
      ?.expires_in_seconds,
    null,
  );
  assert.equal(
    parsePayPayQrSession({
      data: { qr_url: "https://x", merchant_payment_id: "mp_1", expires_in_seconds: "300" },
    })?.expires_in_seconds,
    null,
  );
  // And a null anchor still yields 0 seconds rather than an invented number.
  assert.equal(
    payPayQrSecondsLeft({ expiresInSeconds: null, anchoredAtMs: 0, nowMs: 0 }),
    0,
  );
});

test("the two fields that make a code payable are still required", () => {
  assert.equal(
    parsePayPayQrSession({ data: { merchant_payment_id: "mp_1", expires_in_seconds: 300 } }),
    null,
  );
  assert.equal(
    parsePayPayQrSession({ data: { qr_url: "https://x", expires_in_seconds: 300 } }),
    null,
  );
});

test("parsePayPayQrSession rejects junk, empties and missing envelopes", () => {
  assert.equal(parsePayPayQrSession(null), null);
  assert.equal(parsePayPayQrSession(undefined), null);
  assert.equal(parsePayPayQrSession("nope"), null);
  assert.equal(parsePayPayQrSession({}), null);
  assert.equal(parsePayPayQrSession({ data: null }), null);
  assert.equal(
    parsePayPayQrSession({ data: { qr_url: "", merchant_payment_id: "m", expires_in_seconds: 10 } }),
    null,
  );
});

test("parsePayPayQrSession degrades to scan-only when the deeplink is absent", () => {
  const parsed = parsePayPayQrSession({
    data: { qr_url: "https://x", merchant_payment_id: "mp_1", expires_in_seconds: 300 },
  });
  assert.equal(parsed?.deeplink, "");
  assert.equal(parsed?.amount, 0);
});

test("parsePayPayAvailability is true ONLY for a literal true", () => {
  assert.equal(parsePayPayAvailability({ data: { paypay_enabled: true } }), true);
  assert.equal(parsePayPayAvailability({ data: { paypay_enabled: false } }), false);
  assert.equal(parsePayPayAvailability({ data: { paypay_enabled: "true" } }), false);
  assert.equal(parsePayPayAvailability({ data: { paypay_enabled: 1 } }), false);
  assert.equal(parsePayPayAvailability({ data: {} }), false, "older backend");
  assert.equal(parsePayPayAvailability({}), false);
  assert.equal(parsePayPayAvailability(null), false);
});

// ─── Status → phase ─────────────────────────────────────────────────────────

test("payPayQrPhase maps every documented status", () => {
  // The FULL vocabulary, not the happy four. Anything that falls through to
  // `waiting` is a screen with no exit and no refresh CTA — and four of these
  // used to do exactly that on a customer who could still pay.
  const cases: Array<[string, string]> = [
    // Live.
    ["CREATED", "waiting"],
    // The money moved.
    ["COMPLETED", "paid"],
    // The wallet said no. `PayPayLifecycleMapper::mapPaymentState` folds all
    // three into its canceled state, so all three are real vocabulary.
    ["FAILED", "failed"],
    ["CANCELED", "failed"],
    ["CANCELLED", "failed"],
    ["REVERTED", "failed"],
    // The code is gone. `syncStatus()` synthesises NOT_FOUND whenever there is
    // no live attempt left, whatever ended it — the customer's move is the same
    // as for EXPIRED: mint a new code.
    ["EXPIRED", "expired"],
    ["NOT_FOUND", "expired"],
  ];
  for (const [status, expected] of cases) {
    assert.equal(
      payPayQrPhase({ status, is_fully_paid: false }),
      expected,
      `${status} must not fall through to a dead-end waiting`,
    );
  }
});

test("every terminal status reaches the refresh CTA", () => {
  // The regression this locks: the CTA hung off `expired || failed`, so a
  // cancelled wallet or a NOT_FOUND code left the customer on a screen with no
  // button at all.
  for (const status of ["FAILED", "CANCELED", "CANCELLED", "REVERTED", "EXPIRED", "NOT_FOUND"]) {
    const phase = payPayQrPhase({ status, is_fully_paid: false });
    assert.equal(shouldPollPayPayStatus(phase), false, `${status} keeps polling`);
    assert.equal(
      shouldOfferPayPayRefresh({
        phase,
        awaitingExpiryConfirmation: false,
        lostContact: false,
        superseded: false,
      }),
      true,
      `${status} offers no way out`,
    );
  }
});

test("`paid` is the one state with nothing left to offer", () => {
  assert.equal(
    shouldOfferPayPayRefresh({
      phase: "paid",
      awaitingExpiryConfirmation: true,
      lostContact: true,
      superseded: true,
    }),
    false,
  );
});

test("a healthy wait offers no CTA; a stalled one does", () => {
  const waiting = {
    phase: "waiting" as const,
    awaitingExpiryConfirmation: false,
    lostContact: false,
    superseded: false,
  };
  assert.equal(shouldOfferPayPayRefresh(waiting), false, "do not invite a needless re-mint");
  assert.equal(
    shouldOfferPayPayRefresh({ ...waiting, lostContact: true }),
    true,
    "the poll cannot reach the server — nobody is watching this code",
  );
  assert.equal(
    shouldOfferPayPayRefresh({ ...waiting, awaitingExpiryConfirmation: true }),
    true,
    "the only dead end a SUCCEEDING poll can produce: an unknown status forever",
  );
  assert.equal(shouldOfferPayPayRefresh({ ...waiting, superseded: true }), true);
});

test("lost contact needs repeated failures, not one flaky poll", () => {
  assert.equal(hasLostPayPayStatusContact(0), false);
  assert.equal(hasLostPayPayStatusContact(1), false);
  assert.equal(hasLostPayPayStatusContact(PAYPAY_STATUS_FAILURE_LIMIT - 1), false);
  assert.equal(hasLostPayPayStatusContact(PAYPAY_STATUS_FAILURE_LIMIT), true);
  assert.equal(hasLostPayPayStatusContact(PAYPAY_STATUS_FAILURE_LIMIT + 9), true);
});

test("is_fully_paid beats the code state — the money is the fact", () => {
  // Cashier settled it at the counter while the code sat unscanned.
  assert.equal(payPayQrPhase({ status: "EXPIRED", is_fully_paid: true }), "paid");
  assert.equal(payPayQrPhase({ status: "FAILED", is_fully_paid: true }), "paid");
});

test("an unknown status keeps waiting, never reports a false failure", () => {
  assert.equal(payPayQrPhase({ status: "SOME_FUTURE_STATE", is_fully_paid: false }), "waiting");
  assert.equal(payPayQrPhase({}), "waiting");
  assert.equal(payPayQrPhase({ status: null, is_fully_paid: null }), "waiting");
});

test("polling stops the moment the phase leaves `waiting`", () => {
  assert.equal(shouldPollPayPayStatus("waiting"), true);
  assert.equal(shouldPollPayPayStatus("paid"), false);
  assert.equal(shouldPollPayPayStatus("failed"), false);
  assert.equal(shouldPollPayPayStatus("expired"), false);
});

test("only `paid` is terminal for the customer", () => {
  assert.equal(isPayPayQrTerminal("paid"), true);
  assert.equal(isPayPayQrTerminal("expired"), false);
  assert.equal(isPayPayQrTerminal("failed"), false);
  assert.equal(isPayPayQrTerminal("waiting"), false);
});

test("an expired code on a live order is re-mintable, a paid one is not (D7)", () => {
  assert.equal(canRefreshPayPayQr("expired"), true);
  assert.equal(canRefreshPayPayQr("failed"), true);
  assert.equal(canRefreshPayPayQr("waiting"), true);
  assert.equal(canRefreshPayPayQr("paid"), false);
});

test("fallback poll cadence stays in the 10–15s band (never 2s)", () => {
  assert.ok(PAYPAY_STATUS_POLL_INTERVAL_MS >= 10_000);
  assert.ok(PAYPAY_STATUS_POLL_INTERVAL_MS <= 15_000);
});

// ─── The server owns expiry (status `expires_in_seconds`) ───────────────────

test("readPayPayQrStatus lifts phase, countdown and WHICH code together", () => {
  assert.deepEqual(
    readPayPayQrStatus({
      data: {
        status: "CREATED",
        is_fully_paid: false,
        order_status: "pending",
        expires_in_seconds: 240,
        merchant_payment_id: "mp_1",
      },
    }),
    {
      phase: "waiting",
      expiresInSeconds: 240,
      merchantPaymentId: "mp_1",
      isFullyPaid: false,
    },
  );
  assert.deepEqual(
    readPayPayQrStatus({
      data: {
        status: "EXPIRED",
        is_fully_paid: false,
        expires_in_seconds: 0,
        merchant_payment_id: null,
      },
    }),
    { phase: "expired", expiresInSeconds: 0, merchantPaymentId: null, isFullyPaid: false },
  );
});

test("readPayPayQrStatus survives an older backend and outright junk", () => {
  // No `expires_in_seconds` (the backend before it was added) → no anchor, but
  // still a usable phase. Same for `merchant_payment_id`.
  assert.deepEqual(readPayPayQrStatus({ data: { status: "CREATED" } }), {
    phase: "waiting",
    expiresInSeconds: null,
    merchantPaymentId: null,
    isFullyPaid: false,
  });
  assert.deepEqual(readPayPayQrStatus(null), {
    phase: "waiting",
    expiresInSeconds: null,
    merchantPaymentId: null,
    isFullyPaid: false,
  });
  assert.deepEqual(readPayPayQrStatus("nope"), {
    phase: "waiting",
    expiresInSeconds: null,
    merchantPaymentId: null,
    isFullyPaid: false,
  });
  assert.deepEqual(
    readPayPayQrStatus({ data: { expires_in_seconds: "300", merchant_payment_id: "" } }),
    {
      phase: "waiting",
      expiresInSeconds: null,
      merchantPaymentId: null,
      isFullyPaid: false,
    },
    "a non-numeric countdown is no countdown; an empty id names no code",
  );
});

// ─── The poll is scoped to the code ON SCREEN ───────────────────────────────

test("a second mint marks the older screen stale instead of re-anchoring it", () => {
  // `liveAttempt()` always resolves the NEWEST attempt and the status route
  // takes only an order id, so the first screen's poll starts reporting the
  // second code — including a healthy `expires_in_seconds` that would re-anchor
  // a countdown onto a QR the server already deleted.
  assert.equal(
    isPayPayScreenSuperseded({ displayed: "mp_first", reported: "mp_second" }),
    true,
  );
  assert.equal(
    isPayPayScreenSuperseded({ displayed: "mp_first", reported: "mp_first" }),
    false,
  );
});

test("unknown on either side is never a staleness verdict", () => {
  // An older backend that does not echo the id, or a poll that landed with
  // nothing outstanding, must leave the screen exactly as it was — declaring
  // a live QR dead on silence is the same class of bug in the other direction.
  assert.equal(isPayPayScreenSuperseded({ displayed: "mp_1", reported: null }), false);
  assert.equal(isPayPayScreenSuperseded({ displayed: "mp_1", reported: undefined }), false);
  assert.equal(isPayPayScreenSuperseded({ displayed: null, reported: "mp_2" }), false);
  assert.equal(isPayPayScreenSuperseded({ displayed: "", reported: "mp_2" }), false);
  assert.equal(isPayPayScreenSuperseded({ displayed: null, reported: null }), false);
});

test("a superseded screen still shows the QR, but stops and offers a new code", () => {
  assert.equal(
    shouldOfferPayPayRefresh({
      phase: "waiting",
      awaitingExpiryConfirmation: false,
      lostContact: false,
      superseded: true,
    }),
    true,
  );
});

test("order-level paid outranks staleness — the money is still the fact", () => {
  // Split bill: the other payer's code completed the order. Whoever's code took
  // it, this customer is done, so `isFullyPaid` is read separately from `phase`
  // and consulted BEFORE the staleness bail-out.
  const reading = readPayPayQrStatus({
    data: { status: "COMPLETED", is_fully_paid: true, merchant_payment_id: "mp_second" },
  });
  assert.equal(reading.isFullyPaid, true);
  assert.equal(reading.phase, "paid");
  assert.equal(
    isPayPayScreenSuperseded({ displayed: "mp_first", reported: reading.merchantPaymentId }),
    true,
    "stale AND paid — the call site must answer 'paid' first",
  );
});

// ─── Create failures the customer can and cannot act on ─────────────────────

test("a 422 is not a retry — it is an answer", () => {
  // The one that hurt: customer pays in the PayPay app, iOS discards the tab,
  // they return, the page reloads and mints, `outstanding <= 0` → 422
  // PAYPAY_NOT_AVAILABLE. Behind a retry button that can only 422 again, a
  // customer who JUST PAID is shown a failure screen.
  assert.equal(classifyPayPayCreateFailure(422), "not_available");
  assert.equal(classifyPayPayCreateFailure(404), "not_available");
  assert.equal(classifyPayPayCreateFailure(403), "not_available");
});

test("429 says wait, 5xx and network say try again", () => {
  assert.equal(classifyPayPayCreateFailure(429), "rate_limited");
  assert.equal(classifyPayPayCreateFailure(500), "retryable");
  assert.equal(classifyPayPayCreateFailure(502), "retryable");
  assert.equal(classifyPayPayCreateFailure(null), "retryable", "network / no response");
  assert.equal(classifyPayPayCreateFailure(undefined), "retryable");
  assert.equal(classifyPayPayCreateFailure(0), "retryable");
});

// ─── "Unavailable" and "could not determine" are different facts ────────────

test("only the server may say a branch has no PayPay", () => {
  // One blip during a wifi→4G handoff used to resolve `false` and, because the
  // effect only re-ran on `branchSlug`, told the customer "This shop does not
  // accept PayPay QR" for the whole life of the page — on a shop that does.
  assert.equal(classifyPayPayAvailabilityAnswer(404), false, "the server answered");
  assert.equal(classifyPayPayAvailabilityAnswer(422), false);
  assert.equal(classifyPayPayAvailabilityAnswer(401), false);
  assert.equal(classifyPayPayAvailabilityAnswer(500), null, "ask again");
  assert.equal(classifyPayPayAvailabilityAnswer(503), null);
  assert.equal(classifyPayPayAvailabilityAnswer(null), null, "offline / DNS");
  assert.equal(classifyPayPayAvailabilityAnswer(undefined), null);
});

// ─── Poll cadence: backoff, jitter, Retry-After ─────────────────────────────

test("a healthy poll sits at the 12s cadence, ±20%", () => {
  const floor = nextPayPayPollDelayMs({ consecutiveFailures: 0, jitter: 0 });
  const ceil = nextPayPayPollDelayMs({ consecutiveFailures: 0, jitter: 1 });
  assert.equal(floor, Math.round(PAYPAY_STATUS_POLL_INTERVAL_MS * 0.8));
  assert.equal(ceil, Math.round(PAYPAY_STATUS_POLL_INTERVAL_MS * 1.2));
  // The jitter is the point: seven phones on one split bill must not phase-lock
  // against a 120/min bucket shared with the settlement poll.
  assert.notEqual(floor, ceil);
});

test("failures back off exponentially and stop at the ceiling", () => {
  const at = (n: number) => nextPayPayPollDelayMs({ consecutiveFailures: n, jitter: 0.5 });
  assert.equal(at(1), PAYPAY_STATUS_POLL_INTERVAL_MS);
  assert.equal(at(2), PAYPAY_STATUS_POLL_INTERVAL_MS * 2);
  assert.equal(at(3), PAYPAY_STATUS_POLL_INTERVAL_MS * 4);
  assert.ok(at(20) <= Math.round(PAYPAY_STATUS_BACKOFF_MAX_MS * 1.2));
  assert.ok(at(20) >= Math.round(PAYPAY_STATUS_BACKOFF_MAX_MS * 0.8));
});

test("a 429 floor is never undercut by jitter", () => {
  // The status route shares `customer-order-read` (120/min, keyed on the order)
  // with useOrderSettlement's 5s poll. When the bucket tips, THIS is the poller
  // that must yield — the other one is the one that settles the order.
  assert.equal(
    nextPayPayPollDelayMs({
      consecutiveFailures: 1,
      retryAfterMs: PAYPAY_STATUS_RATE_LIMITED_FLOOR_MS,
      jitter: 0,
    }),
    PAYPAY_STATUS_RATE_LIMITED_FLOOR_MS,
  );
  // A backoff that already exceeds the floor wins instead — the floor is a
  // minimum, not a cap.
  assert.ok(
    nextPayPayPollDelayMs({
      consecutiveFailures: 6,
      retryAfterMs: PAYPAY_STATUS_RATE_LIMITED_FLOOR_MS,
      jitter: 1,
    }) > PAYPAY_STATUS_RATE_LIMITED_FLOOR_MS,
  );
});

test("nextPayPayPollDelayMs never returns junk for junk jitter", () => {
  assert.ok(Number.isFinite(nextPayPayPollDelayMs({ consecutiveFailures: 0, jitter: Number.NaN })));
  assert.ok(nextPayPayPollDelayMs({ consecutiveFailures: 0, jitter: -5 }) > 0);
  assert.ok(nextPayPayPollDelayMs({ consecutiveFailures: 0, jitter: 99 }) > 0);
});

test("a lapsed LOCAL countdown never expires the code — only the server does", () => {
  // This is the whole reason the status endpoint grew `expires_in_seconds`.
  // Deciding locally also stopped the fallback poll, so the screen could never
  // learn about a wallet that settled a second later.
  assert.equal(resolvePayPayQrPhase({ serverPhase: "waiting", secondsLeft: 0 }), "waiting");
  assert.equal(resolvePayPayQrPhase({ serverPhase: "waiting", secondsLeft: -90 }), "waiting");
  assert.equal(
    shouldPollPayPayStatus(resolvePayPayQrPhase({ serverPhase: "waiting", secondsLeft: 0 })),
    true,
    "the poll must survive local zero — that is the recovery path",
  );
});

test("whatever the server said still outranks the countdown, in both directions", () => {
  assert.equal(resolvePayPayQrPhase({ serverPhase: "expired", secondsLeft: 200 }), "expired");
  assert.equal(resolvePayPayQrPhase({ serverPhase: "paid", secondsLeft: 200 }), "paid");
  assert.equal(resolvePayPayQrPhase({ serverPhase: "failed", secondsLeft: 0 }), "failed");
});

test("local zero shows `checking`, not a dead 0:00", () => {
  assert.equal(isAwaitingExpiryConfirmation({ serverPhase: "waiting", secondsLeft: 0 }), true);
  assert.equal(isAwaitingExpiryConfirmation({ serverPhase: "waiting", secondsLeft: 1 }), false);
  assert.equal(
    isAwaitingExpiryConfirmation({ serverPhase: "expired", secondsLeft: 0 }),
    false,
    "once the server has answered there is nothing left to confirm",
  );
});

test("nextCountdownAnchor re-anchors on a server value and ignores silence", () => {
  const current = { expiresInSeconds: 301, anchoredAtMs: 1_800_000_000_000 };
  assert.deepEqual(
    nextCountdownAnchor({
      current,
      serverExpiresInSeconds: 120,
      receivedAtMs: 1_800_000_180_000,
    }),
    { expiresInSeconds: 120, anchoredAtMs: 1_800_000_180_000 },
  );
  assert.equal(
    nextCountdownAnchor({
      current,
      serverExpiresInSeconds: null,
      receivedAtMs: 1_800_000_180_000,
    }),
    current,
    "no server word = keep counting from the anchor we already had",
  );
  assert.equal(
    nextCountdownAnchor({
      current,
      serverExpiresInSeconds: undefined,
      receivedAtMs: 1_800_000_180_000,
    }),
    current,
  );
});

test("a re-anchored countdown reads the SERVER's remaining life, not ours", () => {
  // Tab was suspended for 4 minutes: the local anchor would render ~0, the
  // server says the code was re-minted and has 5 minutes left.
  const anchor = nextCountdownAnchor({
    current: { expiresInSeconds: 301, anchoredAtMs: 1_800_000_000_000 },
    serverExpiresInSeconds: 300,
    receivedAtMs: 1_800_000_240_000,
  });
  assert.equal(
    payPayQrSecondsLeft({
      expiresInSeconds: anchor.expiresInSeconds,
      anchoredAtMs: anchor.anchoredAtMs,
      nowMs: 1_800_000_240_000,
    }),
    300,
  );
});

// ─── The lapsed window must LOOK lapsed ─────────────────────────────────────
//
// A browser walkthrough found the sharpest defect on this screen: when the
// countdown ran out the page still looked completely alive — full-opacity QR,
// "scan this QR to pay", a red deep-link CTA aimed at the dead code, "waiting
// for payment", and nothing anywhere saying it had expired. The only way out
// was a small low-contrast button at the bottom. The cause is a rule worth
// keeping (the CLIENT never declares a lapse, and the sandbox reports CREATED
// long past the real expiry), so the fix is presentation only — which is what
// these lock.

const livePresentation = {
  phase: "waiting" as const,
  superseded: false,
  awaitingExpiryConfirmation: false,
  hasCountdown: true,
  lostContact: false,
  hasDeepLink: true,
};

test("a live code reads as scannable: full-opacity QR, deep link, countdown", () => {
  assert.deepEqual(payPayQrPresentation(livePresentation), {
    headlineKey: "scanHint",
    dimQr: false,
    badgeKey: null,
    showDeepLink: true,
    showCountdown: true,
    waitingKey: "waitingForPayment",
    showKeepOpenHint: true,
    refreshCta: "none",
  });
});

test("a lapsed countdown stops the screen looking alive", () => {
  const ui = payPayQrPresentation({
    ...livePresentation,
    awaitingExpiryConfirmation: true,
  });

  assert.equal(ui.dimQr, true, "the QR must stop reading as scannable");
  assert.equal(ui.badgeKey, "expiryUnconfirmedBadge");
  assert.equal(
    ui.showDeepLink,
    false,
    "the deep link opens the wallet on THIS code — not a primary action on a dead one",
  );
  assert.equal(ui.showCountdown, false, "a frozen 0:00 would be the one untrue thing on screen");
  assert.equal(ui.refreshCta, "primary", "minting a new code is now the customer's move");
});

test("lapsed copy says PROBABLY expired — it never asserts the payment failed", () => {
  const ui = payPayQrPresentation({
    ...livePresentation,
    awaitingExpiryConfirmation: true,
  });

  // A wallet settling one second after our local clock ran out is still a real
  // payment, so this window may NOT borrow the copy of a state the server
  // confirmed. Its own key, and the spinner stays under "still checking".
  assert.equal(ui.headlineKey, "expiryUnconfirmed");
  assert.notEqual(ui.headlineKey, "expired");
  assert.notEqual(ui.headlineKey, "cancelled");
  assert.equal(ui.waitingKey, "stillChecking");
  assert.equal(ui.showKeepOpenHint, false);
});

test("the lapsed screen keeps polling — the phase is untouched by presentation", () => {
  // The whole point: this is pixels, not state. The phase handed in is still
  // `waiting`, so the fallback poll (and with it the only way this screen can
  // ever hear "paid") is still running.
  assert.equal(shouldPollPayPayStatus("waiting"), true);
  assert.equal(
    isAwaitingExpiryConfirmation({ serverPhase: "waiting", secondsLeft: 0 }),
    true,
    "and the flag driving it is still derived from the SERVER's phase",
  );
});

test("lapsed reuses the superseded treatment rather than inventing a second one", () => {
  const lapsed = payPayQrPresentation({
    ...livePresentation,
    awaitingExpiryConfirmation: true,
  });
  const supersededUi = payPayQrPresentation({ ...livePresentation, superseded: true });

  for (const ui of [lapsed, supersededUi]) {
    assert.equal(ui.dimQr, true);
    assert.equal(ui.showDeepLink, false);
    assert.equal(ui.showCountdown, false);
    assert.equal(ui.refreshCta, "primary");
    assert.notEqual(ui.badgeKey, null);
  }
});

test("a server answer outranks the lapsed dressing", () => {
  // `awaitingExpiryConfirmation` is false by construction once the server has
  // spoken (see `isAwaitingExpiryConfirmation`), so its own copy renders.
  assert.equal(
    payPayQrPresentation({ ...livePresentation, phase: "expired" }).headlineKey,
    "expired",
  );
  assert.equal(
    payPayQrPresentation({ ...livePresentation, phase: "failed" }).headlineKey,
    "cancelled",
  );
  assert.equal(
    payPayQrPresentation({ ...livePresentation, phase: "expired", superseded: true })
      .headlineKey,
    "superseded",
    "a newer code means the status endpoint was answering about THAT one",
  );
});

test("a paid order never renders a lapsed screen — the panel returns before this", () => {
  // Belt and braces: `paid` cannot reach here, and if it did it would render
  // the live shape, which claims nothing false.
  const ui = payPayQrPresentation({ ...livePresentation, phase: "paid" });
  assert.equal(ui.dimQr, false);
  assert.equal(ui.refreshCta, "none", "there is nothing to refresh once the money moved");
});

test("lost contact on a LIVE code keeps the refresh quiet", () => {
  const ui = payPayQrPresentation({ ...livePresentation, lostContact: true });
  assert.equal(ui.dimQr, false, "the code is presumed good — only our poll is blind");
  assert.equal(ui.showDeepLink, true);
  assert.equal(
    ui.refreshCta,
    "secondary",
    "an escape hatch, not the thing to do — scanning still is",
  );
});

test("a code with no timer is live, not lapsed", () => {
  // PayPay returned no `expiryDate`: scannable code, no countdown. It must not
  // pick up any of the lapsed dressing.
  const ui = payPayQrPresentation({
    ...livePresentation,
    hasCountdown: false,
    awaitingExpiryConfirmation: false,
  });
  assert.equal(ui.showCountdown, false);
  assert.equal(ui.dimQr, false);
  assert.equal(ui.headlineKey, "scanHint");
  assert.equal(ui.refreshCta, "none");
});

test("no deeplink degrades to scan-only, everything else intact", () => {
  const ui = payPayQrPresentation({ ...livePresentation, hasDeepLink: false });
  assert.equal(ui.showDeepLink, false);
  assert.equal(ui.headlineKey, "scanHint");
  assert.equal(ui.dimQr, false);
});

test("the refresh CTA offered by the presentation matches shouldOfferPayPayRefresh", () => {
  // Two places may not drift: whether the button exists is one decision.
  const cases = [
    { ...livePresentation },
    { ...livePresentation, awaitingExpiryConfirmation: true },
    { ...livePresentation, lostContact: true },
    { ...livePresentation, superseded: true },
    { ...livePresentation, phase: "expired" as const },
    { ...livePresentation, phase: "failed" as const },
    { ...livePresentation, phase: "paid" as const },
  ];
  for (const input of cases) {
    assert.equal(
      payPayQrPresentation(input).refreshCta !== "none",
      shouldOfferPayPayRefresh({
        phase: input.phase,
        awaitingExpiryConfirmation: input.awaitingExpiryConfirmation,
        lostContact: input.lostContact,
        superseded: input.superseded,
      }),
      JSON.stringify(input),
    );
  }
});

// ─── Checkout sub-copy promises a QR screen only where one follows ──────────

test("the checkout PayPay hint renders exactly where the QR flow runs", () => {
  assert.equal(
    shouldShowPayPayCheckoutHint({ paypayEnabled: true, orderType: "takeaway" }),
    true,
  );
  assert.equal(
    shouldShowPayPayCheckoutHint({ paypayEnabled: false, orderType: "takeaway" }),
    false,
    "invariant 1: on an unconfigured branch `qr_pay` still means settle-at-the-till",
  );
  assert.equal(
    shouldShowPayPayCheckoutHint({ paypayEnabled: true, orderType: "dine_in" }),
    false,
    "dine-in is not routed to the QR screen",
  );
  // #1692 — this used to assert `false` for a signed-in customer, back when the
  // route refused them. Now the hint must appear for exactly the same inputs the
  // route accepts, whoever is asking: the silent downgrade (routed to the till
  // screen, never told PayPay existed) was the worse half of that bug.
  assert.equal(
    shouldShowPayPayCheckoutHint({ paypayEnabled: true, orderType: "takeaway" }),
    shouldUsePayPayQrFlow({
      paypayEnabled: true,
      paymentMethod: "qr_pay",
      orderType: "takeaway",
    }),
    "the hint and the route are one predicate — neither may promise nor hide what the other does",
  );
});

// ─── #1296: dine-in ─────────────────────────────────────────────────────────
// Dine-in reaches PayPay through a DIFFERENT door than takeaway: an inline panel
// on the bill screen, not a handover to /orders/[id]/pay. These lock the two
// halves of that — the gate that says "yes, here", and the split payload that
// stops the first of four payers being handed a QR for the whole table.

test("the dine-in panel gate asks about the branch, not about the route", () => {
  assert.equal(
    canRunPayPayQrPanelInline({
      paypayEnabled: true,
      paymentMethod: PAYPAY_DINE_IN_METHOD,
    }),
    true,
  );
  assert.equal(
    canRunPayPayQrPanelInline({
      paypayEnabled: false,
      paymentMethod: PAYPAY_DINE_IN_METHOD,
    }),
    false,
    "no fallback meaning on this surface: an option that cannot mint is a dead button",
  );
  assert.equal(
    canRunPayPayQrPanelInline({ paypayEnabled: true, paymentMethod: "online" }),
    false,
  );
  assert.equal(
    canRunPayPayQrPanelInline({ paypayEnabled: true, paymentMethod: "counter" }),
    false,
  );
});

test("the checkout handover stays takeaway-only after dine-in opened", () => {
  // #1296 widened where PayPay can be USED, not where /orders/[id]/pay is
  // reachable — that route gates on the guest-order pointer, which is typed
  // takeaway. Sending a dine-in order there is a "forbidden" screen on reload.
  assert.equal(
    shouldUsePayPayQrFlow({
      paypayEnabled: true,
      paymentMethod: PAYPAY_RADIO_VALUE,
      orderType: "dine_in",
    }),
    false,
  );
  assert.equal(
    payPayPostOrderRoute({
      paypayEnabled: true,
      paymentMethod: PAYPAY_RADIO_VALUE,
      orderType: "dine_in",
      orderId: "order-1",
    }),
    null,
  );
});

test("pay-in-full sends no split fields at all", () => {
  assert.deepEqual(
    payPaySplitPayload({
      paymentMode: "full",
      splitType: "even",
      splitCount: 4,
      itemAllocations: [{ item_id: "item-a", units: 1 }],
    }),
    {},
    "a full payment carries no split metadata — #1058's null-metadata contract",
  );
});

test("an even split carries the headcount that turns the soft lock hard", () => {
  assert.deepEqual(
    payPaySplitPayload({
      paymentMode: "split",
      splitType: "even",
      splitCount: 4,
      itemAllocations: [],
    }),
    { split_type: "even", split_count: 4 },
  );
  assert.deepEqual(
    payPaySplitPayload({
      paymentMode: "split",
      splitType: "even",
      splitCount: 1,
      itemAllocations: [],
    }),
    {},
    "a headcount of one is not a split; the server rejects min:2 anyway",
  );
});

test("a per-dish split carries the allocation, and refuses to claim an empty one", () => {
  assert.deepEqual(
    payPaySplitPayload({
      paymentMode: "split",
      splitType: "by_items",
      splitCount: 0,
      itemAllocations: [
        { item_id: "item-salad", units: 1 },
        { item_id: "item-soup", units: 2 },
      ],
    }),
    {
      split_type: "by_items",
      item_allocations: [
        { item_id: "item-salad", units: 1 },
        { item_id: "item-soup", units: 2 },
      ],
    },
  );

  // Declaring by_items with nothing allocated stamps `split_mode: by_items` on
  // the ledger row with no dish attributed — which credits nothing AND refuses
  // every later by-items payer (`split_by_items_mode_locked`).
  assert.deepEqual(
    payPaySplitPayload({
      paymentMode: "split",
      splitType: "by_items",
      splitCount: 0,
      itemAllocations: [],
    }),
    {},
  );
  assert.deepEqual(
    payPaySplitPayload({
      paymentMode: "split",
      splitType: "by_items",
      splitCount: 0,
      itemAllocations: [{ item_id: "item-a", units: 0 }],
    }),
    {},
    "zero units allocates nothing",
  );
});

test("a custom-amount split names itself so the mode lock can see it", () => {
  assert.deepEqual(
    payPaySplitPayload({
      paymentMode: "split",
      splitType: "by_amount",
      splitCount: 0,
      itemAllocations: [],
    }),
    { split_type: "by_amount" },
  );
});

// ─── #1303: the dine-in online card has two levels ──────────────────────────
// PayPay is a way of paying online, not a third channel beside "pay at the
// counter". These lock the level-two behaviour that has no component-test rig to
// catch it: what the default is, when it may change, and what a live code
// outranks.

const ONLINE = {
  payingOnline: true,
  picked: null,
  paypayEnabled: true,
  paypayProbeLoading: false,
  hasLiveCode: false,
} as const;

test("PayPay is the default where it exists, the card where it does not", () => {
  assert.equal(
    resolveDineInOnlineGateway({ picked: null, paypayEnabled: true }),
    "paypay",
    "it is the first tab",
  );
  assert.equal(
    resolveDineInOnlineGateway({ picked: null, paypayEnabled: false }),
    "stripe",
    "a branch without PayPay behaves exactly as it did before #1296",
  );
});

test("a tap pins the gateway, and the capability answer cannot un-pin it", () => {
  // The probe resolves AFTER mount. If it could still move the selection, a
  // guest who deliberately chose the card would be switched to PayPay — and
  // their half-typed card number would go with the unmounted form.
  assert.equal(
    resolveDineInOnlineGateway({ picked: "stripe", paypayEnabled: true }),
    "stripe",
  );
  assert.equal(
    resolveDineInOnlineGateway({ picked: "paypay", paypayEnabled: false }),
    "paypay",
    "still their pick; the surface decides what that can DO",
  );
});

test("nothing mounts until the branch capability is known", () => {
  const s = dineInOnlineSurface({ ...ONLINE, paypayEnabled: false, paypayProbeLoading: true });

  // Mounting the card form now and swapping it when the probe lands would tear
  // down Stripe Elements — and on a slow connection, a card number with it.
  assert.equal(s.showProbeSpinner, true);
  assert.equal(s.showCard, false);
  assert.equal(s.showQrPanel, false);
  assert.equal(s.showConfirmButton, false, "no button next to a spinner");
});

test("PayPay branch, nothing picked: PayPay tab, no card form, button mints", () => {
  const s = dineInOnlineSurface(ONLINE);

  assert.equal(s.gateway, "paypay");
  assert.equal(s.showTabs, true);
  assert.equal(s.showCard, false);
  assert.equal(s.showQrPanel, false, "no code minted yet");
  assert.equal(s.showPayPayIntro, true, "…so the tab explains what the button does");
  assert.equal(s.showConfirmButton, true);
});

test("the card tab shows the card form and nothing PayPay", () => {
  const s = dineInOnlineSurface({ ...ONLINE, picked: "stripe" });

  assert.equal(s.showCard, true);
  assert.equal(s.showQrPanel, false);
  assert.equal(s.showTabs, true, "the way back to PayPay stays reachable");
  assert.equal(s.showConfirmButton, true);
});

test("no tab bar on a branch without PayPay — one tab is furniture", () => {
  const s = dineInOnlineSurface({ ...ONLINE, paypayEnabled: false });

  assert.equal(s.showTabs, false);
  assert.equal(s.gateway, "stripe");
  assert.equal(s.showCard, true, "identical to the screen before #1296");
});

test("a live code owns the screen: no card form, no second confirm press", () => {
  const s = dineInOnlineSurface({ ...ONLINE, hasLiveCode: true });

  assert.equal(s.showQrPanel, true);
  assert.equal(s.showCard, false);
  assert.equal(
    s.showConfirmButton,
    false,
    "a second press would void the QR the guest is looking at",
  );
});

test("a live code outranks a capability that stops saying yes", () => {
  // The mint IS proof the branch could mint. Gating the panel on the capability
  // would make the QR vanish mid-scan and hand its space to the card form, while
  // the code stayed collectable at PayPay with nothing on screen watching it.
  const s = dineInOnlineSurface({
    ...ONLINE,
    picked: "paypay",
    paypayEnabled: false,
    hasLiveCode: true,
  });

  assert.equal(s.showQrPanel, true);
  assert.equal(s.showCard, false, "the card form must not take the QR's place");
  assert.equal(s.showTabs, true, "and the guest keeps a way out");
});

test("the counter channel renders none of the online body", () => {
  const s = dineInOnlineSurface({ ...ONLINE, payingOnline: false });

  assert.equal(s.showTabs, false);
  assert.equal(s.showCard, false);
  assert.equal(s.showQrPanel, false);
  assert.equal(s.showProbeSpinner, false);
  assert.equal(s.showConfirmButton, false);
});

test("exactly one body renders in every reachable state", () => {
  // The bug this replaces: five render conditions each read `method === "online"`
  // and that expression meant two different things depending on which one you
  // looked at, so the "your card is encrypted" notice rendered beside a PayPay QR.
  for (const picked of [null, "paypay", "stripe"] as const) {
    for (const paypayEnabled of [true, false]) {
      for (const paypayProbeLoading of [true, false]) {
        for (const hasLiveCode of [true, false]) {
          const s = dineInOnlineSurface({
            payingOnline: true,
            picked,
            paypayEnabled,
            paypayProbeLoading,
            hasLiveCode,
          });
          const bodies = [
            s.showCard,
            s.showQrPanel,
            s.showProbeSpinner,
            s.showPayPayIntro,
          ].filter(Boolean);
          assert.equal(
            bodies.length,
            1,
            `expected one body, got ${bodies.length} for ${JSON.stringify({
              picked,
              paypayEnabled,
              paypayProbeLoading,
              hasLiveCode,
            })}`,
          );
        }
      }
    }
  }
});

// ─────────────────────────────────────────────────────────────────────────────
// #1737 — huỷ mã QR

test("payPayQrCancelPath dùng CÙNG đường dẫn với mint (khác động từ)", () => {
  assert.equal(payPayQrCancelPath("o1"), payPayQrCreatePath("o1"));
});

test("cancelPayPayQr gửi DELETE và keepalive", async () => {
  const calls: Array<{ url: string; init: RequestInit }> = [];
  await cancelPayPayQr("o1", "tempoqr-abc", async (url, init) => {
    calls.push({ url, init });
    return null;
  });

  assert.equal(calls.length, 1);
  assert.equal(calls[0].init.method, "DELETE");
  assert.equal(calls[0].init.keepalive, true);
  assert.match(calls[0].url, /\/orders\/o1\/paypay-qr$/);
});

// godx-tempo#1737 — huỷ phải NÊU TÊN mã. `liveAttempt()` ở backend luôn giải ra
// attempt MỚI NHẤT, nên một lượt huỷ đến muộn (khách thoát rồi mint lại ngay)
// sẽ giết đúng mã vừa mint mà họ đang nhìn nếu request không nói mã nào.
test("cancelPayPayQr nêu tên mã đang huỷ trong body", async () => {
  const calls: Array<{ url: string; init: RequestInit }> = [];
  await cancelPayPayQr("o1", "tempoqr-thecodeonscreen", async (url, init) => {
    calls.push({ url, init });
    return null;
  });

  assert.deepEqual(JSON.parse(String(calls[0].init.body)), {
    merchant_payment_id: "tempoqr-thecodeonscreen",
  });
});

test("cancelPayPayQr NUỐT lỗi — người dùng đã quyết định đi tiếp", async () => {
  // Không có `rejects`: nếu nó ném thì test này đỏ, và đó là điều cần ghim.
  await cancelPayPayQr("o1", "tempoqr-abc", async () => {
    throw new Error("mạng chết");
  });
});

// ─── godx-tempo#1737: watching a code the customer walked away from ─────────
// Leaving the panel does not kill the code. The status endpoint BOOKS money
// (`syncStatus`), so continuing to call it is what turns a 15-minute sweeper
// wait into a next-tick settlement.

test("orphaned code keeps being watched while it could still be paid", () => {
  assert.equal(
    shouldWatchOrphanedPayPayQr({ phase: "waiting", elapsedMs: 0 }),
    true,
  );
  assert.equal(
    shouldWatchOrphanedPayPayQr({ phase: "waiting", elapsedMs: 5 * 60_000 }),
    true,
    "a scan can still land in the code's final seconds",
  );
});

test("orphan watch stops on any definite answer — nothing left to book", () => {
  for (const phase of ["paid", "expired", "failed"] as const) {
    assert.equal(
      shouldWatchOrphanedPayPayQr({ phase, elapsedMs: 0 }),
      false,
      `${phase} keeps polling`,
    );
  }
});

test("orphan watch gives up past the code's own lifetime — sweeper takes over", () => {
  assert.equal(
    shouldWatchOrphanedPayPayQr({
      phase: "waiting",
      elapsedMs: PAYPAY_ORPHAN_WATCH_MS,
    }),
    false,
  );
  assert.equal(
    shouldWatchOrphanedPayPayQr({
      phase: "waiting",
      elapsedMs: PAYPAY_ORPHAN_WATCH_MS - 1,
    }),
    true,
    "boundary is exclusive",
  );
});
