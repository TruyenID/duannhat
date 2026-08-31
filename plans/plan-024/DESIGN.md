# Plan 024 — Design

> Design decisions, approach, and trade-offs for [Stock Management — Auto-Deduct, Inventory Mode, Alert Notifications](README.md).

## Context

@see backend/docs/explanation/inventory-domain.md — current warehouse/stock lifecycle + auto-approve flag semantics that govern who can complete a transaction
@see backend/docs/explanation/stock-management.md — business rules BR-S01..BR-A02; the source of truth this plan partially amends (allow-negative breaks BR-01)
@see backend/docs/contributing/service.md — mandatory `DB::transaction` + `lockForUpdate` + typed-exception idioms; all 6 gaps must conform
@see backend/docs/contributing/policy.md — `ChecksWarehouseContext` trait + manager/staff matrix; explains how StockTransactionPolicy reaches its current state
@see backend/docs/contributing/testing.md — Pest idioms, factory usage, coverage expectations
@see backend/app/Services/Customer/OrderClosingService.php — current close flow; this plan adds Material deduction immediately after the existing SKU stock-out step
@see backend/app/Services/Inventory/StockTransactionService.php — `completeTransaction` is where allow-negative branches; `splitStockOutItemsByFefo` already handles material lot allocation, no changes needed there
@see backend/app/Services/Inventory/ExpiryAlertService.php — canonical reference for `NotificationService.dispatch` wiring (try/catch + idempotency_key + role_user_pivots audience query)
@see plans/material-system-deep-dive.md#stock-management — known cross-cutting issues between Material/Lot and Stock

## Approach

Six targeted changes, **no large refactors**. Each gap is isolated to 1–2 files plus tests. The plan is sequenced so backend gates (G1–G5) ship before the admin-web UI (G6 + Form for G1), letting the API contract stabilize first.

1. **G1 — `inventory_mode` on `ProductSku`** — schema YAML change + omnify regen. The enum file `ProductSkuInventoryMode.yaml` lives in `schemas/Shared/Enum/`. Default is `made_to_order` (conservative: no auto-deduct).
2. **G2 — gate SKU stock-out** — single `if` in `OrderClosingService::close()`: skip the `StockTransaction` creation when `productSku.inventory_mode === 'made_to_order'`.
3. **G3 — Recipe → Material deduction** — append a second pass in `OrderClosingService::close()`: for every `track_stock` SKU in the order, walk `productSku.recipe.ingredients`, compute `qty_per_serving × order_qty` (plain formula — see Decision 7), and build a single combined `stock_out / sales_material_consumption` `StockTransaction` (one transaction with N material items). Targets the same warehouse via existing `getDefaultWarehouse()`. The existing FEFO pre-pass in `StockTransactionService::completeTransaction` will split per-lot rows automatically when a material is lot-tracked. The existing `recordSalesGenealogy()` best-effort FEFO preview (OrderClosingService.php:188-261) stays in place untouched — the new step writes the actual stock movements that genealogy was only previewing. **If `productSku.recipe` is null or has zero ingredients**, skip + `Log::warning` (decision: skip-and-warn, OQ-1 in README).
4. **G4 — allow-negative on sales** — add `allow_negative_sales` boolean to `Warehouse` schema. In `StockTransactionService::completeTransaction`, when shortage is collected AND `warehouse->allow_negative_sales` is true AND transaction subtype is in {`sales`, `sales_material_consumption`}, do NOT throw — instead write the negative `StockLevel.quantity`, force-create an `out_of_stock` alert, and proceed. For ALL other transaction types or when the flag is false, current strict behaviour is preserved.
5. **G5 — Notification on alert** — extract a private `notifyOnAlert(StockAlert $alert): void` method that dispatches via `NotificationService` using `ExpiryAlertService` pattern. Audience: warehouse managers (members of the affected `Warehouse` with `role='manager'`). Idempotency key: `stock_alert:{alert_id}` — since `alert_id` is a fresh PK from `StockAlert::create()`, this key is a safety net for sync-dispatch retries (e.g. if a future change moves dispatch to a queued job, retries on the same alert id remain idempotent). Today the dispatch is sync inside the order-close `DB::transaction`, so the key does no de-dup work on the first call; it's pinned for forward-compatibility. Called from the existing `StockAlert::create()` site inside `completeTransaction` (no change to where alerts fire — only adds a notification side-effect). The audience query uses `WarehouseMember` not `role_user_pivots` (more precise than the `ExpiryAlertService` pattern, which is org-wide).
6. **G6 — Inline threshold-edit** — add a `StockLevelThresholdSheet` component to `admin-web/src/app/shop/[shopSlug]/stock/alerts/components/`. Triggered from a new action in `stock-alert-table.tsx` action dropdown. Submits via the existing `useUpdateStockLevel` mutation (which hits `PUT /stock-levels/{id}`). Backend: after the threshold update, `StockLevelService::update()` invokes a new private `reEvaluateActiveAlertForLevel(StockLevel $level)` method that auto-resolves the active alert when `quantity >= new_min_stock` (and conversely, auto-creates one if quantity is now below the new threshold and no active alert exists).

## Architecture

