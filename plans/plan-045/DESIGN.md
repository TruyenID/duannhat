# Plan 045 — Design

> Design decisions, approach, and trade-offs for [Configurable tax rounding + order_condition ledger + refund lines](README.md).

## Context

@see plans/plan-043/DESIGN.md — the consumption-tax engine (tax types, per-line snapshot, group-once rounding) this plan extends; defines the columns + `OrderPricingCalculator` contract.
@see plans/plan-043/TAX-AUDIT-FIXES.md — the reconciliation rules (Σ line tax == group-once tax, service-charge tax slice) the refund + ledger must preserve.
@see backend/app/Support/RoundingMode.php — `roundHalfUpToStep`, `roundUpToStep`, `step(mode,currency)`; the rounding primitive we generalize to round-down + configurable decimals.
@see backend/app/Services/Customer/OrderPricingCalculator.php — `priceGroups`, `groupTaxFor`, `allocateGroupTax`, `forOrder`; where rounding, discount split, and per-line tax stamping happen.
@see backend/app/Services/Customer/CustomerOrderService.php — `addItems`, `voidOrder`, `voidItem`; the write path a `refundItem` mirrors, and where the rounding snapshot is stamped.
@see backend/docs/contributing/service.md — mandatory `DB::transaction`, `logAudit` on status changes, assert-status-before-transition, services never receive Request.
@see backend/docs/contributing/omnify-architecture.md — YAML → codegen; polymorphic pairs are plain String+Uuid, morph map registered manually in `AppServiceProvider`.

## Approach

Three independent-but-related backend capabilities, all additive (no existing
column is dropped, no engine input changes semantics):

1. **Rounding config + snapshot.** Add `tax_rounding_mode` + `tax_rounding_decimals`
   to `ShopOrderSetting` (merchant config) and copy the resolved pair onto every
   `CustomerOrder` at creation. Generalize the rounding primitive so tax rounds
   with the chosen mode/decimals, once per rate group. The engine reads the
   **order snapshot** when recomputing, so a setting change never re-rounds a
   historical order.

2. **`order_conditions` ledger.** A new Omnify-managed, polymorphic table that
   attaches to both `CustomerOrder` and `CustomerOrderItem`. On every recompute
   the engine (re)writes the order's derived `tax` + `discount` conditions
   (value-copied, signed); refund conditions are append-only events written by
   the refund flow. It is an **audit/reconstruction layer** — the engine still
   computes from the existing inputs (Q1 = additive).

3. **Refund-as-negative-line.** A refund appends a new `CustomerOrderItem` with
   negative quantity, the original line's copied (negated) tax snapshot, a
   `refund_of_item_id` back-link, and bumps a `refunded_quantity` accumulator on
   the original (over-refund guard). The engine treats refund lines specially:
   they do **not** re-enter the positive group-once tax computation; their
   negated snapshot tax is added directly, so the reversal is numerically exact
   (Stripe pattern, Q3).

