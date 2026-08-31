---
plan: 045
issue: 858
title: Configurable tax rounding + order_condition audit ledger + refund-as-negative-line
slug: tax-rounding-order-conditions-refund-lines
status: shipped
branch: feature/plan-045
created: 2026-07-15
updated: 2026-08-05
landed_via: >-
  merged to dev (feature branch deleted); see the plan's tracking issue
  for how it closed. TASKS.md checkboxes are NOT the completion signal here —
  several plans shipped by a different route than the ladder they describe (#1802).
---

# Plan 045 — Configurable tax rounding + order_condition ledger + refund lines

> Give shops control over how consumption tax is rounded (decimals + round
> up/down/half-up), snapshot that decision immutably onto every order, add a
> polymorphic `order_conditions` audit ledger that records the applied tax /
> discount / refund values at order time, and model refunds as **appended
> negative-value order items** (never mutating the original line).

## Status

- **Current:** `draft`
- **Created:** 2026-07-15
- **Owner:** _(assign)_

## Motivation

plan-043 shipped brand-scoped consumption tax with per-line snapshots and
group-once rounding, but three gaps remain that operators and accounting have
asked for:

1. **Rounding is hard-coded to half-up at the currency step.** Japanese shops
   legally choose their own 端数処理 (切り捨て / 切り上げ / 四捨五入) and want to
   set the number of decimal places. Today they cannot, and there is no record
   of which rule produced a historical order's tax — changing a setting would
   silently re-derive different tax on old orders.
2. **The applied-adjustment history is scattered and lossy.** Discounts live in
   `order.discount_amount` + `coupon_code_snapshot`, promotions in
   `item.applied_promotion_snapshot`, tax in per-item columns — there is no
   single append-only ledger that lets accounting reconstruct "what tax,
   discounts, and refunds were applied to this order and each line, and for how
   much" without re-running the engine.
3. **Refunds are payment-only.** `order_payments` carries a negative row with
   `refund_of_id`, but the order lines never reflect a refund, so per-item
   refund history, partial refunds, and refund tax reversal are invisible on the
   order itself.

Research (Stripe Tax reversals, Shopify Refund objects, Japan NTA No.6371,
double-entry ledger design) confirms the industry-standard shapes for all
three; see [DESIGN.md](DESIGN.md#context).

## In scope

- **Configurable tax rounding** (`ShopOrderSetting`): `tax_rounding_mode`
  (rev-B: `round` / `ceil` / `floor`, default `round`; legacy
  `half_up`/`round_up`/`round_down` still alias) + `tax_rounding_decimals`
  (0–3, default 0), independent of `split_bill_rounding_mode`.
- **Rounding snapshot on the order** (`CustomerOrder.tax_rounding_mode`,
  `tax_rounding_decimals`), stamped at checkout so historical orders never
  re-round when the setting changes.
- **Generalized tax rounding** in `OrderPricingCalculator` /
  `RoundingMode` — half-up + round-up (ceil) + round-down (floor) at a
  configurable decimal step, applied once per rate group (NTA-compliant).
- **`order_conditions` table** — polymorphic (morph-many) to both
  `CustomerOrder` and `CustomerOrderItem`. Append-only, value-copied snapshot of
  each applied `tax` / `discount` / `refund` condition (signed amount, rate,
  label, currency, source meta).
- **Refund as a negative-value `CustomerOrderItem`** — appended line with
  negative quantity, `refund_of_item_id` linking the original, copied (negated)
  tax snapshot, `refunded_quantity` accumulator on the original guarding against
  over-refund, partial-refund support.
- **Backend** (Laravel): Omnify YAML schema changes → codegen, engine + service
  + FormRequests + policies + controllers/routes, Eloquent resources, Pest
  tests, and a backfill command for the rounding snapshot on existing orders.
- **Workstation-app** (Go + SQLite): **full parity + sync for every change
  above** — hand-written SQLite migrations mirroring the new columns + the
  `order_conditions` table; the Go tax engine (`pricing.go`) generalized to
  round-up/round-down + decimals reading the order snapshot; `shop_settings`
  mirror of the two rounding keys; a LAN item-refund path (append negative line
  offline); the LAN order shape (`customer_order_shape.go`) emitting
  `conditions[]` + rounding snapshot + refund fields; and **sync UP + DOWN**
  wired for all three features (see DESIGN "Workstation & sync" for the matrix).
- **Backend ↔ workstation sync — every plan-045 change is mirrored both ways:**
  - *DOWN*: `PullBranch` pulls the two rounding keys into `shop_settings`;
    `pullCustomerOrders` pulls `tax_rounding_mode/decimals` + `conditions[]` +
    refund (negative) lines and upserts them; `reconcileOrderFromCloud` adopts
    the rounding snapshot columns (not just money) so an offline order matches
    Cloud after re-resolve.
  - *UP*: a workstation LAN refund enqueues an `order.item_refund` op (negative
    line + `refund_of_item_id`, carrying the original's `product_sku_id`) and its
    `order_conditions` rows; both idempotent, dependency-ordered on the order's
    cloud id, and dead-letter-cascaded with the parent order.

## Out of scope

- **UI**: ~~admin-web settings screen for the rounding config~~ (**shipped in
  rev-B** — see NOTES.md: tax-rounding settings section + refund visibility +
  charge-summary refund total/rounding note). pos-web refund flow remains a
  follow-up (this plan ships the backend API + the workstation LAN endpoints).
- **Superseding legacy snapshots.** `order_conditions` is an **additive audit
  ledger** — `discount_amount`, `coupon_code_snapshot`,
  `applied_promotion_snapshot`, and per-item tax columns remain the
  authoritative inputs the engine reads (on both Cloud and workstation).
  Migrating the engine to read the ledger is explicitly deferred.
- **Refunds via payment reversal** (`order_payments.refund_of_id` /
  workstation `payment.refund`) — unchanged; the negative line is an order-side
  record that complements, not replaces, the payment refund.
- Rounding modes beyond the three named (no banker's/half-even in this plan).
- **godx-kiosk / customer-web / KDS** surfaces of the refund line + conditions —
  they already consume the shared order shape read-only; verifying their render
  is a follow-up, not a plan-045 deliverable.

## Success criteria

- [ ] A shop can PATCH `tax_rounding_mode` + `tax_rounding_decimals`; an order
      created afterwards stamps both, and its tax equals the engine computed
      with that mode/decimals (group-once).
- [ ] Changing the setting does **not** change the tax of any pre-existing
      order (snapshot proven by a Feature test).
- [ ] Every order and every taxed/discounted line has matching `order_conditions`
      rows whose signed amounts reconcile: Σ(order-level condition amounts) ==
      the order's `tax_amount` − `discount_amount` deltas per the ledger
      contract in DESIGN.
- [ ] Refunding N units of a line appends one negative `CustomerOrderItem`
      (qty −N, negated tax snapshot, `refund_of_item_id` set), increments the
      original's `refunded_quantity`, writes a `refund` condition row, and lowers
      `order.total_amount` by exactly the original line's per-unit gross × N.
- [ ] Over-refund (cumulative refunded > original quantity) is rejected 422 on
      BOTH Cloud and the workstation LAN endpoint.
- [ ] **Workstation parity:** the Go engine (`pricing.go`) rounds tax with the
      order's snapshot mode/decimals to the SAME figure as Cloud for the same
      order (round-trip test); a LAN-created refund appends a negative line +
      conditions locally and, once online, syncs UP idempotently so Cloud shows
      the identical negative line, `refunded_quantity`, and refund condition.
- [ ] **Sync round-trip:** an order created + refunded on Cloud pulls DOWN with
      its rounding snapshot, `conditions[]`, and refund line intact; an order
      refunded on the workstation while offline reconciles to the same totals on
      Cloud after drain (no double-refund, no drift).
- [ ] `php artisan test --compact` green + `vendor/bin/pint` clean; workstation
      `go test ./internal/...` green + `gofmt`/`go vet` clean.

## Dependencies

- plan-043 tax engine (shipped) — Cloud `OrderPricingCalculator`, `TaxResolver`,
  `RoundingMode`, per-item tax snapshot columns; **workstation `pricing.go`**
  (Go port of the same engine, `roundHalfUpToStep` + `GroupTaxFor` + gap #7).
- plan-042 sync hardening — the `sync_queue`, `errDependencyNotReady` ordering,
  and dead-letter cascade the new `order.item_refund` op reuses.
- Omnify codegen (`npm run omnify:gen`) for the Cloud schema changes; **hand-
  written SQLite migrations** (`internal/store/migrations/040_*.sql`, template:
  `032_customer_invoices_mirror.sql`) for the workstation side.
- Manual morph-map registration in `AppServiceProvider` (Omnify does not
  auto-register polymorphic aliases).

## Open questions

- [ ] Default for `tax_rounding_decimals` — currency-derived (JPY→0) vs a fixed
      `0`. Leaning nullable → fall back to the currency step (backward-compatible
      with today's behavior). Resolve in DESIGN Key Decision 2.
- [ ] Should refund lines carry `status` = `served` (flow into totals) or a new
      `refunded` status? Leaning reuse `served` + `refund_of_item_id` as the
      discriminator so the engine's existing "non-voided" filter includes them.
- [ ] Whether the engine excludes refund lines from group-once positive tax and
      adds their negated snapshot tax directly (exact reversal) vs letting them
      net into the rate group. DESIGN proposes the former; confirm in review.

## Files in this plan

- [DESIGN.md](DESIGN.md) — approach, decisions, alternatives
- [NOTES.md](NOTES.md) — working log, decisions, blockers

## Related

- plan-043 — consumption tax types (the foundation this extends)
- plan-033 — `RoundingMode` + `split_bill_rounding_mode` (rounding infra reused)
- plan-019 — coupons / `discount_amount` snapshot