```
                    ┌──────────────────────────────────┐
                    │  OrderClosingService::close()    │
                    │  ─────────────────────────       │
                    │  1. lock order                   │
                    │  2. verify paid >= total          │
                    │  3. set status=closed             │
                    │  4. [G2] SKU stock-out            │
                    │     • SKIP if inventory_mode      │
                    │       = made_to_order             │
                    │     • CREATE stock_out tx for     │
                    │       track_stock SKUs            │
                    │  5. [G3 NEW] Recipe→Material      │
                    │     • walk track_stock items      │
                    │     • build combined material tx  │
                    │     • SKIP+WARN if recipe missing │
                    │  6. genealogy + table release     │
                    │  7. audit log                     │
                    └─────────┬────────────────────────┘
                              │
                              ▼ (auto-approves via warehouse flags)
                    ┌──────────────────────────────────┐
                    │ StockTransactionService::         │
                    │   completeTransaction()           │
                    │  ─────────────────────────        │
                    │  • FEFO pre-pass (materials)      │
                    │  • per-item lockForUpdate         │
                    │  • shortage check                  │
                    │    ┌─[G4 NEW]──────────────────┐  │
                    │    │ IF allow_negative_sales   │  │
                    │    │   && subtype IN (sales)   │  │
                    │    │   → write negative qty     │  │
                    │    │   → force out_of_stock     │  │
                    │    │     alert                  │  │
                    │    │   → continue (no throw)    │  │
                    │    └────────────────────────────┘  │
                    │  • create StockMovement           │
                    │  • update StockLevel               │
                    │  • [G5 NEW] notifyOnAlert()       │
                    │    when StockAlert is created     │
                    └──────────────────────────────────┘
                              │
                              ▼
                    ┌──────────────────────────────────┐
                    │  NotificationService::dispatch() │
                    │  audience: WarehouseMember role=  │
                    │  manager for affected warehouse   │
                    └──────────────────────────────────┘
```

For G6 (admin-web):

```
StockAlertsPage
└── StockAlertTable
    └── action dropdown "..." (per row)
        ├── (existing) View detail
        └── NEW "Configure threshold"
            └── opens StockLevelThresholdSheet
                ├── form: min_stock / max_stock / alert_enabled
                └── submits via useUpdateStockLevel (PUT /stock-levels/{id})
                    └── backend re-evaluates active alert
                        ├── quantity >= new_min → resolve existing
                        └── quantity <  new_min && no active → create
```

## Data model changes

| Table                | Owner  | Change                                                                                                                                               | YAML schema file                                                                                            |
| -------------------- | ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `product_skus`       | Omnify | Add `inventory_mode` enum column (default `made_to_order`)                                                                                           | `schemas/Backend/Product/ProductSku.yaml` (modify) + new `schemas/Shared/Enum/ProductSkuInventoryMode.yaml` |
| `warehouses`         | Omnify | Add `allow_negative_sales` boolean column (default `false`)                                                                                          | `schemas/Backend/Inventory/Warehouse.yaml` (modify)                                                         |
| `stock_transactions` | Omnify | No schema change — `sub_type` already supports new values; just add `sales_material_consumption` to the existing `StockTransactionSubType.yaml` enum | `schemas/Shared/Enum/StockTransactionSubType.yaml` (modify)                                                 |

No new tables. No new migrations beyond Omnify-generated ones for the three YAML edits above.

### `ProductSkuInventoryMode.yaml` (new enum)

```yaml
displayName:
  en: ProductSku Inventory Mode
  ja: 在庫モード
  vi: Chế độ tồn kho
kind: enum
values:
  - key: made_to_order
    displayName:
      en: Made to Order
      ja: 注文都度調理
      vi: Làm theo đơn
  - key: track_stock
    displayName:
      en: Track Stock
      ja: 在庫追跡
      vi: Theo dõi tồn kho
```

### `Warehouse.allow_negative_sales` property (added)

```yaml
allow_negative_sales:
  # When true, sales-flow stock-out transactions (sub_type in {sales,
  # sales_material_consumption}) are allowed to drive StockLevel.quantity
  # below zero. Shortage fires an out_of_stock alert + notification but
  # does NOT throw InsufficientStockException. Manual stock-out, transfer,
  # and disposal transactions remain strict regardless of this flag — they
  # always throw on shortage, because manual writes should require staff
  # to know the real on-hand state.
  type: Boolean
  default: false
  nullable: false
```

### `StockTransactionSubType.yaml` (add value)

Existing values (verified against `schemas/Shared/Enum/StockTransactionSubType.yaml`): `purchase, sales, production, transfer_in, transfer_out, return, disposal, adjustment_in, adjustment_out, other`. Add:

```yaml
- key: sales_material_consumption
  displayName:
    en: Sales (Material via Recipe)
    ja: 売上（レシピ経由）
    vi: Bán hàng (NVL qua công thức)
```

## API surface

### Endpoint inventory

| #   | Method  | Path                                                       | Purpose                                                               | Auth                                        | Route file                                                                     |
| --- | ------- | ---------------------------------------------------------- | --------------------------------------------------------------------- | ------------------------------------------- | ------------------------------------------------------------------------------ |
| 1   | `PUT`   | `/api/v1/shops/{shopSlug}/stock-levels/{stockLevel}`       | Update min/max threshold + alert_enabled; trigger alert re-evaluation | sanctum + ChecksWarehouseContext (manager)  | `backend/routes/api/shops/inventory.php` (EXISTS — behaviour change only)      |
| 2   | `PATCH` | `/api/v1/hq/{brandSlug}/product-skus/{productSku}`         | Update ProductSku — now accepts `inventory_mode`                      | sanctum + brand admin (EXISTING)            | HQ catalog routes (EXISTS — request schema change only)                        |
| 3   | `PATCH` | `/api/v1/shops/{shopSlug}/warehouses/{warehouse}/settings` | Update warehouse settings — now accepts `allow_negative_sales`        | sanctum + WarehousePolicy.update (EXISTING) | `backend/routes/api/shops/inventory.php` (EXISTS — request schema change only) |

