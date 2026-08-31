---
title: Payment topology — gateway vs tender, POS modes, 釣銭機
category: guide
tags: [payment, pos, orchestrator, tender, workstation, plan-047]
summary: >
  A summary of the Plan 047 model — separating PaymentGatewayProvider from
  PaymentMethod, the payment flows that never touch a gateway (cash,
  card_terminal, pending confirm), the cloud-only versus workstation-LAN
  topologies, and the constraint that the 釣銭機 only ever reports locally.
related:
  - guide/payment-gateway-paypay-certification.md
  - guide/cashier-shift-recovery.md
  - plans/plan-047/DESIGN.md
  - plans/plan-047/CAPABILITIES.md
---

# Payment topology & tender model

> Part of the payments cluster — the map of all twelve docs is [Payments — where to start](payments-overview.md).

> This document collects the Plan 047 architecture decisions concerning **shops
> with and without a workstation**, **payments that never go through a gateway**
> (hand-recorded, a standalone bank terminal, cash), and the **釣銭機** (the
> change machine — it speaks only locally and never writes directly to Cloud).

## In one sentence each

- **PaymentGatewayProvider** = who *can* move money through a third-party API (or
  `internal` = nobody).
- **PaymentMethod** = the POS tender label and the Z-report bucket — **not** the
  place where a provider is configured.
- **One orchestrator** in Cloud: either the gateway path (Stripe/PayPay) **or**
  the ledger path (`recordTender`).
- **Shop topology** only changes the *transport* (pos-web → Cloud directly, or
  through workstation sync); it never changes the ledger contract.

---

## Two data layers (do not merge them)

| Layer | Table / enum | Cashier picks it? | Role |
|---|---|---|---|
| **Gateway provider** | `payment_gateway_providers` (`stripe`, `paypay`, `sbps`, `internal`) | No | The API contract / runtime adapter |
| **Gateway option** | `payment_gateway_options` (catalog) | Indirectly, through policy | Rail + channel + capability (`stripe.pi.card.web.v1`, `internal.cash.v1`, …) |
| **Connection** | `payment_gateway_connections` | Admin/HQ | The real merchant, the secret reference, the HQ/franchise owner |
| **Effective option** | The policy resolver's output | Yes (POS/kiosk UI) | The intersection of catalog × connection × HQ × shop × device |
| **PaymentMethod** | `payment_methods` | Yes (compat) | Display label, Z-report, till anchor — **currently a compat layer**, gradually derived from the effective option |

The relationship (Plan 047 ADR, Decision 2):

```text
PaymentGatewayProvider
  └── PaymentGatewayOption (catalog)
        └── Connection (merchant instance)
              └── Effective option (policy)
                    └── maps to → PaymentMethod.code (cash, card_terminal, …)
```

The **runtime adapters** (`config/payments.php` → `gateway_drivers`) are currently
only:

- `stripe` → `StripePaymentGateway`
- `paypay` → `PayPayPaymentGateway`

`internal` and `sbps` exist as enum/catalog entries — they have **no** adapter
calling an external API; cash and debt go through `recordTender`.

---

## Three axes describe every form of payment

A payment is not just "pick a PaymentMethod". Three axes are needed:

| Axis | The question | Examples |
|---|---|---|
| **1. External money** | Does the money go through a provider API? | Stripe PI, PayPay preauth, or **internal** (ledger only) |
| **2. Tender / UI** | What does the staff member or customer pick on the POS? | cash, card_terminal, PayPay QR |
| **3. Interaction** | How does the money arrive? | Recorded by hand, pending→confirm, a device event, a provider SDK |

Only axis **1** has `prepare → provider call → finalize`. Axes **2 and 3** decide
the UX and the timing (`immediate` vs `pending`).

---

## The two shop topologies

### A. Cloud-only (many shops have **no workstation**)

```text
pos-web (counter tablet) ──HTTPS──► Cloud Laravel
POST /api/v1/pos/orders/{id}/payments
Auth: SSO + ResolveOpenTillSession (an open shift)
```

- Deployment: `VITE_WORKSTATION_API_URL=none` makes `resolveBaseUrl()` always
  choose Cloud (`web/pos/src/services/workstation/base-url-resolver.ts`).
- Every payment is written **straight** into `order_payments` in Cloud — no local
  SQLite, no sync queue.
