# Plan 025 — Design

> Design decisions, approach, and trade-offs for [Product Review](README.md).

## Context

> Existing docs the planner read while building this design.

@see customer-web/components/menu-list-item.tsx — hardcodes `rating = item.rating ?? 94` (line 41) + deterministic hash review count (lines 33-42); mock to replace
@see customer-web/components/featured-items-carousel.tsx — `item.rating_percentage ?? 94` / `item.rating_count ?? 526` (lines 108-110); mock to replace
@see customer-web/components/menu-card.tsx — optional gate `item.rating && (...)` (line 91); already correct pattern, just needs real data
@see customer-web/data/menu.ts — `MenuItem.rating?: number` + `reviewCount?: number` (lines ~75-78); fields declared, backend needs to populate
@see backend/app/Services/Customer/CustomerMenuService.php — builds menu item payload; add `rating`/`reviewCount` here (same overlay pattern as `active_promotion` from plan-019)
@see backend/app/Http/Controllers/Api/V1/Customer/CustomerOrderController.php — order-id-as-opaque-token pattern for public endpoints (show, applyCoupon, paymentIntent)
@see backend/routes/api/customer.php — customer public route group; review endpoints go here

## Approach

Feature is additive — no refactoring of existing code. Layers split cleanly:

1. **Data**: 1 new schema (`ProductReview`) + 1 new enum (`ReviewSentiment`) + 2 aggregate columns on `Product`.
2. **Logic**: `ProductReviewService` — atomic submit (transaction + `lockForUpdate` + idempotent skip) and reviewable query.
3. **API**: 2 customer public endpoints (order UUID as auth token, same pattern as payment endpoints).
4. **Menu overlay**: `CustomerMenuService` reads Product aggregate, maps to `rating`/`reviewCount` in payload.
5. **FE submit**: `OrderReviewSheet` component triggered post-payment (dine-in + takeaway).
6. **FE display**: Remove mocks, gate badge on `reviewCount > 0`.

Sequence: BE (Phase 1-3) ships first to lock API contract, then FE (Phase 4-5).

## Architecture

```
Customer (after order closed)
  |  GET /orders/{id}/reviewable      -> list items + already_reviewed flag
  |  POST /orders/{id}/reviews        -> batch thumbs up/down
  v
CustomerReviewController --> ProductReviewService.submit()
                                 | DB::transaction + lockForUpdate
                                 | +-- create ProductReview (unique on order_item_id)
                                 | +-- Product.review_total_count++
                                 | +-- Product.review_up_count += (sentiment == up ? 1 : 0)
                                 v
                              products table (denormalized aggregate)
                                 ^
CustomerMenuService.transformMenu() reads aggregate
  |  rating = round(up / total * 100), reviewCount = total
  v
Customer menu payload --> FE menu cards (badge "X% (N)")
```

## Data model changes

> **Omnify ownership:** All schema changes via YAML -> `omnify generate`. Never hand-edit generated files.

| Table | Owner | Change | YAML schema file |
|-------|-------|--------|------------------|
| `product_reviews` | Omnify (new) | New table | `schemas/Backend/Product/ProductReview.yaml` |
| `products` | Omnify (modify) | Add 2 aggregate columns | `schemas/Backend/Product/Product.yaml` |
| — | Omnify (new enum) | `ReviewSentiment` enum | `schemas/Shared/Enum/ReviewSentiment.yaml` |

### `ReviewSentiment.yaml` (new enum)

Values: `up`, `down`

### `ProductReview.yaml` (new entity)

```
# ProductReview
# -------------
# A binary (thumbs up/down) review of a Product, submitted by a customer
# after their order is closed (paid). One review per order item (enforced
# by unique index on customer_order_item_id).
#
# Written by: customer via POST /customer/orders/{id}/reviews
# Read by:    aggregate columns on Product (incremental update on write),
#             GET /customer/orders/{id}/reviewable (already_reviewed flag)
# Lifecycle:  write-once (no edit/delete in v1)

id              uuid PK
product_id      uuid FK -> products
customer_order_id       uuid FK -> customer_orders
customer_order_item_id  uuid FK -> customer_order_items  UNIQUE INDEX
customer_id     uuid FK -> customers  NULLABLE
organization_id uuid FK -> organizations
brand_id        uuid FK -> brands
branch_id       uuid FK -> branches
sentiment       ReviewSentiment (enum)
comment         text NULLABLE
created_at      timestamp
```