**No new routes.** All 6 gaps land on existing endpoints. Behaviour changes are documented per-endpoint below.

### Endpoint detail

#### 1. `PUT /api/v1/shops/{shopSlug}/stock-levels/{stockLevel}` (behaviour change)

- **Auth:** `sanctum + ChecksWarehouseContext::isManagerOf($warehouse)` (existing — manager-only)
- **Route binding:** `{stockLevel}` → `StockLevel` (existing)
- **Request body** (unchanged shape):

  | Field           | Type          | Required | Notes                                          |
  | --------------- | ------------- | -------- | ---------------------------------------------- |
  | `min_stock`     | decimal\|null | no       | Threshold for low-stock alert. NULL disables.  |
  | `max_stock`     | decimal\|null | no       | Threshold for over-stock alert. NULL disables. |
  | `alert_enabled` | boolean       | no       | Master switch.                                 |

- **Success response (`200 OK`):**

  ```json
  {
    "data": {
      "id": "...",
      "warehouse_id": "...",
      "product_sku_id": "...|null",
      "material_id": "...|null",
      "quantity": "10.000",
      "min_stock": "5.000",
      "max_stock": "100.000",
      "alert_enabled": true,
      "active_alert_id": null,
      "updated_at": "..."
    }
  }
  ```

- **Error responses** (unchanged):

  | Status | Code              | When                                |
  | ------ | ----------------- | ----------------------------------- |
  | 401    | `unauthenticated` | No session                          |
  | 403    | `forbidden`       | Caller not manager of the warehouse |
  | 404    | `not_found`       | Stock level not in this shop        |
  | 422    | `validation`      | min_stock > max_stock or negative   |

- **Side effects (NEW in this plan):**
  1. After the threshold write, `StockLevelService::update()` calls `reEvaluateActiveAlertForLevel($level)`.
  2. If `quantity >= new_min_stock` AND an active `StockAlert` exists for this level → mark it `resolved` (set `status='resolved'`, `resolved_at=now()`).
  3. If `quantity < new_min_stock` AND alert_enabled=true AND no active alert exists → create a new `low_stock` `StockAlert` and dispatch notification via the G5 pathway.
  4. The endpoint's response includes the new `active_alert_id` (null if resolved or never existed).

#### 2. `PATCH /api/v1/hq/{brandSlug}/product-skus/{productSku}` (request shape change)

- **Auth:** existing brand-admin gate.
- **New request field:**

  | Field            | Type                                       | Required | Notes                                                                                                                              |
  | ---------------- | ------------------------------------------ | -------- | ---------------------------------------------------------------------------------------------------------------------------------- |
  | `inventory_mode` | string enum `made_to_order \| track_stock` | no       | Persisted on `product_skus.inventory_mode`. Default for new rows: `made_to_order`. Existing rows after migration: `made_to_order`. |

- **Side effects:** none beyond the column write. Flipping `track_stock` → `made_to_order` does NOT zero out existing `StockLevel` rows for this SKU. Flipping `made_to_order` → `track_stock` does NOT pre-populate `StockLevel` rows; they get created lazily on the first stock-in transaction (existing behaviour).

#### 3. `PATCH /api/v1/shops/{shopSlug}/warehouses/{warehouse}/settings` (request shape change)

- **Auth:** existing `WarehousePolicy::updateSettings` (org admin only — per `inventory-domain.md` BR-07).
- **New request field:**

  | Field                  | Type    | Required | Notes                                                            |
  | ---------------------- | ------- | -------- | ---------------------------------------------------------------- |
  | `allow_negative_sales` | boolean | no       | Default `false`. When `true`, sales-flow shortages do not throw. |

- **Side effects:** column write only. Pre-existing alerts/transactions unaffected.

## Screens

> Admin-web only. Per locked decision: existing tab structure at `/shop/[shopSlug]/stock` stays. No new tabs, no new pages.

### Screen inventory

| #   | Path                                        | Type                                                                 | Auth         | Purpose                                    |
| --- | ------------------------------------------- | -------------------------------------------------------------------- | ------------ | ------------------------------------------ |
| S1  | `/hq/[brandSlug]/products/[id]/skus`        | Modified — add `inventory_mode` field to SKU edit form               | HQ admin     | Set inventory mode per SKU                 |
| S2  | `/shop/[shopSlug]/stock/alerts`             | Modified — add inline "Configure threshold" action on each alert row | Shop manager | Quick-edit min/max from alerts triage view |
| S3  | `/shop/[shopSlug]/warehouses/[id]/settings` | Modified — add `allow_negative_sales` toggle                         | Org admin    | Per-warehouse policy                       |

### Screen detail

#### S1 — Modified — `/hq/[brandSlug]/products/[id]/skus`

- **Layout:** existing HQ product detail layout
- **Page file:** `admin-web/src/app/hq/[brandSlug]/products/[id]/skus/components/sku-edit-form.tsx` (or similar — verify exact path during Phase 2 discovery)
- **Fetches:** unchanged
- **Components used:** existing `@godxjp/ui` Form + FormField + Select (from `@godxjp/ui`)
- **Diff:** add a new `<FormField name="inventory_mode">` with a `<Select>` of `made_to_order` / `track_stock`. Default selected = `made_to_order` for new SKUs. Existing SKUs show current value.
- **Empty state:** N/A (modification)
- **Error state:** existing form error toast
- **Loading state:** existing form pending state
- **Interactions:** select → form mark dirty → save → mutation hits `PATCH /hq/{brandSlug}/product-skus/{id}` → toast
- **Form best practices:** select component near the price/SKU code group (the field belongs to the "commerce config" cluster, not the localisation cluster). Label: "Inventory mode" (en) / "在庫モード" (ja) / "Chế độ tồn kho" (vi). Inline description below the select: "Made to order — no stock tracking. Track stock — deduct from inventory on sale (uses recipe if linked)."

