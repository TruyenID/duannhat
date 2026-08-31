---
title: Coupon & Menu Promotion
category: explanation
tags: [coupon, promotion, discount, happy-hour, plan-019]
summary: Two-layer discount system. Coupons are brand-scoped, code-driven, order-level (fixed or percent with cap). Menu Promotions are shop-scoped, auto-apply, time-of-day Happy Hour with stacking_mode and category/product scope. Both interoperate atomically with order create + addItem flows; coupon redemption rows are soft-released to preserve audit but swept on re-apply to keep the unique-per-order constraint clean. Audit metadata travels with payments.
related: [customer-domain, product-domain, authorization]
---

# Coupon & Menu Promotion

This document explains the two-layer discount system shipped in plan-019. Read this to understand how `CouponService` and `MenuPromotionService` plug into the order lifecycle, the difference between the two layers, and the edge cases that drove the fixes after the initial ship.

## Overview

The system has two **independent** discount mechanisms that can coexist on the same order:

- **Coupon** — brand-scoped, code-driven, order-level. Brand admin creates codes like `WELCOME10`; customer/staff types them in. Discounts `discount_amount` on the order header.
- **Menu Promotion** ("Happy Hour") — shop-scoped, auto-apply, item-level. Shop manager sets up time-of-day windows for category/product subsets. When a customer adds a matching item during the window, the line's `unit_price` is reduced before insert and a snapshot is frozen on the line.

The two layers are deliberately separate because they answer different business questions. **"Do you have a code?"** vs **"What time is it and what did you order?"** Shop managers don't need brand approval for Happy Hour, and brand campaigns don't depend on per-shop windows.

### Decision table

| You want to … | Use |
|---|---|
| Brand-wide promo with a memorable code (`WELCOME10`, `SAVE50K`) | Coupon |
| Time-of-day price cut on drinks every evening | Menu Promotion |
| One-time discount only for known customers (rate-limit per phone) | Coupon (`usage_limit_per_customer ≥ 1`) |
| Item-level strikethrough on the menu so customers see the saving | Menu Promotion |
| Cap percent discount with a max amount (e.g. 20% off, up to ¥50,000) | Coupon (`max_discount_cap`) |

---

## Coupon

### Data model

A `Coupon` belongs to a brand and optionally to a subset of branches via the `coupon_branch` pivot. The pivot is **brand-wide when empty**.

| Field | Meaning |
|---|---|
| `code` | Uppercase A–Z0–9_- only, unique per `brand_id`. Stored uppercase (FE auto-uppercases the input). |
| `discount_type` | `fixed` (¥ off) or `percent` (% off, with optional `max_discount_cap`). |
| `min_order_subtotal` | Hard floor — `0` = no minimum. |
| `usage_limit_total` | Optional hard cap on total redemptions. `null` = unlimited. |
| `usage_limit_per_customer` | Per-customer cap. `0` = no per-customer cap (walk-ins allowed). `≥1` requires `customer_id` at apply time. |
| `valid_from` / `valid_until` | Required window. |
| `status` | Stored states: `draft` / `paused`. Derived states (`scheduled`/`active`/`expired`/`exhausted`) come from `CouponService::computeStatus()` against the window + counters — never stored. |
| `coupon_branch[]` | Pivot to `branches`. Empty = brand-wide; non-empty = whitelist. |

### Atomic apply at order

```
CouponService::apply(CustomerOrder $order, string $code, ?string $customerId, string $via, ?User $user)
```

Inside one `DB::transaction`:

1. **Status gate** — `assertOrderModifiable` requires order in `{open, dining, pending, checkout}`. Any other status throws `order_not_modifiable` (409).
2. **Stacking gate** — `assertNoExclusivePromotionStacking` walks the order's items; if any line carries a snapshot whose `stacking_mode = exclusive_with_coupons`, throws `coupon_excluded_by_active_promotion` (422 with `meta.exclusive_item_ids`).
3. **Replace flow** — if `order.coupon_id !== null`, release the prior coupon with `hardDelete=true` (deletes the redemption row, decrements `times_used`, writes audit).
4. **Orphan sweep** — `CouponRedemption::where('customer_order_id', $order->id)->whereNotNull('released_at')->forceDelete()`. The default release path soft-releases (stamps `released_at`, keeps the row for audit per Decision 5). Without this sweep, re-applying after a release blew up with `UniqueConstraintViolationException` on the unique `customer_order_id` index, which the catch below misread as a concurrency race and rethrew as `order_not_modifiable`. **Lesson:** when a unique constraint is the only conflict guard, every code path that wants to reuse the slot must sweep it explicitly.
5. **Lookup + lock** — `lockForUpdate()` on the `Coupon` row prevents oversell. Validation runs against the locked row: status, dates, branch eligibility (via `coupon_branch` pivot), `min_order_subtotal`, `usage_limit_per_customer` (per-customer count), `usage_limit_total`.
6. **Increment + insert** — `Coupon::increment('times_used', where times_used < usage_limit_total)` is a defensive belt-and-braces against parallel applies (the primary `lockForUpdate` already serializes; the WHERE clause is the backup race-guard).
7. **Snapshot** — `coupon_snapshot` JSON column on `CouponRedemption` freezes the rules at apply time. If the coupon is later edited or deleted, the redemption row keeps the version the customer used.

