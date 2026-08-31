---
title: Stripe Terminal — server-driven card_present (#1088)
category: guide
tags: [payment, stripe, terminal, card-present, reader, wisepos, pos]
summary: >
  The Stripe Terminal runtime (WisePOS and other smart readers) — NOT P400 or
  VescaJS. Cloud creates a card_present intent and pushes it to the reader
  through the Stripe API; settlement happens by webhook over the #1125 async
  lifecycle. OFF by default (catalog Disabled) pending certification; already
  sandbox-verified against a simulated reader.
related:
  - guide/pos-card-terminal-p400-vesca.md
  - guide/async-payment-methods.md
  - plans/plan-047/CAPABILITIES.md
status: runtime shipped 2026-07-27 (#1088); Disabled pending certification — enable with payments.stripe_terminal.enabled
---

# Stripe Terminal (server-driven card_present)

> Part of the payments cluster — the map of all twelve docs is [Payments — where to start](payments-overview.md).

## Do not confuse this with the P400

| | Verifone P400 (in production) | Stripe Terminal (this runtime) |
|---|---|---|
| Who drives it | The client opens a LAN `ws://` connection (VescaJS, kiosk/workstation webview) | **Cloud** calls the Stripe API to push the intent down to the reader |
| Provider | SBPS 対面 | Stripe |
| Ledger | `card_terminal`, pending then confirmed by hand | A real gateway, settled by webhook |
| Guide | pos-card-terminal-p400-vesca.md | this document |

The two systems **coexist** — Stripe Terminal does not replace the manually
recorded card reader.

## Status: fail-closed pending certification

With `payments.stripe_terminal.enabled = false` (the default), every endpoint
returns 409 `STRIPE_TERMINAL_DISABLED`, and the catalog row
`stripe.terminal.card_present.v1` stays Disabled in CAPABILITIES.md. The runtime
is fully implemented and sandbox-verified (simulated WisePOS E, a ¥1,200 intent
→ process → tap → succeeded); what remains is ops work: a real reader, the
country/account/currency rows, certification, and then flipping the flag.

## Architecture

- **Reader registry**: register with the code shown on the device screen via
  `POST /shops/{slug}/stripe-terminal/readers` (SSO). The per-branch Terminal
  Location is ensured automatically (`metadata.tempo_branch_id`). The reader is
  stored as a `PeripheralDevice` of type `payment_terminal` with
  `metadata.provider = stripe_terminal` — the P400's `metadata.host` contract is
  unchanged. Re-registering the same device updates it instead of creating a
  duplicate.
  ⚠️ **A JP account requires `address_kanji`** when creating a Location — the
  plain `address` field is usually rejected by Stripe (caught on a real
  sandbox; the service picks the right field from
  `payments.stripe_terminal.location_country`).
- **Charge**: `POST /pos/orders/{id}/stripe-terminal/charge
  {peripheral_device_id}` makes Cloud create an intent with
  `payment_method_types: ['card_present']` (the ONLY legitimate exception to
  that parameter) for the outstanding amount, then run
  `process_payment_intent` on the reader, producing a **PENDING ledger row**
  (reusing the #1125 async lifecycle unchanged). If the reader refuses, the
  intent is cancelled, no row is written, and the call returns a clean 422.
- **Settle**: the `payment_intent.succeeded` webhook flips the pending row and
  settles the order (resolving `metadata.order_id` — it never touches the
  customer-web pointer).
- **Cancel**: `POST /pos/stripe-terminal/cancel` aborts both the reader action
  and the intent, marking the row failed; if the intent already succeeded (the
  customer tapped inside the race window), the row is **not** failed — the
  webhook settles it.

## Connection scope

v1 runs on the legacy global platform connection (the same scope as the current
webhook secret). Connect merchant routing joins at certification time (plan-048
Gate 2).

## Pins

`StripeTerminalRuntimeTest` (9 tests): fail-closed, idempotent registry,
charge→pending, signed-webhook settle end to end, reader refusal, cancel in both
directions, branch and paid guards.
