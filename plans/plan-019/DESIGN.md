# Plan 019 — Design

> Architecture, schema, and API for [Coupon Management](README.md). Coupon là entity brand-owned (HQ admin tạo) có thể giới hạn theo shop, redeemable bằng code ở POS hoặc customer-web checkout, có usage limit + time window + atomic counter.

## Context

@see backend/docs/explanation/customer-domain.md — order lifecycle + BR-O04 `total = subtotal − discount + service + tax`; coupon ghi vào `discount_amount`.
@see backend/docs/contributing/service.md#core-principles — services không touch Request, mọi multi-step trong `DB::transaction()`, status changes ghi audit.
@see backend/docs/contributing/policy.md#template-standard-policy — pattern policy với organization check + role helper, áp dụng cho `CouponPolicy`.
@see backend/docs/contributing/route.md — workflow actions dùng `POST`; HQ vs Shop scope tách file riêng.
@see backend/docs/contributing/omnify-architecture.md — `*Service.php` user-editable extends `*ServiceBase` từ codegen; never edit base files.
@see schemas/Backend/Product/PaymentMethod.yaml — sibling pattern: brand-wide với `branch_id` nullable, `name` translatable, indexes scope `(organization, branch, code)`.
@see schemas/Backend/Product/CustomerOrder.yaml — host của `discount_amount`; lifecycle `open → dining → checkout → paying → closed`, voided từ bất kỳ trạng thái nào trừ closed.
@see backend/app/Services/Notification/NotificationService.php#L140 — sample `lockForUpdate` cho idempotency, áp dụng tương tự cho atomic counter của coupon.
@see backend/app/Services/Customer/CustomerOrderService.php#L290 — `checkout()` hiện đọc `discount_amount` từ data; coupon flow phải set field này thay vì client free-form.

## Approach

Coupon = entity mới do brand admin (`/hq/{brandSlug}/coupons`) tạo và quản lý. Field chính: `code` (unique theo brand), `discount_type` (`fixed` | `percent`), `discount_value`, `max_discount_cap` (chỉ áp dụng khi `percent`), `min_order_subtotal`, `usage_limit_total`, `usage_limit_per_customer`, `valid_from`, `valid_until`, `name` + `description` translatable, `times_used` cache. Brand-wide vs shop-restricted thông qua pivot `coupon_branch` — không có row → áp dụng tất cả branches của brand; có row → giới hạn theo branches đó.

Lifecycle status (`draft` | `paused` lưu DB; `scheduled` | `active` | `expired` | `exhausted` derived từ `valid_from` + `valid_until` + `times_used` ngay tại thời điểm validate). Brand admin override bằng `pause` / `resume`. Không cần cron job — derived state đảm bảo correctness mà không cần background sync.

Atomic redemption: `POST /shops/{shop}/orders/{order}/apply-coupon` mở `DB::transaction`, `lockForUpdate` row coupon, validate (status / window / min subtotal / scope / customer eligibility), tính `discount_applied`, increment `times_used`, ghi `CouponRedemption` (immutable ledger với unique index trên `customer_order_id` để giới hạn 1 coupon/order). Sau đó update `CustomerOrder` (`discount_amount`, `coupon_id`, `coupon_code_snapshot`). Per-customer eligibility check truy vấn `coupon_redemptions` JOIN by `customer_id` count vs limit — bên trong cùng locked transaction.

Khi order voided/cancelled (status chưa `closed`), `CustomerOrderService` gọi `couponService->releaseIfApplied($order)` — soft-delete redemption (set `released_at = now()`), decrement `times_used`. Order đã `closed` voided thì counter giữ nguyên (immutable + audit trail).

Customer-web preview (`POST /customer/coupons/preview`) là stateless validate — không reserve counter — chỉ trả về `{is_valid, discount_amount, error_code?}` để UI hiện preview. Lúc submit order thật, server gọi lại apply-coupon và phải re-validate lock.

## Architecture

```
                       ┌──────────────────────────────────────┐
                       │          Brand Admin (HQ)            │
                       │  /hq/{brandSlug}/coupons (admin-web) │
                       └────────────────┬─────────────────────┘
                                        │ CRUD
                                        ▼
                  ┌─────────────────────────────────────────┐
                  │           CouponService                 │
                  │  list / create / update / delete        │
                  │  pause / resume                         │
                  │  apply / release / preview              │
                  └────┬─────────────┬─────────────┬────────┘
                       │             │             │
              ┌────────▼──┐   ┌──────▼──────┐  ┌──▼───────────┐
              │  Coupon   │   │ Coupon-     │  │ CouponBranch │
              │  (entity) │   │ Redemption  │  │ (pivot)      │
              │           │   │ (ledger)    │  │              │
              │ code      │   │ coupon_id   │  │ coupon_id    │
              │ type      │   │ order_id    │  │ branch_id    │
              │ value     │   │ customer_id │  └──────────────┘
              │ window    │   │ amount      │
              │ limits    │   │ snapshot    │
              │ times_used│   │ released_at │
              └───────────┘   └─────────────┘
                       ▲             ▲
                       │             │
        ┌──────────────┴─────────────┴───────────────────┐
        │                                                │
   ┌────┴───────────────┐          ┌─────────────────────┴────┐
   │  POS (pos-web)     │          │  Customer-web checkout   │
   │  payment-dialog →  │          │  checkout-page →         │
   │  apply-coupon API  │          │  preview API + apply API │
   └────────┬───────────┘          └──────────────┬───────────┘
            │                                     │
            └──────────────┬──────────────────────┘
                           ▼
              ┌─────────────────────────┐
              │  CustomerOrderService   │
              │  checkout / void / cancel│
              │  → coupon hooks         │
              └─────────────────────────┘
```

## Data model changes

| Table | Owner (Omnify / manual) | Change | YAML schema file (if Omnify) |
|-------|-------------------------|--------|------------------------------|
| `coupons` | Omnify (NEW) | Create with full coupon definition | `schemas/Backend/Promotion/Coupon.yaml` |
| `coupon_redemptions` | Omnify (NEW) | Immutable ledger, FK to coupon + order + customer | `schemas/Backend/Promotion/CouponRedemption.yaml` |
| `coupon_branch` | Omnify (NEW pivot) | `coupon_id` × `branch_id`, unique pair | `schemas/Backend/Promotion/CouponBranch.yaml` |
| `customer_orders` | Omnify (modify) | Add `coupon_id` (nullable FK), `coupon_code_snapshot` (nullable string 50) | `schemas/Backend/Product/CustomerOrder.yaml` |
| `coupon_discount_types` enum | Omnify (NEW) | `fixed` \| `percent` | `schemas/Shared/Enum/CouponDiscountType.yaml` |
| `coupon_statuses` enum | Omnify (NEW) | `draft` \| `paused` (storable); derived states `scheduled`/`active`/`expired`/`exhausted` không lưu | `schemas/Shared/Enum/CouponStatus.yaml` |

### `coupons` table — fields (concrete)

| Field | Type | Nullable | Notes |
|-------|------|----------|-------|
| `id` | UUID | no | PK |
| `brand_id` | UUID FK | no | scope owner; index |
| `organization_id` | UUID FK | no | scope owner (BR per service.md) |
| `code` | String(50) | no | unique scope `(brand_id, code)`; case-insensitive lookup; uppercase by convention |
| `name` | String(255) | no | translatable |
| `description` | Text | yes | translatable |
| `discount_type` | EnumRef → CouponDiscountType | no | `fixed` \| `percent` |
| `discount_value` | Decimal(12,2) | no | VND amount or percent (0–100) |
| `max_discount_cap` | Decimal(12,2) | yes | only meaningful when `discount_type=percent` |
| `min_order_subtotal` | Decimal(12,2) | no | default 0 |
| `usage_limit_total` | Int | yes | null = unlimited; checked atomically |
| `usage_limit_per_customer` | Int | no | default 0 = walk-in allowed; >0 ⇒ require `customer_id` |
| `times_used` | Int | no | default 0; atomic increment |
| `valid_from` | DateTime | no | inclusive |
| `valid_until` | DateTime | no | inclusive |
| `status` | EnumRef → CouponStatus | no | default `draft`; only `draft` \| `paused` storable |
| `created_at` / `updated_at` / `deleted_at` | timestamps + softDelete | — | softDelete only when `times_used = 0` |

Indexes:
- unique `(organization_id, brand_id, code)`
- `(brand_id, status)` for filtered listing
- `(valid_from, valid_until)` partial idx for range scans

### `coupon_redemptions` table — fields

| Field | Type | Nullable | Notes |
|-------|------|----------|-------|
| `id` | UUID | no | PK |
| `coupon_id` | UUID FK | no | FK CASCADE? RESTRICT — coupons not deletable while redemptions exist |
| `customer_order_id` | UUID FK | no | unique (1 coupon per order) |
| `customer_id` | UUID FK | yes | walk-in nullable |
| `discount_applied_amount` | Decimal(12,2) | no | computed at apply |
| `coupon_snapshot` | JSON | no | `{type, value, cap}` snapshot for receipts (resilient if coupon edited later) |
| `redeemed_at` | DateTime | no | apply timestamp |
| `released_at` | DateTime | yes | set when rollback (void before closed); `times_used` decrement happens in same tx |
| `redeemed_by_user_id` | UUID FK | yes | staff who applied (null for customer-web) |
| `redeemed_via` | String(20) | no | `pos` \| `customer_web` \| `kiosk` |

Indexes:
- unique `customer_order_id`
- `(coupon_id, released_at)` — count active redemptions
- `(coupon_id, customer_id, released_at)` — per-customer eligibility check

### `coupon_branch` pivot

| Field | Type | Notes |
|-------|------|-------|
| `coupon_id` | UUID FK | composite unique with branch_id |
| `branch_id` | UUID FK | composite unique |

Empty pivot for a coupon ⇒ brand-wide. Any row ⇒ restricted whitelist.

### `customer_orders` additions

```yaml
# In schemas/Backend/Product/CustomerOrder.yaml
coupon_id:
  # Nullable FK — set khi coupon được apply, clear khi release. Mỗi order
  # tối đa 1 coupon (BR-COUP01). Receipt printing đọc snapshot ở
  # coupon_redemptions để giữ giá trị tại thời điểm áp dụng.
  type: Association
  relation: ManyToOne
  target: Coupon
  nullable: true
  onDelete: SET_NULL

coupon_code_snapshot:
  # Mã coupon đã áp (snapshot tại apply time) — phục vụ receipt + audit
  # ngay cả khi coupon row sau này bị rename. Synced với
  # coupon_redemptions.coupon_snapshot.code.
  type: String
  length: 50
  nullable: true
```

`discount_amount` đã có sẵn — coupon flow chỉ ghi đè field này, không thêm column riêng.

## API surface

> Mọi endpoint dùng JSON `data:` envelope theo convention dxs-product. Coupon nằm trong 3 scope: HQ admin, Shop staff, Customer (public).

### Endpoint inventory

| # | Method | Path | Purpose | Auth | Route file |
|---|--------|------|---------|------|------------|
| 1 | GET | `/api/v1/hq/{brandSlug}/coupons` | List + filter coupons trong brand | sanctum + `CouponPolicy@viewAny` | `routes/api/hq/coupons.php` (NEW) |
| 2 | POST | `/api/v1/hq/{brandSlug}/coupons` | Create | sanctum + `CouponPolicy@create` | same |
| 3 | GET | `/api/v1/hq/{brandSlug}/coupons/{coupon}` | Show + redemption history (paginated) | sanctum + `CouponPolicy@view` | same |
| 4 | PUT | `/api/v1/hq/{brandSlug}/coupons/{coupon}` | Update (some fields locked after first redemption) | sanctum + `CouponPolicy@update` | same |
| 5 | DELETE | `/api/v1/hq/{brandSlug}/coupons/{coupon}` | Soft-delete (only `times_used = 0`) | sanctum + `CouponPolicy@delete` | same |
| 6 | POST | `/api/v1/hq/{brandSlug}/coupons/{coupon}/pause` | Set status=paused | sanctum + `CouponPolicy@pause` | same |
| 7 | POST | `/api/v1/hq/{brandSlug}/coupons/{coupon}/resume` | Clear pause | sanctum + `CouponPolicy@resume` | same |
| 8 | POST | `/api/v1/shops/{shopSlug}/orders/{customerOrder}/apply-coupon` | Atomic apply coupon to order | sanctum + `CustomerOrderPolicy@applyCoupon` | `routes/api/shops/orders.php` (extend) |
| 9 | DELETE | `/api/v1/shops/{shopSlug}/orders/{customerOrder}/coupon` | Release coupon (only if order not yet closed) | sanctum + `CustomerOrderPolicy@releaseCoupon` | same |
| 10 | POST | `/api/v1/customer/coupons/preview` | Stateless preview — validate only, no reserve | guest token / customer auth | `routes/api/customer.php` (extend) |

### Endpoint detail

#### 1. GET `/api/v1/hq/{brandSlug}/coupons`

- **Auth:** `sanctum + CouponPolicy@viewAny` — user phải có `console_organization_id` matching brand's org.
- **Route binding:** `{brandSlug}` resolved bởi `ResolveBrandFromSlug` → `request->attributes->get('brand_id')`.
- **Query params:**

  | Param | Type | Required | Notes |
  |-------|------|----------|-------|
  | `status` | enum (`draft`/`paused`/`scheduled`/`active`/`expired`/`exhausted`) | no | filter — derived states computed in service |
  | `search` | string | no | match `code` (ilike) hoặc `name` translation |
  | `branch_id` | uuid | no | filter coupons applicable cho branch (brand-wide + có pivot match) |
  | `discount_type` | enum | no | `fixed` \| `percent` |
  | `valid_at` | datetime | no | filter active at time |
  | `sort` | string | no | default `-created_at` |
  | `per_page` | int | no | default 25 |

