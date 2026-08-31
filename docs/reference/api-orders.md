---
title: Orders API
category: reference
tags: [orders, api, create, update, init, spot, table-assignment, items, payments, void, coupon, split-bill]
summary: Field-by-field reference for the customer order API — create (header-only), init (first-write-wins table/guest assignment), general update, confirm, item mutations, split bill, coupons, table take-over, payments, and void endpoints.
related: [order-domain, api-payment-methods, split-by-items]
verified_at: 2026-07-30
source_of_truth: backend/routes/api/shops/orders.php
---

# Orders API

Reference doc for the shop-scoped customer order endpoints. All routes are mounted under `/api/v1/shops/{shopSlug}/orders` and require Sanctum authentication. Authorization uses `CustomerOrderPolicy` with org-scoped checks.

## Endpoints

The table below mirrors `backend/routes/api/shops/orders.php` one-for-one (23 routes).

| Method | Path | Purpose | Auth |
|--------|------|---------|------|
| GET | `/orders` | List branch orders (paginated) | sanctum |
| POST | `/orders` | Create order header | sanctum |
| GET | `/orders/{id}` | Show order detail | sanctum |
| PUT | `/orders/{id}` | Update order header (last-write-wins) | sanctum |
| PUT | `/orders/{id}/init` | Update after init (first-write-wins) | sanctum |
| DELETE | `/orders/{id}` | Delete order | sanctum |
| POST | `/orders/{id}/items` | Add items to order | sanctum |
| PATCH | `/orders/{id}/items/{item}` | Update item quantity / status / note | sanctum |
| DELETE | `/orders/{id}/items/{item}` | Remove a pending item | sanctum |
| POST | `/orders/{id}/items/{item}/void` | Soft-void a pending item with reason | sanctum |
| POST | `/orders/{id}/confirm` | Confirm a pending order (`pending` → `open`) | sanctum |
| POST | `/orders/{id}/checkout` | Checkout order | sanctum |
| POST | `/orders/{id}/void` | Void order | sanctum |
| GET | `/orders/{id}/split-bill` | Calculate even split amounts (`?split_count=`) | sanctum |
| POST | `/orders/{id}/merge-table` | Merge table into order | sanctum |
| POST | `/orders/{id}/unmerge-table` | Remove merged table | sanctum |
| POST | `/orders/{id}/apply-coupon` | Apply a coupon code (plan-019) | sanctum |
| DELETE | `/orders/{id}/coupon` | Release the applied coupon (idempotent) | sanctum |
| GET | `/orders/{id}/payments` | List payments on an order | sanctum |
| POST | `/orders/{id}/payments` | Record a payment | sanctum |
| POST | `/orders/{id}/payments/{payment}/confirm` | Confirm a pending payment | sanctum |
| POST | `/orders/{id}/payments/{payment}/refund` | Refund a succeeded payment | sanctum |
| POST | `/orders/continue-table` | Auto-close the old order on a table and open a new one (plan-021) | sanctum |

> **Not on this route file.** The by-items split preview
> (`GET /orders/{id}/split-by-items/preview`) is mounted on the POS, kiosk, and
> customer surfaces instead — `routes/api/pos.php`, `routes/api/kiosk.php`,
> `routes/api/customer.php`. Its shape, error codes, and rounding contract are
> documented in [Split-by-items Bill Division](../explanation/split-by-items.md).

> **Item-mutation response shape.** `POST`, `PATCH`, `DELETE`, and `void` on items all return `200` (or `201` on add) with the full `CustomerOrderResource` body (`items` + `tables` eager-loaded, `subtotal` + `total_amount` recomputed server-side). `DELETE /items/{id}` is `200 OK`, not `204 No Content`. Clients can update the cart and tab-chip totals in a single round trip without a follow-up `GET /orders/{id}`.

---

## GET `/orders` — List branch orders

Paginated list of orders for the shop's branch.

### Query parameters

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `status` | string | no | Single status (`open`, `dining`, `checkout`, `paying`, `closed`, `voided`) or a comma-separated list (e.g. `open,dining,checkout`). Multi-value uses `WHERE status IN (…)`. |
| `order_type` | string | no | One of `spot`, `dine_in`, `takeaway`. |
| `customer_id` | UUID | no | Filter by customer. |
| `table_id` | UUID | no | Filter by assigned table. |
| `date_from`, `date_to` | ISO date | no | Range filter on `created_at`. |
| `search` | string | no | Free-text match on `order_code`. |
| `sort` | string | no | Sort key; default newest first. |
| `per_page` | integer | no | Default 15. |