### `Product.yaml` additions (aggregate columns)

```
review_up_count     int  default 0   # thumbs-up count (incremented in transaction)
review_total_count  int  default 0   # total review count (up + down)
```

`recommend_percent` is NOT stored — computed at read time: `total > 0 ? round(up / total * 100) : null`.

## API surface

> All endpoints are public (no auth guard). Order UUID serves as opaque access token, following the established pattern used by `show`, `applyCoupon`, and `paymentIntent` endpoints in `CustomerOrderController`.

### Endpoint inventory

| # | Method | Path | Purpose | Auth | Route file |
|---|--------|------|---------|------|------------|
| 1 | GET | `/api/v1/customer/orders/{orderId}/reviewable` | List order items with `already_reviewed` flag | Public (order UUID) | `backend/routes/api/customer.php` |
| 2 | POST | `/api/v1/customer/orders/{orderId}/reviews` | Batch submit thumbs up/down reviews | Public (order UUID) | `backend/routes/api/customer.php` |

### Endpoint detail

#### 1. GET `/api/v1/customer/orders/{orderId}/reviewable`

- **Auth:** Public — order UUID is the access token
- **Route binding:** `{orderId}` -> `CustomerOrder` (UUID lookup)
- **Request body:** None (GET)
- **Query params:** None
- **Success response (`200`):**

  ```json
  {
    "data": {
      "order_id": "uuid",
      "items": [
        {
          "order_item_id": "uuid",
          "product_id": "uuid",
          "product_name": "string",
          "product_image": "string|null",
          "variant_name": "string|null",
          "already_reviewed": false
        }
      ]
    }
  }
  ```

- **Error responses:**

  | Status | Code | When |
  |--------|------|------|
  | 404 | `order_not_found` | Order UUID does not exist |
  | 422 | `order_not_closed` | Order status is not `closed` (not yet fully paid) |

- **Side effects:** None (read-only)
- **Notes:** Voided items (`status = voided`) are excluded from the list.

#### 2. POST `/api/v1/customer/orders/{orderId}/reviews`

- **Auth:** Public — order UUID is the access token
- **Route binding:** `{orderId}` -> `CustomerOrder` (UUID lookup)
- **Request body:**

  | Field | Type | Required | Notes |
  |-------|------|----------|-------|
  | `reviews` | array | Yes | 1..N review objects |
  | `reviews[].order_item_id` | uuid | Yes | Must belong to this order, not voided |
  | `reviews[].product_id` | uuid | Yes | Must match the item's product |
  | `reviews[].sentiment` | string | Yes | `"up"` or `"down"` |
  | `reviews[].comment` | string | No | Free text, max 1000 chars (nullable) |

- **Success response (`201`):**

  ```json
  {
    "data": {
      "created": 2,
      "skipped": 1
    }
  }
  ```

- **Error responses:**

  | Status | Code | When |
  |--------|------|------|
  | 404 | `order_not_found` | Order UUID does not exist |
  | 422 | `order_not_closed` | Order status is not `closed` |
  | 422 | `validation_error` | Missing required fields, invalid sentiment value, item not in order |

- **Side effects:**
  - Creates `ProductReview` row per valid item (skips already-reviewed items)
  - Atomically increments `Product.review_total_count` (+1 per created review)
  - Atomically increments `Product.review_up_count` (+1 per created review with `sentiment = up`)
  - Uses `DB::transaction` + `lockForUpdate` on Product row to prevent race conditions

## Screens