- **Success response (`200`):**

  ```json
  {
    "data": [
      {
        "id": "01H...",
        "code": "WELCOME10",
        "name": {"ja":"...","en":"...","vi":"..."},
        "description": {"ja":null,"en":null,"vi":null},
        "discount_type": "percent",
        "discount_value": "10.00",
        "max_discount_cap": "50000.00",
        "min_order_subtotal": "100000.00",
        "usage_limit_total": 100,
        "usage_limit_per_customer": 1,
        "times_used": 23,
        "valid_from": "2026-05-08T00:00:00+07:00",
        "valid_until": "2026-05-15T23:59:59+07:00",
        "status": "draft",
        "computed_status": "active",
        "applicable_branch_ids": [],
        "remaining_uses": 77,
        "created_at": "...",
        "updated_at": "..."
      }
    ],
    "meta": { "current_page": 1, "per_page": 25, "total": 3, "last_page": 1 }
  }
  ```

- **Error responses:** `401` not authenticated · `403` not in brand's org.
- **Side effects:** none (read).

#### 2. POST `/api/v1/hq/{brandSlug}/coupons`

- **Auth:** `sanctum + CouponPolicy@create` — must be org-admin or org-manager (matches `policy.md` template).
- **Request body:**

  | Field | Type | Required | Notes |
  |-------|------|----------|-------|
  | `code` | string(2-50) | yes | uppercase A-Z 0-9 _-; unique within brand |
  | `name` | object `{ja, en, vi}` | yes | per convention #2 — top-level locale keys |
  | `description` | object `{ja?, en?, vi?}` | no | translatable optional |
  | `discount_type` | enum | yes | `fixed` \| `percent` |
  | `discount_value` | decimal | yes | `fixed`: 0 < x ≤ 10,000,000 (VND ceiling); `percent`: 0 < x ≤ 100 |
  | `max_discount_cap` | decimal | yes if `percent` else null | required when type=percent |
  | `min_order_subtotal` | decimal | no | default 0; ≥ 0 |
  | `usage_limit_total` | int \| null | no | null = unlimited; >0 |
  | `usage_limit_per_customer` | int | no | default 0; ≥ 0 |
  | `valid_from` | datetime | yes | ISO 8601 |
  | `valid_until` | datetime | yes | > valid_from |
  | `applicable_branch_ids` | uuid[] | no | empty/omit = brand-wide; each must belong to brand |
  | `status` | enum | no | default `draft` (`paused` cũng accept — for create-and-pause) |

- **Success response (`201`):** single CouponResource (same shape as inventory item).
- **Error responses:**

  | Status | Code | When |
  |--------|------|------|
  | 401 | `unauthenticated` | no token |
  | 403 | `forbidden` | not org admin/manager |
  | 422 | `validation_failed` | unique violation, percent>100, valid_until ≤ valid_from, max_cap missing for percent type, branch không thuộc brand |

- **Side effects:** insert `coupons`, attach `coupon_branch` rows if any. Write `AuditEvent` `coupon.created`.

#### 3. GET `/api/v1/hq/{brandSlug}/coupons/{coupon}`

- **Auth:** `sanctum + CouponPolicy@view`.
- **Route binding:** `{coupon}` implicit by id.
- **Query params:** `with_redemptions` (bool, default true), `redemptions_per_page` (int, default 20).
- **Success response (`200`):** CouponResource + paginated `redemptions` relation:

  ```json
  {
    "data": {
      "id": "...",
      "code": "WELCOME10",
      "...": "...",
      "applicable_branches": [{"id":"...","name":"..."}],
      "redemptions": {
        "data": [
          {
            "id":"...",
            "customer_order_id":"...",
            "customer_id":"...",
            "discount_applied_amount":"20000.00",
            "redeemed_at":"...",
            "released_at": null,
            "redeemed_via":"pos"
          }
        ],
        "meta": {...}
      }
    }
  }
  ```

- **Error responses:** 401, 403, 404.
- **Side effects:** none.

#### 4. PUT `/api/v1/hq/{brandSlug}/coupons/{coupon}`

- **Auth:** `sanctum + CouponPolicy@update`.
- **Request body:** same as create. **Locked fields after first redemption** (`times_used > 0`): `code`, `discount_type`, `discount_value`, `max_discount_cap` — chỉ được edit `name`, `description`, `valid_until` (extend), `usage_limit_total` (only increase), `usage_limit_per_customer`, `applicable_branch_ids`, `status`.
- **Success response (`200`):** updated CouponResource.
- **Error responses:** 401, 403, 404, 422 (locked field edited / validation).
- **Side effects:** update row, sync pivot, write `AuditEvent` `coupon.updated` with diff.

#### 5. DELETE `/api/v1/hq/{brandSlug}/coupons/{coupon}`

- **Auth:** `sanctum + CouponPolicy@delete`.
- **Behavior:** soft-delete only when `times_used = 0`. Else 409 `coupon_already_redeemed_use_pause_instead`.
- **Success response (`204`).**
- **Side effects:** soft-delete; write `AuditEvent` `coupon.deleted`.

#### 6. POST `/api/v1/hq/{brandSlug}/coupons/{coupon}/pause`

- **Auth:** `sanctum + CouponPolicy@pause`.
- **Behavior:** set `status = paused`. New redemptions reject với `coupon_paused`. Existing redemptions không thay đổi.
- **Success response (`200`):** updated CouponResource.

#### 7. POST `/api/v1/hq/{brandSlug}/coupons/{coupon}/resume`

- **Auth:** `sanctum + CouponPolicy@resume`.
- **Behavior:** set `status = draft`. Computed status sẽ trở về `active`/`scheduled`/`expired`/`exhausted` tùy điều kiện thời gian + counter.

#### 8. POST `/api/v1/shops/{shopSlug}/orders/{customerOrder}/apply-coupon`

- **Auth:** `sanctum + CustomerOrderPolicy@applyCoupon` — staff thuộc shop và shop's brand match coupon's brand.
- **Route binding:** `{shopSlug}` → branch_id; `{customerOrder}` model binding (must belong to branch).
- **Request body:**

  | Field | Type | Required | Notes |
  |-------|------|----------|-------|
  | `code` | string | yes | case-insensitive lookup |
  | `customer_id` | uuid | no | required nếu coupon có `usage_limit_per_customer > 0` |

- **Server flow (atomic, in `DB::transaction`):**

  1. `assertStatus($order, [Open, Dining, Pending, Checkout])` — reject `paying`/`closed`/`voided` (409 `order_not_modifiable`).
  2. Nếu `$order->coupon_id !== null` → release current redemption first (nested release, cùng tx).
  3. Lookup coupon: `Coupon::where('brand_id', $brandId)->whereRaw('UPPER(code) = ?', [strtoupper($code)])->lockForUpdate()->first()` — 404 nếu null.
  4. Validate (return 422 với code-error tương ứng):
     - `coupon_paused` nếu `status = paused`.
     - `coupon_not_started` nếu `now() < valid_from` → status derived `scheduled`.
     - `coupon_expired` nếu `now() > valid_until`.
     - `coupon_branch_not_eligible` nếu pivot có row + `$order->branch_id` không match.
     - `coupon_min_subtotal_not_met` nếu `$order->subtotal < min_order_subtotal`.
     - `coupon_exhausted` nếu `usage_limit_total !== null && times_used >= usage_limit_total`.
     - `customer_required` nếu `usage_limit_per_customer > 0 && customer_id === null`.
     - `coupon_already_used_by_customer` nếu `usage_limit_per_customer > 0` và `count(redemptions WHERE customer_id = ? AND released_at IS NULL) >= usage_limit_per_customer`.
  5. Compute `discount_applied_amount`:
     - `fixed`: `min($coupon->discount_value, $order->subtotal)`.
     - `percent`: `min($order->subtotal * $value / 100, $max_discount_cap ?? PHP_FLOAT_MAX)`. Round to 2 decimals.
  6. `Coupon::where('id', $coupon->id)->where('times_used', '<', $coupon->usage_limit_total ?? PHP_INT_MAX)->increment('times_used')` (defensive — primary lock đã giữ).
  7. Create `CouponRedemption` row (unique customer_order_id sẽ throw QueryException nếu đã có — race-safe redundancy).
  8. Update `$order` set `coupon_id`, `coupon_code_snapshot`, `discount_amount = $discount_applied_amount`. Recompute `total_amount` nếu order đang ở `Checkout` (đã có service charge + tax → cần recalculate via shared `recalculateTotals`); ngược lại để nguyên `total_amount` (sẽ recompute tự động ở `checkout()`).
  9. `$order->logAudit('coupon_applied', ['coupon_code' => ..., 'discount' => ...])`.

- **Success response (`200`):** updated CustomerOrderResource (đã có `coupon_id`, `coupon_code_snapshot`, `discount_amount`, `total_amount`) + `applied_coupon` block:

  ```json
  {
    "data": { /* CustomerOrderResource */ },
    "meta": {
      "applied_coupon": {
        "code": "WELCOME10",
        "discount_type": "percent",
        "discount_value": "10.00",
        "discount_applied_amount": "20000.00"
      }
    }
  }
  ```

- **Error responses:**

  | Status | Code | When |
  |--------|------|------|
  | 401 | `unauthenticated` | |
  | 403 | `forbidden` | order không thuộc shop của user |
  | 404 | `coupon_not_found` | code không tồn tại trong brand |
  | 409 | `order_not_modifiable` | status = paying/closed/voided |
  | 422 | `coupon_paused` / `coupon_expired` / `coupon_not_started` / `coupon_min_subtotal_not_met` / `coupon_exhausted` / `customer_required` / `coupon_already_used_by_customer` / `coupon_branch_not_eligible` | per validation rule |

- **Side effects:** lockForUpdate + increment `coupons.times_used`, insert `coupon_redemptions`, update `customer_orders` (coupon_id, code_snapshot, discount_amount, recalculated total). AuditEvent `coupon_applied`.

#### 9. DELETE `/api/v1/shops/{shopSlug}/orders/{customerOrder}/coupon`

- **Auth:** `sanctum + CustomerOrderPolicy@releaseCoupon`.
- **Behavior (atomic in `DB::transaction`):**
  1. `assertStatus($order, [Open, Dining, Pending, Checkout])` — reject `paying`/`closed`/`voided`.
  2. Nếu `$order->coupon_id === null` → 422 `no_coupon_applied`.
  3. Find redemption by `customer_order_id` `lockForUpdate`. Nếu `released_at !== null` → no-op (idempotent).
  4. `Coupon::find($redemption->coupon_id)->lockForUpdate()`.
  5. Set `redemption.released_at = now()`. `coupons.times_used = max(0, times_used - 1)` via `decrement`.
  6. Update `$order` set `coupon_id = null`, `coupon_code_snapshot = null`, `discount_amount = 0`, recompute `total_amount` if at Checkout.
  7. `$order->logAudit('coupon_released')`.

- **Success response (`200`):** updated CustomerOrderResource.
- **Error responses:** 401, 403, 404, 409 (`order_not_modifiable`), 422 (`no_coupon_applied`).

#### 10. POST `/api/v1/customer/coupons/preview`

- **Auth:** customer-web JWT hoặc guest token (per existing `routes/api/customer.php` patterns).
- **Request body:**

  | Field | Type | Required | Notes |
  |-------|------|----------|-------|
  | `code` | string | yes | |
  | `brand_id` | uuid | yes | scope lookup |
  | `branch_id` | uuid | yes | for branch eligibility |
  | `customer_id` | uuid | no | nếu authenticated |
  | `subtotal` | decimal | yes | computed cart subtotal |

- **Server flow:** read-only — không lock, không increment. Apply same validation chain (window, min subtotal, scope, exhaustion, per-customer usage).
- **Success response (`200`):**

  ```json
  {
    "data": {
      "is_valid": true,
      "code": "WELCOME10",
      "discount_type": "percent",
      "discount_applied_amount": "20000.00",
      "name": "10% off welcome"
    }
  }
  ```

  hoặc, khi invalid:

  ```json
  {
    "data": {
      "is_valid": false,
      "error_code": "coupon_min_subtotal_not_met",
      "min_required": "100000.00"
    }
  }
  ```

- **Error responses:** 401, 422 (validation of payload, không phải coupon error — coupon errors đi vào `is_valid: false`).
- **Side effects:** **none** — preview không ghi DB.

## Screens

