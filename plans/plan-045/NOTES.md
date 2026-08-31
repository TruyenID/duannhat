# Plan 045 — Notes

> Working log for [Configurable tax rounding + order_condition ledger + refund lines](README.md). Append-only. Newest entries on top.

---

## 2026-07-15 — Scope EXPANDED to backend + workstation (full sync)

User overrode the Phase-1 "backend-first" answer: plan-045 must ship on **both
Cloud (Laravel) AND workstation-app (Go + SQLite)** with full UP/DOWN sync for
every change — an order priced/refunded on the LAN gateway has to reconcile with
Cloud, so shipping one side would leave a broken shop-floor state. Web UI stays
deferred. Decision 4 rewritten; DESIGN gains a "Workstation & sync" section +
feature×layer matrix; TESTS +11 (`[Go]`/`[GoInt]`); TASKS +Phases 10–17.

### Workstation discovery (Sonnet sub-agent)

- **Go engine:** `pricing.go` has one `roundHalfUpToStep(v, step)` + `currencyStep`;
  no `RoundingMode` type. `GroupTaxFor` + `totalAmount` are the two round sites.
  **Gap 1:** `AllocateGroupTax` clamps line ideals ≥0 → negative refund lines
  corrupt it → refund lines must be partitioned OUT (parity with Cloud).
- **SQLite:** hand-written migrations 001–999 own `orders`/`order_items`
  (`005_orders.sql`, plan-043 tax in `038_tax_types.sql`); omnify 1000+ owns
  catalog mirrors. New cols → hand migration `040_*.sql`. `order_conditions`
  mirror table template = `032_customer_invoices_mirror.sql` (`PullInvoices`,
  cursor-based, read-only).
- **Settings mirror:** `PullBranch` flattens `data.settings.*` into a KV
  `shop_settings` table; `shopSetting(key, default)` reads. Two new keys land
  here; order stamps them at create.
- **Sync UP:** `sync_queue` → `processQueue` → handler → `cloudPost`;
  `client_order_id` idempotency. `order.item_add` sends id/sku/qty/note/toppings
  — NO tax up (Cloud resolves); `reconcileOrderFromCloud` adopts Cloud money +
  per-line tax back. **Gap 2:** `readOrderItemForSync` skips `product_sku_id=''`
  → refund line must carry the original SKU. Existing `payment.refund` handler is
  payment-level (distinct). New `order.item_refund` op needed.
- **Sync DOWN:** `pullCustomerOrders` (5s) → `cloudOrderPayload`/`…ItemPayload` →
  `upsertOrder`. Item carries per-line tax; order carries `is_tax_included`.
  **Gap 3:** `reconcileOrderFromCloud` adopts only money + per-line tax, NOT the
  rounding snapshot cols → must extend. `conditions[]` not present today.
- **LAN shape:** `customer_order_shape.go` already emits `tax_breakdown` +
  `is_tax_included`; add `conditions[]` + rounding snapshot + refund fields.

Design closes all three gaps (see DESIGN "Workstation gaps this design closes").

## 2026-07-15 — Design decisions locked (Phase 1 questions)

User answered all four Phase-1 questions with the **recommended** option:

1. **order_conditions = additive audit ledger** — engine keeps reading existing
   inputs; conditions are regenerated (tax/discount) + append-only (refund).
2. **Dedicated tax rounding config** — new `tax_rounding_mode` (rev-B:
   round / ceil / floor, default round) + `tax_rounding_decimals` (0–3, default
   0), independent of `split_bill_rounding_mode`, snapshotted onto the order.
   See the rev-B addendum below for the rename + frontend completion.
3. **Refund = Stripe-standard negative line** — copied+negated tax snapshot,
   `refund_of_item_id` link, `refunded_quantity` accumulator, partial refunds.
4. **Backend-first scope** — schema + engine + service + API + tests + backfill;
   workstation Go mirror + UI deferred to plan-046.

Open items still to confirm in review: refund line `status` (reuse `served`),
whether service_charge gets its own condition rows, refund route namespace.

## 2026-07-15 — Discovery (Phase 0.5 research)

### Web best-practices (Sonnet sub-agent 0.5a)

**Established contract.** Refunds as append-only reversal records (Shopify
`Refund`+`refundLineItems`, Stripe Tax `reversal` with negative per-line
`amount`/`amount_tax`) referencing the original by ID — never mutate the
original line; negative-qty lines are anomalous, the canonical shape is a
sibling record with a back-FK. Tax rounding: Japan NTA No.6371 mandates rounding
**once per invoice per rate**, mode (切り捨て/切り上げ/四捨五入) merchant's choice,
and the resolved mode + rounded amount must be snapshotted onto the order.
Financial snapshot: separate mutable order object from an immutable append-only
adjustment log (Formance/Modern Treasury), each adjustment a new row referencing
order + optional line → total is always reconstructable ("balance replay").