- Cash and card_terminal: `OrderPaymentService::create` → the orchestrator's
  `recordTender` (when the runtime is ON).

### B. LAN + workstation (some shops)

```text
pos-web ──LAN──► workstation (SQLite)
                      │
                      │ sync UP: payment.create
                      ▼
                 Cloud POST /api/v1/workstation/payments
```

- Offline-first: write locally first, and the queue pushes to Cloud afterwards
  (`workstation/internal/handler/local_pos.go`, `sync_service.go`).
- Cloud still uses the same orchestrator and ledger — only the **request source**
  differs (a device token plus idempotency replay).

```text
                    ┌─────────────────┐
                    │ PaymentPolicy   │
                    │ Resolver        │
                    └────────┬────────┘
                             │
           ┌─────────────────┴─────────────────┐
           ▼                                   ▼
   EXTERNAL_FUNDED                      INTERNAL / MANUAL
   (connection + adapter)               (ledger-only)
           │                                   │
   prepare → API → finalize              recordTender
   or a webhook inbox                    or pending → confirm/fail
           │                                   │
           └─────────────────┬─────────────────┘
                             ▼
              OrderPaymentLedgerWriter → order_payments
                             ▼
              OrderService::settleIfPaid()
```

---

## Real-world case table

| Situation | Workstation? | Provider | PaymentMethod | Cloud flow |
|---|---|---|---|---|
| Staff take cash and enter the tendered amount | No / Yes | `internal` | `cash` | `recordTender` immediately (E1) |
| Staff swipe a bank terminal (Stera) and record it | No / Yes | `internal` | `card_terminal` | `recordTender`, auto-confirm (an E2 variant) |
| A shop with no integrated payment device yet | — | `internal` | `card_terminal` | Recorded by hand after an out-of-band swipe — Stripe/PayPay are **not** called |
| A pending card (kiosk/workstation) | Usually with a workstation | `internal` | `card` / `transfer` | `pending` → `confirm`/`fail` |
| Customer-web Stripe | — | `stripe` | `stripe` (canonical) | `prepareStripePaymentIntent` → webhook/finalize |
| PayPay wallet (when enabled) | — | `paypay` | A policy option | The gateway adapter |
| Recording a debt | — | `internal` | `debt` (`on_account`) | Ledger plus the debt workflow |
| **釣銭機** (change machine) | See the section below | `internal` | `cash` | Cloud only receives the **result** by POST; it never talks to the machine |

### The POS namespace: why is `card_terminal` auto-confirm?

The seeder deliberately sets `is_auto_confirm=true`, because the **Cloud POS has
no** `payments/{id}/confirm` route. A pending payment from the POS would leave the
order hanging forever.

```text
backend/database/seeders/PaymentMethodSeeder.php — code=card_terminal
routes/api/pos.php — store and refund only, no confirm
```

The kiosk and the workstation still use `card` with `is_auto_confirm=false` plus
the confirm/fail routes.

---

## The orchestrator: gateway vs ledger

The shared entry points are `OrderPaymentService::create` / `confirm` / `fail`.

| Condition | Branch |
|---|---|
| `is_auto_confirm` plus the orchestrator ON for that transport | `OrderPaymentOrchestrationCompat::recordAutoConfirmTender` → `PaymentOrchestrator::recordTender` |
| Stripe on customer-web | `prepareStripePaymentIntent` / `finalizeStripePayment` |
| `is_auto_confirm=false` | `OrderPaymentLedgerWriter::createRow` (`pending`), then `confirm()` |
| A confirm/fail carrying a `payment_attempt_id` | Additionally `finalizeLegacyConfirm` / `finalizeLegacyFail` |

**No gateway call:** cash, card_terminal, debt, and a manually pending terminal —
their provider reference logic is `ledger:{payment_id}`.

The orchestrator is ON by default in configuration:
`backend/config/payments.php` → `PAYMENT_ORCHESTRATOR_RUNTIME=true`, for all four
transports.

---

## Policy and the POS UI

- The POS loads `GET /api/v1/pos/effective-payment-options` — cash and card are
  not hard-coded (`Plan 047 F6`).
- The enricher maps to the legacy shape: `PosEffectivePaymentOptionEnricher` →
  `legacy_payment_method_id`, `client.immediate_settlement`,
  `client.requires_tendered`.