> Toàn bộ UI dùng `@godxjp/ui` (convention #1 + #2). Translatable fields dùng `<Input translatable={{locales}} />` / `<Textarea translatable />`, KHÔNG layout 4-Card-per-locale.

### Screen inventory

| # | Path | Type | Auth | Purpose |
|---|------|------|------|---------|
| S1 | `/hq/{brandSlug}/coupons` | List page | HQ admin | Browse + filter coupons |
| S2 | `/hq/{brandSlug}/coupons/new` | Create form | HQ admin | Tạo coupon mới |
| S3 | `/hq/{brandSlug}/coupons/{id}` | Detail page | HQ admin | Coupon detail + redemption history |
| S4 | `/hq/{brandSlug}/coupons/{id}/edit` | Edit form | HQ admin | Edit (locked fields after first redemption) |
| S5 (modified) | `pos-web payment-dialog.tsx` | Modal section | POS staff | Apply / remove coupon trước khi confirm payment |
| S6 (modified) | `customer-web checkout-page.tsx` | Form section | Customer | Nhập code + preview discount |

### Screen detail

#### S1 — List page — `/hq/{brandSlug}/coupons`

- **Layout:** `admin-web/src/app/hq/[brandSlug]/layout.tsx` (sidebar HQ).
- **Page file:** `admin-web/src/app/hq/[brandSlug]/coupons/page.tsx`.
- **Fetches:** API #1 `GET /hq/{brandSlug}/coupons`.
- **Components used:**
  - `Card` cho header section
  - `Button` (variant primary) — "+ New coupon" → navigate S2
  - `Input` — search box (debounced)
  - `Select` — status filter (`all`/`active`/`scheduled`/`expired`/`exhausted`/`paused`/`draft`)
  - `Combobox` — branch filter (multi không cần — single chọn 1 branch)
  - `Table` — columns: Code · Name (locale fallback) · Type · Value · Window · Used / Limit · Status (Badge) · Actions
  - `Badge` — status chip (`success` cho `active`, `warning` cho `scheduled`, `destructive` cho `expired`/`exhausted`, `secondary` cho `draft`/`paused`)
  - `DropdownMenu` per row — "View" / "Edit" / "Pause" / "Resume" / "Delete"
  - `Dialog` confirm cho Delete + Pause/Resume actions
  - `Pagination`
  - `Skeleton` (loading state)
  - `Alert` (variant destructive) cho error fetch
- **Translatable fields:** Name column hiển thị locale fallback chain (current locale → ja → en).
- **Empty state:** Card với icon + text "Brand này chưa có coupon nào" + CTA Button "Tạo coupon đầu tiên".
- **Error state:** `Alert` variant destructive với message + retry button.
- **Loading state:** `Skeleton` rows trong Table.
- **Interactions:** click row → S3 detail; "Edit" → S4; "Delete"/"Pause"/"Resume" → confirmation Dialog → mutation + toast.

#### S2 — Create form — `/hq/{brandSlug}/coupons/new`

- **Layout:** same HQ layout.
- **Page file:** `admin-web/src/app/hq/[brandSlug]/coupons/new/page.tsx`.
- **Fetches:** preload `GET /hq/{brandSlug}/shops` (for `applicable_branch_ids` Combobox); on submit POST API #2.
- **Components used:**
  - `Form` + `FormField` + `FormItem` + `FormLabel` (react-hook-form)
  - `Input` — `code` (auto-uppercase via input mask)
  - `Input translatable={{locales: {ja, en, vi}}}` — `name`
  - `Textarea translatable={{locales: {ja, en, vi}}}` — `description`
  - `Select` — `discount_type` (`fixed` / `percent`)
  - `Input` type="number" — `discount_value` với suffix `%` hoặc `₫` derived từ type
  - `Input` type="number" — `max_discount_cap` (visible chỉ khi `percent`)
  - `Input` type="number" — `min_order_subtotal`
  - `Input` type="number" — `usage_limit_total` (placeholder "Để trống = không giới hạn")
  - `Input` type="number" — `usage_limit_per_customer` (default 0)
  - `DatePicker` — `valid_from` + `valid_until` (range mode)
  - `Combobox` multi-select — `applicable_branch_ids` (placeholder "Tất cả shops của brand")
  - `Switch` — "Tạo và pause ngay" (sets status=paused on submit, default off)
  - `Button` submit / cancel — sticky footer
  - `Alert` (destructive) cho server validation error
- **Translatable fields:** `name` (Input translatable single component, locale tabs trong cùng input theo convention #2), `description` (Textarea translatable).
- **Form best practices** (per convention #4 — form-screen sub-agent task in TASKS Phase Discovery):
  - Layout: single-column, max-width ~720px; section grouping (Identity / Discount rules / Validity / Scope / Limits)
  - Label placement: top-aligned (matches existing admin-web forms)
  - Field ordering: group rules — Identity first (code, name, description), then Discount math (type, value, cap, min subtotal), Validity (dates), Scope (branches), Limits (usage caps)
  - Error surfaces: inline under field for client validation; `Alert` (destructive) banner top of form for server error; toast cho success
  - Save/Cancel patterns: sticky footer, primary "Tạo" right-aligned, Cancel left-aligned. Submit disabled while pending.
  - Keyboard affordances: `Enter` submits when focus is in `Input` (not `Textarea`); `Escape` triggers Cancel với confirm Dialog nếu form dirty.
- **Empty state:** N/A (form luôn có default values).
- **Error state:** Alert top of form với error per-field details from 422.
- **Loading state:** submit button → Spinner; form disabled.
- **Interactions:** validate client-side via Zod (mirror server rules), submit → toast → navigate S3 detail.

#### S3 — Detail page — `/hq/{brandSlug}/coupons/{id}`

- **Layout:** HQ.
- **Page file:** `admin-web/src/app/hq/[brandSlug]/coupons/[id]/page.tsx`.
- **Fetches:** API #3.
- **Components used:**
  - `Card` cho 3 panels: Overview · Validity · Limits & Usage
  - Header với `Badge` (computed_status), action `DropdownMenu` (Edit, Pause/Resume, Delete)
  - `Tabs` — "Tổng quan" + "Lịch sử redemption" + "Branches áp dụng"
  - Trong tab "Lịch sử redemption": `Table` columns (Date · Order code · Customer · Discount applied · Status `Released`/`Active` · Channel) + `Pagination`
  - Trong tab "Branches": `Table` của branches áp dụng (hoặc `Alert` info "Áp dụng cho TẤT CẢ shops trong brand" khi pivot rỗng)
  - `Skeleton` loading
- **Empty state (redemption history):** `Card` với "Coupon chưa được redeem lần nào".
- **Interactions:** action menu triggers các API workflow + toasts.

#### S4 — Edit form — `/hq/{brandSlug}/coupons/{id}/edit`

- **Layout:** HQ.
- **Page file:** `admin-web/src/app/hq/[brandSlug]/coupons/[id]/edit/page.tsx`.
- **Fetches:** API #3 then PUT API #4 on submit.
- **Components used:** Same as S2. Locked fields (`code`, `discount_type`, `discount_value`, `max_discount_cap`) khi `times_used > 0`: render disabled với `Tooltip` "Không thể sửa sau khi đã có redemption".
- **Diff vs S2:** Locked-field tooltips; submit button label "Lưu thay đổi"; show banner ("Coupon này đã được dùng X lần — một số field đã bị khóa") nếu applicable.

#### S5 (MODIFIED) — POS payment-dialog.tsx — coupon section

- **File:** `pos-web/src/app/pos/components/payment-dialog.tsx` (extend).
- **Fetches:** API #8 (apply), API #9 (release).
- **Components used:** `@godxjp/ui` Input + Button trong subsection "Mã giảm giá":
  - Khi `coupon_id === null`: render Input + "Áp dụng" Button → mở mini Dialog nhập code (+ optional customer search via existing customer combobox in PaymentDialog) → submit → API #8 → toast.
  - Khi `coupon_id !== null`: render `Card` chip với `coupon_code_snapshot`, `discount_applied_amount` formatted VND, "X" Button → confirm Dialog → API #9.
- **Empty state:** placeholder "Không có coupon nào được áp dụng".
- **Error state:** Alert inline trong Dialog với error code mapped sang i18n string (ví dụ `coupon_expired` → "Coupon đã hết hạn").
- **Loading state:** Button spinner.

#### S6 (MODIFIED) — customer-web checkout-page.tsx — coupon section

- **File:** `customer-web/components/checkout-page.tsx` (extend).
- **Fetches:** debounced API #10 preview khi user nhập; API #8 ngay khi customer submit order (phía backend gọi `apply-coupon` trong cùng order create transaction).
- **Components used:** `@godxjp/ui` Input + Button "Áp dụng":
  - Input field với placeholder "Nhập mã giảm giá", `name="coupon_code"`
  - Khi typing → debounced 600ms → preview API #10 — render kết quả:
    - `is_valid: true`: Badge success `−20.000₫` cạnh code
    - `is_valid: false`: Alert info inline với localized error message
  - Discount line trong order summary: cập nhật real-time theo preview
  - Khi customer click "Đặt đơn" → backend create order rồi apply-coupon trong cùng tx (server-side recheck — preview là client UX, server vẫn re-validate)

## Sitemap

> Navigation diff cho admin-web HQ scope. POS / customer-web không có sitemap mới — chỉ extend dialog/section trong pages hiện hữu.

### Navigation diff (admin-web)

```
/hq/{brandSlug}
├── catalog/
├── menus/
├── customers/
├── ...
└── coupons/                    [NEW] → /hq/{brandSlug}/coupons
    ├── (list)                  [NEW] S1
    ├── new/                    [NEW] S2
    └── {id}/
        ├── (detail)            [NEW] S3
        └── edit/               [NEW] S4
```

### Entry points

| From | Via | To | Visibility |
|------|-----|----|------------|
| HQ Sidebar | New nav item "Coupons" (icon: `Ticket` từ lucide) | S1 | HQ admin only |
| S1 | "+ New coupon" Button | S2 | HQ admin |
| S1 | Click row | S3 | HQ admin |
| S1 | "Edit" trong DropdownMenu | S4 | HQ admin |
| S3 | "Edit" Button trong header | S4 | HQ admin |
| POS payment-dialog | "Áp dụng coupon" inline | (mini dialog inside dialog) | POS staff |
| customer-web checkout | "Mã giảm giá" Input | (inline in form) | Customer |

### Breadcrumbs

| Screen | Crumbs |
|--------|--------|
| S1 | `Brand` > `Coupons` |
| S2 | `Brand` > `Coupons` > `Tạo mới` |
| S3 | `Brand` > `Coupons` > `{code}` |
| S4 | `Brand` > `Coupons` > `{code}` > `Chỉnh sửa` |

### Deep-link / back-link behaviour

- S2 cancel → S1.
- S2 success → S3 of new id + toast "Đã tạo coupon".
- S4 cancel → S3.
- S4 success → S3 + toast "Đã lưu".
- S3 delete success → S1 + toast.
- S3 pause/resume → reload S3.
- 404 (coupon không tồn tại / không thuộc brand) → S1 + Alert info.

## Authorization matrix

> Roles theo `backend/docs/contributing/policy.md` template + `docs/explanation/authorization.md`. HQ-side roles từ `console_organization_id + spatie roles`. Shop-side roles via `WarehouseMember` hoặc tương đương; coupon đọc qua `CustomerOrderPolicy` cho apply/release.

### Roles involved

| Role key | Display | Source | Notes |
|----------|---------|--------|-------|
| `org-admin` | HQ Owner | spatie `Role` | Full access HQ scope |
| `org-manager` | HQ Manager | spatie | Full create/update; có thể delete |
| `org-staff` | HQ Staff | spatie | Read-only |
| `shop-manager` | Shop Manager | spatie scoped | Apply/release coupon trong shop của mình |
| `shop-staff` | Shop Staff | spatie scoped | Apply/release coupon trong shop của mình |
| (anonymous) | Customer | guest token / customer auth | Preview only |

### Action × Role matrix

| Action | org-admin | org-manager | org-staff | shop-manager | shop-staff | customer |
|--------|-----------|-------------|-----------|--------------|------------|----------|
| List/View coupons trong brand | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| Create coupon | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Update coupon (unlocked fields) | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Delete coupon (`times_used = 0`) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Pause / Resume | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Apply coupon to order | ❌ | ❌ | ❌ | ✅ (own shop) | ✅ (own shop) | ✅ (via order create) |
| Release coupon from order | ❌ | ❌ | ❌ | ✅ (own shop) | ✅ (own shop) | ❌ |
| Preview coupon | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

Legend: ✅ allowed · ❌ forbidden · ✅ (own shop) — scoped to user's shop.

### Policy ↔ UI gate cross-check

| Action | Backend gate | Frontend gate |
|--------|--------------|---------------|
| Create coupon | `CouponPolicy@create` (org-admin/org-manager) | "+ New coupon" Button hidden cho `org-staff` |
| Delete coupon | `CouponPolicy@delete` (org-admin only + `times_used = 0`) | DropdownMenu "Delete" hidden khi không phải org-admin hoặc khi coupon đã có redemption |
| Apply coupon | `CustomerOrderPolicy@applyCoupon` (shop staff/manager + branch match) | Inline "Áp dụng coupon" chỉ hiển thị trong POS — admin-web không có path apply |
| Release coupon | `CustomerOrderPolicy@releaseCoupon` (shop staff/manager + order chưa closed) | "X" Button hidden khi `order.status` ∈ `paying/closed/voided` |

### Role-switch verification checklist

- [ ] HQ org-staff login → Sidebar có hiện "Coupons" → click vào S1 thấy list (read-only) → không thấy "+ New" / Edit / Delete buttons.
- [ ] HQ org-manager → thấy Edit/Pause/Resume nhưng KHÔNG thấy Delete.
- [ ] HQ org-admin → thấy Delete (chỉ visible khi `times_used = 0`; còn lại bị disable + Tooltip giải thích).
- [ ] Shop manager/staff → KHÔNG thấy item "Coupons" trong sidebar HQ (vì không có quyền vào HQ scope). Trong POS payment-dialog thấy section coupon.
- [ ] Customer-web → checkout-page input coupon code visible.
- [ ] Cross-shop: shop A staff cố gắng apply coupon (brand B chứ không phải brand của shop A) → 403; cố gắng apply vào order của shop B → 403.

## User journeys

### Journey 1 — Brand owner tạo coupon `WELCOME10`

**Persona:** Linh, brand owner (HQ org-admin), đang ở dashboard `/hq/{brandSlug}/dashboard`.

**Happy path:**

1. Sidebar HQ click "Coupons" → vào S1 list → thấy empty state với CTA "Tạo coupon đầu tiên".
2. Click CTA → vào S2 form. Fill: `code = WELCOME10` (auto-upper), `name = {ja: "ようこそ10%オフ", en: "Welcome 10% off", vi: "Chào mừng giảm 10%"}`, `discount_type = percent`, `discount_value = 10`, `max_discount_cap = 50000`, `min_order_subtotal = 100000`, `usage_limit_total = 100`, `usage_limit_per_customer = 1`, `valid_from = today 00:00`, `valid_until = today + 7d 23:59`, để trống `applicable_branch_ids` (brand-wide). Submit.
3. Backend POST API #2 success → toast "Đã tạo coupon WELCOME10" → redirect S3 detail. Linh thấy `Badge` "Active", redemption history trống, branches "Áp dụng cho tất cả shops".

**Alternate path — Linh muốn pause coupon ngay:**
Bật `Switch` "Tạo và pause ngay" trên S2 → submit → S3 hiện `Badge` "Paused".

**Edge / error paths:**
- `code` đã tồn tại (`WELCOME10` của brand này) → server 422 `validation_failed` → `Alert` đỏ trên S2 với "Mã coupon đã được sử dụng".
- `valid_until ≤ valid_from` → client Zod báo inline "Ngày kết thúc phải sau ngày bắt đầu".
- Network error → Alert + "Thử lại" button; form values giữ nguyên.

### Journey 2 — POS staff áp dụng coupon vào order

**Persona:** Tâm, shop staff tại Branch HCM-1, đang ở POS với order #ORD-2026-0421 subtotal 200,000₫, status `Dining`.

**Happy path:**

1. Tâm bấm "Thanh toán" → mở `payment-dialog`. Tâm thấy section "Mã giảm giá" với placeholder "Chưa áp dụng coupon nào".
2. Click "Áp dụng coupon" → mini Dialog hiện ra. Khách nói có code `WELCOME10` và đăng ký số 0901xxx.
3. Tâm chọn customer qua Combobox (tìm theo phone) → nhập code `WELCOME10` → "Áp dụng".
4. Backend POST API #8 success → mini Dialog đóng → payment-dialog hiển thị `Badge` "WELCOME10" với `−20.000₫`. Order summary: subtotal 200,000 − 20,000 = 180,000 + tax/service.
5. Tâm hoàn tất payment → close order. Coupon counter +1.

**Alternate path — Customer chưa đăng ký:**
Coupon yêu cầu `usage_limit_per_customer = 1` → Tâm bỏ qua Combobox customer → Apply trả 422 `customer_required` → mini Dialog hiển thị "Cần chọn khách hàng để áp dụng coupon này".

**Edge / error paths:**
- `coupon_min_subtotal_not_met` (subtotal < 100,000₫) → Alert "Đơn tối thiểu 100.000₫ để áp dụng".
- `coupon_already_used_by_customer` → "Khách đã dùng coupon này rồi".
- `coupon_exhausted` (race condition: 99 → 100 ngay khi Tâm click) → "Coupon đã hết lượt sử dụng".
- `order_not_modifiable` (order đã `paying` rồi mà Tâm cố apply) → "Không thể áp dụng coupon cho đơn đang thanh toán".

### Journey 3 — Customer-web tự nhập coupon

**Persona:** Hùng, khách online order trên customer-web, đã chọn món subtotal 250,000₫.

**Happy path:**

1. Checkout-page hiện form. Hùng nhập code `WELCOME10` vào field "Mã giảm giá" → debounced preview API #10.
2. UI hiện `Badge` xanh `−25.000₫` (10% × 250,000 = 25,000, dưới cap 50,000).
3. Hùng điền thông tin nhận hàng + chọn payment method → "Đặt đơn".
4. Backend create order → trong cùng transaction gọi `apply-coupon` (re-validate atomic). Counter +1. Order confirmation hiện total đã giảm.

**Alternate path — Code không tồn tại:**
Sau debounce → preview trả `is_valid: false`, `error_code = coupon_not_found` → UI hiển thị "Mã không hợp lệ" inline.

**Edge / error paths:**
- Hùng nhập code đúng nhưng giữa preview và submit, brand pause coupon → submit-side apply 422 `coupon_paused` → checkout-page hiển thị Alert "Coupon đã bị tạm khoá. Vui lòng thử mã khác." + bỏ giảm giá khỏi order summary.
- Race: counter 99 lúc preview, 100 lúc submit → 422 `coupon_exhausted` (ack).

### Journey 4 — Manager pause coupon đang chạy

**Persona:** Hà, brand manager, đang giám sát một promo cuối tuần.

**Happy path:**

1. Hà ở S3 detail. Click "Pause" trong action menu → confirm Dialog "Tạm dừng coupon WELCOME10? Khách đang trong giỏ hàng có code này sẽ bị từ chối ngay lập tức." → Confirm.
2. Backend POST API #6 → toast "Đã tạm dừng" → S3 reload, `Badge` chuyển "Paused".
3. Khách nào sau đó submit order với code này → 422 `coupon_paused`. Existing redemptions không thay đổi.

### Journey 5 — Shop staff void order có coupon

**Persona:** Bình, shop manager, void một order khách bỏ đi (status `Dining`).

**Happy path:**

1. Bình mở order detail trong POS. Order có chip "WELCOME10 −20.000₫".
2. Bình click "Void" → confirm modal nhập `void_reason = "Khách đổi ý"` → Confirm.
3. Backend `CustomerOrderService::voidOrder()` mở transaction:
   - Void items.
   - Set order status = voided.
   - **`couponService->releaseIfApplied($order)`**: locks coupon, soft-deletes redemption (`released_at = now()`), decrements `times_used`.
   - Audit `voided` + `coupon_released_on_void`.
4. UI quay về tables overview. Coupon counter trở về `times_used - 1` → khách khác (hoặc cùng khách) có thể redeem lại.

**Edge / error paths:**
- Order đã `closed` rồi mới void (refund flow) → `voidOrder()` reject 409 `Cannot void a closed order` (existing behavior). Coupon counter **không** bị giảm. Manager phải refund manual.

### Cross-journey checklist

- [x] Mỗi happy-path step ánh xạ ≥ 1 endpoint trong API inventory (J1: API #2, #3 / J2: #8 / J3: #10, #8 / J4: #6 / J5: existing void + couponService internal).
- [x] Mọi error path có 4xx case tương ứng trong endpoint detail.
- [x] Mọi navigation step ánh xạ Sitemap entry-points (S1↔S2↔S3↔S4 + POS modal + customer-web inline).
- [x] Mọi role trong matrix được cover: org-admin (J1, J4), shop-manager (J5), shop-staff (J2), customer (J3), org-staff (passive view of S1 — no journey needed, listed in role-switch checklist).

## Field lifecycle

### `Coupon`

| Field | Added? | Default | Displayed on screens | Editable on screens | Editable by roles | Validation | Omnify prop |
|-------|--------|---------|----------------------|---------------------|-------------------|------------|-------------|
| `code` | NEW | (none) | S1, S3, S4, S5, S6 | S2, S4 (locked sau redemption) | org-admin, org-manager | required, regex `^[A-Z0-9_-]{2,50}$`, unique scope (brand) | `String length: 50` |
| `name` | NEW | (none) | S1, S3, S4 | S2, S4 | org-admin, org-manager | required all 3 locales | `String translatable: true` |
| `description` | NEW | null | S3, S4 | S2, S4 | org-admin, org-manager | optional, length ≤ 500 mỗi locale | `Text translatable: true nullable: true` |
| `discount_type` | NEW | (none) | S1, S3, S4 | S2, S4 (locked sau redemption) | org-admin, org-manager | enum CouponDiscountType | `EnumRef enum: CouponDiscountType` |
| `discount_value` | NEW | (none) | S1, S3, S4 | S2, S4 (locked sau redemption) | org-admin, org-manager | fixed: 0<x≤10M; percent: 0<x≤100 | `Decimal 12,2` |
| `max_discount_cap` | NEW | null | S3, S4 | S2, S4 (locked sau redemption) | org-admin, org-manager | required nếu type=percent; nullable nếu fixed | `Decimal 12,2 nullable: true` |
| `min_order_subtotal` | NEW | 0 | S3, S4 | S2, S4 | org-admin, org-manager | ≥ 0 | `Decimal 12,2 default: 0` |
| `usage_limit_total` | NEW | null | S1, S3, S4 | S2, S4 (only increase sau redemption) | org-admin, org-manager | nullable; >0 | `Int nullable: true` |
| `usage_limit_per_customer` | NEW | 0 | S3, S4 | S2, S4 | org-admin, org-manager | ≥ 0 | `Int default: 0` |
| `times_used` | NEW | 0 | S1, S3 | (system) | system | ≥ 0 | `Int default: 0` |
| `valid_from` | NEW | (none) | S1, S3, S4 | S2, S4 | org-admin, org-manager | datetime | `DateTime` |
| `valid_until` | NEW | (none) | S1, S3, S4 | S2, S4 (extend) | org-admin, org-manager | > valid_from | `DateTime` |
| `status` | NEW | `draft` | S1, S3 (Badge derived) | via Pause/Resume actions | org-admin, org-manager | enum | `EnumRef default: draft` |
| `brand_id` | NEW | (route) | (implicit) | (system, set tại create) | system | required FK | `Association ManyToOne Brand` |
| `organization_id` | NEW | (auth) | (implicit) | (system) | system | required FK | `Association ManyToOne Organization` |

### `CouponRedemption`

| Field | Added? | Default | Displayed on screens | Editable on screens | Editable by roles | Validation | Omnify prop |
|-------|--------|---------|----------------------|---------------------|-------------------|------------|-------------|
| `coupon_id` | NEW | (apply) | S3 redemption tab | (system) | system | FK | Association |
| `customer_order_id` | NEW | (apply) | S3 | (system) | system | FK unique | Association unique |
| `customer_id` | NEW | null | S3 | (system) | system | FK nullable | Association nullable |
| `discount_applied_amount` | NEW | (computed) | S3 | (system) | system | ≥ 0 | Decimal 12,2 |
| `coupon_snapshot` | NEW | (computed) | (internal) | (system) | system | json | JSON |
| `redeemed_at` | NEW | now() | S3 | (system) | system | timestamp | DateTime |
| `released_at` | NEW | null | S3 | (system) | system | timestamp nullable | DateTime nullable |
| `redeemed_by_user_id` | NEW | null | S3 | (system) | system | FK nullable | Association nullable |
| `redeemed_via` | NEW | (apply) | S3 | (system) | system | enum string | String length 20 |

### `CustomerOrder` additions

| Field | Added? | Default | Displayed on screens | Editable on screens | Editable by roles | Validation | Omnify prop |
|-------|--------|---------|----------------------|---------------------|-------------------|------------|-------------|
| `coupon_id` | NEW | null | POS S5, customer S6 (chip) | apply/release flow | shop-staff, shop-manager, customer | FK nullable | Association nullable onDelete SET_NULL |
| `coupon_code_snapshot` | NEW | null | POS S5, customer S6 | apply/release flow | (system) | string ≤ 50 nullable | String nullable length 50 |

### Orphaned field audit

Fields trên `coupons` schema không touch trong v1: không có (mọi field được surface).

Fields trên `customer_orders` không touch ngoài 2 cột mới + sự ghi vào `discount_amount`: được giữ nguyên — tài liệu cập nhật BR-O04 để chú thích discount_amount nay có thể được set bởi coupon flow.

| Field | Why not touched | Currently editable at | Acceptable? |
|-------|-----------------|-----------------------|-------------|
| `customer_orders.discount_amount` | Đã editable ở `OrderCheckoutRequest`; coupon flow ghi đè value đó. Server vẫn cho phép manual override khi không có `coupon_id`. | POS checkout (manual nhập) | Yes — backward compat; nếu order có `coupon_id`, FE disabled manual edit. |

### Field lifecycle cross-check

- [x] Mỗi "Editable on screens" cell có matching input ở screen detail.
- [x] Mỗi "Displayed on screens" cell có matching column / display.
- [x] Mỗi NEW field có ≥ 1 validation rule trong endpoint request body.
- [x] Mỗi `translatable: true` (`name`, `description`) dùng `<Input/Textarea translatable />` per convention #2.
- [x] Mỗi NOT NULL field không có default đều có Required entry trong Request body table.

## Authorization

Per `backend/docs/contributing/policy.md`. Tóm tắt:

- **`CouponPolicy`** — methods: `viewAny(User)`, `view(User, Coupon)`, `create(User)`, `update(User, Coupon)`, `delete(User, Coupon)`, `pause(User, Coupon)`, `resume(User, Coupon)`. Tất cả đều check `$coupon->organization_id === $user->console_organization_id`. `create`/`update`/`pause`/`resume` thêm role check `org-admin || org-manager`. `delete` chỉ `org-admin` + `times_used = 0`.
- **`CustomerOrderPolicy@applyCoupon` + `@releaseCoupon`** — methods mới (extend existing policy). Check user thuộc org của order's brand AND user có scope vào branch của order. Reuse helper `userBelongsToBranch(User, branch_id)` (tương tự pattern `WarehouseMember` cho inventory).
- Customer-side `preview` không cần policy — endpoint là public, validation hoàn toàn ở service.

## Key decisions

### Decision 1 — Atomic counter qua `lockForUpdate` thay vì Redis INCR

- **Chose:** `SELECT ... FOR UPDATE` row-level lock + `DB::transaction`, đặt trong `CouponService::apply()`.
- **Rejected:** Redis `INCR` với eviction để cap; optimistic `WHERE times_used < limit ... UPDATE`.
- **Why:** Codebase đã có pattern `lockForUpdate` ở `NotificationService`, `TableStatusService`, `MaterialLotService`. Throughput của 1 nhà hàng đơn lẻ ≪ 10 req/s — DB lock là đủ. Tránh phụ thuộc Redis cho consistency-critical path. Optimistic update được đính kèm như defensive layer (`->where('times_used', '<', limit)->increment()`) để tránh oversell ngay cả khi lock bị bypass do bug.

### Decision 2 — Brand-scoped owner với pivot branch thay vì duplicate per shop

- **Chose:** Coupon row sống ở brand level; pivot `coupon_branch` lưu whitelist nếu cần restrict.
- **Rejected:** Mỗi shop tự create coupon riêng (shop-only model); single column `branch_id` nullable (PaymentMethod pattern).
- **Why:** User chọn "Cả hai (brand-wide + shop-local)" — pivot table cho phép một coupon áp một subset shops mà không cần duplicate row. PaymentMethod `branch_id nullable` chỉ cho 2 trạng thái (all hoặc 1) — không đủ cho "áp 5/10 shops". Pivot scale tốt hơn và dễ filter `WHERE EXISTS (SELECT 1 FROM coupon_branch ...)`.

### Decision 3 — Status derived từ time + counter, không cần cron

- **Chose:** Lưu DB chỉ `draft` | `paused`; computed status (`scheduled` / `active` / `expired` / `exhausted`) tính tại read/validate time.
- **Rejected:** Scheduled job cập nhật `status` mỗi giờ.
- **Why:** Cron tạo race window (status update trễ vài phút sau khi `valid_until` qua, redeem sai trong khoảng đó). Computed status đảm bảo correctness mọi lúc + zero ops debt. List query có thể tính ở SQL via `CASE WHEN now() < valid_from THEN 'scheduled' ...` cho filter performance.

### Decision 4 — `customer_order_id` unique trên `coupon_redemptions`

- **Chose:** Pivot row immutable + unique (`customer_order_id`) — guarantee 1 coupon/order.
- **Rejected:** Chỉ FK lỏng; cho nhiều redemption per order (stacking tương lai).
- **Why:** User đã chọn "1 coupon/order, order-level". DB-level unique loại bỏ race khả năng 2 transaction concurrent insert 2 redemption cho cùng order. Khi user re-apply một coupon khác, server release current rồi insert mới — sequential, không race.

### Decision 5 — Snapshot trên `CouponRedemption` (`coupon_snapshot` JSON)

- **Chose:** Lưu snapshot `{type, value, cap, code}` lúc apply.
- **Rejected:** Đọc qua FK → coupon row tại lúc print receipt.
- **Why:** Coupon row có thể bị edit (extend `valid_until`, sửa `name`) sau khi redeem. Receipt phải hiển thị giá trị tại thời điểm áp dụng. Snapshot làm receipt resilient với edit về sau và tách biệt analytics.

### Decision 6 — Apply chỉ ở `Open`/`Dining`/`Pending`/`Checkout` — reject ở `Paying`

- **Chose:** Cho phép apply / release từ `Open` đến `Checkout`. Reject `Paying` / `Closed` / `Voided`.
- **Rejected:** Cho phép apply mọi lúc trước `Closed`.
- **Why:** Khi order đã ở `Paying`, total đã được khoá để xử lý payment terminal. Thay đổi `discount_amount` lúc đó tạo race với gateway response (số tiền charged ≠ số tiền lưu trong order). User chọn "Auto-restore khi cancel TRƯỚC khi closed" → nhất quán với việc reject mutate ở `Paying`.

## Alternatives considered

- **Filament-style discount engine** (rule expression DSL như "subtotal > 200k AND has_category=drinks") — quá overkill cho v1; đẩy về future plan nếu cần item/category scope.
- **Stripe Promotion Code split (Coupon = template, PromotionCode = redeemable)** — thêm 1 layer indirection. v1 simpler nếu mỗi `Coupon` row vừa là template vừa là redeemable code. Có thể migrate sau nếu cần multi-code campaign.
- **Tách `coupon_redemptions` thành `coupon_holds` (lúc preview/lock) + `coupon_redemptions` (commit)** — 2-phase commit pattern. Quá phức tạp; preview hoàn toàn stateless — không cần hold.

## Risks & mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Race condition oversell `usage_limit_total` | M | H (refund + complaint) | `lockForUpdate` + defensive `WHERE times_used < limit` UPDATE + DB CHECK constraint `times_used <= usage_limit_total` (where `usage_limit_total IS NOT NULL`). Test: 100 concurrent redemption với limit=50, assert exactly 50 success. |
| Customer abuse: order → cancel → redeem → repeat | M | M (margin loss) | `released_at` chỉ cho phép release trước `closed`. Closed order voided → counter giữ. Audit log tất cả release events. Manager dashboard có report "coupon released > 5 times by customer X". |
| Coupon code share trên forum / social | H | L–M (bạn-bè share) | `usage_limit_per_customer` + `usage_limit_total`. Customer-side preview log `ip_address` để fraud review. |
| Edit coupon đang chạy → khách bị trừ value khác giữa preview và submit | L | M | Preview là client UX, server-side apply re-validate trong locked tx → snapshot lưu giá trị lúc apply. UI customer-web hiển thị warning nếu preview value ≠ apply value (rare race). |
| Order-create + apply-coupon không atomic ở customer-web flow | L | M | Customer create order endpoint phải call `couponService->apply()` trong cùng transaction. Tests integration kiểm tra order created without coupon nếu apply throws. |
| Translatable `name` edit sau khi đã hiển thị trên receipt | M | L | `coupon_snapshot.name` không được snapshot v1 (chỉ snapshot type/value/cap). Receipt hiển thị `coupon_code_snapshot` (mã không đổi). Có thể mở rộng snapshot field để include localized name nếu cần audit chặt. |
| Migration adding `coupon_id` FK to `customer_orders` lock bảng lớn | L | M | Migration in production: `ALTER TABLE customer_orders ADD COLUMN coupon_id UUID NULL;` không lock prolonged trên Postgres/MySQL 8 với online DDL. Nếu bảng > 10M rows, dùng `pt-online-schema-change` thủ công ngoài migration. |
| Decimal precision (10% × 200,000 + cap → rounding) | L | L | Round HALF_UP 2 decimal cho VND (0 minor unit cũng OK), test fixture có scenario rounding ngay tại boundary cap. |

## Open questions

- [ ] **Q1 — POS UX cho customer lookup khi `usage_limit_per_customer > 0`.** Hiện thiết kế dùng existing customer Combobox trong PaymentDialog. Cần confirm với UX: có cần shortcut "tạo nhanh customer mới" inline không? *Default:* dùng Combobox + nếu khách mới, pre-link sang flow Customer tạo nhanh (existing in app).
- [ ] **Q2 — Customer-web: preview rate limiting?** Debounced 600ms ở client là đủ, hay cần backend rate-limit (Laravel `throttle` middleware) để chống brute-force code? *Đề xuất:* `throttle:60,1` trên route preview.
- [ ] **Q3 — Audit log granularity.** Hiện nay `logAudit('coupon_applied', [...])` ghi trên `CustomerOrder` model. Có cần ghi audit riêng trên `Coupon` model (mỗi redemption tăng counter) không? *Đề xuất:* không — `coupon_redemptions` table đã là ledger, audit Coupon chỉ cần log create/update/pause/resume/delete.
- [ ] **Q4 — Localized error mapping.** `coupon_expired` etc. là server error_code. Frontend cần i18n strings tương ứng. Sẽ list hết trong TASKS Phase Frontend i18n.
- [ ] **Q5 — `applicable_branch_ids` validation: strict hay coerce?** Khi PUT, nếu user gửi `branch_id` của brand khác → reject 422. Khi user xoá branch (DELETE branch) → cascade or set_null trong pivot? *Đề xuất:* `onDelete: CASCADE` ở pivot — branch xóa thì coupon mất ràng buộc đó (không nguy hiểm vì coupon vẫn thuộc brand).

---

# PART B — Menu Promotion (Happy Hour)

> Phần B mở rộng plan-019 với cơ chế discount thứ 2 song song với Coupon: shop manager auto-apply giảm % giá item theo khung giờ, hiển thị strikethrough trên menu, tích hợp với coupon qua flag `stacking_mode` per promotion.

## B.Approach

Mỗi shop có entity `MenuPromotion` riêng (NOT brand-wide). Shop Manager setup tại admin-web Shop scope (`/shop/{shopSlug}/promotions`). Promotion match khi: `is_active = true` AND `valid_from ≤ now() ≤ valid_until` (overall window) AND time-of-day match `daily_time_from..daily_time_to` (theo `branch.timezone`) AND weekday hiện tại ∈ `weekdays` AND món thuộc scope (`all_items` hoặc qua pivot category/product).

Auto-apply ngay tại `addItems` lifecycle (server-side authoritative): server resolve promotion cho mỗi item, ghi `original_unit_price` + giảm `unit_price` + set FK + snapshot. Customer-web menu API kèm `active_promotion` block cho mỗi item để FE render strikethrough.

Stacking với Coupon (Part A) qua flag `MenuPromotion.stacking_mode`:
- `stackable_with_coupons` (default): cả Coupon + Promotion cùng áp được; coupon discount tính trên subtotal đã giảm bởi promotion.
- `exclusive_with_coupons`: order có item promotion exclusive → cố apply coupon → 422; ngược lại order có coupon → cố add item promotion exclusive → 422 + UI Dialog "Auto-remove coupon để add món?".

Multi-promotion match cùng món (vd promotion "all items 10%" + "drinks 20%") → chọn `discount_percent` cao nhất (best-for-customer); không cộng dồn 2 promotion.

## B.Architecture

```
                  ┌──────────────────────────────────┐
                  │   Shop Manager (POS / Admin)     │
                  │  /shop/{shopSlug}/promotions     │
                  └────────────────┬─────────────────┘
                                   │ CRUD
                                   ▼
              ┌──────────────────────────────────────┐
              │       MenuPromotionService           │
              │  list / create / update / delete    │
              │  toggle / resolveActivePromotion     │
              └─────┬──────────┬──────────┬──────────┘
                    │          │          │
       ┌────────────▼──┐  ┌────▼──────┐  ┌▼────────────────┐
       │ MenuPromotion │  │ MPCategory│  │ MPProduct       │
       │ (entity)      │  │ (pivot)   │  │ (pivot)         │
       │ branch_id     │  │ promo_id  │  │ promo_id        │
       │ percent       │  │ category  │  │ product         │
       │ window        │  └───────────┘  └─────────────────┘
       │ stacking_mode │
       └───────┬───────┘
               │
               ▼ resolveActivePromotion(branch, product, cats, at)
   ┌──────────────────────────────────────┐
   │   CustomerOrderService::addItems()   │  ← hook
   │   Promotion → unit_price discounted  │
   │   Snapshot to item                   │
   └──────────────┬───────────────────────┘
                  │
                  ▼ stacking guard
   ┌──────────────────────────────────────┐
   │   CouponService::apply()             │  ← guard
   │   reject if any item exclusive       │
   └──────────────────────────────────────┘
                  │
                  ▼ menu list response
   ┌──────────────────────────────────────┐
   │ MenuService::list (customer-web)     │
   │ Each item → active_promotion block   │
   └──────────────────────────────────────┘
```

## B.Data model changes

| Table | Owner | Change | YAML schema file |
|-------|-------|--------|------------------|
| `menu_promotions` | Omnify (NEW) | Create | `schemas/Backend/Promotion/MenuPromotion.yaml` |
| `menu_promotion_category` | Omnify (NEW pivot) | `(promotion_id, category_id)` unique | `schemas/Backend/Promotion/MenuPromotionCategory.yaml` |
| `menu_promotion_product` | Omnify (NEW pivot) | `(promotion_id, product_id)` unique | `schemas/Backend/Promotion/MenuPromotionProduct.yaml` |
| `customer_order_items` | Omnify (modify) | Add `original_unit_price`, `applied_promotion_id`, `applied_promotion_snapshot` | `schemas/Backend/Product/CustomerOrderItem.yaml` |
| `branches` (verify only) | Omnify (verify) | Confirm `timezone` field exists; add if missing | `schemas/Backend/Sso/Branch.yaml` |
| `menu_promotion_applies_to` enum | Omnify (NEW) | `all_items` \| `categories` \| `products` \| `mixed` | `schemas/Shared/Enum/MenuPromotionAppliesTo.yaml` |
| `menu_promotion_stacking_modes` enum | Omnify (NEW) | `exclusive_with_coupons` \| `stackable_with_coupons` | `schemas/Shared/Enum/MenuPromotionStackingMode.yaml` |

### `menu_promotions` table — fields

| Field | Type | Nullable | Notes |
|-------|------|----------|-------|
| `id` | UUID | no | PK |
| `branch_id` | UUID FK | no | shop scope (NOT brand-wide); index |
| `organization_id` | UUID FK | no | derived from branch |
| `brand_id` | UUID FK | no | derived; for HQ cross-shop reporting |
| `name` | String(255) | no | translatable |
| `description` | Text | yes | translatable |
| `discount_percent` | Decimal(5,2) | no | 0 < x ≤ 100 |
| `applies_to` | EnumRef → MenuPromotionAppliesTo | no | drives FE scope picker |
| `daily_time_from` | Time | yes | nullable (no time restriction) |
| `daily_time_to` | Time | yes | nullable; supports midnight cross when `to < from` |
| `weekdays` | JSON int[] | yes | `[1,2,3,4,5,6,7]` (1=Mon, 7=Sun); null/empty = mọi ngày |
| `valid_from` | DateTime | no | overall start |
| `valid_until` | DateTime | no | overall end (must > valid_from) |
| `stacking_mode` | EnumRef → MenuPromotionStackingMode | no | default `stackable_with_coupons` |
| `is_active` | Boolean | no | default true; shop manual toggle |
| timestamps + softDelete | — | — | soft-delete only when no `customer_order_items.applied_promotion_id = id` |

Indexes:
- `(branch_id, is_active)` for resolve query
- `(valid_from, valid_until)` for active range scan
- composite `(branch_id, valid_from, valid_until, is_active)` to optimize hot path

### `menu_promotion_category` pivot

| Field | Type | Notes |
|-------|------|-------|
| `menu_promotion_id` | UUID FK | composite unique |
| `category_id` | UUID FK | composite unique; `onDelete: CASCADE` |

### `menu_promotion_product` pivot

| Field | Type | Notes |
|-------|------|-------|
| `menu_promotion_id` | UUID FK | composite unique |
| `product_id` | UUID FK | composite unique; `onDelete: CASCADE` |

### `customer_order_items` additions

```yaml
original_unit_price:
  # Snapshot giá gốc TRƯỚC khi promotion áp dụng. Null nếu item không hưởng
  # promotion. Display strikethrough trên POS cart + receipt khi non-null.
  type: Decimal
  precision: 12
  scale: 2
  nullable: true

applied_promotion_id:
  # FK MenuPromotion lúc add item. Null nếu không có promotion match.
  # Snapshot giá đã giảm vào unit_price; promotion sau này edit không
  # ảnh hưởng item đã add.
  type: Association
  relation: ManyToOne
  target: MenuPromotion
  nullable: true
  onDelete: SET_NULL

applied_promotion_snapshot:
  # JSON snapshot {name, discount_percent, stacking_mode} tại apply time —
  # dùng cho receipt + reporting (resilient với promotion edit về sau).
  type: Json
  nullable: true
```

`unit_price` (đã có): khi promotion match → lưu giá ĐÃ GIẢM (= `original_unit_price * (100-percent)/100`, round half-up 2 decimal). Khi không match → `original_unit_price = null`, `unit_price` giữ giá MenuProductSku như hiện tại. BR-O04 không đổi (`subtotal = sum(unit_price * qty + topping_subtotal)`).

## B.API surface

### B.Endpoint inventory

| # | Method | Path | Purpose | Auth | Route file |
|---|--------|------|---------|------|------------|
| P1 | GET | `/api/v1/shops/{shopSlug}/promotions` | List shop's promotions | sanctum + `MenuPromotionPolicy@viewAny` | `routes/api/shops/promotions.php` (NEW) |
| P2 | POST | `/api/v1/shops/{shopSlug}/promotions` | Create | sanctum + `@create` | same |
| P3 | GET | `/api/v1/shops/{shopSlug}/promotions/{promotion}` | Detail + report (impacted item count, total discount) | sanctum + `@view` | same |
| P4 | PUT | `/api/v1/shops/{shopSlug}/promotions/{promotion}` | Update | sanctum + `@update` | same |
| P5 | DELETE | `/api/v1/shops/{shopSlug}/promotions/{promotion}` | Soft-delete (only if 0 order items applied) | sanctum + `@delete` | same |
| P6 | POST | `/api/v1/shops/{shopSlug}/promotions/{promotion}/toggle` | Toggle `is_active` | sanctum + `@toggle` | same |
| P7 | GET | `/api/v1/hq/{brandSlug}/promotions` | HQ read-only cross-shop list | sanctum + `MenuPromotionPolicy@viewAnyHq` | `routes/api/hq/promotions.php` (NEW) |
| P8 | GET | `/api/v1/customer/menus/{menu}/items` (extend existing) | Each item kèm `active_promotion` block | guest/customer | (existing customer menu route) |

### B.Endpoint detail

#### P1 — GET `/api/v1/shops/{shopSlug}/promotions`

- **Auth:** `sanctum + MenuPromotionPolicy@viewAny`. Shop manager + shop staff (read-only POS use case).
- **Query params:** `is_active` (bool), `currently_active` (bool — derived: window match now), `applies_to` (enum), `search` (name fuzzy), `sort` (default `-created_at`), `per_page` (default 25).
- **Success response (`200`):**

  ```json
  {
    "data": [
      {
        "id": "...",
        "name": {"ja":"...","en":"...","vi":"Giờ vàng cuối ngày"},
        "description": {"vi":"Giảm 20% đồ uống + tráng miệng từ 21:00"},
        "discount_percent": "20.00",
        "applies_to": "categories",
        "daily_time_from": "21:00",
        "daily_time_to": "23:00",
        "weekdays": [1,2,3,4,5,6,7],
        "valid_from": "...",
        "valid_until": "...",
        "stacking_mode": "stackable_with_coupons",
        "is_active": true,
        "currently_active": true,
        "applicable_category_ids": ["uuid-1","uuid-2"],
        "applicable_product_ids": [],
        "created_at": "...", "updated_at": "..."
      }
    ],
    "meta": { ... }
  }
  ```

#### P2 — POST `/api/v1/shops/{shopSlug}/promotions`

- **Auth:** `sanctum + @create` — only `shop-manager` (NOT shop-staff).
- **Request body:**

  | Field | Type | Required | Notes |
  |-------|------|----------|-------|
  | `name` | object {ja, en, vi} | yes | translatable |
  | `description` | object | no | translatable optional |
  | `discount_percent` | decimal | yes | 0 < x ≤ 100 |
  | `applies_to` | enum | yes | `all_items` / `categories` / `products` / `mixed` |
  | `applicable_category_ids` | uuid[] | conditional | required if `applies_to` ∈ `categories`/`mixed`; each must belong to brand |
  | `applicable_product_ids` | uuid[] | conditional | required if `applies_to` ∈ `products`/`mixed` |
  | `daily_time_from` | time `HH:MM` | no | nullable |
  | `daily_time_to` | time | conditional | required if `daily_time_from` set; supports cross-midnight |
  | `weekdays` | int[] | no | each ∈ 1..7; null/empty = all days |
  | `valid_from` | datetime | yes | |
  | `valid_until` | datetime | yes | > valid_from |
  | `stacking_mode` | enum | no | default `stackable_with_coupons` |
  | `is_active` | bool | no | default true |

- **Success response (`201`):** `MenuPromotionResource`.
- **Error responses:**
  - `403` not shop-manager
  - `422` validation: scope mismatch (category not in brand), percent out of range, `daily_time_to` missing, `valid_until` ≤ `valid_from`, weekday invalid
- **Side effects:** insert promotion + pivot category/product rows. Audit `promotion.created`. Invalidate Redis cache key `branch:{id}:active_promotions` (Q6 caching).

#### P3 — GET `/api/v1/shops/{shopSlug}/promotions/{promotion}`

- **Auth:** `@view`.
- **Response:** promotion + `report` block:
  ```json
  {
    "data": {
      "id":"...", "...":"...",
      "report": {
        "items_with_promotion_count": 142,
        "total_discount_applied": "284000.00",
        "first_redeemed_at": "...",
        "last_redeemed_at": "..."
      },
      "applicable_categories": [{"id":"...","name":"..."}],
      "applicable_products": []
    }
  }
  ```

#### P4 — PUT `/api/v1/shops/{shopSlug}/promotions/{promotion}`

- **Auth:** `@update`.
- **Request body:** same as create. **Locked-after-application rule (lighter than coupon)**: nếu đã có `customer_order_items.applied_promotion_id = $promotion->id`, vẫn cho edit `name`, `description`, `valid_until` (extend), `weekdays`, `daily_time_from/to`, `is_active`, `stacking_mode`. Chỉ lock `discount_percent` (vì sẽ làm receipts khác value với items đã apply) → 422 `field_locked`. Bonus: lock `applies_to` + scope pivots — không cho thay đổi targeting sau khi đã có item áp.

#### P5 — DELETE `/api/v1/shops/{shopSlug}/promotions/{promotion}`

- **Auth:** `@delete` — only `shop-manager`.
- **Behavior:** soft-delete only when 0 items applied. Else 409 `promotion_already_used_use_deactivate_instead`.

#### P6 — POST `/api/v1/shops/{shopSlug}/promotions/{promotion}/toggle`

- **Auth:** `@toggle`.
- **Behavior:** flip `is_active`. Audit `promotion.activated` / `promotion.deactivated`. Invalidate cache.

#### P7 — GET `/api/v1/hq/{brandSlug}/promotions` (read-only cross-shop)

- **Auth:** `sanctum + @viewAnyHq` — HQ org-admin/manager/staff.
- **Query params:** `branch_id` (filter), `is_active`, `currently_active`, `sort` (e.g. `-report.total_discount_applied`).
- **Success response:** list with `branch` block populated for each row.

#### P8 — Extend customer menu items API

Existing customer-web menu API (e.g. `GET /api/v1/customer/menus/{menu}/items` or wherever menu list lives — confirm exact path during T9.1) returns each item with new optional block:

```json
{
  "id": "...",
  "name": {...},
  "selling_price": "25000.00",
  "active_promotion": {
    "id": "uuid",
    "discount_percent": "20.00",
    "discounted_price": "20000.00",
    "ends_at": "2026-05-08T23:00:00+07:00",
    "stacking_mode": "stackable_with_coupons"
  }
}
```

Khi item không trong promotion window → `active_promotion: null` (hoặc field omitted). FE check non-null → render strikethrough + Badge.

#### Modified endpoints

- **`POST /shops/{shopSlug}/orders/{order}/items`** (existing addItem):
  - Server resolve promotion lúc add → ghi item fields theo logic auto-apply.
  - Stacking guard: nếu `$order->coupon_id !== null` AND new item promotion `stacking_mode = exclusive_with_coupons` → 422 `cannot_add_promotion_item_with_coupon` với meta `{coupon_code, promotion_name, promotion_id, suggested_action: "release_coupon_then_retry"}`. FE hiện confirm Dialog → nếu user chọn "Auto-remove coupon", FE gọi `DELETE coupon` → retry addItem.
- **`POST /shops/{shopSlug}/orders/{order}/apply-coupon`** (existing):
  - Stacking guard: nếu order có `customer_order_items` mà item.applied_promotion.stacking_mode = exclusive → 422 `coupon_excluded_by_active_promotion` với meta `{exclusive_promotion_ids: [...], exclusive_item_ids: [...]}`.

## B.Screens

### B.Screen inventory

| # | Path | Type | Auth | Purpose |
|---|------|------|------|---------|
| S7 | `/shop/{shopSlug}/promotions` | List page | shop-manager + shop-staff (read-only) | Browse promotions của shop |
| S8 | `/shop/{shopSlug}/promotions/new` | Create form | shop-manager | Tạo promotion |
| S9 | `/shop/{shopSlug}/promotions/{id}` | Detail page | shop-manager + shop-staff | Detail + báo cáo redemption |
| S10 | `/shop/{shopSlug}/promotions/{id}/edit` | Edit form | shop-manager | Edit (locked fields nếu đã có items áp) |
| S11 | `/hq/{brandSlug}/promotions` | List read-only | HQ org-admin/manager/staff | Cross-shop reporting |
| S5b (modified) | `pos-web/menu-catalog.tsx` + `order-cart.tsx` | (component) | shop-staff | Render strikethrough giá gốc + Badge HH |
| S6b (modified) | `customer-web/menu-page` | (component) | customer | Strikethrough + Badge "Happy Hour" trên item card |

### B.Screen detail

#### S7 — Shop promotion list

- **Layout:** `admin-web/src/app/shop/[shopSlug]/layout.tsx` (sidebar Shop scope; thêm nav item "Promotions" với icon `Percent` từ lucide).
- **Page file:** `admin-web/src/app/shop/[shopSlug]/promotions/page.tsx`.
- **Fetches:** P1.
- **Components:**
  - `Card` header với "+ New promotion" Button (shop-manager only)
  - `Input` search (name fuzzy)
  - `Select` filter `is_active` (`all`/`active`/`inactive`)
  - `Switch` "Đang áp dụng (currently_active)" filter
  - `Table` columns: Name (locale fallback) · Discount % · Scope (chip: All items / Categories / Products / Mixed) · Window (formatted "21:00-23:00 hàng ngày") · Validity range · Stacking · Active · Actions
  - `Badge` "Đang áp dụng" / "Sắp tới" / "Đã hết hạn" (computed)
  - `DropdownMenu`: View / Edit / Toggle / Delete (shop-manager)
  - `Pagination` · `Skeleton` · `Alert` error
- **Empty state:** "Chưa có promotion nào" + CTA Button.

#### S8 — Shop promotion create form

- **Page file:** `admin-web/src/app/shop/[shopSlug]/promotions/new/page.tsx`.
- **Fetches:** preload `GET /shops/{shop}/categories` + `GET /shops/{shop}/products` cho scope picker; on submit P2.
- **Components:**
  - `Form` + `FormField`. Sections:
    1. **Identity**: `Input translatable={{locales}}` cho `name`; `Textarea translatable` cho `description`.
    2. **Discount**: `Input` number `discount_percent` với suffix `%`, slider visualization (optional).
    3. **Scope**: `RadioGroup` for `applies_to` với 4 option (All items / Categories / Products / Mixed); conditionally render `Combobox` multi-select cho category và/hoặc product theo lựa chọn radio.
    4. **Time window**:
       - `TimePicker` cho `daily_time_from` + `daily_time_to` (nullable cả 2 hoặc cả 2 — không cho chỉ 1)
       - Note text "Để trống = áp dụng cả ngày"
       - `ToggleGroup` multi-select cho `weekdays` (T2-CN hiển thị label localized) — chọn rỗng = mọi ngày
       - `DatePicker` range cho `valid_from`/`valid_until`
    5. **Stacking**: `RadioGroup` với 2 option:
       - "Loại trừ với coupon" — option label, description giải thích
       - "Cộng dồn với coupon" — option label, description giải thích
    6. **Active**: `Switch` `is_active` default ON.
  - Sticky footer: Cancel + "Tạo promotion".
- **Form best practices** (per convention #4 sub-agent T0.1):
  - Single-column max 720px; section-grouped với heading
  - Label top-aligned
  - Inline validation per field; `Alert` banner top-of-form cho server 422 với từng field error
  - `Enter` submits in single-line inputs; section nào dirty → confirm Dialog khi cancel
- **Translatable fields:** `name`, `description` dùng `<Input/Textarea translatable />` (1 component, locale tabs trong cùng input — KHÔNG 4-Card layout).

#### S9 — Detail page

- **Page file:** `admin-web/src/app/shop/[shopSlug]/promotions/[id]/page.tsx`.
- **Fetches:** P3.
- **Components:**
  - Header với Badge status + DropdownMenu actions (Edit/Toggle/Delete)
  - `Tabs` 3 tab: "Tổng quan" / "Báo cáo" / "Phạm vi áp dụng"
  - "Tổng quan": Card panels (Discount math · Time window · Stacking)
  - "Báo cáo": Card KPI tiles (`items_with_promotion_count`, `total_discount_applied`, first/last redeemed_at) + `Table` mới các order items áp promotion (limited 50 latest, link sang order detail)
  - "Phạm vi áp dụng": list categories và/hoặc products áp dụng (read-only)

#### S10 — Edit form

- **Page file:** `admin-web/src/app/shop/[shopSlug]/promotions/[id]/edit/page.tsx`.
- Same as S8 với prefill. Locked fields khi `report.items_with_promotion_count > 0`: `discount_percent`, `applies_to`, `applicable_category_ids`, `applicable_product_ids`. Tooltip "Promotion đã áp cho X items, không thể đổi giá trị giảm hoặc scope. Hãy tạo promotion mới." Vẫn edit được: `name`, `description`, `daily_time_from/to`, `weekdays`, `valid_until` (extend), `stacking_mode`, `is_active`.

#### S11 — HQ cross-shop list

- **Page file:** `admin-web/src/app/hq/[brandSlug]/promotions/page.tsx`.
- **Fetches:** P7.
- **Components:**
  - `Card` header (read-only, không có "+ New")
  - `Combobox` filter Shop, `Switch` `currently_active`, `Select` sort by total_discount_applied / created_at
  - `Table` columns: Shop · Name · % · Scope · Window · Currently active · Total discount applied · Items count
  - Click row → navigate `/shop/{shopSlug}/promotions/{id}` (nếu user có quyền), else read-only modal.

#### S5b — POS menu-catalog + order-cart (modified)

- **Files:**
  - `pos-web/src/app/pos/components/menu-catalog.tsx` (extend)
  - `pos-web/src/app/pos/components/order-cart.tsx` (extend)
- **menu-catalog.tsx changes:**
  - Mỗi product card check `active_promotion` (từ menu API extension P8)
  - Nếu non-null: render `~~25.000₫~~` (smaller, gray, line-through) trên dòng giá; thêm `<Badge variant="warning">HH −20%</Badge>` góc trên-phải card
  - Tooltip Badge: "Áp dụng đến 23:00"
- **order-cart.tsx changes:**
  - Mỗi line item check `original_unit_price !== null`
  - Nếu non-null: render giá đã giảm in đậm + giá gốc strikethrough nhỏ phía dưới + `Badge` "HH" cạnh tên item
- **Apply-coupon error handling:** nếu apply trả 422 `coupon_excluded_by_active_promotion` → mini Dialog hiển thị "Order có món happy hour exclusive: [tên món]. Để áp coupon, vui lòng remove các món này trước." (lock không có "Auto-remove items").
- **AddItem error handling:** nếu addItem trả 422 `cannot_add_promotion_item_with_coupon` → confirm Dialog 2 button "Hủy" / "Auto-remove coupon và thêm món" — chọn cái sau → FE gọi `DELETE coupon` → retry addItem.

#### S6b — customer-web menu (modified)

- **File:** `customer-web/components/` menu listing component (chính xác file confirm khi T9.4).
- **Changes:**
  - Mỗi item card check `active_promotion`
  - Nếu non-null: render strikethrough giá gốc + giá đã giảm + `Badge` "Happy Hour −20%" với màu vàng/cam
  - Có dropdown "Còn X giờ Y phút" (countdown đến `ends_at`) — optional polish
- **Cart / checkout** — server-authoritative; FE không tự tính promotion, chỉ render từ response.
- **Apply-coupon UX in checkout:**
  - Nếu cart có item promotion exclusive → preview API trả `is_valid: false`, `error_code: coupon_excluded_by_active_promotion` → Alert "Cart đang có món happy hour không stack với coupon: [tên món]. Để áp coupon, vui lòng remove các món này hoặc đổi món khác."
  - Nếu tất cả promotion stackable → coupon áp bình thường.

## B.Sitemap

### Navigation diff

```
admin-web Shop scope:
/shop/{shopSlug}
├── customers/
├── menus/
├── orders/
├── tables/
├── ...
├── settings/
└── promotions/                 [NEW] → /shop/{shopSlug}/promotions
    ├── (list)                  [NEW] S7
    ├── new/                    [NEW] S8
    └── {id}/
        ├── (detail)            [NEW] S9
        └── edit/               [NEW] S10

admin-web HQ scope:
/hq/{brandSlug}
├── coupons/                    [Part A]
└── promotions/                 [NEW] → /hq/{brandSlug}/promotions
    └── (list)                  [NEW] S11 (read-only)
```

### Entry points

| From | Via | To | Visibility |
|------|-----|----|------------|
| Shop sidebar | New nav item "Promotions" (icon `Percent`) | S7 | shop-manager + shop-staff |
| HQ sidebar | New nav item "Promotions (cross-shop)" | S11 | HQ admin/manager/staff |
| S7 row click | | S9 | per role |
| S7 "+ New" | shop-manager only | S8 | shop-manager |
| S9 Edit | shop-manager only | S10 | shop-manager |
| S11 row click → external link to S9 | | S9 | per role |

### Breadcrumbs

| Screen | Crumbs |
|--------|--------|
| S7 | `Shop` > `Promotions` |
| S8 | `Shop` > `Promotions` > `Tạo mới` |
| S9 | `Shop` > `Promotions` > `{name}` |
| S10 | `Shop` > `Promotions` > `{name}` > `Chỉnh sửa` |
| S11 | `Brand` > `Promotions (cross-shop)` |

## B.Authorization matrix

### Roles already covered (Part A) — recap

`org-admin`, `org-manager`, `org-staff`, `shop-manager`, `shop-staff`, customer.

### B.Action × Role matrix (combined cập nhật)

| Action | org-admin | org-manager | org-staff | shop-manager | shop-staff | customer |
|--------|-----------|-------------|-----------|--------------|------------|----------|
| **Promotion** List shop của mình | ❌ | ❌ | ❌ | ✅ | ✅ (read) | ❌ |
| Promotion List cross-shop của brand | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| Promotion View (own shop) | ❌ | ❌ | ❌ | ✅ | ✅ (read) | ❌ |
| Promotion Create | ❌ | ❌ | ❌ | ✅ (own shop) | ❌ | ❌ |
| Promotion Update | ❌ | ❌ | ❌ | ✅ (own shop) | ❌ | ❌ |
| Promotion Delete (no items applied) | ❌ | ❌ | ❌ | ✅ (own shop) | ❌ | ❌ |
| Promotion Toggle is_active | ❌ | ❌ | ❌ | ✅ (own shop) | ❌ | ❌ |
| Auto-apply Promotion vào item lúc add | (system) | (system) | (system) | (system) | (system) | (system) |
| Customer xem giá đã giảm trên menu | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

### Policy ↔ UI gate cross-check (mới)

| Action | Backend gate | Frontend gate |
|--------|--------------|---------------|
| Create promotion | `MenuPromotionPolicy@create` (shop-manager + branch ownership) | "+ New" Button hidden cho shop-staff; nav "Promotions" trong shop sidebar visible cho cả manager và staff |
| Delete promotion | `@delete` (shop-manager only + 0 items applied) | "Delete" trong DropdownMenu hidden cho staff; disabled + tooltip nếu đã có items |
| HQ cross-shop list | `@viewAnyHq` (HQ user + brand match) | HQ sidebar "Promotions (cross-shop)" visible cho org-admin/manager/staff; create/edit buttons không tồn tại trên S11 |
| Stacking conflict on apply-coupon | service-layer guard 422 | mini Dialog với clear error message; không có "auto-resolve" |
| Stacking conflict on addItem | service-layer guard 422 | confirm Dialog "Auto-remove coupon?" với 2 button |

### Role-switch verification (mới)

- [ ] Shop manager A → vào `/shop/{shopSlug-A}/promotions` → CRUD đầy đủ; vào `/shop/{shopSlug-B}/promotions` → 403.
- [ ] Shop staff A → vào `/shop/A/promotions` → list visible, "+ New" + Edit + Delete hidden; click row → S9 read-only.
- [ ] HQ org-staff → `/hq/{brandSlug}/promotions` (S11) → read-only list visible; không có path navigate vào S8 / S10.
- [ ] Cross-brand: HQ user của brand X → GET `/hq/{brandSlug-Y}/promotions` → 403.

## B.User journeys

### J6 — Shop Manager Bình setup happy hour cuối ngày

**Persona:** Bình, shop manager tại Branch HCM-1.

**Happy path:**

1. Bình vào sidebar Shop → click "Promotions" → S7 với empty state.
2. "+ Tạo promotion" → S8.
3. Fill:
   - name `{vi: "Giờ vàng cuối ngày", en: "Late-night Happy Hour", ja: "ハッピーアワー"}`
   - `discount_percent = 20`
   - `applies_to = categories`, chọn ["Đồ uống", "Tráng miệng"] qua Combobox
   - `daily_time_from = 21:00`, `daily_time_to = 23:00`
   - `weekdays` để rỗng (mọi ngày)
   - `valid_from = today`, `valid_until = today + 30d`
   - `stacking_mode = stackable_with_coupons` (radio: "Cộng dồn với coupon")
   - `is_active = true`
4. Submit → toast "Đã tạo promotion" → redirect S9.
5. Bình thấy panel "Báo cáo" trống (`items_with_promotion_count = 0`), Badge "Sắp tới" (vì 21:00 chưa tới).

**Alternate paths:**
- Bình bỏ sót: chọn `applies_to=categories` nhưng quên chọn category nào → 422 → Alert "Phải chọn ít nhất 1 category".
- Bình muốn áp tất cả món → chọn `applies_to=all_items` → 2 Combobox category/product hidden.

### J7 — Customer Hùng order trong khung happy hour

**Persona:** Hùng, customer-web, 21:30 thứ 7.

**Happy path:**

1. Hùng vào `customer-web/menu-page` → API trả menu items kèm `active_promotion` cho món thuộc "Đồ uống"/"Tráng miệng".
2. Item "Trà chanh" (selling_price 25,000₫) hiển thị `~~25.000₫~~ **20.000₫**` + Badge cam "Happy Hour −20%" + Tooltip "Áp dụng đến 23:00".
3. Hùng add 2 trà chanh + 1 phở (món không thuộc category promotion) vào cart. Cart subtotal = 2×20,000 + 60,000 = 100,000₫.
4. Hùng nhập coupon `WELCOME10` (10%, stackable) → preview API trả `is_valid: true, discount_applied_amount: 10000`. Order summary: 100,000 − 10,000 = 90,000₫ + tax/service.
5. Submit order → server addItems(): 2 item trà chanh có `original_unit_price=25000`, `unit_price=20000`, `applied_promotion_id` set, snapshot ghi. 1 item phở: `original_unit_price=null`, `unit_price=60000`. Server apply-coupon: stacking guard kiểm tra → tất cả promotion stackable → ok. `coupon.times_used += 1`.

**Edge paths:**
- Hùng order lúc 22:55, checkout lúc 23:05 (qua khung happy hour) → 2 item trà chanh giữ giá đã giảm (snapshot lúc add). Nếu Hùng add thêm món lúc 23:05 → giá gốc.
- Race: 23:00:00.500 promotion expire giữa preview và addItem → server-side resolve khi addItem → không match → giá gốc.

### J8 — POS Staff add món promotion exclusive khi order đã có coupon

**Persona:** Tâm, shop staff tại Branch HCM-1, đang ở POS.

Bối cảnh: Bình đã setup thêm 1 promotion riêng "Bánh flan -50% exclusive" với `stacking_mode = exclusive_with_coupons` (chỉ flan, độc quyền không cùng coupon).

**Happy path (resolve via auto-remove coupon):**

1. Order đã có coupon `WELCOME10` áp (chip hiển thị trong payment-dialog đóng — Tâm chưa mở dialog).
2. Tâm tap món "Bánh flan" trên menu-catalog → POS gọi `POST /orders/{order}/items`.
3. Server reject 422 `cannot_add_promotion_item_with_coupon` với meta `{coupon_code: "WELCOME10", promotion_name: "Bánh flan -50%", suggested_action: "release_coupon_then_retry"}`.
4. POS hiện Dialog: "Bánh flan đang hưởng happy hour không stack với coupon WELCOME10. Bạn muốn:" → 2 button:
   - "Hủy thêm món" — đóng dialog, không add
   - "Tự động remove coupon và thêm món" — FE gọi `DELETE /orders/{order}/coupon` → on success retry `POST /items` → toast "Đã remove coupon WELCOME10 và thêm Bánh flan".
5. Coupon `times_used` đã giảm 1 (release flow). Order giờ có item Bánh flan với `applied_promotion_id` set, `coupon_id = null`.

**Alternate path (cancel):**
- Tâm chọn "Hủy thêm món" → dialog đóng → coupon vẫn áp, không có Bánh flan trong cart.

### J9 — Apply coupon khi order đã có item promotion exclusive

**Persona:** Tâm.

**Happy path:**

1. Order có 1 item Bánh flan (promotion exclusive). Coupon chưa apply.
2. Tâm mở payment-dialog → click "Áp dụng coupon" → mini Dialog → fill `WELCOME10`.
3. Server `apply-coupon` reject 422 `coupon_excluded_by_active_promotion` với meta `{exclusive_item_ids: [...], promotion_name: "Bánh flan -50%"}`.
4. Mini Dialog hiển thị Alert đỏ: "Order có món Bánh flan đang hưởng happy hour exclusive — không thể áp coupon. Hãy remove món này hoặc bỏ qua coupon." Không có "auto-resolve" button (vì xóa item là destructive — staff phải làm thủ công ở cart).

### J10 — HQ Manager Hà giám sát promotion cross-shop

**Persona:** Hà, brand manager.

**Happy path:**

1. Hà vào HQ sidebar → "Promotions (cross-shop)" → S11 read-only list.
2. Filter shop = "HCM-1", `currently_active = true`, sort `-report.total_discount_applied`.
3. Hà thấy Top 3 promotion đang chạy với tổng discount 480,000₫ trong 7 ngày qua.
4. Click row → navigate sang `/shop/HCM-1/promotions/{id}` (S9 read-only mode vì Hà không phải shop-manager của HCM-1) — nếu Policy không cho vào S9 cross-shop, fallback sang detail Modal trên S11 với same data.

### Cross-journey checklist (mới)

- [x] Mỗi happy-path step ánh xạ ≥ 1 endpoint trong API inventory.
- [x] Mọi error path có 4xx case tương ứng.
- [x] Mọi role trong matrix được cover (org-admin J10, org-manager J10, org-staff passive in role-switch checklist, shop-manager J6, shop-staff J7+J8+J9, customer J7).
- [x] Stacking guards có happy + reject scenarios.

## B.Field lifecycle

### `MenuPromotion`

| Field | Added? | Default | Displayed on screens | Editable on screens | Editable by roles | Validation | Omnify prop |
|-------|--------|---------|----------------------|---------------------|-------------------|------------|-------------|
| `branch_id` | NEW | (route) | (implicit) | (system at create) | system | required FK | Association ManyToOne Branch |
| `organization_id` | NEW | (auth) | (implicit) | (system) | system | required FK | Association |
| `brand_id` | NEW | (derived) | S11 column | (system) | system | required FK | Association |
| `name` | NEW | (none) | S7, S9, S10, S11, S5b, S6b | S8, S10 | shop-manager | required all 3 locales, ≤255 mỗi locale | `String translatable: true` |
| `description` | NEW | null | S9, S10 | S8, S10 | shop-manager | optional, ≤500 mỗi locale | `Text translatable: true nullable: true` |
| `discount_percent` | NEW | (none) | S7, S9, S10, S11, S5b, S6b | S8, S10 (locked sau apply) | shop-manager | 0 < x ≤ 100 | `Decimal 5,2` |
| `applies_to` | NEW | (none) | S7, S9, S10 | S8, S10 (locked sau apply) | shop-manager | enum | `EnumRef MenuPromotionAppliesTo` |
| `daily_time_from` | NEW | null | S7, S9, S10 | S8, S10 | shop-manager | nullable; if non-null, `daily_time_to` required | `Time nullable: true` |
| `daily_time_to` | NEW | null | S7, S9, S10 | S8, S10 | shop-manager | nullable; required if `from` set; cross-midnight allowed | `Time nullable: true` |
| `weekdays` | NEW | null | S7, S9, S10 | S8, S10 | shop-manager | array int 1..7 nullable | `Json nullable: true` |
| `valid_from` | NEW | (none) | S7, S9, S10 | S8, S10 | shop-manager | datetime | `DateTime` |
| `valid_until` | NEW | (none) | S7, S9, S10 | S8, S10 (extend allowed) | shop-manager | > valid_from | `DateTime` |
| `stacking_mode` | NEW | `stackable_with_coupons` | S7, S9, S10 | S8, S10 | shop-manager | enum | `EnumRef MenuPromotionStackingMode default: stackable_with_coupons` |
| `is_active` | NEW | true | S7, S9, S10 | S8, S10 + Toggle action P6 | shop-manager | boolean | `Boolean default: true` |

### `CustomerOrderItem` additions

| Field | Added? | Default | Displayed on screens | Editable on screens | Editable by roles | Validation | Omnify prop |
|-------|--------|---------|----------------------|---------------------|-------------------|------------|-------------|
| `original_unit_price` | NEW | null | S5b cart, S6b cart, receipt | (system at addItems) | system | nullable decimal ≥ 0 | `Decimal 12,2 nullable: true` |
| `applied_promotion_id` | NEW | null | (internal) | (system) | system | nullable FK | Association nullable onDelete SET_NULL |
| `applied_promotion_snapshot` | NEW | null | (internal — receipt template reads) | (system) | system | json nullable | `Json nullable: true` |

### Orphaned field audit

| Field | Why not touched | Currently editable at | Acceptable? |
|-------|-----------------|-----------------------|-------------|
| `customer_order_items.unit_price` | Đã có; sẽ là **giá đã giảm** sau promotion (không thêm field mới). FE đọc `original_unit_price` cho strikethrough. | Set bởi server tại addItems | Yes |
| `customer_order_items.topping_subtotal` | Promotion không áp lên topping (chỉ áp lên main item price). Topping giữ nguyên. | Server | Yes |

### Field lifecycle cross-check (mới)

- [x] Mỗi "Editable on screens" cell có matching input in S8/S10 form detail.
- [x] Mỗi "Displayed on screens" cell có matching column/render.
- [x] Mỗi NEW field có ≥ 1 validation rule trong P2/P4 request body.
- [x] `name`, `description` translatable dùng `<Input/Textarea translatable />` per convention #2.

## B.Authorization

`MenuPromotionPolicy` per `policy.md` template:
- `viewAny(User)` — shop-manager OR shop-staff với matching branch.
- `view(User, MenuPromotion)` — shop-manager OR shop-staff matching branch.
- `create(User)` — shop-manager only matching branch.
- `update(User, MenuPromotion)` — shop-manager only matching branch.
- `delete(User, MenuPromotion)` — shop-manager only + `report.items_with_promotion_count = 0`.
- `toggle(User, MenuPromotion)` — shop-manager only.
- `viewAnyHq(User)` — HQ org-admin/manager/staff với matching organization.

Helper `userBelongsToBranch(User, branchId): bool` tái sử dụng từ Part A `CustomerOrderPolicy`.

## B.Key decisions

### Decision B1 — Promotion shop-scoped, không brand-wide

- **Chose:** `branch_id` NOT NULL trên `menu_promotions`. Mỗi shop tự quản.
- **Rejected:** Brand admin tạo + assign cho N shops (như coupon).
- **Why:** Happy hour cực highly local — mỗi shop khung giờ + giá khác. Brand-wide setup tạo friction (brand không hiểu rhythm từng shop). Nếu brand muốn áp đồng loạt → mở plan-020 sau với entity `BrandPromotion` distribute xuống shops.

### Decision B2 — Auto-apply tại addItems, không tại checkout

- **Chose:** Resolve promotion lúc add từng item; snapshot vào `unit_price`.
- **Rejected:** Tính promotion lúc checkout (server reads all items + recompute totals).
- **Why:** Snapshot giá lúc add = match what user/customer thấy lúc add. Customer add lúc 22:55, checkout lúc 23:10 → giữ giá đã giảm (fair). Tính lại lúc checkout phá UX (giá thay đổi) và phức tạp khi mix exclusive items.

### Decision B3 — Stack chỉ áp `discount_percent` cao nhất, không cộng dồn promotion

- **Chose:** Multi-promotion cùng match → 1 promotion thắng (highest %).
- **Rejected:** Cộng dồn (20% + 10% = 28% áp tuần tự).
- **Why:** Cộng dồn dễ làm shop margin âm khi setup vô tình. Best-for-customer (highest %) là pattern Toast/Square dùng. Shop muốn áp 30% thì tạo promotion 30%, không phải mix nhiều cái.

### Decision B4 — Pivot 2 bảng (category + product) thay vì 1 polymorphic

- **Chose:** `menu_promotion_category` + `menu_promotion_product` riêng.
- **Rejected:** Polymorphic `targetable_type / targetable_id`.
- **Why:** Query rõ ràng (`SELECT … WHERE category_id IN ?`), index tốt, FK constraint chuẩn. Polymorphic rườm rà khi reporting.

### Decision B5 — Stacking flag per promotion, không global per shop

- **Chose:** `MenuPromotion.stacking_mode` field.
- **Rejected:** Setting global per shop ("toàn shop allow stack" / "không stack").
- **Why:** Shop có thể muốn promotion "drinks 20% stackable" + "premium item 50% exclusive" cùng tồn tại. Per-promotion granularity là natural. User đã chọn này (Q6 update).

### Decision B6 — Snapshot `unit_price` ghi đè, giá gốc ở `original_unit_price`

- **Chose:** Item giữ giá đã giảm trên `unit_price`; thêm `original_unit_price` cho strikethrough.
- **Rejected:** Lưu giá gốc trên `unit_price`, tính giảm trên field riêng `promotion_discount_per_unit`.
- **Why:** BR-O04 (`subtotal = sum(unit_price × qty)`) không thay đổi. Tax + service charge tự động tính trên subtotal đã giảm — đúng business expectation. Nếu lưu separate field thì phải sửa formula nhiều chỗ.

### Decision B7 — Stacking conflict on addItem → confirm Dialog "Auto-remove coupon"

- **Chose:** UI hiện 2 button: "Hủy" / "Auto-remove coupon và thêm món". Server-side reject 422 trước; FE handle resolve.
- **Rejected:** (a) hard reject; (c) silent auto-remove.
- **Why:** (a) buộc staff phải nhớ check coupon trước khi add món — friction. (c) silent change confused. (b) explicit transparent — staff click confirm thì biết hậu quả. Atomic resolve trên client là OK vì 2 API call (release + addItem) ngắn.

## B.Alternatives considered

- **MenuPromotion as MenuItem field override** — thêm `discounted_price` lên MenuProductSku. Bị reject vì không support time window, không scope được dynamic, khó audit.
- **Subscribe-style promotion (channel/segment)** — promotion fire qua event system. Overkill cho v1; v1 simple direct lookup.
- **Cron-based menu rebuild at promotion start** — mỗi khi promotion bắt đầu, re-snapshot menu prices. Bị reject vì không real-time và phức tạp với cancellation.

## B.Risks & mitigations (mới)

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Performance: mỗi addItem → DB query resolve promotion | M | M | Redis cache key `branch:{id}:active_promotions` TTL 60s; invalidate trên CRUD/toggle. Test load: 10 RPS addItem ≤ 50ms p95. |
| DST + midnight cross trên `daily_time_from/to` | M | M | Store `Time` (no TZ); compare bằng `branch.timezone` qua Carbon `now($tz)->toTimeString()`. Test fixture xác nhận pin behavior. |
| Customer-web menu nặng nếu N items × 1 query promotion | H | M | Batch resolve trong service: 1 query SELECT promotion JOIN pivot WHERE branch_id + active_window → map về set `(category|product → promotion)` → merge vào response. Đảm bảo O(1) extra cost. |
| Promotion edit `discount_percent` giữa lúc khách order | L | L | Locked field after items applied (Q4 tweaked); buộc shop tạo promotion mới thay vì edit. |
| Receipt printing không hiển thị strikethrough | M | L | `applied_promotion_snapshot` lưu `discount_percent` + name; receipt template render `original − percent% = unit_price` line. Phối hợp workstation-app. |
| Auto-remove coupon khi add item exclusive failure giữa 2 API call | L | L | FE handle: nếu DELETE coupon thành công nhưng addItem fail → toast "Coupon đã bị remove nhưng không thêm được món" + bật lại button apply coupon. Atomic transaction giữa client là acceptable. |
| Xung đột với plan-014 MenuSchedule (món không hiển thị nhưng đang trong promotion) | L | L | Resolve ưu tiên: nếu menu-schedule loại món → menu API không trả món đó → promotion irrelevant. Test: schedule "Drinks 11-15" + promotion "Drinks 21-23" → tại 22:00 menu không có drinks → không có promotion áp. |
| Multi-promotion match — performance khi shop có 50+ promotion | L | M | Limit MenuPromotion per branch ≤ 50 (DB CHECK constraint hoặc service-level guard). Cảnh báo shop manager trên S8 nếu > 30. |

## B.Open questions

(đã đẩy lên README — Q5 timezone field, Q6 cache strategy, Q7 derived computed_status, Q8 receipt format, Q9 realtime menu refresh)

---

## References

### Coupon (Part A)

- @see backend/docs/contributing/service.md
- @see backend/docs/contributing/controller.md
- @see backend/docs/contributing/policy.md
- @see backend/docs/contributing/route.md
- @see backend/docs/contributing/omnify-architecture.md
- @see backend/docs/contributing/testing.md
- @see schemas/Backend/Product/PaymentMethod.yaml — sibling translatable+scope pattern
- @see schemas/Backend/Product/CustomerOrder.yaml — order schema (BR-O04)
- @see backend/app/Services/Notification/NotificationService.php — `lockForUpdate` reference
- @see backend/app/Services/Customer/CustomerOrderService.php — checkout + voidOrder hooks
- @see backend/app/Http/Requests/OrderCheckoutRequest.php — current discount input shape
- Stripe Coupons API — https://stripe.com/docs/api/coupons/object (split coupon/promotion_code two-layer)
- Voucherify — https://docs.voucherify.io/docs/voucher-object (canonical voucher field reference)
- Square Discounts API — https://developer.squareup.com/docs/discounts-api/overview (validation sequence)
- Talon.One coupon budgets — https://docs.talon.one/docs/dev/concepts/coupon-budgets (budget + per-customer limits)

### Menu Promotion (Part B)

- @see schemas/Backend/Product/CustomerOrderItem.yaml — host của `original_unit_price`/`applied_promotion_id`
- @see schemas/Backend/Product/Category.yaml + `Product.yaml` — promotion scope FK targets
- @see schemas/Backend/Sso/Branch.yaml — `branch.timezone` cần verify
- @see backend/app/Services/Customer/CustomerOrderService.php#addItems — hook auto-apply
- Toast — happy hour patterns (operator manual)
- Square Restaurants — discount + categories scope (developer.squareup.com/docs/items-api)
- KiotViet — happy hour cuối ngày Vietnam pattern