### Release semantics

Soft release by default (stamps `released_at`, decrements `times_used`, keeps the row). Hard delete is reserved for the `apply` replace flow per Decision 5 (audit log in `audit_logs` carries the `coupon_released` event with full snapshot, so the row is redundant after replace).

```
CouponService::release(CustomerOrder $order)       // soft, public
CouponService::releaseLocked($order, hardDelete=false)  // inner, called from apply replace flow
CouponService::releaseIfApplied($order)            // no-throw helper for void/cancel hooks
```

### Per-order constraint

`coupon_redemptions.customer_order_id` is UNIQUE. **One coupon per order, ever** (even released ones occupy the slot until apply() sweeps them). This is enforced at the DB level so two parallel apply requests on the same order can't both succeed — the loser hits the unique constraint and is re-thrown as `order_not_modifiable`.

### Branch whitelist validation

The `coupon_branch` pivot lets HQ restrict a coupon to a subset of shops within the brand. The Form Request validates "every supplied branch_id must belong to this brand" before insert:

```php
// CouponStoreRequest::after() — same shape in CouponUpdateRequest
$brand = request()->attributes->get('brand');
$consoleBrandId = $brand?->console_brand_id
    ?? Brand::whereKey(request()->attributes->get('brand_id'))->value('console_brand_id');

DB::table('branches')
    ->whereIn('id', $branchIds)
    ->where(fn ($q) => $q->where('console_brand_id', '!=', $consoleBrandId)
                          ->orWhereNull('console_brand_id'))
    ->count();
```

> ⚠️ **Important:** the `branches` table joins to the SSO console via `console_brand_id`, NOT a local `brand_id` column (there is no such column). Validating cross-brand whitelists requires resolving the local `Brand.console_brand_id` first. Earlier code that queried `branches.brand_id` directly threw `SQLSTATE[42S22]` whenever staff actually picked a whitelist. Fixed in `CouponStoreRequest::after()` + `CouponUpdateRequest::after()`.

### Customer-web atomic apply

`POST /api/v1/customer/tables/{qrToken}/orders` and `POST /api/v1/customer/branches/{slug}/orders` accept an optional `coupon_code` field. Inside the controller a single `DB::transaction` wraps create + addItems + `couponService->apply`, so a bad code rolls the whole order back (no orphan `customer_order` row, no occupied table). The FE checkout-page does a debounced preview via `/api/v1/customer/coupons/preview` only for UX; the server re-validates at apply time so a "valid" preview that flipped to "expired" between view and submit still produces a clean 4xx.

---

## Menu Promotion

### Data model

A `MenuPromotion` is **shop-scoped** (one row per branch) and resolved by `MenuPromotionService::resolveActivePromotion(branch_id, product_id, category_ids, now)` — **menu-agnostic**, i.e. it cares about product/category/time/branch but not which menu the product was discovered through.

| Field | Meaning |
|---|---|
| `discount_percent` | 0.01–100. No fixed-amount mode at the line level — that's the coupon's job. |
| `applies_to` | `all_items` / `categories` / `products` / `mixed`. Drives which pivot is consulted. |
| `menu_promotion_category[]` / `menu_promotion_product[]` | M2M scope pivots. |
| `daily_time_from` / `daily_time_to` | Time-of-day window in `branches.timezone`. Cross-midnight handled. |
| `weekdays` | ISO `[1..7]` (Mon=1, Sun=7). Empty = every day. |
| `valid_from` / `valid_until` | Calendar window. |
| `stacking_mode` | `stackable_with_coupons` or `exclusive_with_coupons`. Decides whether a coupon can coexist on an order containing this promo. |
| `is_active` | Manual on/off switch. `false` → resolver always returns null. |

### Auto-apply at addItem

Inside `CustomerOrderService::addItems`, after resolving the per-unit price from `MenuProductSku::selling_price`:

```php
$promo = $menuPromotionService->resolveActivePromotion($branch_id, $sku->product_id, $category_ids, now());
if ($promo) {
    $orderItem->original_unit_price        = $unit_price;
    $orderItem->unit_price                 = round($unit_price * (100 - $promo->discount_percent) / 100, 0);
    $orderItem->applied_promotion_id       = $promo->id;
    $orderItem->applied_promotion_snapshot = ['name' => …, 'discount_percent' => …, 'stacking_mode' => …];
}
if ($order->coupon_id !== null && $promo?->stacking_mode === 'exclusive_with_coupons') {
    throw new CannotAddPromotionItemWithCouponException(...);
}
```

Snapshot is **immutable** — later edits to the source promotion don't touch already-priced lines.

### Menu schedule × promotion (independence)

`menu_schedules` (plan-014) and `menu_promotions` are orthogonal:

- Menu schedule answers **"is this product discoverable on the menu right now?"**
- Menu promotion answers **"when this product IS ordered right now, what's the price?"**

