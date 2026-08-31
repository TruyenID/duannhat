---
title: Async payment methods — Konbini / 銀行振込 (Stripe, #1125 option B)
category: guide
tags: [payment, stripe, konbini, bank-transfer, async, customer-web, webhook]
summary: >
  Asynchronous payment infrastructure for customer-web: the customer receives a
  Konbini voucher or bank-transfer instructions and pays LATER (hours to days);
  a webhook closes the books. OFF by default (card-only); enabled with one env
  var plus the Stripe Dashboard. The lifecycle stays armed even while the flag
  is off.
related:
  - guide/payment-topology-and-tender-model.md
  - guide/takeaway-payment-policy.md
status: shipped 2026-07-27 (#1125 option B); pins ClaimD3AsyncMethodsTest (12) + ClaimD1 (metadata fallback)
---

# Async payment methods (Konbini / 銀行振込)

> Part of the payments cluster — the map of all twelve docs is [Payments — where to start](payments-overview.md).

## Default state: OFF — card-only

With `payments.async_payment_methods.enabled = false`, all four sites that create
a browser PaymentIntent keep `payment_method_types: ['card']` (the option-A
posture of #1125). Leave the flag off and behaviour is exactly what it has always
been.

## What changes when you turn it on

The intent switches to **Stripe dynamic payment methods**
(`automatic_payment_methods` with `allow_redirects: 'never'`, because the pay
page has no return trip for a redirect flow) — the Stripe Dashboard then decides
which methods each customer sees. Konbini voucher expiry is capped by
`payment_method_options.konbini.expires_after_days`
(`PAYMENT_KONBINI_EXPIRES_AFTER_DAYS`, default 3 — keep it at or below the
takeaway payment window so the reaper and the voucher never contradict each
other).

**Runbook to enable Konbini (three steps, no deploy needed):**
1. Enable Konbini / Bank transfer in the Stripe Dashboard under Payment methods.
2. Set `PAYMENT_ASYNC_METHODS_ENABLED=true`.
3. Adjust `PAYMENT_KONBINI_EXPIRES_AFTER_DAYS` if needed.

## Lifecycle — ALWAYS armed; the flag only opens the intent-creation door

```
confirm → intent `processing` / voucher `requires_action`
        → PENDING ledger row (awaiting_async_payment — NO 422, money NOT counted)
webhook payment_intent.succeeded (hours to days later)
        → FLIP that pending row → succeeded (currency + overpay guards run again)
        → order settles. No duplicate row.
webhook payment_failed / canceled
        → row marked failed + CLEAR the intent pointer on the order
        → the customer can pay again immediately.
```

- A pending row never reaches `paid_amount` or the KPIs (only succeeded and
  refunded rows do).
- `requires_action` counts as async only when `next_action` is a
  voucher/instruction type (konbini, bank transfer, oxxo…) — 3DS still fails as
  before.
- **The D1 tail was patched in the same wave**: the full-flow webhook falls back
  to `metadata.order_id` when the intent pointer has already been overwritten by
  a newer intent, so a late konbini payment always finds its order; the guards
  then decide between a ledger entry and an auto-refund of stranded money.

## Reaper (expired takeaway orders) — money-safe

Before expiring an order that still has a live async intent, the reaper
**cancels the intent at Stripe first** — the voucher dies, so there is no way to
pay for an order that has been cancelled. An intent that already succeeded is
**not** expired (the webhook will settle it). If Stripe is unreachable, that
order is skipped. A host with no `STRIPE_SECRET` configured still reaps
counter-pay orders normally, because the service is only constructed when a
pending row actually exists.

## Customer-web UX

`payment-context` returns `async_payment_methods_enabled` (fail-closed), which
makes the Payment Element show method tabs; confirm returns `pending: true`,
which shows a waiting screen (ja/en/vi) that flips to success on the realtime
`order.paid` event. Desktop and mobile checkout route back to
`/orders/{id}?awaiting_payment=1`. Dine-in stays card-only on purpose.

## Not routed through the orchestrator

The orchestrator path (policy/catalog, plan-047/048) stays card-only per the
capability matrix — opening async there needs its own catalog option (a relative
of #1088). This flag only affects the legacy `StripePaymentService` sites in
customer-web.