- pos-web: `effectiveOptionToPaymentMethod()` maps `immediate_settlement` to
  `is_auto_confirm`.

A shop with **no** Stripe/PayPay connection gets no integrated option from the
resolver; only the manual/internal siblings. A shop **with** a connection also
gets the integrated option (Terminal/PayPay) — although the Terminal runtime is
**not shipped** yet (`CAPABILITIES.md`: `stripe.terminal.card_present.v1` is
disabled).

---

## 釣銭機 (the change machine)

### Product constraints

1. The machine **only reports locally** — there is no Cloud-facing API to
   Glory/Fuji/etc.
2. Many shops **have no workstation**.
3. Cloud needs **no** 釣銭機 driver — only a correct ledger row (amount, tendered,
   change, till).

### Cloud-only (no workstation)

```text
釣銭機 ──(USB/LAN)──► the tablet/PC running pos-web
                           │
                           │ POST /api/v1/pos/.../payments
                           │ (tendered taken from the machine's event)
                           ▼
                        Cloud recordTender (cash)
```

- This needs a **device bridge in pos-web** (Web Serial, a native shell, or a
  local agent) — **not implemented** in this repo.
- Until that bridge exists: staff still take money through the 釣銭機 outside the
  UI, then press cash and type the tendered amount by hand — the ledger is
  correct, only the automation is missing.
- Optional metadata on the payment: `capture_source: cash_changer`,
  `device_id: …` (for audit).

### With a workstation

```text
釣銭機 ──► the workstation driver ──► SQLite payments
                                          │
                                          │ payment.create (sync UP)
                                          ▼
                                       Cloud recordTender
```

- The same ledger contract; only the transport differs.
- Offline: the shift and the Z-report happen locally first, and Cloud catches up
  on sync (Plan 047 F2/F4).

### What NOT to do in Cloud

- Do not add a `PaymentGatewayProvider` such as `glory` unless a cloud-facing
  settlement API appears later.
- Do not add an adapter in `gateway_drivers` for the 釣銭機.
- It stays `internal` plus `PaymentMethod.code = cash`.

---

## Client profile (manual cash vs 釣銭機)

| Situation | `immediate_settlement` | `requires_tendered` | Source of the numbers |
|---|---|---|---|
| Staff take cash (keypad) | true | true | The pos-web PaymentDialog |
| 釣銭機 (future) | true | false* | A device event auto-fills the tendered amount |
| Recording card_terminal (Stera) | true | false | The amount only |
| An integrated terminal (future) | Depends on the SDK | false | The provider plus the reader |

\* Or `requires_tendered=true` if the amount handed over is still displayed for
cross-checking — a product decision.

---

## Till / Z-report

- `cash` → drawer reconciliation (`expected_cash`).
- `card_terminal` → folded into the `card` bucket on the Z-report (the same
  physical payment device as counter card payments).

  `TillSessionService` / `workstation/internal/handler/local_pos_till.go` — merges
  `card_terminal` into `card`.

- Cloud-only POS: `till_session_id` is stamped by the `ResolveOpenTillSession`
  middleware.

---

## What exists and what does not

| Item | Status |
|---|---|
| Cloud-only POS payments | ✅ `POST /api/v1/pos/.../payments` |
| Manual cash plus tendered | ✅ E1 acceptance |
| Hand-recorded card_terminal | ✅ Seeder plus POS auto-confirm |
| Pending → confirm (kiosk/workstation) | ✅ E2 |
| Orchestrator ON by default | ✅ `config/payments.php` |
| Stripe/PayPay gateway adapters | ✅ Stripe web plus the PayPay SDK |
| Integrated Stripe Terminal | ❌ Catalog only, no runtime yet |
| `internal.cash.v1` catalog seed | ✅ Seeder plus data migration (2026-07-26, plan-048 T1.1) |
| 釣銭機 driver (workstation) | ✅ Shipped (the Glory adapter on the workstation — cash_changer.go; the cloud-only pos-web bridge is still ❌, which means **a 釣銭機 requires a workstation on the LAN**, even in a shop that is otherwise cloud-only) |
| J1 circuit breaker | ❌ Deferred |
| Generic webhook route (`/webhooks/payment/{provider}`) | ✅ Live (2026-07-26, plan-048 Gate 3) |
| PayPay HTTP webhook | ✅ `POST /api/v1/webhooks/payment/paypay` |

