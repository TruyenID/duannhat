---
title: Gateway settlement & payout reconciliation (plan-050, #1155)
category: guide
tags: [payment, settlement, payout, stripe, paypay, reconciliation, fees, aging]
summary: >
  Sub-ledger quán ↔ gateway: real per-transaction gateway fees from balance
  transactions, two-direction payout reconciliation, and pending-payout aging.
  Estimates are dashboard-only; booked numbers always come from the gateway.
related:
  - guide/payment-topology-and-tender-model.md
  - guide/business-time.md
  - plans/plan-050/DESIGN.md
status: backend M1+M2+M4-core shipped 2026-07-28; M3 PayPay importer blocked on T1.0 (real report file); M5 admin surfaces pending
---

# Gateway settlement and payout reconciliation

> Part of the payments cluster — the map of all twelve docs is [Payments — where to start](payments-overview.md).

This guide covers the plan-050 settlement sub-ledger: how real gateway fees
land per transaction, how payouts (tiền về tài khoản) are verified, and how
operators run and read `settlements:reconcile`. Read it before touching
anything under `backend/app/Services/Payment/Settlement/`.

## The boundary (most important thing on this page)

```text
order_conditions               =  shop ↔ CUSTOMER   (what the customer owes — the invoice)
order_payments (AR, #1151)     =  shop ↔ CUSTOMER   (what the customer paid, when)
payment_settlements (plan-050) =  shop ↔ GATEWAY    (what the gateway kept as fees, net per event)
gateway_payouts (plan-050)     =  GATEWAY → BANK    (which transfer carried the money, and does it add up)
```

- **Gateway fees never touch an order.** Order revenue and consumption tax
  are computed on the gross the customer paid; the fee is a selling expense
  (支払手数料) recorded on the settlement side only.
- **No GL** (ADR #1151) — but the accounting mapping is ready-made: revenue =
  orders, collections = payments, cash-in = payouts, difference = fees +
  money still held at the gateway.

## Three layers

| Layer | Source | Table / column | Authority |
|---|---|---|---|
| L1 estimate | `fee_estimate` on the connection option (`merchant_configuration`) | `payment_attempts.estimated_fee_minor` | **Dashboard only — never booked** |
| L2 real fee per transaction | Stripe balance transaction (API) / PayPay 精算 report (M3) | `payment_settlements` | Authoritative |
| L3 payout | `payout.paid` webhook + payout balance-transaction listing / 振込 report line | `gateway_payouts` + `payment_settlements.payout_id` | Authoritative |

Invariant per row (S-15, enforced before persist by
`SettlementRowAssembler` — the only sanctioned row builder):

```text
net_minor = gross_minor - fee_minor - fee_tax_minor
```

Invariant per payout (S-12, verified, never assumed):

```text
Σ net_minor of attached settlement rows == gateway_payouts.net_minor
```

## Schema

Three hand-written tables (`backend/database/migrations/2026_07_28_150000_*`):

| Table | Row meaning | Idempotency constraint |
|---|---|---|
| `payment_settlements` | One typed money-event on the gateway side (`payment`, `refund`, `dispute_withdrawal`, `dispute_reversal`, `dispute_fee`, `account_fee`, `manual`) | `UNIQUE (provider, external_ref)` |
| `gateway_payouts` | One gateway → bank transfer | `UNIQUE (provider, external_payout_id)` |
| `settlement_report_batches` | One report-file import (M3) | `UNIQUE (file_hash)` |

All amounts are **signed bigint minor units** — refunds, dispute
withdrawals and debit payouts are legitimately negative (S-11); nothing
calls `abs()`. `provider_settled_at` follows the **gateway's calendar**, not
branch business time (#1091 — see
[Business time](business-time.md)); settlement reports never use
`BusinessClock`.

Documented deviation from DESIGN.md §2: `payment_settlements.metadata`
(nullable json) exists because S-12 requires manual rows to carry a reason
and S-13 requires unknown provider types to keep their raw type.
`gateway_payouts.status` additionally allows `mismatch` — the queryable
"Σ check failed" verdict S-12 and reconcile direction B need.

### Row statuses

| Status | Meaning |
|---|---|
| `pending_payout` | Real fee known, money still held at the gateway |
| `reconciled` | Attached to a payout whose Σ verified — row is now **immutable** (S-24) |
| `orphan` | The gateway reported money we cannot match (kept forever, S-05) |
| `mismatch` | The row contradicts something we can verify (e.g. currency, S-17) — flagged, never auto-corrected |

## Stripe path (API-driven, M2)

All ingest hangs off the plan-048 provider-event inbox
(`ProviderEventApplicator` → `StripeSettlementRecorder`). All Stripe API
access goes through the injectable `StripeSettlementClient` port
(`StripeSettlementApiClient` in production, `FakeStripeSettlementClient` in
tests — no settlement test performs HTTP).

| Event | Effect |
|---|---|
| `payment_intent.succeeded` | Fetch the charge's balance transaction → `kind=payment` row (`source=api`, `pending_payout`). No local attempt yet → `orphan` (S-19). |
| `charge.refunded` | One `kind=refund` row per refund balance transaction. Stripe keeps the original fee, so the refund txn reports fee 0 — recorded from the statement, not hardcoded (S-07). |
| `charge.dispute.created` / `funds_withdrawn` | The negative dispute txn splits into `dispute_withdrawal` (gross) + `dispute_fee` (the ¥1,500 fee, read from the txn) whose nets sum to the txn net (S-08). |
| `charge.dispute.funds_reinstated` / `closed` (won) | Append-only `dispute_reversal` row — withdrawal rows are never edited (matches the #1123 reinstatement philosophy). |
| `payout.paid` | Upsert `gateway_payouts`, list the payout's balance transactions, attach `payout_id`, backfill missed rows (unknown types → `kind=manual` + raw type in metadata, S-13), then verify Σ. Match → `reconciled_at` + rows `reconciled`; differ → payout `mismatch`, **never** auto-balanced (S-12). |
| `payout.failed` | Payout `failed`; its rows are released back to `pending_payout` through the sanctioned guard bypass, keeping a `released_from_payout_ids` audit trail (S-10). |

Fee tax comes from `fee_details` entries of type `tax` when the statement
carries them; JP card fees are 非課税 so `fee_tax_minor` is 0 **because the
data says so** (S-16). PayPay fees are taxable 10% JCT — they will come from
the report columns in M3, same rule.

Failure posture: for payment/refund/dispute events the recorder is
**fail-open** — a settlement fault is logged and never dead-letters a
payment event whose ledger outcome already succeeded. `payout.*` events are
owned by the recorder, so their failures ride the inbox retry. The daily
reconcile sweep is the backstop either way (G6: a missing webhook
subscription surfaces as direction-A findings, so money is never lost, only
late).

## Estimate layer (L1) — never the truth

Declare the contract rate on the connection option:

```json
{
  "fee_estimate": { "percent": 3.6, "fixed_minor": 0 }
}
```

(`payment_gateway_connection_options.merchant_configuration`). When an
attempt reaches `succeeded`, `EloquentPaymentPersistence` stamps
`payment_attempts.estimated_fee_minor` via `SettlementFeeEstimator`. Rules:

- No declared config → `null`. There is no default rate — a missing estimate
  must look missing, not free.
- An existing stamp is never recomputed (the estimate reflects the catalog
  at payment time; drift against the real fee is a signal, not an error).
- **Official reports read `payment_settlements` exclusively.** The G1
  contract test (`SettlementFeeEstimatorTest`) scans every settlement
  source file and fails the build if any references the estimate column.

## Reconcile and aging (M4)

```bash
php artisan settlements:reconcile [--connection=<uuid>] [--dry-run]
```

Runs daily at 06:30 `operations_timezone` (see `routes/console.php`).
Idempotent — running it twice back-to-back changes nothing (S-23). Sections
of the output:

| Section | Direction | Meaning / action |
|---|---|---|
| Unsettled payments | A (us → gateway) | Succeeded gateway payments older than the provider window with no settlement row. Check webhook subscription (`payout.paid`, `charge.refunded` enabled?), then the gateway dashboard. |
| Re-matched orphans | — | Orphans automatically claimed by late-arriving payments (offline replay #1092). Informational. |
| Orphan rows | B (gateway → us) | Gateway money we cannot match. Kept forever; investigate against the merchant panel. |
| Mismatch payouts | B | Σ of attached rows ≠ payout net. Never auto-balanced — a human explains the difference; a correcting entry is a **manual row** (`source=manual`) with `imported_by_id` + reason in metadata. |
| Mismatch rows | B | Row-level contradictions (currency etc.). |
| Aging | — | `pending_payout` totals per connection in day buckets, with per-provider over-threshold flags. |

Configuration (`config/payments.php → settlement`):

| Key | Default | Meaning |
|---|---|---|
| `reconcile_after_days.stripe` | 7 | Direction-A window. Providers not listed (e.g. `internal`) are exempt — cash has no gateway statement. |
| `reconcile_after_days.paypay` | 45 | Monthly cycle + buffer. |
| `aging_alert_days.{provider}` | 7 / 45 | Aging over-threshold flag per provider (G4: configuration, not hardcode). |
| `aging_buckets` | `[3, 7, 14, 30]` | Upper-inclusive day edges; an open-ended bucket is appended. |

`SettlementAgingReportService::pendingPayoutAging()` provides the same data
programmatically for the M5 admin surface.

## Read API (M5 T5.0, #1370)

Until this landed, the reconcile scheduler had been writing settlement data
daily with **no way to read it except a database client**.

```
GET /api/v1/hq/{brandSlug}/settlements            # rows: gross, fee, fee_tax, net, status
GET /api/v1/hq/{brandSlug}/settlements/batches    # imported report batches + matched/orphan counts
GET /api/v1/hq/{brandSlug}/settlements/payouts    # gateway payouts (money leaving the gateway)
GET /api/v1/hq/{brandSlug}/settlements/aging      # pending-payout aging buckets
```

**Why HQ scope and not shop.** A settlement belongs to a *gateway connection* —
the entity that holds the merchant account and the bank account it pays into —
and a connection is brand-level (`payment_gateway_connections.brand_id`). This is
the same split Stripe, Adyen and Square all make: transactions belong to the
location, payouts and fees belong to the account holder. A shop-scoped view is
possible later and would return that branch's own settlement lines **only** —
never connection-level payout totals, which mean nothing at one branch.

**Authorization rides `PaymentGatewayConnection`** (`viewAny`/`view`) rather than
a new permission: whoever may see the connection may see its money. A second
permission axis over the same asset is a place for the two to drift apart.

**Scoping is `whereIn(brand connections)` first, filters second.** The
user-supplied `connection_id` filter narrows *within* the brand's connections —
a connection id belonging to another brand returns an empty list, not that
brand's money. `pendingPayoutAging(null)` scans every connection of every tenant,
so the controller never calls it without an id.

**No estimates, ever (G1).** The response allowlists fields explicitly instead of
serialising the model, so `payment_attempts.estimated_fee_minor` — or any future
internal column — cannot appear by default. Ships with a test asserting the
response body contains neither `estimated_fee_minor` nor the substring
`estimate`.

Query filters on the rows endpoint: `connection_id`, `status`, `kind`,
`provider`, `currency`, `settled_from` / `settled_to` (against
`provider_settled_at`), and `unmatched=1` — the orphan shape, which is a slice of
the same table rather than a separate resource. Pagination is `per_page`
(default 50, max 200).

**CSV export (T5.2) is deliberately a separate future endpoint**, not a `format=`
parameter here: the CSV column order is a contract with accounting, and coupling
it to this JSON shape means one cannot change without the other.

## Immutability (S-24)

A `reconciled` settlement row refuses `update()` and `delete()` at the
model level (same pattern as `PaymentPolicyRevision`). Corrections are new
append-only rows. The single sanctioned exception is
`PaymentSettlement::whileReleasingReconciled()` — used only by the S-10
payout-failure release.

## Verification

```bash
cd backend
php -d memory_limit=-1 vendor/bin/pest --compact \
  tests/Unit/Services/Settlement \
  tests/Feature/Settlement
```

Suites: `SettlementRowAssemblyTest` (S-15/S-11/S-16),
`SettlementFeeEstimatorTest` (L1 + G1 contract),
`StripeBalanceTxnIngestTest` (kinds, idempotency, S-07/S-08/S-17/S-19),
`PayoutReconcileTest` (S-10/S-11/S-12/S-13/S-23),
`SettlementGuardTest` (S-24 + DB constraints S-01/S-02/S-21),
`SettlementReconcileCommandTest` (directions A/B, re-match, S-23),
`AgingReportTest` (S-22, 3-timezone freeze),
`ProviderEventSettlementHookTest` (fail-open + payout routing),
`EstimatedFeeStampTest` (L1 stamping),
`StripeWebhookSubscriptionAuditTest` (T2.4 — required-event coverage,
family match, disabled endpoint, unreachable connection).

## Ops runbook

- **`partial` is not `ok`.** A FAMILY requirement (`charge.dispute.*`) matched
  by only some concrete members is reported as `partial`, with the members it
  did see. The command deliberately **does not judge** whether that family is
  complete: Stripe's event list changes over time, and asserting "complete"
  here would be guessing a third party's contract. `--strict` fails on
  `partial` — it exists to PROVE the subscription is sufficient, and `partial`
  has not proven that.

- **Webhook checklist per Stripe connection**: the endpoint must subscribe
  to `payment_intent.succeeded`, `charge.refunded`, `charge.dispute.*`,
  `payout.paid`, `payout.failed`. A missing `payout.*` event shows up as
  payouts never reconciling; a missing `charge.refunded` shows up as
  direction-A noise on refund-heavy days.

  The checklist is **machine-checked** (T2.4, #1978) — the list above lives
  in config `payments.settlement.required_webhook_events.stripe`:

  ```bash
  php artisan settlements:audit-webhooks                      # every active Stripe connection
  php artisan settlements:audit-webhooks --connection=<uuid>  # one connection
  php artisan settlements:audit-webhooks --json --strict      # CI / cron: non-zero on a gap
  ```

  It lists the endpoints registered at Stripe and names the required events
  nobody is subscribed to. Rules worth knowing before reading its output:

  - Coverage is the **union** across a connection's endpoints — splitting
    payout events onto a second endpoint is a legitimate setup.
  - A **disabled** endpoint counts for nothing (it delivers nothing); it is
    listed separately so the missing events make sense.
  - `*` covers everything; a required entry ending in `.*` is a family and is
    satisfied by any concrete member (Stripe's `enabled_events` accepts only
    concrete names or `*`, never a family wildcard).
  - A connection Stripe would not answer for is reported as **error**, never
    as "everything missing" — the sweep did not look, so it does not accuse.
  - **Scope caveat**: each connection is read in its own Stripe account
    scope. A platform-level Connect endpoint (`connect: true`) is invisible
    from a connected account, and the retrieved `WebhookEndpoint` object
    carries no field saying whether a platform endpoint is a Connect one —
    so the two scopes are deliberately not merged. Audit the platform
    connection on its own.
  - Read-only by design: it never creates or edits an endpoint. Subscribing
    an endpoint to money events stays a human act in the dashboard.

  This is the pre-emptive twin of reconciliation direction A: there, a
  missing subscription only surfaces days later as money that never arrived,
  indistinguishable from a genuine gateway delay.
- **Mismatch payout**: read `metadata.reconciliation_mismatch` on the
  payout (expected vs attached net). Compare against the Stripe dashboard
  payout detail. Book the explained difference as a manual row; never edit
  existing rows.
- **PayPay JCT invoice (T4.5, when M3 lands)**: PayPay's fee is a taxable
  service (10% JCT). 仕入税額控除 requires keeping PayPay's インボイス for
  the fee (issued in the merchant panel) per settlement cycle, filed next
  to the imported report. JP card fees are 非課税 — no invoice needed.
- **PayPay importer (M3)** is intentionally absent: it is blocked on T1.0 —
  obtaining a real 精算レポート/取引明細 file (columns, encoding, row key)
  before any parser is written (G2). Do not write it from assumptions.

## Out of scope (G5)

Bank-statement matching (`bank_ref` reserves the seat), GL export, delivery
platform fees (UberEats), and automatic PayPay report download. Same
pattern, later plans.