---

## POST `/orders` — Create order header

Creates a new order with status `open`. Items are added separately via `POST /orders/{id}/items`.

### Request body

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `order_type` | string | no | One of `spot`, `dine_in`, `takeaway`. Defaults to `spot` when null. |
| `customer_id` | UUID | no | FK to `customers`. Nullable for walk-in guests. |
| `table_ids` | array of UUID | no | Tables to assign. Each must be free or reserved. |
| `guest_count` | integer | no | Minimum 1 if provided. |
| `note` | string | no | Free text. |

### Response

- `201` — order created

```json
{
  "data": {
    "id": "uuid",
    "order_code": "ORD-2026-0001",
    "status": "open",
    "order_type": "spot",
    "guest_count": null,
    "note": null,
    "subtotal": "0.00",
    "total_amount": "0.00"
  }
}
```

### Errors

| Status | When |
|--------|------|
| 422 | Invalid fields, non-existent table, occupied table, table not free/reserved |

### Side effects

- Tables in `table_ids` get `current_order_id` set and status changed to `occupied`
- Audit log entry: `opened`

---

## PUT `/orders/{id}` — Update order header

General-purpose update with last-write-wins semantics. Only works on `open` orders.

### Request body

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `guest_count` | integer | no | Overwrites existing value. Min 1. |
| `note` | string | no | Overwrites existing value. |
| `customer_id` | UUID | no | Overwrites existing value. |
| `order_type` | string | no | One of `spot`, `dine_in`, `takeaway`. |

### Response

- `200` — order updated

### Errors

| Status | When |
|--------|------|
| 409 | Order is past `open` status |
| 422 | Invalid fields |

---

## PUT `/orders/{id}/init` — Update after init

First-write-wins update for deferred table assignment and guest count. Safe to retry — second call is a no-op for already-set values. Only works on `open` orders.

### Request body

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `table_ids` | array of UUID | no | Only assigned if order has no tables yet. Ignored otherwise. |
| `guest_count` | integer | no | Only saved if current DB value is null. Ignored if already set. |

### Response

- `200` — order init updated

### Errors

| Status | When |
|--------|------|
| 409 | Order is not in `open` status |
| 422 | Invalid table IDs, occupied table, table not free/reserved |

### Side effects

- Tables assigned get `current_order_id` set and status changed to `occupied`
- Audit log entry: `init_updated`

---

## POST `/orders/{id}/items` — Add items

Adds one or more items to an `open` order. Response body is the full order with recomputed totals.

### Request body

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `items` | array | yes | Min 1 entry. |
| `items[].product_sku_id` | UUID | yes | FK to `product_skus`. Must be active. |
| `items[].quantity` | integer | yes | Min 1. |
| `items[].menu_product_sku_id` | UUID | no | Pin `unit_price` to this specific `MenuProductSku` row when the same SKU appears on multiple menus. Omit to let the service resolve the first active menu row. |
| `items[].note` | string | no | Per-item kitchen note. |

### Response

- `201` — items added; body is the full `CustomerOrderResource`.

### Errors

| Status | When |
|--------|------|
| 409 | Order is not in `open` or `dining` status |
| 422 | Invalid SKU, inactive SKU, or SKU not on the resolved menu |

---

## PATCH `/orders/{id}/items/{item}` — Update item

Change quantity, kitchen status, or note on a single item. Returns the full order.

### Request body

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `quantity` | integer | no | Min 1. |
| `status` | string | no | One of `pending`, `preparing`, `ready`, `served`. `voided` is not accepted here — use the void endpoint. |
| `note` | string | no | Overwrites existing note. |

### Response

- `200` — full `CustomerOrderResource` with recomputed totals.

### Errors

| Status | When |
|--------|------|
| 409 | Order is past `open`/`dining`; or item is already `voided` |
| 422 | Invalid status value |

---

## DELETE `/orders/{id}/items/{item}` — Remove pending item

Hard-deletes an item while the order is still `open` and the item is still `pending`. For items the kitchen has already acknowledged, use the void endpoint instead.

### Response

- `200` — full `CustomerOrderResource` with the item removed and totals recomputed. (Not `204`.)

### Errors

| Status | When |
|--------|------|
| 409 | Order is past `open`; or item status is not `pending` |

---

## POST `/orders/{id}/items/{item}/void` — Soft-void item