#### S2 — Modified — `/shop/[shopSlug]/stock/alerts`

- **Layout:** existing shop stock layout
- **Page file:** `admin-web/src/app/shop/[shopSlug]/stock/alerts/page.tsx` (no structural change)
- **Table:** `admin-web/src/app/shop/[shopSlug]/stock/alerts/components/stock-alert-table.tsx`
- **NEW component:** `admin-web/src/app/shop/[shopSlug]/stock/alerts/components/stock-level-threshold-sheet.tsx`
- **Fetches:** existing `useStockAlerts`, `useStockAlertSummary`, `useWarehouseLookup`. NEW: `useUpdateStockLevel` mutation hook (if not yet present — check during Phase 2; if it exists, reuse).
- **Components used:** existing `Button`, `Badge`, `Spinner`, `DataTable`, `EllipsisVertical` dropdown — all `@godxjp/ui`. NEW: `Sheet` + `SheetTrigger` + `SheetContent` + `SheetHeader` + `Form` + `FormField` + `FormItem` + `FormLabel` + `Input` (number) + `Switch` — all `@godxjp/ui`.
- **Diff:** add a new dropdown item "Configure threshold" to each row's action menu. Clicking opens the `StockLevelThresholdSheet` pre-populated with the alert's StockLevel current values. On submit → `PUT /stock-levels/{stockLevelId}` → toast → invalidate `useStockAlerts` + `useStockAlertSummary` query keys.
- **Empty state:** unchanged (no alerts → empty state already exists)
- **Error state:** mutation error → toast "Failed to update threshold"
- **Loading state:** sheet submit button → `<Spinner>` while pending
- **Interactions:**
  1. User clicks `⋮` on an alert row → dropdown shows.
  2. User clicks "Configure threshold" → sheet opens from right.
  3. Sheet shows: warehouse name (read-only header), item name (read-only), current quantity (read-only), min_stock (editable number), max_stock (editable number), alert_enabled (switch).
  4. User edits values → "Save" button enabled.
  5. Click Save → mutation fires → on success: toast "Threshold updated", sheet closes, table refetches. If the alert auto-resolves, it disappears from the active filter view.
- **Form best practices:**
  - Single column layout (sheet is narrow).
  - Fields ordered: alert_enabled (master switch first), min_stock, max_stock (most-edited first after the master).
  - Validation: min_stock <= max_stock when both set; both >= 0.
  - Save/Cancel: sticky footer inside sheet. Save disabled when no changes.
  - Keyboard: Enter on form submits; Escape closes (`@godxjp/ui` Sheet default).
  - Inline description below min_stock: "Alert fires when quantity drops below this. Leave empty to disable."

#### S3 — Modified — `/shop/[shopSlug]/warehouses/[id]/settings`

- **Layout:** existing warehouse settings page
- **Page file:** `admin-web/src/app/shop/[shopSlug]/warehouses/[id]/settings/page.tsx` (verify exact path during Phase 2)
- **Diff:** add a new `<Switch>` for `allow_negative_sales` in the policy section, alongside the existing auto-approve toggles.
- **Label:** "Allow negative stock on sales" (en) / "売上時のマイナス在庫を許可" (ja) / "Cho phép tồn kho âm khi bán hàng" (vi)
- **Inline description:** "When enabled, sales-flow stock-out can drive quantity below zero. An out-of-stock alert fires immediately. Useful for high-volume restaurants where the kitchen can keep cooking past the recorded stock."
- **Components used:** existing `Switch`, `FormField`, `FormLabel`, `Form` (all `@godxjp/ui`)

### Admin UI prep tasks (convention #1)

Admin-web is already on `@godxjp/ui` (visible in existing alerts page). **Skip** the install/wire/copy-globals tasks.

## Sitemap

### Navigation diff

```
shop / [shopSlug] / stock
├── overview                       [unchanged]
├── levels                         [unchanged]
├── transactions                   [unchanged]
├── counts                         [unchanged]
├── alerts                         [MODIFIED] — new inline threshold action
├── disposals                      [unchanged]
└── settings                       [unchanged]

shop / [shopSlug] / warehouses / [id] / settings  [MODIFIED] — new allow_negative_sales toggle

hq / [brandSlug] / products / [id] / skus  [MODIFIED] — new inventory_mode field
```

### Entry points

| From              | Via                                           | To                            | Visibility   |
| ----------------- | --------------------------------------------- | ----------------------------- | ------------ |
| Alerts list row   | "..." action dropdown → "Configure threshold" | Sheet opens in-place (no nav) | Shop manager |
| Warehouse detail  | Settings tab                                  | Allow-negative toggle         | Org admin    |
| HQ Product detail | SKU tab → SKU edit                            | Inventory mode select         | HQ admin     |

### Breadcrumbs