The intersection is computed at menu API response time. A menu visible 14:00–20:00 with a promotion active 19:00–20:00 means the customer only sees the discount in the overlap (19:00–19:59). Promos that fall outside the menu's display window are **dead time** — present in the DB but unreachable. The shop promotion form (S8) carries an info banner pointing this out so managers don't accidentally create a promo no customer can hit.

### Cache invalidation

`branch:{id}:active_promotions:{date}` Redis cache, TTL 60s. Invalidated on every CRUD/toggle. Trade-off: customers who view the menu right at the second-to-minute boundary of a promotion window may see a stale price for up to 60s; backend re-resolves at addItem time so the actual order is always correct. Customer-web also auto-refetches the menu right after the soonest `active_promotion.ends_at` to keep the visual fresh.

### Stacking matrix

| Promotion stacking_mode | Order already has coupon → addItem of HH item | Order has HH item → apply coupon |
|---|---|---|
| `stackable_with_coupons` | ✅ Allowed | ✅ Allowed |
| `exclusive_with_coupons` | ❌ 422 `cannot_add_promotion_item_with_coupon` (POS resolves with auto-remove-coupon dialog) | ❌ 422 `coupon_excluded_by_active_promotion` (POS/customer-web show item list; staff/customer must remove HH item) |

---

## Reporting

### HQ cross-shop promotion list

`GET /api/v1/hq/{brandSlug}/promotions` is a read-only aggregator across every branch in the brand. The service-layer flag `with_report=true` (always on for this endpoint) opts into two aggregates:

- `withCount('customerOrderItems as items_with_promotion_count')` — number of order lines that applied this promo.
- `withSum((original_unit_price − unit_price) × quantity)` → `total_discount_amount` (also mirrored into `report.total_discount_applied`).

The resource flattens the eager-loaded `branch` relation into `branch_slug` + `branch_name` to spare the FE a nested traversal. KPI tiles on the HQ page sum these per page.

### Coupon redemption history

`GET /api/v1/hq/{brandSlug}/coupons/{coupon}/redemptions` — paginated list of all redemptions for a coupon, eager-loading customer + customerOrder so each row flattens to `{customer_name, order_code, discount_applied_amount, redeemed_at, released_at, redeemed_via}`. Soft-released rows are included with `released_at` set; the FE table can filter client-side. This endpoint is consumed by the HQ coupon detail page "Lịch sử dùng" tab.

---

## Edge cases (worth knowing)

1. **Re-applying after release blows up.** Pre-fix, applying coupon B to an order that previously had coupon A (released) hit the unique `customer_order_id` constraint. The catch in `apply()` saw `UniqueConstraintViolationException` and rethrew as `order_not_modifiable`, which surfaced in the cart UI as "Cannot change coupon at this order status" — misleading because the order WAS modifiable. Fixed by sweeping any soft-released row before insert.

2. **Branch whitelist on `branches.brand_id` doesn't exist.** Joined to SSO via `console_brand_id`. Fixed in both Store + Update requests.

3. **Customer view-then-add discrepancy.** Customer views menu at 19:59 (HH active), adds item at 20:01 (HH closed). Backend re-resolves at addItem → full price; FE shows stale discounted price from menu cache. Mitigated by client-side `setTimeout` refetch after the soonest `active_promotion.ends_at + 3s`. Always-correct server is the floor; client UX is the polish.

4. **Promotion outside menu schedule = dead time.** Form S8 helper banner; backend doesn't auto-reject because shop manager may intend it (other menus carrying same product across hours).

5. **One coupon per order ever.** Even released. Sweep happens in apply() before insert.

---

## Integration points

| Surface | Touch |
|---|---|
| POS staff coupon entry | `<CouponRow>` inside `OrderCart` checkout draft. Apply/Release through `useApplyCoupon` / `useReleaseCoupon`. |
| POS Happy Hour visuals | `<PromotionBadge>` + `<StrikethroughPrice>` atoms on `menu-catalog` cards + `order-cart` lines. |
| POS stacking conflict | `<StackingConflictDialog>` triggered on 422 `cannot_add_promotion_item_with_coupon` — "Auto-remove coupon & add" CTA re-fires the same addItem payload after releasing. |
| Customer-web coupon | Debounced preview via `/api/v1/customer/coupons/preview`. Apply happens atomically inside the order create endpoint. |
| Customer-web Happy Hour | Per-item Badge + countdown on menu cards; menu auto-refetches at `active_promotion.ends_at`. |
| HQ admin Coupon | CRUD + redemption history tab + branches-tab listing actual shops. |
| HQ admin Promotion (cross-shop) | Read-only list with KPI tiles (live / scheduled / inactive / total discount) and per-row `branch_slug` / `total_discount_applied`. Click row → inline read-only Sheet. |
| Shop admin Promotion | Full CRUD + toggle + detail with report. Form has menu-schedule-overlap helper banner. |

## See also

- [customer-domain](customer-domain.md) — order lifecycle the coupon plugs into
- [authorization](authorization.md) — `CouponPolicy` + `MenuPromotionPolicy` roles
- `plans/plan-019/DESIGN.md` — original design with rejected alternatives + open-question decisions
