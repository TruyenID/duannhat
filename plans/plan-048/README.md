---
plan: 048
title: Payment gateway production cutover and provider webhook completion
slug: payment-gateway-production-cutover
issue: 1098
status: shipped
branch: feature/plan-048-payment-gateway-cutover
created: 2026-07-23
updated: 2026-08-05
parent: plan-047
landed_via: >-
  merged to dev (feature branch deleted); see the plan's tracking issue
  for how it closed. TASKS.md checkboxes are NOT the completion signal here —
  several plans shipped by a different route than the ladder they describe (#1802).
---

# Plan 048 — Payment gateway production cutover

> **GitHub:** [godx-jp/godx-tempo#1098](https://github.com/godx-jp/godx-tempo/issues/1098) — status `implementing` since 2026-07-26.

Plan 047 built the **scaffolding** (orchestrator, adapters, policy API, inbox, acceptance tests).
Plan 048 **ships it**: staged transport cutover, real merchant connections, generic provider
webhooks, and removal of legacy Stripe/global paths — without breaking cloud-only shops (no
workstation), takeaway counter-pay, or internal ledger tenders (cash / card_terminal).

## Why this plan exists

| Gap (as of 2026-07-23) | Impact |
|---|---|
| Orchestrator code default ON but **production unproven** | Money path untested at scale |
| Stripe customer-web still on **`LegacyGlobalStripeConnection`** | Wrong merchant, no HQ/franchise policy |
| Webhook inbox **Stripe-only** (`/customer/stripe/webhook`) | PayPay has adapter `verifyWebhook` but **no HTTP route** |
| Takeaway **counter-pay** never hits payment API at checkout | Correct — but undocumented ops + POS handoff |
| `internal.cash.v1` in CAPABILITIES but **not seeded** | Policy/catalog gap for ledger tenders |
| T7.6 legacy cleanup **open** | Dual writers remain |

Companion doc: [`docs/guide/payment-topology-and-tender-model.md`](../../docs/guide/payment-topology-and-tender-model.md).

## Relationship to Plan 047

- **047 = build** (Gates 0–6 largely done; Gate 7 partial).
- **048 = cutover + complete webhooks + retire legacy** (finish Gate 7, add missing provider routes).
- Do **not** reopen 047 schema/orchestrator design unless a cutover blocker requires it.

## Shop topologies (explicit)

| Topology | Payment transport | Webhook? |
|---|---|---|
| **Cloud-only POS** (`VITE_WORKSTATION_API_URL=none`) | `POST /api/v1/pos/…/payments` → Cloud | N/A for cash/terminal |
| **Customer-web Stripe** (takeaway + dine-in QR) | intent + confirm + **webhook inbox** | **Yes — Stripe** |
| **Takeaway counter-pay** | Order only on customer-web; **POS collects later** | N/A at checkout |
| **Workstation LAN** (subset of shops) | local SQLite → sync UP | N/A for ledger; Stripe via Cloud |
| **釣銭機** | Local device → pos-web → Cloud POST | **No provider webhook** (optional Phase 8) |

## Delivery gates

| Gate | Outcome | Blocks production? |
|---|---|---|
| **0** | Doc sync, observation tooling, kill-switch runbook | Yes |
| **1** | Cloud-only POS: internal tender soak (`pos` transport) | Yes — first live slice |
| **2** | Customer-web Stripe on **real connection** + policy (not legacy global) | Yes — takeaway online pay |
| **3** | **Generic provider webhook intake** + PayPay route + Stripe per-connection | Yes — async recovery |
| **4** | Takeaway counter-pay contract, ops runbook, acceptance tests | Yes — mixed shops |
| **5** | Workstation transport soak (shops that have WS) | Only WS-enabled shops |
| **6** | PayPay sandbox → staging pilot (optional prod flag) | PayPay-only shops |
| **7** | Observation window, T7.6 legacy removal, J1 decision | Yes — declare shipped |
| **8** | 釣銭機 pos-web bridge (optional per customer) | No — deferrable |

## Non-negotiable invariants (inherited from 047)

1. One ledger writer through orchestrator compat → `PaymentOrchestrator`.
2. Internal tenders (cash, card_terminal, debt) = **`recordTender`**, never gateway API.
3. External money = **prepare → provider call → finalize** or **verified webhook → applicator**.
4. Webhooks: verify before ack, dedupe, async process, dead-letter + operator recovery.
5. Counter-pay takeaway: **no fake payment row** at customer submit — money at POS only.
6. Cloud-only shops never require workstation for payment correctness.

## Success criteria

- [ ] `pos` transport live on ≥1 production org with **zero ledger drift** (7-day observation).
- [ ] Customer-web Stripe uses **connection-scoped** secrets and stamps `gateway_*` on payments.
- [ ] `POST /api/v1/webhooks/payment/{provider}` (or equivalent) receives **Stripe + PayPay** with inbox parity.
- [ ] Takeaway counter + Stripe online documented and covered by Pest acceptance rows.
- [ ] `LegacyGlobalStripeConnection` removed from live path (compat read-only for history).
- [ ] Plan 047 T7.6 table empty or explicitly deferred with ticket links.

## Out of scope

- SBPS production adapter (architecture proof only if time permits).
- Stripe Terminal SDK integrated POS (catalog row exists; runtime separate initiative).
- Replacing `PaymentMethod` admin CRUD entirely (sunset 2027-01-01 stands).
- customer-web submodule init / Amplify deploy wiring (tracked separately).

## Artifacts

| File | Purpose |
|---|---|
| `TASKS.md` | Ordered implementation checklist |
| [ROLLOUT.md](ROLLOUT.md) | Staging → prod ramp, kill switches, rollback |
| `TESTS.md` | Acceptance scenarios for this cutover |
| [WEBHOOKS.md](WEBHOOKS.md) | Provider webhook URL matrix and intake design |

## References

- [Plan 047 README](../plan-047/README.md)
- [Plan 047 ROLLOUT SLOs](../plan-047/ROLLOUT.md)
- [Payment topology guide](../../docs/guide/payment-topology-and-tender-model.md)
- [API payment gateways](../../docs/reference/api-payment-gateways.md)