| Screen                               | Crumbs                                                           |
| ------------------------------------ | ---------------------------------------------------------------- |
| `/shop/[s]/stock/alerts`             | Shop > {shopName} > Stock > Alerts (unchanged)                   |
| `/shop/[s]/warehouses/[id]/settings` | Shop > {shopName} > Warehouses > {whName} > Settings (unchanged) |
| `/hq/[b]/products/[id]`              | HQ > {brandName} > Products > {productName} (unchanged)          |

### Deep-link / back-link behaviour

- Sheet on alerts page: closing returns to the alerts list at the same scroll position. No URL change (sheet is unrouted).
- Cancel on warehouse settings: stays on settings page (no nav). Successful save: stays on settings page with toast.
- Cancel on SKU edit: returns to product detail (existing behaviour).

## Authorization matrix

### Roles involved

| Role key       | Display                | Source                   | Notes                                                                         |
| -------------- | ---------------------- | ------------------------ | ----------------------------------------------------------------------------- |
| `org-admin`    | Org Admin              | SSO role                 | Can change warehouse settings (allow_negative_sales)                          |
| `shop-manager` | Shop Manager           | SSO role                 | Can configure thresholds, approve transactions                                |
| `shop-staff`   | Shop Staff             | SSO role                 | Can view alerts, cannot configure threshold                                   |
| `hq-admin`     | HQ Admin / Brand Admin | SSO role                 | Can set ProductSku.inventory_mode; read-only cross-shop visibility (existing) |
| `manager`      | Warehouse Manager      | `warehouse_members.role` | Can approve transactions; can configure threshold on owned warehouse          |
| `staff`        | Warehouse Staff        | `warehouse_members.role` | Can submit transactions; read-only on threshold                               |

### Action × Role matrix

| Action                                  | org-admin             | shop-manager                    | shop-staff                      | hq-admin                  |
| --------------------------------------- | --------------------- | ------------------------------- | ------------------------------- | ------------------------- |
| Set `ProductSku.inventory_mode` (HQ)    | ❌ (out of scope)     | ❌                              | ❌                              | ✅                        |
| Update `StockLevel` threshold           | ✅                    | ✅ (own warehouse)              | ❌                              | ❌                        |
| Toggle `Warehouse.allow_negative_sales` | ✅                    | ❌                              | ❌                              | ❌                        |
| View stock alerts                       | ✅                    | ✅ (own shop)                   | ✅ (own shop)                   | ✅ (read-only cross-shop) |
| Receive stock alert notification        | ✅ (warehouse member) | depends on warehouse membership | depends on warehouse membership | ❌ (org-wide noise risk)  |

Legend: ✅ allowed · ❌ forbidden · ✅ (scoped) — allowed within scope

### Policy ↔ UI gate cross-check

| Action                                 | Backend gate                                                              | Frontend gate                                                                             |
| -------------------------------------- | ------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| Configure threshold (action menu item) | `StockLevelPolicy::update` requires `ChecksWarehouseContext::isManagerOf` | Action item hidden when `!hasPermission('stock-levels.update')` (existing context helper) |
| Toggle allow_negative_sales            | `WarehousePolicy::updateSettings` requires `isOrgAdmin`                   | Toggle disabled (read-only) when `!isOrgAdmin`                                            |
| Set inventory_mode                     | existing HQ product-sku update policy                                     | Field disabled when `!hasPermission('product-skus.update')`                               |

### Role-switch verification checklist