Marks a single pending item as voided with a captured reason. The row **persists** in `customer_order_items` with `status=voided`, `voided_at=<ts>`, `void_reason=<text>` so end-of-shift reconciliation and void analytics remain possible. Voided items are excluded from `subtotal` / `total_amount`.

### Request body

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `void_reason` | string | yes | Captured for audit — no silent voids. |

### Response

- `200` — full `CustomerOrderResource`. The voided item is still inside `data.items[]` with `status=voided`; clients render it behind an expand-voided toggle rather than dropping it.

### Errors

| Status | When |
|--------|------|
| 409 | Item status is not `pending`; or order is past `open`/`dining` |
| 422 | `void_reason` missing |

---

## POST `/orders/{id}/payments` — Record a payment

Records a payment against an order. If the selected `PaymentMethod.is_auto_confirm` is true (e.g. cash), the payment lands as `succeeded` and `paid_amount` advances. Otherwise it stays `pending` until confirmed.

### Request body

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `payment_method_id` | UUID | yes | FK to `payment_methods`. Must be active for the shop (see [Payment Methods API](api-payment-methods.md)). |
| `amount` | decimal | yes | Amount applied to the order. |
| `tip_amount` | decimal | no | Defaults to 0. |
| `tendered_amount` | decimal | conditional | Required for cash methods where `requires_tendered=true`. |
| `reference_no` | string | no | External reference (e.g. card terminal trace id). |
| `note` | string | no | Free text. |

### Partial payments

`amount` can be less than the order's `remaining_amount`. The order transitions to `paying` (not `closed`) and `remaining_amount` is recomputed as `total_amount - paid_amount`. The order reappears as an outstanding debt on the customer's next visit (see [Order Domain](../explanation/order-domain.md#partial-payment-and-outstanding-debt)).

### Response

- `201` — `OrderPaymentResource` for the newly recorded payment. When the payment fully covers the bill and the method auto-confirms, the order is closed in the same transaction.

### Errors

| Status | When |
|--------|------|
| 409 | Order is `closed` or `voided` |
| 422 | Missing `tendered_amount` when method requires it; `amount` exceeds remaining; inactive payment method |

---

## POST `/orders/{id}/apply-coupon` — Apply a coupon

Atomic apply (plan-019). Validates window / scope / exhaustion and the per-customer limit, increments `times_used` under `lockForUpdate`, and writes a `CouponRedemption` row.

### Request body

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `code` | string | yes | Max 50 chars. |
| `customer_id` | UUID | no | Required by coupons that are per-customer limited. |
| `downgrade_exclusive_promotions` | boolean | no | Opt in to dropping an exclusive promotion that blocks the coupon. |

### Response

- `200` — `data` is the updated `CustomerOrderResource`; `meta.applied_coupon` carries `code`, `discount_type`, `discount_value`, `discount_applied_amount`.

### Errors

| Status | When |
|--------|------|
| 404 | `coupon_not_found` |
| 409 | `order_not_modifiable` |
| 422 | `coupon_paused` / `coupon_expired` / `coupon_min_subtotal_not_met` / `coupon_exhausted` / `customer_required` / `coupon_already_used_by_customer` / `coupon_branch_not_eligible` / `coupon_excluded_by_active_promotion` |

---

## DELETE `/orders/{id}/coupon` — Release the coupon

Decrements `times_used`, sets `released_at`, and clears `coupon_id`, `coupon_code_snapshot`, and `discount_amount`. **Idempotent** — an already-released redemption still returns success.

### Errors

| Status | When |
|--------|------|
| 409 | `order_not_modifiable` |
| 422 | `no_coupon_applied` |

---

## POST `/orders/continue-table` — Table take-over

Plan-021. Closes (or voids, per the #554 rule) the order currently sitting on the given tables, then opens a fresh order with the supplied items and rebinds the tables. Not scoped to an existing order id — the table ids identify the target.

### Request body

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `table_ids` | array of UUID | yes | Empty or missing is a `422`, not a `500`. |
| `items` | array | yes | Min 1 entry; same shape as `POST /orders/{id}/items`. |
| `order_type` | string | no | Defaults to `dine_in`. |
| `customer_id`, `guest_count`, `note` | — | no | Same semantics as `POST /orders`. |

---

## Order types

| Value | Description |
|-------|-------------|
| `spot` | Default. Flexible/quick order. Tables optional. Starts at `open`. |
| `dine_in` | Sit-down meal. Tables can be assigned at creation or later via init. Starts at `open`. |
| `takeaway` | Take food home. No tables expected. Starts at `pending` (kitchen queue) — skips the table-side lifecycle. |
