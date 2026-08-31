---
plan: 047
title: Unified Payment Gateway, orchestration, and canonical domain mutation boundaries
slug: unified-payment-gateway-orchestration
issue: 968
status: shipped
branch: feature/plan-047-unified-payment-gateway-orchestration
created: 2026-07-22
updated: 2026-08-05
landed_via: >-
  merged to dev (feature branch deleted); see the plan's tracking issue
  for how it closed. TASKS.md checkboxes are NOT the completion signal here —
  several plans shipped by a different route than the ladder they describe (#1802).
---

# Plan 047 — Unified Payment Gateway, orchestration, and canonical domain mutation boundaries

This plan replaces the two current payment engines with one provider-neutral payment domain. It
adds explicit merchant-account ownership, gateway capabilities, HQ/shop/device policy resolution,
durable provider recovery, and one settlement path for every payment source. The accepted scope
amendment also closes Order/Product/Menu/Customer mutation bypasses in the same touched paths so
each transport is migrated once rather than refactored again after payment cutover.

## Status

- **Current:** `implementing` — approved by the user on 2026-07-22.
- **GitHub:** [godx-jp/godx-tempo#968](https://github.com/godx-jp/godx-tempo/issues/968)
- **Research mode:** full research (official provider/PCI sources, project code, Omnify schemas,
  admin/POS/Kiosk UI, and offline workstation flows).

## Why this is necessary

Tempo has one `order_payments` ledger but two application engines:

- POS, Kiosk, and Workstation use `OrderPaymentService` and delegate fully-paid transitions to
  `OrderClosingService`.
- Customer Stripe flows use `StripePaymentService`, which also writes ledger rows, updates
  `paid_amount`, and closes orders.

The duplicate Stripe path can diverge from canonical inventory, table/session, invoice, mail,
broadcast, and audit side effects. Payment configuration also collapses provider, rail, brand,
merchant connection, shop policy, and device availability into mutable `PaymentMethod` rows and
client-side hard-coded lists.

## In scope

- A provider-neutral gateway adapter contract, resolver, orchestrator, attempt state machine, and
  canonical order-settlement boundary.
- Stripe migration to the new adapter as the first production provider.
- Provider catalog and capability model ready for PayPay, SB Payment Service (SBPS), and future
  providers without changing order settlement.
- Explicit HQ-owned versus franchise/shop-owned merchant connections with no silent fallback.
- HQ policy, shop preference, device restriction, and deterministic effective-option resolution.
- Versioned effective-policy snapshots for offline Workstation/POS/Kiosk behavior.
- Durable webhook inbox, provider-operation recovery, reconciliation, and operator visibility.
- Repeated partial refunds represented as independent refund operations.
- PCI-safe credential/token handling and redaction.
- Admin-web configuration screens and removal of hard-coded payment lists from POS and Kiosk.
- Backward-compatible migration of current payment methods, Stripe data, historical reports, debt,
  till attribution, receipts, and refund data.
- Canonical mutation boundaries for Order, Product, Menu, and Customer wherever Plan 047 touches
  their controllers, imports, jobs, sync handlers, settlement, or snapshots; all known runtime
  bypasses are removed before cutover rather than deferred to a second refactor.

## Out of scope

- Storing or processing PAN/CVV in Tempo.
- Production PayPay or SBPS launch without contracts, sandbox credentials, and provider-specific
  certification. A second-provider contract test/spike is included as architecture proof.
- Changing Godx Console into a payment-operations product. Console remains the authoritative
  identity/organization/branch ownership plane; payment operations remain in Tempo admin-web.
- Rebuilding the accounting ledger, till system, split-bill allocation, or tax engine.
- Replacing all historical `PaymentMethod` rows immediately. Compatibility reads remain until the
  rollout proves parity.

## Non-negotiable invariants

1. Exactly one Cloud application service mutates payment lifecycle state in `order_payments`.
2. Gateway adapters never mutate orders, ledger rows, policies, or settlement state.
3. Every fully-paid order passes through the same idempotent settlement boundary.
4. Provider network calls never run while a long-lived order/payment DB lock is held.
5. Merchant ownership is explicit and fail-closed; a franchise connection never falls back to HQ.
6. Effective availability is the intersection of provider capability, owner/HQ policy, shop
   policy, and device policy. A lower level cannot override an upstream deny.
7. Provider request identity and idempotency keys survive retries and process crashes.
8. Webhooks are treated as duplicated, unordered signals, not as an ordered command stream.
9. Refunds are append-only operations; one partial refund does not make the remaining captured
   amount unrefundable.
10. Credentials, PAN, CVV, and unredacted provider payloads never reach browser/device APIs or logs.
11. Order, Product, Menu, and Customer each expose exactly one public mutation gateway; internal
    handlers may be split, but transports and adjacent domains cannot mutate their models/tables.
12. Payment persistence and Order persistence remain separate: payment finalization invokes
    `OrderService::settleIfPaid()` and never directly updates Order state or `paid_amount`.
13. A touched legacy writer is migrated in the same Plan 047 task; no new direct-write allowlist
    entry is accepted, and Gate 4 ends with zero runtime exceptions for the five aggregates.

## Delivery slices

| Slice | Outcome | Release gate |
|---|---|---|
| 0 | Ownership contract, state/side-effect inventory, ADR, characterization tests | No schema work until the Console/SSO ownership source is confirmed |
| 1 | Omnify-owned schemas, compatibility mapping, migrations | Schema validation, generated diff review, rollback rehearsal |
| 2 | Canonical domain mutation facades, resolver, adapter contract, orchestrator skeleton, durable attempts/inbox | Architecture, behavior and concurrency tests pass without switching production traffic |
| 3 | Stripe adapter and shadow comparison | Current Stripe and new normalized results match in test/staging |
| 4 | Unified Cloud writers and canonical settlement | POS/Kiosk/Workstation/Stripe parity suite passes |
| 5 | HQ/shop configuration and effective-policy UI/API | Tenant, role, inheritance, accessibility, and no-secret tests pass |
| 6 | Device policy and offline revision sync | Offline/stale/reconnect convergence and safe-checkout tests pass |
| 7 | Backfill, controlled cutover, reconciliation dashboard, legacy deletion | Drift is zero for the agreed observation window; rollback is rehearsed |
| 8 | Second-provider architecture proof | PayPay or SBPS fake/sandbox adapter requires no core settlement changes |

## Success criteria

- [ ] Stripe full, split, webhook, refund, timeout, and recovery flows use the adapter and unified
      orchestrator, with no direct Stripe ledger/order writes.
- [ ] POS, Kiosk, Workstation, customer web, and webhook transports share the same Cloud lifecycle
      commands and settlement behavior.
- [ ] The authoritative Console/SSO management model determines HQ-managed versus franchise shops;
      Tempo does not introduce a conflicting ownership flag.
- [ ] HQ-managed shops resolve the correct HQ connection; franchise shops resolve their own
      connection or fail with a setup-required result.
- [ ] Shop/device toggles are enforced server-side and explained by the effective-policy API/UI.
- [ ] Offline devices reject stale policy at safe boundaries and converge after reconnect without
      duplicating money movement.
- [ ] `paid_amount` matches the immutable ledger projection, including repeated partial refunds.
- [ ] Captured-but-unledgered, ambiguous timeout, webhook failure, and refund-required cases are
      durable and operator-recoverable.
- [ ] Existing debt, till/Z-report, receipts, tax, inventory, table/session, mail, and broadcast
      behavior remains reconcilable.
- [ ] A second adapter passes the provider contract without modifying settlement or ledger rules.
- [x] Order, Product, Menu, Customer and Payment runtime mutation scans have zero bypasses; generated
      CRUD services and compatibility facades have no remaining mutation consumers.
      <!-- Đo 2026-08-04 sau #1550: `php artisan architecture:domain-writers --json`
           → known 0 / new 0 / stale 0 / errors 0, ở `current_gate: 4` với
           `architecture/domain-mutation-writers.php` RỖNG (0 mục) — tức không
           còn cửa nào được miễn trừ, chứ không phải "đã miễn trừ hết".
           Hạng mục `generated_service_consumer` nằm trong cùng lượt quét đó,
           nên nửa sau của tiêu chí này cũng do chính con số trên phủ.
           `CanonicalPortsAreBindableTest` 8/8 xanh và `UNIMPLEMENTED_BY_DESIGN`
           nay RỖNG — trước #1550 nó còn giữ MenuMutationFacade + CustomerMutationFacade. -->

## Hard gates and open decisions

- [ ] **Ownership source:** the current local `Branch` schema exposes `is_headquarters` and
      `is_standalone`, but neither is a sufficient franchise-management contract. Confirm the
      authoritative Console/SSO field/API. If absent, create the upstream contract first.
- [ ] **Stripe Connect legal model:** approve direct versus destination/separate charges for each
      management model with finance/legal; code must store the selected charge model explicitly.
- [x] **Secret store:** dedicated encrypted database store with external versioned master-key keyring,
      opaque references, authenticated tenant/environment binding, audited rotation/revocation, and
      bounded webhook dual-read; see `SECRET-STORE-RUNBOOK.md`.
- [x] **Offline disable semantics:** approve the grace rule for an already-started payment when a
      policy revision changes while a device is offline.
- [x] **Second provider:** choose PayPay or SBPS for the architecture proof based on sandbox access.

## Files in this plan

- [ADR.md](ADR.md) — accepted ownership, charge-model, secret, transaction, offline, and second-provider decisions.
- [INVENTORY.md](INVENTORY.md) — exact current writers, transitions, provider calls, readers, sync paths, and side effects.
- [DESIGN.md](DESIGN.md) — target model, state machines, APIs, UI, security, migration, and rollout.
- [TEST-CASES.md](TEST-CASES.md) — Given/When fixtures and exact assertions for all 110 scenarios.
- [STATE-MACHINES.md](STATE-MACHINES.md) — legal attempt/refund/event transitions and typed public errors.
- [CAPABILITIES.md](CAPABILITIES.md) — fail-closed, versioned provider/rail/channel/currency operation matrix.
- [ROLLOUT.md](ROLLOUT.md) — measurable rollout SLOs, ramp, stop/rollback triggers, and release evidence.
- [DOMAIN-BOUNDARIES.md](DOMAIN-BOUNDARIES.md) — single mutation gateway rules, writer inventory, enforcement, and migration order.
- [NOTES.md](NOTES.md) — research evidence, current-state inventory, constraints, and decisions.