**Two engines, kept in lock-step.** The same three capabilities land on **Cloud
(Laravel)** AND **workstation-app (Go + SQLite)** — the workstation is the
offline-first LAN gateway that mirrors Cloud DOWN and syncs orders UP, so every
schema column, every rounding rule, and the refund flow must exist on both
sides and reconcile through sync. The full backend↔workstation mapping is in
[§ Workstation & sync](#workstation--sync) below; it is a hard requirement that
no plan-045 change lands on only one side.

**No web UI in this plan.** admin-web settings UI + pos-web refund flow are a
follow-up; this plan ships the Cloud API + the workstation LAN endpoints they
will call. Screens / Sitemap / User-journeys sections are therefore omitted (the
only "screens" are API + LAN contracts).

## Architecture

```
ShopOrderSetting ──(tax_rounding_mode, tax_rounding_decimals)──┐
                                                                │ resolve @ create
                                                                ▼
CustomerOrderService::createOrder ──stamp──> CustomerOrder.{tax_rounding_mode,
                                                             tax_rounding_decimals}
                                                                │
                    ┌───────────────────────────────────────────┘
                    ▼
OrderPricingCalculator::forOrder(order)
   ├─ reads rounding snapshot from ORDER (not setting)
   ├─ RoundingMode::roundTax(v, decimals, mode)  ← NEW generalized primitive
   ├─ group-once tax over POSITIVE lines
   ├─ refund lines: add negated snapshot tax directly (no re-round)
   └─ writeConditions(order)  ← delete+reinsert tax/discount rows

RefundService::refundItem(order, item, qty)
   ├─ guard: refunded_quantity + qty ≤ item.quantity
   ├─ append CustomerOrderItem(qty = −qty, tax_amount = −perUnitTax·qty, refund_of_item_id)
   ├─ item.refunded_quantity += qty
   ├─ append order_conditions(type=refund)  ← immutable event
   └─ recalc totals (engine) + logAudit

order_conditions  (morphMany)
   conditionable_type ∈ {order, order_item}   ← manual morph map in AppServiceProvider
```

## Data model changes

> All four schemas are **Omnify-owned** — changes happen in the YAML under
> `schemas/Backend/**` then `npm run omnify:gen`. No hand-written migrations, no
> edits to `backend/app/Omnify/` or `backend/database/migrations/omnify/`. The
> new `order_conditions` table is Omnify-managed (base model + resource + request
> + policy generated); its polymorphic columns are plain `String` + `Uuid` and
> the morph alias is registered **manually** in `AppServiceProvider` per the
> project convention.

| Table | Owner | Change | YAML schema file |
|-------|-------|--------|------------------|
| `shop_order_settings` | Omnify | +`tax_rounding_mode`, +`tax_rounding_decimals` | `schemas/Backend/Shop/ShopOrderSetting.yaml` |
| `customer_orders` | Omnify | +`tax_rounding_mode`, +`tax_rounding_decimals` (snapshot) | `schemas/Backend/Product/CustomerOrder.yaml` |
| `customer_order_items` | Omnify | +`refund_of_item_id` (self-FK), +`refunded_quantity` | `schemas/Backend/Product/CustomerOrderItem.yaml` |
| `order_conditions` | Omnify (new) | new polymorphic ledger table | `schemas/Backend/Product/OrderCondition.yaml` |

### `shop_order_settings` — new properties

- `tax_rounding_mode`: `String` len 20, default `round` (**rev-B**).
  Enum-in-practice: `round` (四捨五入) \| `ceil` (切り上げ) \| `floor` (切り捨て).
  Validated by FormRequest `Rule::in`. Legacy snapshots
  (`half_up`/`round_up`/`round_down`) still price via `RoundingMode::roundToStep`
  aliases. Independent of `split_bill_rounding_mode` (which keeps driving split-bill).
- `tax_rounding_decimals`: `Integer` nullable, **default 0** (rev-B: the
  "auto"/currency-step option was dropped from the settings UI). Range 0–3; a
  value `d` forces step `10^(−d)` (`0` → 1 = 整数 for JPY/VND). A legacy `null`
  still derives the step from `currency_code` via `RoundingMode::step('auto', …)`.

### `customer_orders` — new snapshot properties

- `tax_rounding_mode`: `String` len 20, **not null**, default `round` (rev-B).
  Stamped at create from the setting; immutable thereafter.
- `tax_rounding_decimals`: `Integer` nullable, default 0. Stamped at create; immutable.
  The engine's `forOrder(order)` reads these two, NOT the live setting.

### `customer_order_items` — refund support

- `refund_of_item_id`: `Uuid` **nullable**, FK → `customer_order_items.id`,
  onDelete `RESTRICT` (can't delete a line that has refund children). `null` on
  normal lines; set on refund lines. Self-referential.
- `refunded_quantity`: `Decimal` 15,2, default `0`. Accumulator on the
  **original** line — Σ of refunded units across all its refund children. Guard
  `refunded_quantity ≤ quantity`.
- A **refund line** is any row with `refund_of_item_id != null`; it carries
  `quantity < 0`, `subtotal < 0`, `tax_amount ≤ 0`, `status = served`,
  `unit_price`/`tax_rate`/`tax_type_id` copied from the original.

### `order_conditions` — new polymorphic ledger

```yaml
# OrderCondition
# --------------
# Append-only audit ledger of every financial condition applied to an order or a
# single line: consumption tax, discounts (coupon/promotion), and refunds. Rows
# are VALUE-COPIED snapshots (rate + amount + label captured at write time), so a
# later edit/delete of the source coupon/promotion/tax-type never changes history.
# Written by: OrderPricingCalculator (tax/discount, regenerated per recompute) and
#             RefundService (refund, append-only immutable event).
# Read by:    accounting/reporting, order detail resource, reconciliation checks.
```

| Property | Type | Notes |
|----------|------|-------|
| `id` | Uuid | pk |
| `conditionable_type` | String 20 | morph alias: `order` \| `order_item` |
| `conditionable_id` | Uuid | morph id |
| `type` | String 20 | `tax` \| `discount` \| `refund` |
| `source` | String 30 nullable | `tax_type` \| `service_charge` \| `coupon` \| `promotion` \| `manual` |
| `label` | String 100 | snapshot description (e.g. `10%対象`, coupon code, promo name) |
| `rate` | Decimal 5,2 nullable | % when applicable (tax rate, percent discount) |
| `amount` | Decimal 15,2 | **signed**: tax `+`, discount `−`, refund `−` |
| `currency_code` | String 3 | snapshot |
| `meta` | Json nullable | source ids (coupon_id/promotion_id/tax_type_id), rate-group key, `refund_of_item_id` — reference only; the value is already copied |
| timestamps | — | |

Indexes: `[conditionable_type, conditionable_id]`, `[type]`.

**Sign & reconciliation contract.** For a given order, summing conditions by
type reconstructs the money story:
`Σ(type=tax).amount` == `order.tax_amount`;
`Σ(type=discount).amount` == `−order.discount_amount`;
`Σ(type=refund).amount` == the total refunded gross (negative).
Refund conditions live on the refund `order_item` (and mirror an `order`-level
row). Tax/discount conditions are regenerated on every recompute; refund
conditions are never touched by recompute.

**Morph map** (`AppServiceProvider::boot`, extend the existing `Relation::morphMap`):
`'order' => CustomerOrder::class`, `'order_item' => CustomerOrderItem::class`.

## Engine & rounding changes

### `RoundingMode` (extend)

- `roundDownToStep(float $value, float $step): float` → `floor($value/$step)*$step`.
- `roundTax(float $value, ?int $decimals, ?string $currency, string $mode): float`
  — resolve `step = $decimals === null ? step('auto',$currency) : 10 ** (−$decimals)`,
  then dispatch on `$mode`: `half_up`→`roundHalfUpToStep`, `round_up`→`roundUpToStep`,
  `round_down`→`roundDownToStep`. `$step ≤ 0` passes value through (mode `none` parity).

### `OrderPricingCalculator`

- `groupTaxFor()` / `priceGroups()` accept the order's `(mode, decimals)` and call
  `RoundingMode::roundTax(...)` instead of the hard-coded `roundHalfUpToStep`.
  Group-once rounding is preserved (one round per rate group — NTA).
- **Refund partition.** `forOrder`/`price` split items into `positive` (normal)
  and `refund` (`refund_of_item_id != null`). Positive lines drive
  `rateSubtotals` + group-once tax as today. Refund lines are **excluded** from
  that; their stamped negated `tax_amount` and `subtotal` are summed and added to
  `tax_amount` / `subtotal` / per-rate breakdown directly → exact reversal.
- **Condition writes.** After computing, `writeConditions($order)`:
  delete `order_conditions` where `type IN (tax, discount)` for the order + its
  items, then insert fresh rows (per rate group: tax; per discount source:
  discount; per line: item-level tax mirror). Refund rows untouched. Wrapped in
  the same transaction as the recompute.

### `CustomerOrderService::refundItem` (new editable service, `app/Services/Customer/RefundService.php`)

`refundItem(CustomerOrder $order, CustomerOrderItem $item, float $quantity, ?string $reason): CustomerOrder`

1. Assert: `$item->customer_order_id === $order->id`; `$item->refund_of_item_id === null`
   (can't refund a refund); `$order->status` not `voided`/`refunded`;
   `$quantity > 0`; `$item->refunded_quantity + $quantity ≤ $item->quantity`.
2. Per-unit gross/tax from the original snapshot (respecting `is_tax_included` +
   the order's rounding snapshot).
3. Append refund `CustomerOrderItem` (negative qty, copied+negated tax, `refund_of_item_id`).
4. `$item->refunded_quantity += $quantity` (locked row, `lockForUpdate`).
5. Append `order_conditions(type=refund)` on the refund line + order-level mirror.
6. Recompute totals via `OrderPricingCalculator`.
7. `$order->logAudit('order_item.refunded', [...])`.
8. All in `DB::transaction`.

## Workstation & sync

> Hard requirement: **every plan-045 change is mirrored on the workstation Go +
> SQLite side and reconciled through sync.** The workstation prices orders
> locally (offline-first), serves pos-web/kiosk over LAN, and syncs UP to Cloud.
> The Go engine (`internal/service/pricing.go`) is a port of `OrderPricingCalculator`
> and must produce byte-identical tax to Cloud for the same order snapshot.

### Feature × layer matrix (nothing lands on one side only)

| Change | Cloud (Laravel) | Workstation (Go + SQLite) | Sync |
|--------|-----------------|---------------------------|------|
| Rounding config (`tax_rounding_mode`, `tax_rounding_decimals`) | `ShopOrderSetting` YAML cols | 2 new `shop_settings` KV keys (read via `shopSetting()`) | **DOWN**: `PullBranch` flattens both keys from `data.settings.*` |
| Rounding snapshot on order | `customer_orders` cols, stamped @create | `orders` cols (migration `040`), stamped @create from `shop_settings` | **DOWN**: `pullCustomerOrders` adds the 2 cols to `cloudOrderPayload`; **`reconcileOrderFromCloud` adopts them** (gap #3 fix) |
| Generalized tax rounding | `RoundingMode::roundTax` + engine | Go `roundUpToStep`/`roundDownToStep` + mode-aware `GroupTaxFor`/`totalAmount`, reads order snapshot | round-trip equality test Cloud vs Go |
| `order_conditions` ledger | Omnify table + `writeConditions` | new mirror table (migration `040`, template `032_customer_invoices_mirror.sql`) | **DOWN**: `conditions[]` on order payload → upsert in `upsertOrder` tx; **UP**: LAN-refund conditions via new op |
| Refund negative line | `CustomerOrderItem` cols + `CustomerOrderService::refundItem` + API | `order_items` cols (`refund_of_item_id`, `refunded_quantity`) + Go LAN refund service + engine partition | **UP**: new `order.item_refund` queue op; **DOWN**: refund arrives as a negative-qty item in `pullCustomerOrders` |
| LAN order shape | `CustomerOrderResource` | `customer_order_shape.go` emits `conditions[]` + rounding snapshot + refund fields | pos-web/kiosk read it over LAN unchanged |

### Go engine changes (`internal/service/pricing.go`)

- Add `roundUpToStep(v, step)` (ceil) + `roundDownToStep(v, step)` (floor) beside
  the existing `roundHalfUpToStep`; add a mode dispatcher `roundTax(v, decimals,
  currency, mode)` (step = `decimals` → `10^-decimals`, else `currencyStep`).
- `GroupTaxFor` + the `totalAmount` rounding line take `(mode, decimals)` from
  the **order row** (`orders.tax_rounding_mode/decimals`), NOT `shop_settings`.
- **Refund partition (parity with Cloud):** exclude refund lines
  (`refund_of_item_id != ''` / negative qty) from the positive group-once tax;
  add their negated snapshot `subtotal`/`tax_amount` directly. **This also fixes
  the gap that `AllocateGroupTax` clamps line ideals to ≥0** (would corrupt
  negative lines) — refund lines never enter the allocator.

### SQLite migrations (`internal/store/migrations/`)

Hand-written (workstation-owned range 001–999; orders/items live here, not
omnify). New file `040_plan045_refund_rounding.sql`:
`ALTER TABLE orders ADD COLUMN tax_rounding_mode TEXT`;
`ADD COLUMN tax_rounding_decimals INTEGER`;
`ALTER TABLE order_items ADD COLUMN refund_of_item_id TEXT`;
`ADD COLUMN refunded_quantity INTEGER DEFAULT 0`;
`CREATE TABLE order_conditions (...)` mirroring the Cloud shape (id,
conditionable_type/id, type, source, label, rate, amount, currency_code, meta,
timestamps) + indexes. `order_conditions` is read-mostly (Cloud-authoritative);
workstation writes only refund conditions from a LAN refund.

### Sync UP — new `order.item_refund` op (`sync_service.go`)

- The LAN refund enqueues `order.item_refund` with the negative line +
  `refund_of_item_id` + the refund `order_conditions` rows. **The refund line
  MUST carry the original's `product_sku_id`** — else `readOrderItemForSync`
  (skips SKU-less items) silently drops it from the UP payload (gap #2 fix).
- Idempotency via the local refund-line UUID as `client_order_item_id`;
  dependency-ordered on the parent order's `cloud_id` (reuse
  `errDependencyNotReady`); dead-letter-cascaded with the parent order (plan-042).
- Cloud endpoint: the workstation POSTs to a `/api/v1/workstation/orders/{order}/
  items/{item}/refund` mirror of the pos refund endpoint (or a batched
  `order.item_refund` in the workstation namespace) → server runs the same
  `CustomerOrderService::refundItem`, returns reconciled totals, workstation adopts them.

### Sync DOWN (`sync_pull.go`)

- `cloudOrderPayload` gains `tax_rounding_mode`, `tax_rounding_decimals` +
  `conditions[]`; `upsertOrder` writes the two order cols and upserts conditions
  in the same transaction. Refund lines arrive as ordinary negative-qty items in
  `items[]` (carrying `refund_of_item_id`) and upsert like any line.
- **`reconcileOrderFromCloud` must adopt the rounding snapshot columns** after an
  `item_add`/refund response, not only money + per-line tax (gap #3), so a
  locally-priced order converges to Cloud's snapshot.
- `PullBranch` upserts `tax_rounding_mode` + `tax_rounding_decimals` into
  `shop_settings` when Cloud's branch settings include them.

### Workstation gaps this design closes

1. **Negative line vs `AllocateGroupTax` clamp (≥0).** Refund lines are excluded
   from the allocator entirely (partition), matching Cloud → no corruption.
2. **SKU-less item drop.** `readOrderItemForSync` skips `product_sku_id = ''`;
   refund lines copy the original's `product_sku_id` so they always sync UP.
3. **`reconcileOrderFromCloud` money-only adoption.** Extended to also adopt
   `tax_rounding_mode/decimals` so the offline order's snapshot matches Cloud.

## API surface

> Cloud HTTP endpoints below; the workstation LAN mirrors EP 2 as
> `/api/v1/workstation/orders/{order}/items/{item}/refund` (device-token auth)
> and the sync-UP `order.item_refund` op posts to it. The LAN order-read
> (`customer_order_shape.go`) surfaces the new fields to pos-web/kiosk.

### Endpoint inventory

| # | Method | Path | Purpose | Auth | Route file |
|---|--------|------|---------|------|------------|
| 1 | PATCH | `/api/v1/pos/settings/order` | Set `tax_rounding_mode` + `tax_rounding_decimals` | sanctum + `ShopOrderSettingPolicy@updateTax` (manager) | `routes/api/pos.php` |
| 2 | POST | `/api/v1/pos/orders/{customerOrder}/items/{item}/refund` | Refund N units of a line (append negative line) | sanctum + `CustomerOrderPolicy@refund` (cashier/manager) | `routes/api/pos.php` |
| 3 | GET | `/api/v1/pos/orders/{customerOrder}` | Order detail now includes `conditions[]`, rounding snapshot, refund fields | sanctum + existing | `routes/api/pos.php` (existing — resource change only) |
| 4 | POST | `/api/v1/workstation/orders/{order}/items/{item}/refund` | LAN/sync mirror of EP 2 — workstation posts here on drain of an `order.item_refund` op | device token | `routes/api/workstation.php` |

### Endpoint detail

#### 1. PATCH `/api/v1/pos/settings/order`

- **Auth:** `sanctum` + `ShopOrderSettingPolicy@updateTax` (manager+; mirrors the
  plan-043 403 tax-toggle gate). `X-Shop-Slug` scopes the setting.
- **Request body:**

  | Field | Type | Required | Notes |
  |-------|------|----------|-------|
  | `tax_rounding_mode` | string | no | `in:half_up,round_up,round_down` |
  | `tax_rounding_decimals` | int\|null | no | `nullable,integer,between:0,3` |

- **Success `200`:** the updated `ShopOrderSettingResource` (includes the two new fields).
- **Errors:** `422` invalid enum/range; `403` non-manager; `409`
  `TAX_ROUNDING_LOCKED_OPEN_SHIFT` if an open till shift exists (reuse the
  plan-031 currency-lock guard so mid-shift changes can't corrupt reconciliation).
- **Side effects:** `logAudit('shop_order_setting.tax_rounding_updated')`. Does NOT
  retro-change existing orders (they carry their own snapshot).

#### 2. POST `/api/v1/pos/orders/{customerOrder}/items/{item}/refund`

- **Auth:** `sanctum` + `CustomerOrderPolicy@refund`. Route-model-binds
  `{customerOrder}` and `{item}` (item scoped to the order).
- **Request body:**

  | Field | Type | Required | Notes |
  |-------|------|----------|-------|
  | `quantity` | number | yes | `numeric,gt:0`; ≤ remaining refundable |
  | `reason` | string | no | `nullable,string,max:255` — snapshot into audit + condition meta |

- **Success `201`:** the updated `CustomerOrderResource` (new refund line in
  `items[]`, lowered `total_amount`, new `refund` condition, original's
  `refunded_quantity` bumped).
- **Errors:**

  | Status | Code | When |
  |--------|------|------|
  | 422 | `REFUND_EXCEEDS_QUANTITY` | `refunded_quantity + quantity > item.quantity` |
  | 422 | `CANNOT_REFUND_REFUND_LINE` | target item is itself a refund line |
  | 409 | `ORDER_NOT_REFUNDABLE` | order voided / not in a refundable state |
  | 404 | — | item not in order |
  | 403 | — | not cashier/manager |

- **Side effects:** appends a `CustomerOrderItem`, an `order_conditions` refund
  row, bumps `refunded_quantity`, recomputes totals + `order_conditions` tax rows,
  `logAudit('order_item.refunded')`.

#### 3. GET `/api/v1/pos/orders/{customerOrder}` (resource change only)

- `CustomerOrderResource` adds: `tax_rounding_mode`, `tax_rounding_decimals`,
  `conditions[]` (id, type, source, label, rate, amount, currency_code, meta),
  and each item gains `refund_of_item_id`, `refunded_quantity`,
  `is_refund` (computed `refund_of_item_id != null`).

## Authorization matrix

### Roles involved

| Role key | Display | Source | Notes |
|----------|---------|--------|-------|
| `cashier` | Thu ngân | tempo SSO role on shop | operates POS, can refund |
| `manager` | Quản lý | tempo SSO role on shop | refund + tax settings |
| `staff` | Nhân viên | tempo SSO role | no refund, no settings |

### Action × Role matrix

| Action | cashier | manager | staff |
|--------|---------|---------|-------|
| Refund a line (EP 2) | ✅ (scoped to shop) | ✅ (scoped) | ❌ |
| Change tax rounding (EP 1) | ❌ | ✅ (scoped) | ❌ |
| View order conditions (EP 3) | ✅ | ✅ | ✅ (read) |

Legend: ✅ allowed · ❌ forbidden · ✅ (scoped) — within their shop only.

### Policy ↔ gate cross-check

| Action | Backend gate | Frontend gate |
|--------|--------------|---------------|
| Refund | `CustomerOrderPolicy@refund` (role + shop scope) | (follow-up pos-web) hide refund button for staff |
| Tax rounding | `ShopOrderSettingPolicy@updateTax` (manager + shop) | (follow-up admin-web) manager-only settings section |

### Role-switch verification checklist

- [ ] `staff` POST refund → 403 (not just hidden button).
- [ ] `cashier` PATCH tax rounding → 403.
- [ ] Refund on an order in another shop → 403 (route binding + policy scope).

## Field lifecycle

### ShopOrderSetting

| Field | Added? | Default | Editable by | Validation | Omnify prop |
|-------|--------|---------|-------------|------------|-------------|
| `tax_rounding_mode` | ✅ | `half_up` | manager | `in:half_up,round_up,round_down` | String 20 |
| `tax_rounding_decimals` | ✅ | `null` | manager | `nullable,int,0..3` | Integer nullable |

### CustomerOrder

| Field | Added? | Default | Editable | Validation | Omnify prop |
|-------|--------|---------|----------|------------|-------------|
| `tax_rounding_mode` | ✅ | `half_up` | system (stamped @create, immutable) | — | String 20 |
| `tax_rounding_decimals` | ✅ | `null` | system (stamped @create, immutable) | — | Integer nullable |

### CustomerOrderItem

| Field | Added? | Default | Editable | Validation | Omnify prop |
|-------|--------|---------|----------|------------|-------------|
| `refund_of_item_id` | ✅ | `null` | system (set @refund) | exists in same order | Uuid nullable FK |
| `refunded_quantity` | ✅ | `0` | system (accumulator) | `≤ quantity` | Decimal 15,2 |

### OrderCondition (all new)

| Field | Added? | Default | Editable | Validation | Omnify prop |
|-------|--------|---------|----------|------------|-------------|
| `type` | ✅ | — | system | `in:tax,discount,refund` | String 20 |
| `amount` | ✅ | — | system | signed decimal | Decimal 15,2 |
| (others as table above) | ✅ | — | system | — | — |

### Orphaned field audit

| Field | Why not touched | Currently editable at | Acceptable? |
|-------|-----------------|-----------------------|-------------|
| `order.discount_amount`, `coupon_code_snapshot` | additive ledger — stay authoritative | CouponService | ✅ (Q1) |
| `item.applied_promotion_snapshot` | mirrored into a `discount` condition, source of truth unchanged | addItems | ✅ |
| `order_payments.refund_of_id` | payment-side refund unchanged; order-side line is complementary | OrderPaymentService | ✅ |
| `split_bill_rounding_mode` | split-bill only; tax rounding is now independent | settings | ✅ |

### Field lifecycle cross-check

- [ ] Every editable field (EP 1 body) has a validation rule.
- [ ] `refunded_quantity` write is covered by a Feature test (accumulator + guard).
- [ ] New NOT NULL fields (`order.tax_rounding_mode`) have a default → backfill safe.

## Key decisions

### Decision 1 — order_conditions is an additive audit ledger (Q1)

- **Chose:** additive — engine keeps reading existing inputs; conditions are a
  regenerated (tax/discount) + append-only (refund) snapshot for reporting.
- **Rejected:** canonical ledger (engine reads conditions as source of truth).
- **Why:** far lower risk; no rewrite of the shipped, audited tax engine; ledger
  correctness is verifiable against the existing totals. Migration to canonical
  can come later once the ledger is trusted.

### Decision 2 — nullable `tax_rounding_decimals`, dedicated tax mode (Q2)

- **Chose:** new `tax_rounding_mode` (3 modes) + nullable `tax_rounding_decimals`
  (null ⇒ currency step), independent of `split_bill_rounding_mode`. Snapshot
  both on the order.
- **Rejected:** reuse `split_bill_rounding_mode` (couples split-bill & tax).
- **Why:** tax rounding is legally distinct and must snapshot per-order; nullable
  default preserves today's exact behavior for shops that don't opt in.

### Decision 3 — refund lines excluded from group-once, exact negated snapshot (Q3)

- **Chose:** refund line carries the original's copied+negated tax; engine adds
  it directly rather than netting it into the positive rate group.
- **Rejected:** let the negative line net into the rate group and re-round.
- **Why:** guarantees the refund exactly reverses the original line's tax (no ±1
  drift), matching Stripe reversal semantics and the plan-043 reconciliation
  invariant.

### Decision 4 — backend + workstation (full sync) in one plan; web UI deferred

- **Chose:** Cloud (Laravel) **and** workstation-app (Go + SQLite) with full
  UP/DOWN sync for all three features; web UI (admin-web/pos-web) deferred.
- **Rejected:** backend-first (workstation → plan-046) — the original scope,
  changed at the user's request because an order priced/refunded on the LAN
  gateway MUST reconcile with Cloud; shipping only one side would leave offline
  orders with wrong tax rounding, no refund lines, and no conditions until a
  second plan, i.e. a broken intermediate state on the shop floor.
- **Why:** the two engines are already a matched pair (plan-043 ported
  `OrderPricingCalculator` → `pricing.go`); keeping rounding, refund, and the
  ledger in lock-step in one plan is the only way to guarantee Cloud == LAN. Web
  UI is genuinely independent (it only consumes the contracts) so it stays out.

## Alternatives considered

- **Refund via status flag on the original line** — collapses history, no partial
  refund granularity, no tax reversal record. Rejected per research.
- **Event-sourced ledger** — stronger guarantees but adds projection complexity
  the team hasn't adopted; morph-many table is the pragmatic middle ground.
- **Per-line tax rounding** — prohibited by NTA No.6371; group-once retained.

## Risks & mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Negative lines break existing aggregates (revenue, stock-out, split-bill, void guard) | High | High | Audit every consumer of `items` for sign-awareness; refund lines excluded from stock-out (BR-OI07 analogue) + group-once; explicit tests per consumer |
| Rounding change alters historical orders | Med | High | Order snapshot read by `forOrder`, never the live setting; backfill stamps existing orders; Feature test proves immutability |
| Condition regeneration races recompute on concurrent item edits | Med | Med | Same `DB::transaction` + `lockForUpdate` on the order; conditions delete+insert atomic with totals |
| Over-refund via concurrent requests | Med | High | `lockForUpdate` on the original item; guard re-checked inside the transaction (both Cloud + Go) |
| Go `AllocateGroupTax` clamps line ideals ≥0 → corrupts negative refund lines | High | High | Refund lines partitioned OUT of the allocator (parity with Cloud); negated snapshot added directly; Go unit test on a mixed pos/refund order |
| Refund line dropped from sync UP (`readOrderItemForSync` skips SKU-less items) | Med | High | Refund line copies the original's `product_sku_id`; sync-UP test asserts the negative line reaches Cloud |
| Offline refund double-applies after reconnect | Med | High | Idempotent `client_order_item_id`; Cloud `CustomerOrderService::refundItem` guard re-checks `refunded_quantity` on the server side too; drain test |
| Cloud vs Go rounding diverge (round-up/down/decimals) | Med | High | `roundTax` ported 1:1; round-trip test compares Cloud `tax_amount` to Go for each mode/decimals |
| `reconcileOrderFromCloud` doesn't adopt rounding snapshot → local order re-rounds differently | Med | Med | Extend reconcile to copy `tax_rounding_mode/decimals`; test an offline order converges post-drain |

## Open questions

- [ ] Refund line `status` — reuse `served` (flows into totals) vs new `refunded`?
      Leaning `served` + `refund_of_item_id` discriminator (README open Q).
- [ ] Should `order_conditions` also snapshot `service_charge` + `service_charge_tax`
      as their own condition rows for full reconstruction? (Proposed: yes, as
      `type=tax, source=service_charge`.)
- [ ] Refund sync-UP shape: a dedicated `order.item_refund` op vs a
      `refund_of_item_id` flag on the existing `order.item_add` op. DESIGN
      proposes a dedicated op (clearer dead-letter + audit); confirm in review.
- [ ] Whether `handy` (mobile waiter) also needs a refund route now, or only
      `pos` + `workstation`. (Proposed: `pos` + `workstation` this plan; `handy`
      when its refund UI exists.)

## References

- Stripe Tax reversal API, Shopify Refund object, Japan NTA No.6371 端数処理,
  Modern Treasury / Formance immutable-ledger design — see NOTES.md Discovery.
- plan-043 (tax engine), plan-033 (RoundingMode), plan-019 (coupons).