> Customer-web screens. This project does NOT use `@godxjp/ui` (that's admin-web only). Customer-web has its own Tailwind component set.

### Screen inventory

| # | Path/Component | Type | Auth | Purpose |
|---|----------------|------|------|---------|
| 1 | `OrderReviewSheet` (new component) | Sheet/modal | Public (order context) | Post-payment review submission |
| 2 | `paid-view.tsx` (modified) | Existing component | Public (QR session) | Add "Rate your dishes" trigger button |
| 3 | `order-success/page.tsx` (modified) | Existing page | Public | Add "Rate your dishes" trigger button |
| 4 | `menu-list-item.tsx` (modified) | Existing component | Public | Replace mock rating with real data |
| 5 | `featured-items-carousel.tsx` (modified) | Existing component | Public | Replace mock rating with real data |
| 6 | `menu-card.tsx` (modified) | Existing component | Public | Gate badge on `reviewCount > 0` (already optional) |

### Screen detail

#### 1. OrderReviewSheet (new) — triggered from paid-view / order-success

- **Layout:** Bottom sheet / modal overlay (reuse existing sheet pattern from customer-web)
- **Page file:** `customer-web/components/order-review-sheet.tsx` (colocated component)
- **Fetches:** Endpoint #1 (GET reviewable) on mount
- **Components used:** Sheet container, item list with product image + name, toggle buttons (thumbs up/down), optional textarea for comment (hidden by default, expand on tap), submit button, skip/close button
- **Translatable fields:** None (UI labels only, handled via i18n keys)
- **Empty state:** All items already reviewed -> "You've already reviewed this order" message + close button
- **Error state:** Network error -> toast with retry option
- **Loading state:** Skeleton list while fetching reviewable items
- **Interactions:**
  - Toggle thumbs up/down per item (default: no selection, must choose to include in batch)
  - "Submit" sends batch POST to endpoint #2
  - On success: toast "Thank you!" + close sheet
  - "Skip" closes sheet without submitting
- **i18n keys:** `review.title`, `review.thumbsUp`, `review.thumbsDown`, `review.submit`, `review.skip`, `review.thankYou`, `review.alreadyReviewed`, `review.rateYourDishes`

#### 2. paid-view.tsx (modified) — dine-in post-payment

- **Diff:** Add "Rate your dishes" button below the existing "Back to Home" button. Button opens `OrderReviewSheet` with the current `order.id`.
- **Fetches:** No new fetches (passes existing order ID to sheet)
- **Condition:** Only show button if order has items (always true for paid orders)

#### 3. order-success/page.tsx (modified) — takeaway post-payment

- **Diff:** Add "Rate your dishes" button below existing action buttons. Button opens `OrderReviewSheet` with `id` from search params.
- **Fetches:** No new fetches
- **Condition:** Only show when `id` search param is present

#### 4-6. Menu card components (modified) — replace mocks

- **menu-list-item.tsx:** Remove `const rating = item.rating ?? 94` (line 41) and `reviewCountFor()` hash function (lines 33-42). Use `item.rating` and `item.reviewCount` directly. Only render badge when `item.reviewCount > 0`.
- **featured-items-carousel.tsx:** Remove `?? 94` / `?? 526` fallbacks (lines 108-110). Use `item.rating_percentage` and `item.rating_count` directly. Only render when values are truthy.
- **menu-card.tsx:** Already uses optional gate `item.rating && (...)`. No change needed beyond ensuring data shape matches.

### Admin UI prep tasks

Not applicable — this plan touches `customer-web/`, not `admin-web/`. Customer-web does not use `@godxjp/ui`.

## Sitemap

> The review sheet is a modal overlay, not a standalone route. No new pages added to navigation.

### Navigation diff

```
/[locale]
+-- /dine-in/[shop]/table/[qrToken]
|   +-- paid-view (component)
|       +-- OrderReviewSheet    [NEW] (modal, no route)
+-- /order-success
|   +-- OrderReviewSheet        [NEW] (modal, no route)
+-- /menus (and all menu views)
    +-- menu-list-item          [MODIFIED] (real rating data)
    +-- featured-items-carousel [MODIFIED] (real rating data)
    +-- menu-card               [MODIFIED] (no functional change)
```

### Entry points

| From | Via | To | Visibility |
|------|-----|----|------------|
| paid-view.tsx (dine-in) | "Rate your dishes" button | OrderReviewSheet (modal) | Always visible after payment |
| order-success/page.tsx (takeaway) | "Rate your dishes" button | OrderReviewSheet (modal) | When `id` param present |

### Breadcrumbs

No breadcrumbs — review sheet is a modal overlay, not a navigable page.

### Deep-link / back-link behaviour

- **Cancel/Skip in OrderReviewSheet:** Closes modal, returns to underlying page (paid-view or order-success). No navigation change.
- **After submit:** Closes modal, returns to underlying page. No redirect.
- **Direct URL access:** Not applicable (modal, not a route).

## Authorization matrix

> This feature is entirely customer-facing with public endpoints. Order UUID serves as the access token (same pattern as existing payment/coupon endpoints). No admin roles involved.

### Roles involved

| Role key | Display | Source | Notes |
|----------|---------|--------|-------|
| `anonymous_customer` | Anonymous customer (dine-in QR) | Session context | Has order_id from QR session |
| `logged_in_customer` | Logged-in customer (takeaway) | `auth:customer` guard (optional) | Has order_id + customer_id |

### Action x Role matrix

| Action | anonymous_customer | logged_in_customer |
|--------|-------------------|-------------------|
| GET reviewable items | Allowed (order UUID) | Allowed (order UUID) |
| POST submit reviews | Allowed (order UUID) | Allowed (order UUID + customer_id attached) |
| View rating on menu | Allowed | Allowed |

Legend: All actions are public. The order UUID is the sole authorization gate — anyone with the UUID can review. `customer_id` is opportunistically attached when available but not required.

### Policy / UI gate cross-check

| Action | Backend gate | Frontend gate |
|--------|--------------|---------------|
| Submit review for closed order | Controller validates `order.status === closed` | Sheet only opens from post-payment views (order is already closed at that point) |
| Prevent double-vote | Unique index on `customer_order_item_id` + service skip logic | `already_reviewed` flag hides toggle / shows "reviewed" badge |
| Exclude voided items | Service filters `item.status !== voided` | Reviewable endpoint already excludes them |

### Role-switch verification checklist

- [ ] Anonymous dine-in customer can submit review via order UUID without login
- [ ] Logged-in takeaway customer can submit review; `customer_id` is attached to the review row
- [ ] Random UUID that doesn't match an order returns 404 (not 403 — no auth distinction)
- [ ] Order in non-closed status returns 422 regardless of who requests

## User journeys

### Journey 1 — Dine-in customer reviews dishes after payment

**Persona:** Anonymous customer who scanned QR code, ordered dishes, and just completed payment at a restaurant table.

**Happy path:**

1. Customer sees the `paid-view` success screen with confetti animation, order code, and total paid amount.
2. Below "Back to Home", a "Rate your dishes" button is visible.
3. Customer taps "Rate your dishes" -> `OrderReviewSheet` opens as bottom sheet.
4. Sheet fetches `GET /orders/{id}/reviewable` and displays the list of items (product image + name + thumbs up/down toggle per item).
5. Customer taps thumbs-up on 2 items, thumbs-down on 1 item, leaves 1 item unrated.
6. Customer taps "Submit" -> `POST /orders/{id}/reviews` with batch of 3 reviews.
7. Toast "Thank you for your feedback!" appears, sheet closes.
8. Customer is back on paid-view, can tap "Back to Home".

**Alternate path — skip review:**

1. Customer sees "Rate your dishes" button but taps "Back to Home" instead (or taps "Skip" / closes the sheet).
2. No review submitted. No data changed.

**Edge / error paths:**

- **All items already reviewed (re-open sheet):** Sheet shows "You've already reviewed this order" message + close button. No toggle inputs rendered.
- **Network error on submit:** Toast error "Failed to submit. Please try again." with retry. Sheet stays open.
- **Order somehow not closed:** Sheet fetch returns 422 -> toast "Order not ready for review" + auto-close sheet. (Defensive — shouldn't happen since paid-view only renders for paid orders.)

### Journey 2 — Takeaway customer reviews dishes after order success

**Persona:** Customer (may or may not be logged in) who placed a takeaway order and completed Stripe payment.

**Happy path:**

1. Customer lands on `/order-success?id={uuid}&code=ORD-...&type=takeaway&stripe_return=1`.
2. Success UI shows green checkmark, order code, payment confirmation badge.
3. Below action buttons, "Rate your dishes" button is visible.
4. Customer taps it -> `OrderReviewSheet` opens.
5. Same flow as Journey 1 steps 4-8.

**Alternate path — no order ID in params:**

1. Customer navigates to `/order-success` without `id` param (e.g., direct URL).
2. "Rate your dishes" button is NOT rendered (guarded by `id` presence).
3. Only "Order More" and "Back Home" buttons visible.

**Edge / error paths:**

- Same as Journey 1 (network error, already reviewed, order not closed).

### Cross-journey checklist

- [x] Every happy-path step maps to at least one endpoint in the API inventory (GET reviewable = #1, POST reviews = #2, menu data = CustomerMenuService).
- [x] Every error path has a corresponding 4xx case in the endpoint detail (404, 422).
- [x] Every navigation step maps to a row in the Sitemap entry-points table.
- [x] Both customer roles (anonymous dine-in, logged-in takeaway) are covered by journeys.

## Field lifecycle

### ProductReview (new entity)

| Field | Added? | Default | Displayed on screens | Editable on screens | Editable by roles | Validation | Omnify prop |
|-------|--------|---------|----------------------|---------------------|-------------------|------------|-------------|
| `id` | Yes | auto (uuid) | — | — | — | — | PK |
| `product_id` | Yes | — | — (internal) | — | — | Required, exists in products | FK |
| `customer_order_id` | Yes | — | — (internal) | — | — | Required, exists in customer_orders | FK |
| `customer_order_item_id` | Yes | — | — (internal) | — | — | Required, exists in order items, unique | FK, unique |
| `customer_id` | Yes | null | — (internal) | — | — | Optional, exists in customers | FK, nullable |
| `organization_id` | Yes | — | — (internal) | — | — | Auto-filled from order | FK |
| `brand_id` | Yes | — | — (internal) | — | — | Auto-filled from order | FK |
| `branch_id` | Yes | — | — (internal) | — | — | Auto-filled from order | FK |
| `sentiment` | Yes | — | OrderReviewSheet (toggle) | OrderReviewSheet (toggle) | anonymous_customer, logged_in_customer | Required, in: up,down | EnumRef |
| `comment` | Yes | null | — (hidden v1) | OrderReviewSheet (optional textarea) | anonymous_customer, logged_in_customer | Optional, max:1000 | text, nullable |
| `created_at` | Yes | now() | — | — | — | Auto | timestamp |

### Product (existing — aggregate additions)

| Field | Added? | Default | Displayed on screens | Editable on screens | Editable by roles | Validation | Omnify prop |
|-------|--------|---------|----------------------|---------------------|-------------------|------------|-------------|
| `review_up_count` | Yes | 0 | — (internal, used for computation) | — (system-managed) | — | — | int, default: 0 |
| `review_total_count` | Yes | 0 | Menu cards (as `reviewCount`) | — (system-managed) | — | — | int, default: 0 |

Computed (not stored): `recommend_percent = review_total_count > 0 ? round(review_up_count / review_total_count * 100) : null` -> displayed as `rating` on menu cards.

### Orphaned field audit

Fields on `Product` that this feature does NOT touch:

| Field | Why not touched | Currently editable at | Acceptable? |
|-------|-----------------|-----------------------|-------------|
| `name`, `slug`, `description` | Catalog identity — unrelated to reviews | HQ admin product CRUD | Yes |
| `status`, `is_hidden` | Lifecycle — independent from review feature | HQ admin | Yes |
| `thumbnail`, `gallery` | Media — independent | HQ admin | Yes |
| `approved_by_id`, `approved_at`, etc. | Approval workflow fields | HQ admin | Yes |
| All FK fields (brand, org, productType, etc.) | Structural relations | HQ admin | Yes |

### Field lifecycle cross-check

- [x] `sentiment` is editable on OrderReviewSheet — toggle component in screen detail.
- [x] `comment` is editable on OrderReviewSheet — optional textarea in screen detail.
- [x] `review_total_count` is displayed as `reviewCount` on menu cards — confirmed in screen detail for items 4-6.
- [x] `recommend_percent` (computed from `review_up_count` / `review_total_count`) is displayed as `rating` on menu cards.
- [x] All new ProductReview fields have validation rules in endpoint #2 request body.
- [x] No translatable fields in this feature (no convention #2 concern).
- [x] Every NOT NULL field without a default has Required in the request body table.

## Key decisions

### Decision 1 — Auth via order UUID (no login required)

- **Chose:** Order UUID as opaque access token (public endpoints)
- **Rejected:** `auth:customer` guard (require login), session-based tokens
- **Why:** Dine-in customers are anonymous (QR scan, no account). Requiring login would block the majority of reviews. Order UUID is already the established pattern for customer endpoints (show, applyCoupon, paymentIntent). Trade-off: anyone with the UUID can review — acceptable because UUIDs are not guessable and the review window is naturally limited.

### Decision 2 — Unique gate on `customer_order_item_id`

- **Chose:** DB unique index + service-level idempotent skip
- **Rejected:** Application-level check only (race condition risk), allowing re-votes (complexity)
- **Why:** DB constraint is the strongest guarantee against double-votes. Service skips duplicates gracefully (returns `skipped` count) instead of erroring, so re-submits from flaky networks are harmless. Trade-off: customer cannot change their vote — acceptable for v1.

### Decision 3 — Per-Product rating (not per-SKU)

- **Chose:** Reviews aggregate at Product level
- **Rejected:** Per-SKU aggregation
- **Why:** Customers rate the dish (Product), not the size/variant (SKU). Per-SKU would fragment data (e.g., "Large Latte" vs "Small Latte" rated separately — confusing). Menu displays Product-level cards.

### Decision 4 — Binary thumbs up/down (not star rating)

- **Chose:** Binary sentiment (up/down) -> % recommend
- **Rejected:** 5-star rating, 3-tier (good/ok/bad)
- **Why:** UI already shows "X% recommend" format. Binary maps directly to this. Simpler UX (one tap per item). Trade-off: less granularity — acceptable for restaurant context where "would recommend" is the key signal.

### Decision 5 — Denormalized aggregate on Product

- **Chose:** `review_up_count` + `review_total_count` columns on Product, incremented in transaction
- **Rejected:** COUNT/SUM query on `product_reviews` per menu request, materialized view, separate aggregate table
- **Why:** Menu renders N items per request — JOIN + GROUP BY per item is expensive. Denormalized columns are O(1) reads. Updated atomically with `lockForUpdate` in the review transaction. Trade-off: risk of drift if bug skips increment — mitigated by optional reconcile job (out of scope for v1).

### Decision 6 — Batch submit (all items in one request)

- **Chose:** Single POST with array of reviews
- **Rejected:** One POST per item
- **Why:** UX is "review all items at once" in one sheet. Single request = one transaction = atomicity. Trade-off: slightly larger payload (negligible for typical 2-5 items per order).

## Alternatives considered

1. **Separate review page (new route)** instead of modal sheet: Would require its own URL, layout, navigation. Overkill for a simple thumbs up/down flow. Sheet keeps the user on the payment success screen and is dismissible.

2. **Star rating (1-5)** instead of binary: More granular but harder to aggregate into a single "recommend %" number. Restaurant context favors simple "would recommend" signal over nuanced scoring.

3. **Require customer login for reviews**: Would improve data quality and enable edit/delete. But blocks anonymous dine-in customers (majority of use case). Can be added in v2 as an enhancement for logged-in users.

4. **Aggregate table instead of columns on Product**: Cleaner separation but adds a JOIN on every menu query. For the expected scale (hundreds of products, not millions), denormalized columns are simpler and faster.

## Risks & mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Aggregate drift (bug causes count mismatch) | Low | Medium (incorrect % on menu) | Optional reconcile job (deferred); `lockForUpdate` prevents race conditions |
| Spam reviews via automated POST | Low | Low (limited to valid order UUIDs) | Order must be `closed`; unique constraint per item; optional rate-limit via `throttle:` middleware |
| UX confusion (customer doesn't understand thumbs up/down) | Low | Low | Clear i18n labels; skip option always visible |
| Order UUID leakage enables unauthorized reviews | Very low | Low (review is write-once, no sensitive data exposed) | UUIDs are cryptographically random (128-bit); review has no destructive side effect |

## Open questions

- [ ] Include `comment` text field in v1? (Currently in schema as nullable, textarea hidden by default in UI — expand on tap.)
- [ ] Review window policy: only from post-payment screens, or allow via direct `GET /orders/{id}/reviewable` anytime (if `closed`)?
- [ ] Rate-limit config for POST review endpoint? (Suggest `throttle:10,1` — 10 requests per minute per IP.)

## References

- `customer-web/components/menu-list-item.tsx` — current mock implementation
- `customer-web/components/featured-items-carousel.tsx` — current mock implementation
- `backend/app/Http/Controllers/Api/V1/Customer/CustomerOrderController.php` — order-id-as-token pattern
- `backend/app/Services/Customer/CustomerMenuService.php` — menu payload builder
- [plan-019](../plan-019/README.md) — Coupon & Menu Promotion (menu overlay pattern reference)
- plan-004 (đã archive — xem git history) — Dine-in Order, Payment & Transaction Flow