**Key trade-offs.** Negative sibling row ≫ status-flag (auditability, partial
granularity, own tax snapshot). Snapshot rounding on the order ≫ read-live
(setting change would rewrite history). Group rounding ≫ per-line-then-sum
(NTA-required, kills Σ-drift). Morph-many adjustment table = pragmatic middle
vs event-sourcing. **Value-copy** the adjustment (rate/amount/label), never
FK-only (source edit would silently rewrite history).

**Failure modes.** Σ line tax ≠ invoice tax (round once per group); refund tax
mismatch (copy original snapshot, don't re-derive at current rate); snapshot
referencing mutable FK; double-refund (accumulator + `refunded ≤ original`);
sign errors breaking revenue aggregates (type discriminator + sign-aware sums);
rounding-setting change rewriting history (write mode + rounded amount at
creation).

**Sources:** Stripe Tax reversal API; Shopify GraphQL `Refund`; NTA No.6371
(端数処理); Modern Treasury immutability journal; Formance double-entry blog.

### Project domain (Sonnet sub-agent 0.5b)

**Today.** Tax computed by `OrderPricingCalculator` (plan-043): per-item snapshot
(`tax_type_id`, `tax_rate`, `tax_amount`); order carries
`is_tax_included` + aggregate `tax_amount`. Discounts = single
`order.discount_amount` + `coupon_code_snapshot`/`coupon_id`. Promotions =
`item.applied_promotion_snapshot` (JSON) + `original_unit_price`. Refunds =
**payment-level only** (`order_payments` negative row + `refund_of_id`; original
flips `refunded`). No per-item refund, **no negative order_item**, **no
order_condition table**.

**Rounding today.** `RoundingMode::roundHalfUpToStep(value, step)`, `step` from
`RoundingMode::step(mode, currency)` (default `auto`: JPY/VND→1). Tax reuses
`split_bill_rounding_mode`. **No round-up/round-down**, **no tax_rounding_mode /
decimals**. Tax rounds once per rate group (`groupTaxFor`), allocated via
largest-remainder (`allocateGroupTax`).

**Refund/void today.** Order void = `status=voided` + `voided_at`/`void_reason`;
item void from `pending` only. Payment refund = negative `order_payments` row.

**Conventions.** `docs/contributing/service.md`: `DB::transaction`, `logAudit` on
status change, assert status before transition, services never receive Request.
`docs/contributing/omnify-architecture.md`: YAML → `npm run omnify:gen`;
polymorphic pairs are plain String+Uuid; **morph map registered manually in
`AppServiceProvider`** (Omnify doesn't auto-register); user-editable siblings
survive regen.

**Gaps flagged:** tax vs split-bill rounding coupling; Omnify-vs-manual for
order_condition (chose Omnify-managed + manual morph map); negative lines'
interaction with `rateSubtotals`/`allocateGroupTax`/stock-out/void guards/split
(→ refund lines excluded from group-once, added directly); order_condition
overlap with existing snapshots (→ additive); rounding snapshot column on order
(→ added); workstation mirror (→ deferred plan-046).

**Files read:** plan-043 README/DESIGN/TAX-AUDIT-FIXES; `OrderPricingCalculator`,
`CustomerOrderService`, `RoundingMode`, `OrderPaymentService`; migrations for
customer_orders/items + shop_order_settings; YAML for CustomerOrder/Item,
ShopOrderSetting, TaxType; `docs/contributing/{service,omnify-architecture}.md`.

## 2026-07-15 — Plan created

Initial scaffold via `/mcp__omnify__plan`. Backend-first. Next after plan-044.

## 2026-07-15 — Implementation: Phase 1-2 findings

- **Omnify `Integer` type bug**: `type: Integer` silently generates a STRING column.
  The correct type is `Int` (used by guest_count/sort_order/Coupon). Fixed
  `tax_rounding_decimals` (ShopOrderSetting + CustomerOrder) → `Int`. DB verified `int`.
- **Stale `.omnify/lock.json` on dev** (blocker): lock was behind ~12 committed
  migrations → `omnify:gen` emitted duplicate/polluted catch-up migrations.
  Resolved via a precursor commit refreshing the lock to dev's committed state
  (regenerated lock from dev YAML, kept only `.omnify/*`). plan-045 codegen then clean.
- **Morph aliases (T2.1)**: no manual `AppServiceProvider::morphMap` needed —
  omnify's generated `OmnifyServiceProvider::enforceMorphMap` ALREADY registers
  `'CustomerOrder'`/`'CustomerOrderItem'` (verified `getMorphClass()`). So
  `order_conditions.conditionable_type` stores those values; `morphMany(...,
  'conditionable')` works out of the box. DESIGN's `'order'`/`'order_item'` alias
  plan superseded.
- **Scope**: workstation omnify codegen output was pure dev-drift (no plan-045 Go);
  plan-045's Go is hand-written (Phase 10-16). admin-web/customer-web/kiosk type
  regen deferred to the UI follow-up (web UI out of plan-045 scope).

## 2026-07-15 — Implementation COMPLETE (all phases)

Branch `feature/plan-045` (umbrella + workstation-app submodule).

**Backend (Cloud, Phases 1-9):** 18 commits. Schema/migrations (order_conditions +
rounding/refund cols), models/relations, RoundingMode (roundToStep/taxStep/round
up-down), engine (rounding snapshot threading + refund partition/applyRefundLines +
writeConditions ledger), refundItem (negative line + accumulator + RefundException),
API (POST .../refund + settings PATCH + resource conditions[]), backfill command,
tests. **Full pest suite: 4868 passed / 0 failed.**

**Workstation (Go/SQLite, Phases 10-16):** 3 commits (subagent, verified by me —
build/vet/gofmt/go test all green). Migration 040, engine port (round modes +
refund partition fixing the AllocateGroupTax ≥0-clamp gap), settings mirror + order
snapshot stamp, LAN RefundItem + shape (conditions[]/rounding/refund), sync UP
order.item_refund (idempotent, product_sku_id carried — gap 2), sync DOWN conditions/
rounding + reconcile adopts rounding (gap 3), ~18 Go tests. **go test ./internal/...
all ok.** Cloud sync-UP target endpoint (T14) added on the Cloud side.

**Notable during impl:** (1) fixed pre-existing stale `.omnify/lock.json` dev drift
via precursor commit; (2) omnify `Integer`→`Int` type bug; (3) morph aliases come
free from omnify enforceMorphMap; (4) RefundService lives on CustomerOrderService
(reuses private recalculateTotals) not a sidecar; (5) roundToStep(1.005,0.01) float
edge matches Cloud PHP exactly — documented parity contract.

**Follow-ups (out of plan-045 scope):** pos-web refund UI +
kiosk/customer-web render of the refund line/conditions; stock RETURN on refund
(only stock-out exclusion done). *(admin-web UI shipped — see rev-B below.)*

---

## rev-B (2026-07-15) — rounding rename + admin-web frontend

Two follow-up changes on the `feature/plan-045` branch, requested after the
first pass landed:

**1. Rounding config redesign (BE + admin-web + workstation).**
- The mode values were renamed to the product owner's vocabulary:
  `half_up → round` (四捨五入, default), `round_up → ceil` (切り上げ),
  `round_down → floor` (切り捨て). **Not a behavioural change** — `round`
  dispatches to the same half-up rounding the old `half_up` did.
- Both rounding dispatchers (`RoundingMode::roundToStep` PHP + `roundToStep` Go)
  accept the **legacy aliases** (`half_up`/`round_up`/`round_down`) so any order
  snapshotted before the rename prices identically. Cloud normalizes the mode to
  round/ceil/floor at the resource + settings-response layer; workstation
  normalizes it in `customerOrderShape` + `taxRoundingModeSetting`.
- `tax_rounding_decimals` default changed `null → 0`; the settings UI dropped the
  "auto / theo tiền tệ" (currency-step) option — decimals is now a plain 0–3
  select. A legacy `null` still reads as the currency step in the engine.
- Regen touched only the 2 tables (service-layer defaults `round`/`0`); the DB
  migration default stays `half_up`/nullable but is dead (service + explicit
  stamping always win, and the engine aliases it). Full pest suite 4871 pass;
  `go test ./internal/{service,handler,store}/...` all ok.

**2. admin-web UI shipped** (was deferred as "web types" in the first pass):
- **Refund visibility** in `order-line-items.tsx`: refund lines (negative
  `refund_of_item_id`) render with a 返金/Refund badge, red negative subtotal +
  refund-reason note; original lines show a "返金済 x/y" progress hint from
  `refunded_quantity`.
- **`order-charge-summary.tsx`**: a 返金合計 informational footnote (from the
  `refund` conditions, mode-correct) placed BELOW the total — refunds are already
  netted into subtotal/total, so it is NOT a running-total deduction — plus a
  small tax-rounding note (mode + decimals).
- **Shop settings** (`settings/page.tsx`): a tax-rounding section (round/ceil/floor
  select + 0–3 decimals select) inside the Tax card, with the
  `TAX_ROUNDING_LOCKED_OPEN_SHIFT` 409 guard + open-shift disable mirroring the
  currency / tax-mode guards.
- `order_conditions` raw ledger is deliberately NOT dumped into the operator UI
  (it duplicates the already-shown tax breakdown / discount / refund lines) — the
  UI reads only its aggregate `refund` rows.