- [ ] As `shop-staff`, the "Configure threshold" action item is hidden on the alerts page (not just 403'd on click).
- [ ] As `shop-manager` of shop A, attempting `PUT /stock-levels/{id}` for a stock_level in shop B's warehouse → 403.
- [ ] As `shop-manager` (not org-admin), the allow_negative_sales toggle is disabled in the warehouse settings page.
- [ ] As `hq-admin`, the alerts page is read-only — no "Configure threshold" action item.

## User journeys

### Journey 1 — Shop Manager handles a low-stock alert via fast triage

**Persona:** Shop Manager for "Tokyo-Shibuya". Has 200 alerts in queue, mostly long-term low items. Wants to quickly raise the threshold on a category of materials that are deliberately running thin (seasonal dish ending tomorrow).

**Happy path:**

1. Logs in → navigates to Shop > Stock > Alerts.
2. Filters by warehouse "Kitchen" + type "low_stock".
3. Scans the list — sees 15 alerts. For each alert that should be deliberately ignored:
   - Clicks `⋮` → "Configure threshold".
   - Sheet opens. Sets `min_stock` to 0 (or unchecks `alert_enabled`).
   - Clicks Save.
   - Toast "Threshold updated". Sheet closes. Row disappears from active filter view (alert auto-resolved because `quantity >= 0`).
4. Done in 30 seconds per alert.

**Alternate path — alert auto-resolves with quantity unchanged:**

Setting `min_stock=0` when `quantity=2` auto-resolves the existing `low_stock` alert. The alert table refreshes; the row disappears.

**Edge / error paths:**

- **Validation failure** (min > max): inline error under min_stock field; Save button stays disabled.
- **Network error**: toast "Failed to update threshold". Sheet stays open with values preserved.
- **Auth mismatch** (token expired mid-session): mutation → 401 → app-level redirect to login.
- **Concurrent update**: another manager edited the same threshold simultaneously → 200 success on the second write (last-write-wins, acceptable for this slow-moving field).

### Journey 2 — Shop Manager configures `allow_negative_sales` for a busy weekend

**Persona:** Org Admin of brand "Foo Restaurants". Knows that Saturday evening the kitchen routinely runs past on-paper stock (deliveries arrive Monday). Wants to enable allow-negative for the kitchen warehouse so POS doesn't block.

**Happy path:**

1. Logs in → Shop > Warehouses > Kitchen → Settings tab.
2. Toggles "Allow negative stock on sales" on.
3. Clicks Save.
4. Toast "Settings saved".

**Alternate path — same flow, toggle off after the weekend.**

**Edge / error paths:**

- **As `shop-manager` (not org-admin)**: toggle is greyed out with tooltip "Org admin only".
- **Save fails (server validation)**: toast with reason.

### Journey 3 — HQ Admin marks an SKU as `track_stock`

**Persona:** HQ Admin / Brand Admin. They've just added a new SKU "Bottle of imported sake 720ml" — sold whole, not made-to-order. Wants stock tracking.

**Happy path:**

1. Logs in → HQ > Brand > Products > "Imported Sake" → SKU "720ml".
2. In the SKU edit form, finds the new "Inventory mode" select.
3. Changes from `Made to order` (default) to `Track stock`.
4. Save → toast.

**Edge / error paths:**

- **Save fails**: toast with reason.
- **Setting back to Made to order later**: existing `StockLevel` rows for this SKU are not deleted (just no longer drained on sale). Documented behaviour — non-destructive flip.

### Journey 4 — End-of-day order close fires Recipe → Material deduction

**Persona:** System (not a human). Triggered when a CustomerOrder transitions to `paid → closed`.

**Happy path:**

1. Payment service completes payment → calls `OrderClosingService::close($order)`.
2. Order locked, status set to closed.
3. For each non-voided `OrderItem` with `productSku.inventory_mode = track_stock`:
   - Existing SKU stock-out transaction is created (one combined transaction for all SKU items).
4. **NEW**: For each `track_stock` SKU, walk `productSku.recipe.ingredients`:
   - Compute total material qty = `ingredient.qty_per_serving × orderItem.quantity`.
   - Aggregate per material across all SKU items.
   - Build ONE combined `stock_out / sales_material_consumption` `StockTransaction` with N material items.
5. Auto-approve fires `completeTransaction` → FEFO splits per-lot rows → `StockLevel.quantity` decrements per lot.
6. If shortage AND warehouse has `allow_negative_sales=true`: quantity goes negative, `out_of_stock` alert fires + notification dispatched to warehouse managers.
7. If shortage AND `allow_negative_sales=false`: `InsufficientStockException` is raised, the entire `close()` `DB::transaction` rolls back, order stays `paid` not `closed`. Caller surfaces the error.

**Alternate path — recipe missing:**

- SKU has `inventory_mode=track_stock` but `productSku.recipe` is null → skip silently, log warning `"order-close: material deduction skipped: SKU {sku_id} has track_stock but no recipe"`. Order still closes (no exception).

**Alternate path — `made_to_order` SKU:**

- No SKU stock-out, no material deduction. Existing genealogy still runs (best-effort).

**Edge / error paths:**

- **NotificationService.dispatch throws**: caught by try/catch wrapper (per `ExpiryAlertService` pattern). Order close still succeeds.
- **Lot allocation finds zero lots**: FEFO falls back to legacy `lot_id=null` bucket (existing plan-017 behaviour). If even that is empty: shortage path kicks in (allow-negative or throw).

### Cross-journey checklist

- [x] Every happy-path step maps to at least one endpoint in the API inventory.
- [x] Every error path has a corresponding 4xx case or documented exception.
- [x] Every navigation step maps to a row in the Sitemap entry-points table.
- [x] Every role in the matrix is covered by at least one journey.

## Field lifecycle

### ProductSku

| Field            | Added?        | Default         | Displayed on screens | Editable on screens | Editable by roles | Validation                                 | Omnify prop                       |
| ---------------- | ------------- | --------------- | -------------------- | ------------------- | ----------------- | ------------------------------------------ | --------------------------------- |
| `inventory_mode` | **YES (NEW)** | `made_to_order` | S1 (SKU edit form)   | S1                  | hq-admin          | enum value in {made_to_order, track_stock} | EnumRef → ProductSkuInventoryMode |

### Warehouse

| Field                  | Added?        | Default | Displayed on screens    | Editable on screens | Editable by roles | Validation | Omnify prop |
| ---------------------- | ------------- | ------- | ----------------------- | ------------------- | ----------------- | ---------- | ----------- |
| `allow_negative_sales` | **YES (NEW)** | `false` | S3 (warehouse settings) | S3                  | org-admin         | boolean    | Boolean     |

### StockTransactionSubType (enum)

| Value                        | Added?        | Notes                                                                                                           |
| ---------------------------- | ------------- | --------------------------------------------------------------------------------------------------------------- |
| `sales_material_consumption` | **YES (NEW)** | Differentiates recipe-driven material deductions from manual material stock-outs in ledger filters and reports. |

### Orphaned field audit

Fields on `StockLevel`, `StockTransaction`, `StockAlert`, `Warehouse` that this plan does NOT touch (verified that none drift due to this plan):

| Field                                                   | Why not touched                                      | Currently editable at             | Acceptable?                                 |
| ------------------------------------------------------- | ---------------------------------------------------- | --------------------------------- | ------------------------------------------- |
| `Warehouse.auto_approve_stock_in/out/transfer/disposal` | Not in scope                                         | Settings page (existing)          | ✅                                          |
| `StockLevel.alert_enabled`                              | Already editable via existing PUT (no change)        | Levels detail + NEW alerts inline | ✅                                          |
| `StockAlert.acknowledged_at`                            | Not in current schema; not in scope                  | —                                 | ✅ (deferred — could be added in follow-up) |
| `StockAlert.notification_sent_at`                       | Not added; idempotency_key on dispatch handles dedup | —                                 | ✅                                          |

### Field lifecycle cross-check

- [x] Every "Editable on screens" cell has a matching input in that screen's "Components used" list.
- [x] Every "Displayed on screens" cell has a matching column/line in that screen's detail block.
- [x] Every NEW field is covered by at least one validation rule in an endpoint request body.
- [x] No `translatable: true` fields added; convention #2 N/A for this plan.
- [x] Every NOT NULL field without a default has an explicit Required entry — N/A, both new columns have defaults.

## Key decisions

### Decision 1 — `inventory_mode` is an enum (not a boolean)

- **Chose:** Enum `made_to_order | track_stock` on `ProductSku`.
- **Rejected:** Boolean `track_inventory` (Square pattern).
- **Why:** A future third mode (`assemble_to_order`, `non_stock_service`, etc.) is plausible. Enum is forward-compatible without a second migration. The web research surfaced ERPs (NetSuite, Restaurant365) using enums for the same reason, despite the Square boolean being simpler. Trade-off cost is minimal — admin-web select vs switch — but the future-proofing value is real.

### Decision 2 — `allow_negative_sales` lives on `Warehouse`, not `Product`

- **Chose:** Warehouse-level flag.
- **Rejected:** Per-product flag (Shopify `inventory_policy` pattern).
- **Why:** The locked user decision is "Cho phép tồn kho âm khi bán hàng" framed at the warehouse-policy level. Restaurants typically toggle this per location (Saturday rush at branch A but strict at branch B), not per ingredient. Per-product would multiply config complexity by `count(products)` for negligible benefit in this domain. We can add a per-product override later if needed without breaking the warehouse-level flag.

### Decision 3 — Recipe-missing on track_stock SKU = skip + warn (not block)

- **Chose:** Skip the material deduction silently, emit `Log::warning`.
- **Rejected:** Block the order close with 422.
- **Why:** Order close is in the critical path of the customer payment flow. Blocking it because a back-office data linkage (Recipe) is missing punishes the customer for the operator's misconfiguration. Skip + warn keeps the POS green and surfaces the gap in logs / observability dashboards. Future iteration could add a soft `StockAlert` of type `recipe_missing` if this becomes a real operational pain point — explicitly out of scope here.

### Decision 4 — Combined material transaction per order, not per SKU

- **Chose:** Aggregate all materials across all `track_stock` SKUs in the order → ONE `StockTransaction` with N item rows.
- **Rejected:** One `StockTransaction` per SKU (cleaner per-SKU audit trail).
- **Why:** Most orders have 2–10 items but share a small set of ingredients (rice, oil, etc.). Aggregating produces fewer rows in `stock_transactions` and `stock_movements`, and matches the existing SKU stock-out aggregation pattern in `OrderClosingService` (which also creates one combined transaction). Audit traceability is preserved via the existing `reference_type=CustomerOrder, reference_id=order.id` polymorphic link.

### Decision 5 — Notification audience = warehouse managers (not org-wide)

- **Chose:** Query `WarehouseMember` with `warehouse_id=affected_warehouse AND role='manager'`.
- **Rejected:** Org-wide (per `ExpiryAlertService`).
- **Why:** Stock alerts are operational, not strategic. A bell ping for every shop's low-stock event spamming HQ admins is noise. The `ExpiryAlertService` pattern is a known wart (its audience is over-broad and was flagged in plan-023 retro). Plan-024 sets the precedent for tighter audiences. The query is a one-liner: `WarehouseMember::where('warehouse_id', $warehouseId)->where('role', 'manager')->pluck('user_id')`.

### Decision 6 — Reuse `PUT /stock-levels/{id}` instead of a new endpoint

- **Chose:** Augment existing endpoint with auto-resolve side-effect.
- **Rejected:** New `POST /stock-alerts/{id}/configure-threshold` endpoint.
- **Why:** The thing being edited is the StockLevel's threshold, not the alert itself. The current endpoint already accepts these fields and is well-tested. Adding a side-effect inside the service (`reEvaluateActiveAlertForLevel`) is a smaller change than a new endpoint + controller + policy + tests. The alerts page UI just uses the existing service hook.

### Decision 7 — Material deduction uses plain `qty_per_serving × order_qty` (no `recipe_multiplier`)

- **Chose:** Plain formula `material_qty = ingredient.qty_per_serving × orderItem.quantity` for every ingredient row in `recipe.ingredients`. No multiplier.
- **Rejected:** Wait for plan-022's dual-BOM unification before shipping G3.
- **Why:** Plan-024 should not block on plan-022. The current Recipe schema stores `qty_per_serving` in the material's base unit, which is enough for a correct first pass. If plan-022 later introduces a `recipe_multiplier` or a different unit-conversion semantic, the formula in `OrderClosingService::close()` gets rewritten as part of that plan — single, localised change. This decision supersedes the conditional language in TESTS Edge 3.

### Decision 8 — Warehouse must have `auto_approve_stock_out=true` for material deduction to actually deduct

- **Chose:** Document this as an explicit operational requirement. No code change in plan-024 to force-override the flag.
- **Rejected:** Force `auto_approve=true` for the new `sales_material_consumption` subtype regardless of warehouse flag.
- **Why:** The auto-approve flag is the existing escape hatch for shops that want a human in the loop on every stock movement. Silently overriding it would surprise those shops. The honest behaviour is: if the warehouse has `auto_approve_stock_out=false`, the material `StockTransaction` lands in `submitted` state and waits for a manager — same as a manual stock-out today. Documented in `inventory-domain.md` + release notes. Risk and mitigation captured in the risk table below.

## Alternatives considered

- **Trigger material deduction at item-fired (KDS) instead of order-paid**: would catch shortage earlier but requires void-reversal logic. Locked user decision: trigger at order paid. Defer to follow-up if needed.
- **Block POS sale when stock=0 (`inventory_policy: deny` pattern)**: rejected — locked decision is allow-negative + alert. Less robust but matches restaurant operational reality.
- **Add `acknowledge` state to `StockAlert`**: the 3-state machine (active → acknowledged → resolved) the web research surfaced is the SaaS standard. Plan-024 keeps the existing 2-state (active → resolved) because there's no `acknowledged_at` column today and adding it expands scope. Document as a possible follow-up.
- **Use a dedicated `Job` (queued) for the material deduction step**: rejected — order close is already a synchronous DB::transaction. Adding async would create a window where SKU stock is decremented but material stock isn't, opening race conditions on subsequent order close. Synchronous is the safer choice.

## Risks & mitigations

| Risk                                                                                                                                                                | Likelihood | Impact            | Mitigation                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- | ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Existing SKUs default to `made_to_order` → shops that relied on implicit stock tracking see stock-outs stop firing                                                  | Medium     | Medium            | Release notes call this out clearly; provide a one-liner SQL/UI script for shops to bulk-set `inventory_mode=track_stock` for catalogue subsets. Document in `inventory-domain.md`.                                                                                                                                                                                                                                                               |
| Material deduction at order close adds DB write time → impacts close latency under load                                                                             | Low        | Low               | One transaction with N items adds ~1 INSERT + N INSERTS (movements/levels lock updates). Existing SKU stock-out already does this for N SKUs; doubling to ~2N is acceptable. Add a perf note + measure under sample load before merge.                                                                                                                                                                                                            |
| Recipe-missing skip + warn lets data quality silently degrade                                                                                                       | Medium     | Low (operational) | Add a counter metric `material_deduction_skipped_no_recipe` (log-derived). If counter rises, file a follow-up plan for `recipe_missing` stock alerts.                                                                                                                                                                                                                                                                                             |
| Notification spam if a warehouse has 1000+ low-stock alerts in a single batch (mass receive that empties inventory)                                                 | Low        | Medium            | Idempotency_key `stock_alert:{alert_id}` already dedupes per alert. Existing alerts don't re-fire. Notification template should be designed for batch context (could fold into a digest later — out of scope here).                                                                                                                                                                                                                               |
| `allow_negative_sales=true` on the wrong warehouse leads to silent negative stock going unnoticed                                                                   | Medium     | High              | The flag is org-admin-only (existing `updateSettings` policy). Add a clear inline description on the toggle. Audit log entry on flag change (existing pattern). Doc update in `inventory-domain.md`.                                                                                                                                                                                                                                              |
| Concurrent `OrderClosingService::close` calls on parallel orders consuming the same lot → race condition double-spend                                               | Low        | High              | `StockTransactionService::completeTransaction` already uses `StockLevel::lockForUpdate()` per-row. Inheriting this protection automatically. Verify with a Pest concurrency test (DB-locked dataset).                                                                                                                                                                                                                                             |
| Warehouse with `auto_approve_stock_out=false` → material `StockTransaction` lands in `submitted` and never deducts; ledger silently drifts until a manager approves | Medium     | High              | Document the requirement in `inventory-domain.md` + release notes: "auto-deduct of materials at order close requires `Warehouse.auto_approve_stock_out=true`." Add a Pest test asserting the `submitted` state when the flag is off, so the behaviour is at least pinned. Optional follow-up: a warning banner in admin-web warehouse settings when `auto_approve_stock_out=false` AND any SKU in the brand is `track_stock` (out of scope here). |

## Open questions

- [ ] **OQ-2 from README — notification template seed**: do `stock.alert.low` and `stock.alert.out` templates exist in seeded `NotificationTemplate`? Verify during Phase 2 sub-agent research; if missing, add seeder task to TASKS.md.
- [ ] **Phase 2 verify** — exact admin-web file paths:
  - SKU edit form (HQ): under `admin-web/src/app/hq/[brandSlug]/products/[id]/` — find the SKU section component.
  - Warehouse settings page: confirm `admin-web/src/app/shop/[shopSlug]/warehouses/[id]/settings/page.tsx` exists vs the toggle living in a different sub-route.
- [ ] **Phase 2 verify** — does `useUpdateStockLevel` mutation hook already exist? If yes reuse, if no create.

## References

- plan-017 DESIGN (đã archive — xem git history) — material lot tracking + FEFO foundation
- [plan-022/README.md](../plan-022/README.md) — known material correctness fixes (may or may not have landed by plan-024 merge)
- [plan-023/README.md](../plan-023/README.md) — notification platform completeness
- [material-system-deep-dive.md](../material-system-deep-dive.md) — full gap analysis behind plan-022 (informative for Recipe/Material cross-references)
- Web research (Phase 0.5a):
  - https://developer.squareup.com/docs/inventory-api/how-it-works — Square `track_inventory` semantics
  - https://help.shopify.com/en/manual/products/inventory/setup/selling-when-out-of-stock — Shopify `inventory_policy` per-variant flag
  - https://k-series-support.lightspeedhq.com/hc/en-us/articles/4407509542043 — Lightspeed par-level pattern