---

## Webhooks (Plan 048 Gate 3 — deployed)

| Provider | Route | Notes |
|---|---|---|
| Stripe | `POST /api/v1/webhooks/payment/stripe` | The connection is resolved from the Connect `account` → `merchant_account_id`; the per-connection secret comes from GatewaySecretStore (dual read during rotation); an unknown account falls back to the legacy global one (transitional) |
| Stripe (old alias) | `POST /api/v1/customer/stripe/webhook` | **Deprecated** — same pipeline; the response carries `Deprecation: true` plus `Sunset: 2027-06-01`; to be removed after 30 days of zero traffic (ROLLOUT Stage C) |
| PayPay | `POST /api/v1/webhooks/payment/paypay` | HMAC using `services.paypay.webhook_secret` (⚠️ this MUST be set before Gate 6 — with no secret, verification is skipped); the applicator converges through an authoritative retrieve (`ProviderRetrievalRecoveryService`) |
| Cash / card_terminal | N/A | Ledger-only, no provider events |

**A route that verifies signatures perfectly still delivers nothing if the
gateway is not subscribed to the event.** The Stripe side of that is
machine-checked per connection — `php artisan settlements:audit-webhooks`
(plan-050 T2.4, #1978) lists the endpoints registered at Stripe and names the
required events nobody is listening for. Required list, matching rules and the
Connect scope caveat: [`gateway-settlement.md`](./gateway-settlement.md#ops-runbook).
There is no PayPay equivalent — PayPay has no endpoint-listing API in use here.

Both routes verify the signature **before** any persistence; deduplication is on
`(connection, environment, provider_event_id)`; processing is async through the
inbox plus `ProcessPaymentProviderEventJob`. The optional `?connection={uuid}`
hint must match the provider and be active. Design detail:
[`plans/plan-048/WEBHOOKS.md`](../../plans/plan-048/WEBHOOKS.md); acceptance:
`Plan048ProviderWebhookIntakeTest` (C1-C6).

---

## Reference files

| Topic | Path |
|---|---|
| Plan 047 design | `plans/plan-047/DESIGN.md` |
| Plan 048 cutover | `plans/plan-048/README.md` |
| Capability matrix | `plans/plan-047/CAPABILITIES.md` |
| Orchestrator compat | `backend/app/Services/Payment/Orchestration/OrderPaymentOrchestrationCompat.php` |
| POS create payment | `backend/app/Http/Controllers/Api/V1/Shop/OrderPaymentController.php` |
| PaymentMethod seed | `backend/database/seeders/PaymentMethodSeeder.php` |
| POS API mode | `web/pos/src/services/workstation/base-url-resolver.ts` |
| Workstation local payment | `workstation/internal/handler/local_pos.go` |
| Sync UP | `workstation/internal/service/sync_service.go` |
| Acceptance E1/E2 | `backend/tests/Feature/Payment/Plan047AcceptanceLedgerSettlementTest.php` |

---

## Rollout direction (Plan 048)

The formal plan is [`plans/plan-048/`](../../plans/plan-048/README.md) — a ramp by
topology:

1. **Gate 1 — cloud-only POS:** the internal ledger (`cash`, `card_terminal`),
   transport `pos` only.
2. **Gate 2 — customer-web Stripe:** a real connection, the takeaway online plus
   counter-pay contract.
3. **Gate 3 — webhooks:** Stripe plus PayPay through the shared inbox.
4. **Gate 5 — workstation** (shops with a LAN).
5. **Gate 8 (optional) — 釣銭機:** a pos-web device bridge posting to Cloud.

The integrated terminal (`stripe.terminal.*`) remains its own initiative; it does
not replace hand-recorded `card_terminal`.

---

## Short FAQ

**Does every provider have a runtime PaymentGatewayProvider?**
No. Only providers with an adapter in `gateway_drivers`. Cash and debt are
`internal`, ledger only.

**Should PaymentMethod be deleted?**
Not yet. It is the compat layer and the Z-report anchor; it is gradually derived
from the effective option.

**How does a shop without a workstation use a 釣銭機?**
Machine → local pos-web → POST to Cloud. No workstation involved.

**Does a POS card record after a bank terminal call PayPay or Stripe?**
No, unless the Terminal SDK is integrated — today it is `card_terminal`, ledger
only.
