# Plan 043 — Design

> ### ⚠️ Một thứ trong file này KHÔNG CÒN TỒN TẠI (đo lại 2026-08-07, #2049)
>
> **`ConsumptionTaxCalculator` đã bị xoá** (`916616ec2`) — và bị xoá vì
> **đúng lý do ngược lại** với cách plan này từng mô tả nó. Plan gọi nó là
> *"single source of truth"* cho hằng số STANDARD; commit xoá nó ghi rằng nó
> không còn caller nào và các hằng RATE_STANDARD/REDUCED/EXEMPT đã nằm inline.
> Đừng đi tìm hằng số ở đó, và đừng dựng lại nó.
>
> Phần còn lại của plan-043 (tax type phạm vi brand, chuỗi 4 tầng resolve,
> làm tròn một lần mỗi mức) thì ĐÃ SHIP và vẫn đúng — xem
> `docs/guide/tax-types.md`.


> ## ⚠️ SUPERSEDED IN PART — read this before trusting anything below
>
> The **two-rate tax type** (`rate_dine_in` / `rate_takeaway` chosen by
> `order_type`) was **removed on 2026-07-26 (#1099)**. A tax type is ONE rate;
> the MENU decides the consumption context. Every mention of it below is a
> record of what was built, **not an instruction**.
>
> Still true and still shipped: immutable per-line snapshots, rounding ONCE per
> rate group (インボイス), 総額表示 mode, service-charge rate, per-rate output,
> the workstation Go engine.
>
> Current truth: [`docs/guide/tax-types.md`](../../docs/guide/tax-types.md).

> Design decisions, approach, and trade-offs for [Tax Types — Japanese Consumption Tax (軽減税率 / インボイス)](README.md).
>
> **This plan had an external authoritative spec:** `TAX_FEATURE_PLAN.md` (superseded #1099, removed from the tree #2188 — see git history). It contains the full current-state audit (§3, with file:line references), 33 gap items (§5), the data design (§6), resolve logic (§7), the 6-step pricing algorithm (§8), rollout rules (§11) and Appendix A/B (affected tests / every money-display surface). This DESIGN.md holds the *contract* (API, screens, authz, field lifecycle) and the decision record; algorithmic detail is referenced by § number rather than duplicated.

## Context

@see TAX_FEATURE_PLAN.md (đã xoá #2188 — git history) — former spec: 5 audit rounds, locked decisions, algorithm, rollout order; every § reference below points here.
@see schemas/Backend/Product/ProductType.yaml — the exact template for TaxType (brand-scoped code+name entity, header comment style, per-property comments, unique [brand_id, code], RESTRICT-on-use idiom).
@see schemas/Backend/Shop/ShopOrderSetting.yaml — current home of `tax_rate` (BR-SOS05) + the `close_report_*` toggle family the new toggle joins; new settings fields land here.
@see backend/app/Services/Shop/EffectiveOrderPolicyService.php — brand→shop→default inherit-resolution + 5-min cache + invalidation hooks; TaxResolver's fallback tiers reuse this idiom (and its cache must be re-checked when `ShopOrderSetting` gains fields).
@see docs/explanation/split-by-items.md — split-by-items allocation & rounding contract that the per-rate rewrite must preserve (4 mirrors).
@see docs/guide/cashier-shift-recovery.md — plan-031/032 open-shift guard (409 `..._BLOCKED_OPEN_SHIFT`) and TillSession snapshot pattern reused for `prices_include_tax`.
@see docs/contributing/service.md — service-layer conventions (Omnify editable `*Service.php` siblings; seeders go through services).

## Approach

Adopt spec **Option C (Japanese standard)** — the only option that represents "bentō 8% / beer 10% in the same takeaway order" without breaking history:

1. **Model** — new brand-scoped `TaxType` entity with two rates (`rate_dine_in`, `rate_takeaway`). Products (including combos) reference a tax type via nullable FK; menu items may override; branch and brand defaults complete the chain (§7). No raw percentage is ever stored on a product.
2. **Snapshot** — when an item is added to an order, the resolver picks a tax type, selects the rate by `order_type`, and stamps `tax_type_id + tax_rate + tax_amount` onto the order line, exactly where `unit_price` snapshots today (`CustomerOrderService::addItems`). The order stamps `is_tax_included`. Later edits to tax types cannot alter history — this also kills the root cause of the invoice `tax_rate = 0` bug.
3. **Engine** — one 6-step pricing algorithm (§8) replaces the 4 divergent calculators: group lines by snapshot rate → coupon discount pro-rata per group → service charge with its own tax rate (whose tax **joins the breakdown group of the same rate** when rendering — gap #7) → tax per group rounded **once** (インボイス) in either excluded (additive) or included (extraction) mode → one rounding rule (half-up, currency step) → all derived flows (void/update/split/coupon/order-type-change/refund) operate per-line.
4. **Surfaces** — every output (receipt, shift report, Z-report PDF, VAT invoice, emails/PDF, revenue reports, all client previews) renders per-rate blocks; workstation syncs tax types + menu tax fields over the existing 5s pull and ports the engine to Go.
5. **Rollout** — additive-first (spec §11): cloud schema+engine keeps legacy `tax_rate` working in parallel → admin data entry → workstation release → outputs/previews → backfill-verified drop of `tax_rate` in the final phase. No feature flag exists; safety comes from deploy order + defensive defaults.

## Architecture

```
                 resolve (add-item time)                       compute (every mutation §3.4)
 ┌──────────────────────────────────────────────┐   ┌─────────────────────────────────────────┐
 │ MenuProduct.tax_type_id (override)           │   │ group items by snapshot tax_rate        │
 │   ↓ null                                     │   │   → coupon discount pro-rata per group  │
 │ Product.tax_type_id  (combo = product)       │   │   → service charge + its own tax rate   │
 │   ↓ null                                     │   │   → tax per group, rounded ONCE         │
 │ ShopOrderSetting.default_tax_type_id (branch)│   │     (excluded: add · included: extract) │
 │   ↓ null                                     │   │   → totals                              │
 │ Brand default (is_default / BrandOrderPolicy)│   └─────────────────────────────────────────┘
 └──────────────┬───────────────────────────────┘
                │ rate by order_type: spot|dine_in → rate_dine_in · takeaway → rate_takeaway
                ▼
   snapshot on customer_order_items (tax_type_id, tax_rate, tax_amount)
                │
   ┌────────────┼───────────────────┬──────────────────────┬────────────────────┐
   ▼            ▼                   ▼                       ▼                    ▼
 Cloud PHP   Workstation Go      pos-web preview      customer-web preview   Outputs
 OrderPricing computeOrderTotals order-cart /         checkout/payment/      receipt·shift report·
 Calculator + NormalizedTotals   split mirrors        summary mirrors        Z-PDF·invoice·email·
 recalculate  (SQLite mirror,                                                revenue reports
 Totals       sync DOWN tax_types, 5s pull)                                  (per-rate blocks)
```

New backend modules: `App\Services\Customer\TaxResolver` (chain §7), rewritten `OrderPricingCalculator` (§8), `TaxTypeService` (Omnify editable sibling), `TaxTypeController` + `TaxTypePolicy`, backfill migration + `orders:backfill-tax-snapshots` command. Workstation: `tax_types` SQLite table, pull-DOWN entry, Go engine port.

## Data model changes

| Table | Owner | Change | YAML schema file (if Omnify) |
|-------|-------|--------|------------------------------|
| `tax_types` (NEW) | Omnify | code (String 50, unique per brand), name (String 100, translatable), rate_dine_in + rate_takeaway (Decimal 5,2), is_default (Boolean) + generated `default_marker` col (`brand_id` when is_default else NULL) with `UNIQUE(default_marker)` = one default per brand at the DB layer (Decision 9), is_active (Boolean, default true), brand/organization FKs CASCADE, softDelete, indexes like ProductType | `schemas/Backend/Shop/TaxType.yaml` (NEW) |
| `products` | Omnify | + `taxType` ManyToOne, **nullable** (null = inherit), `onDelete: RESTRICT` | `schemas/Backend/Product/Product.yaml` |
| `menu_products` | Omnify | + `taxType` ManyToOne, **nullable** (null = inherit from product), `onDelete: RESTRICT`. Override lives at MenuProduct (brand-level), NOT MenuProductSku — Japanese tax is national, no per-branch need (§6.2) | `schemas/Backend/Product/MenuProduct.yaml` |
| `customer_order_items` | Omnify | + `taxType` ManyToOne nullable RESTRICT (snapshot ref), + `tax_rate` Decimal(5,2) nullable, + `tax_amount` Decimal(15,2) default 0 — immutable snapshots (§6.3) | `schemas/Backend/Product/CustomerOrderItem.yaml` |
| `customer_orders` | Omnify | + `is_tax_included` Boolean default false (snapshot at creation); keep `tax_amount` = Σ groups | `schemas/Backend/Product/CustomerOrder.yaml` |
| `shop_order_settings` | Omnify | + `defaultTaxType` ManyToOne nullable RESTRICT, + `prices_include_tax` Boolean default false, + `service_charge_tax_rate` Decimal(5,2) default 0, + `close_report_tax_breakdown` Boolean default true; **`tax_rate` dropped only in Phase 6** | `schemas/Backend/Shop/ShopOrderSetting.yaml` |
| `customer_invoices` | **manual** (plan-038 table is outside Omnify) | + `tax_breakdown` JSON (per-rate: rate → taxable, tax), + `seller_registration_number` String(14) nullable (T+13), items_json gains per-line tax; keep old columns for old rows | manual migration (allowed: non-Omnify table) |
| `till_sessions` | Omnify | + `prices_include_tax_at_open` Boolean nullable — tax-mode stamp at shift open (pattern: `default_currency_code`) | `schemas/Backend/Till/TillSession.yaml` |
| workstation `tax_types` (NEW) | SQLite | mirror table (id, code, name, rate_dine_in, rate_takeaway, is_default, is_active) | `workstation-app/internal/store/migrations/0xx_tax_types.sql` |
| workstation `order_items` | SQLite | + tax_type_id TEXT, tax_rate REAL, tax_amount INTEGER (DEFAULTs for old rows); update `repairLegacySchema()`; rebuilds must list new cols (migration-031 pattern) | SQLite migration |
| workstation `orders` | SQLite | + is_tax_included INTEGER DEFAULT 0 | SQLite migration |

Backfill (Phase 1, before any drop): for each brand, create one tax type per **distinct legacy branch `tax_rate`** (legacy is per-branch, tax types are per-brand — §5#15), mark the brand's most common as `is_default`, stamp each branch's `default_tax_type_id`.

## API surface

### Endpoint inventory

| # | Method | Path | Purpose | Auth | Route file |
|---|--------|------|---------|------|------------|
| 1 | GET | `/api/v1/hq/{brandSlug}/tax-types` | List tax types (paginated, q/status filter) | SSO + `TaxTypePolicy@viewAny` | `backend/routes/api/hq/catalog.php` |
| 2 | POST | `/api/v1/hq/{brandSlug}/tax-types` | Create tax type | SSO + `TaxTypePolicy@create` | 〃 |
| 3 | GET | `/api/v1/hq/{brandSlug}/tax-types/{taxType}` | Show one | SSO + `TaxTypePolicy@view` | 〃 |
| 4 | PUT/PATCH | `/api/v1/hq/{brandSlug}/tax-types/{taxType}` | Update | SSO + `TaxTypePolicy@update` | 〃 |
| 5 | DELETE | `/api/v1/hq/{brandSlug}/tax-types/{taxType}` | Soft-delete (guarded) | SSO + `TaxTypePolicy@delete` | 〃 |
| 6 | GET | `/api/v1/hq/{brandSlug}/tax-types/lookup` | Dropdown lookup (id, code, name, rates) | SSO + `viewAny` | 〃 |
| 7 | POST | `/api/v1/hq/{brandSlug}/tax-types/bulk-delete` | Bulk soft-delete (guarded per row) | SSO + `delete` | 〃 |
| 8 | POST | `/api/v1/hq/{brandSlug}/tax-types/{taxType}/restore` | Restore soft-deleted | SSO + `restore` | 〃 |
| 9 | POST | `/api/v1/hq/{brandSlug}/tax-types/{taxType}/toggle-status` | Toggle `is_active` | SSO + `update` | 〃 |
| 10 | PATCH | `/api/v1/shops/{shopSlug}/settings/order` | MODIFIED — accepts new tax settings fields; 409 guard | SSO (shop-scoped, existing) | existing |
| 11 | GET | `/api/v1/customer/branches` | MODIFIED — settings payload gains tax fields (additive) | public (existing) | existing |
| 12 | GET | `/api/v1/workstation/menu` | MODIFIED — + `tax_types[]` + per-item resolved `tax_type_id` (additive) | device token (existing) | `backend/routes/api/workstation.php` |
| 13 | — | Product store/update, MenuProduct update | MODIFIED — accept `tax_type_id` (Omnify-regen requests) | existing | existing |
| 14 | — | Order/checkout/payment responses | MODIFIED — per-line tax fields + per-rate totals in resources (additive) | existing | existing |

No CSV import/export routes for tax-types themselves (3–5 rows per brand — Decision 6); the **product** CSV gains a `tax_type_code` column instead.

### Endpoint detail

#### 1. GET `/api/v1/hq/{brandSlug}/tax-types`

- **Auth:** sanctum SSO + `ResolveBrandFromSlug` middleware + `TaxTypePolicy@viewAny` (org-scope via `belongsToUserOrg`, ProductType idiom)
- **Query params:** `page`, `per_page`, `q` (code/name search), `status` (active|inactive), `with_trashed`
- **Success (200):** paginated `data: [{id, code, name, rate_dine_in, rate_takeaway, is_default, is_active, products_count, created_at, updated_at}]` (Omnify `TaxTypeResource`)
- **Errors:** 401 unauthenticated · 403 brand not in user's org · 404 unknown brandSlug

#### 2. POST `/api/v1/hq/{brandSlug}/tax-types`

- **Auth:** SSO + `TaxTypePolicy@create`
- **Request body:**

  | Field | Type | Required | Notes |
  |-------|------|----------|-------|
  | code | string(50) | ✅ | unique per brand (422 on duplicate) |
  | name | object | ✅ | translatable top-level locale keys `{ja, en, vi}` (convention #2) |
  | rate_dine_in | decimal | ✅ | 0–100, 2dp — applied to `spot` + `dine_in` |
  | rate_takeaway | decimal | ✅ | 0–100, 2dp — applied to `takeaway` |
  | is_default | boolean | ❌ (false) | setting true atomically clears the previous brand default |
  | is_active | boolean | ❌ (true) | |

- **Success (201):** `{ "data": { ...TaxTypeResource } }`
- **Errors:** 422 `VALIDATION_ERROR` (missing/duplicate/range) · 401 · 403
- **Side effects:** audit_logs row (`tax_type.created`); workstation picks the row up on next 5s pull once sync DOWN ships.

#### 3–4. GET / PUT `/tax-types/{taxType}` — show returns the resource (200/404); update takes the same body as create (all fields optional), same validation, audit `tax_type.updated`. **Changing rates does NOT touch historical orders** (per-line snapshot) — rate edits propagate to future adds only.

#### 5. DELETE `/tax-types/{taxType}`

- **Guard:** 409 `TAX_TYPE_IN_USE` when referenced by any `products.tax_type_id`, `menu_products.tax_type_id`, or `shop_order_settings.default_tax_type_id` (plan-031/042 delete-guard idiom; DB backstop = RESTRICT FKs). Historical `customer_order_items` references also block hard-delete — recommended path is `toggle-status` (deactivate).
- **Success (204).** Audit `tax_type.deleted`.
- 409 body: `{ "code": "TAX_TYPE_IN_USE", "message": ..., "meta": {"products": n, "menu_products": n, "branch_defaults": n} }`

#### 6. GET `/tax-types/lookup` — lightweight `[{id, code, name, rate_dine_in, rate_takeaway, is_default}]` of active types, for product-sidebar / menu-editor / settings dropdowns.

#### 7–9. bulk-delete / restore / toggle-status — mirror the ProductType block (`catalog.php:23-31`) semantics; bulk-delete applies the #5 guard per row and returns per-row results; toggle-status flips `is_active` (deactivation blocks *new* assignment but keeps existing references valid).

#### 10. PATCH `/api/v1/shops/{shopSlug}/settings/order` (MODIFIED)

- **New accepted fields:** `default_tax_type_id` (uuid, nullable, must belong to the shop's brand + be active), `prices_include_tax` (bool), `service_charge_tax_rate` (decimal 0–100), `close_report_tax_breakdown` (bool). Legacy `tax_rate` stays accepted until Phase 6.
- **Guard:** changing `prices_include_tax` while any till of the org's shop has `current_session_id IS NOT NULL` → **409 `TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT`** (exact plan-031 pattern at `ShopOrderSettingsController.php:218-246`). Rate / default-type changes are allowed (Q6 default).
- **Side effects:** audit_logs row per changed tax field; `EffectiveOrderPolicyService` cache invalidation (existing booted hooks on the hand-written model — must be re-checked, §3.15).

#### 11. GET `/api/v1/customer/branches` (MODIFIED — additive, breaking-change care §5#17)

`settings` object gains `prices_include_tax`, `service_charge_tax_rate`, `default_tax_type` `{id, code, rate_dine_in, rate_takeaway}`; legacy `settings.tax_rate` key **stays until Phase 6** so already-deployed customer-web keeps working.

#### 12. GET `/api/v1/workstation/menu` (MODIFIED — additive)

Payload gains top-level `tax_types: [{id, code, name, rate_dine_in, rate_takeaway, is_default, is_active}]` and per menu-item `tax_type_id` (resolved MenuProduct→Product, null = use branch/brand default on the workstation side). Old workstations ignore unknown JSON fields (Go decode) — safe. `GET /workstation/branch` settings payload gains `prices_include_tax`, `service_charge_tax_rate`, `default_tax_type_id`, `close_report_tax_breakdown`. Downstream on the LAN: the workstation's **local menu API (Go)** re-exposes `tax_types` + per-item `tax_type_id` to pos-web/kiosk clients, and the SQLite `menu_items` mirror gains the column (004_menu.sql successor) — spec Phase 3 "local LAN menu API + struct/TS types".

#### 13. Product / MenuProduct writes (MODIFIED)

`tax_type_id` (uuid nullable) accepted on Product store/update (Omnify-regen FormRequest + editable Service) and on the MenuProduct update endpoint (inherit/override dropdown). Validation: must belong to same brand, must be active. Combo products are just Products — same field (一体資産 assignment is an ops rule, Q9).

#### 14. Read-model changes (MODIFIED, additive)

- `CustomerOrderItemResourceBase` (regen): + `tax_type_id, tax_rate, tax_amount`.
- `CustomerOrderResourceBase` (regen): + `is_tax_included`, + computed `tax_breakdown: [{rate, taxable, tax}]`.
- Hand-written `CustomerOrderDetailResource` (customer-web): same additions — **manual edit**.
- Workstation LAN `customer_order_shape.go`: items gain the 3 fields; order gains `is_tax_included` + breakdown; kiosk LAN keeps `tax_amount` (+ fixes hardcoded `"JPY"` → synced currency).
- Broadcast events `OrderItemAdded` / `OrderPaymentRecorded`: keep existing keys, semantics unchanged (additive only — consumers verified §3.19).
- `CustomerMenuService::transformMenu`: items gain `tax_type_id` + effective rates so customer-web can render 総額表示 tax-included prices.
- Invoice payloads gain `tax_breakdown` + `seller_registration_number`.

## Screens

### Screen inventory

| # | Path | Type | Auth | Purpose |
|---|------|------|------|---------|
| 1 | `/hq/[brandSlug]/tax-types` | NEW list+CRUD page | HQ SSO | Manage brand tax types (2-rate editor) |
| 2 | `/hq/[brandSlug]/products` (+ sidebar) | MODIFIED | HQ SSO | Tax-type column + dropdown in 分類 card |
| 3 | Menu item editor (menus section) | MODIFIED | HQ SSO | Inherit/override tax type per menu product |
| 4 | `/shop/[shopSlug]/settings` | MODIFIED | Shop SSO | Default tax type, included flag (409 UX), service-charge tax %, close-report toggle |
| 5 | `/shop/[shopSlug]/orders/[id]` | MODIFIED | Shop SSO | `OrderChargeSummary` per-rate breakdown |
| 6 | Product import dialog + CSV export | MODIFIED | HQ SSO | `tax_type_code` column |
| 7 | pos-web cart / split / debt / tab bar | MODIFIED | device | Per-rate preview (drops `taxRate` prop + `TAX_RATE` const) |
| 8 | customer-web checkout/payment/summary/history | MODIFIED | customer | Per-rate lines, tax-included display |
| 9 | godx-handy `OrderSummary` | MODIFIED | device | Per-rate lines |
| 10 | workstation `Settings.tsx` | MODIFIED | local | Remove legacy display-only taxRate box |

### Screen detail

#### 1. List page — `/hq/[brandSlug]/tax-types` (NEW)

- **Layout / page file:** `admin-web/src/app/hq/[brandSlug]/tax-types/page.tsx` — clone the `product-types` screen structure (`admin-web/src/app/hq/[brandSlug]/product-types/`), which is the repo's canonical brand-scoped lookup CRUD.
- **Fetches:** #1 list, #2/#4 create/update, #5/#7/#8/#9 row actions, via Omnify-generated `TaxTypeService`/hooks.
- **Components:** all `@godxjp/ui` — Table (code, name, rate columns "店内 10% / 持ち帰り 8%", default Badge, active Switch/Badge, row DropdownMenu), Button, Dialog/Sheet editor with Form+FormField, Skeleton rows, Alert on errors.
- **Translatable fields:** `name` → single `<Input translatable={{locales: {ja: '日本語', en: 'English', vi: 'Tiếng Việt'}}} />` (convention #2 — no per-locale cards). State per-field `Record<LocaleCode, string>`; payload = top-level locale keys.
- **Editor form:** code (immutable after create), name, `rate_dine_in` + `rate_takeaway` side-by-side numeric inputs (%, 2dp) with helper text "spot・dine_in / takeaway", is_default checkbox (warns it replaces current default), is_active.
- **Empty state:** "No tax types yet" + CTA + hint that the seeder ships 標準/軽減/非課税.
- **Error state:** toast on mutation failure; **409 `TAX_TYPE_IN_USE`** on delete → Alert dialog listing usage counts, offering "Deactivate instead".
- **Loading:** table Skeleton. **Form best practices** (convention #4): research task T2.0 feeds final layout; default single-column, labels on top, required-first, inline field errors, sticky footer Save/Cancel, Enter submits, Esc cancels.

#### 2–6, 7–10 (MODIFIED — diffs only)

- **Products list/sidebar:** new column after `type` (`products/page.tsx:353-360` pattern); dropdown in the 分類 card of `products/components/product-sidebar.tsx:85-100`, options from #6 lookup, first option "デフォルトを使用 (inherit)" = null. Combo products show a hint line about 一体資産 when components straddle tax types (warning only, Q9).
- **Menu item editor:** "Inherit from product / Override" select — clone `shop-set-service-type-dialog.tsx` UX (issue #463 idiom).
- **Shop settings:** new "税 / Tax" section — default tax type Select (lookup), `prices_include_tax` Switch (on 409 → Alert "close all shifts first", plan-031 UX), `service_charge_tax_rate` Input, `close_report_tax_breakdown` Switch next to its 4 siblings. Legacy tax_rate input stays visible until Phase 6.
- **Order detail:** `order-charge-summary.tsx:71-76` single tax line → per-rate lines (`10%対象 … / 内消費税 …`) + "included/excluded" mode chip from `is_tax_included`.
- **CSV:** import dialog + export handler (`products/page.tsx:239-268`) add `tax_type_code`.
- **pos-web:** `order-cart.tsx` (draft 945-957, read-only 997-1006) render per-rate summary from server/local engine; delete `lib/totals.ts` TAX_RATE const + helpers; `person-card.tsx` + `PersonBill` type + both split dialogs per-rate; `debt-search-dialog.tsx`, `pos-tab-bar.tsx` follow the shape change; settings editor adds the `close_report_tax_breakdown` toggle beside the existing four close-report toggles.
- **customer-web:** `checkout-review-page.tsx`, `checkout-page.tsx`, `checkout-page-mobile.tsx`, `payment-view.tsx`, `summary-view.tsx`, `account-order-detail-view.tsx` per-rate lines; `context/brand-context.tsx:102` (the tax data source for every preview) swaps `branch.tax_rate` for the new settings fields; menu prices show tax-included figures (総額表示) from the new menu payload.
- **godx-handy:** `OrderSummary.tsx:19-31` + `types/pos.ts` + i18n — per-rate lines; must tolerate old payloads (fields optional).
- **workstation Settings.tsx:** remove the display-only taxRate box (legacy cleanup, spec PHẦN 4).

### Admin UI prep tasks (convention #1)

admin-web is already on `@godxjp/ui` + `<UIProvider>` + tokenized `globals.css` — **skip install/wiring tasks**, reference the existing setup.

## Sitemap

### Navigation diff

```
HQ /hq/[brandSlug]
└── Catalog group (sidebar)
    ├── Products                      [MODIFIED] → tax-type column + sidebar dropdown
    ├── Product Types
    └── Tax Types                     [NEW] → /hq/[brandSlug]/tax-types
Shop /shop/[shopSlug]
    ├── Settings                      [MODIFIED] → Tax section (default type, included flag, SC tax %, report toggle)
    └── Orders / [id]                 [MODIFIED] → per-rate charge summary
```

### Entry points

| From | Via | To | Visibility |
|------|-----|----|------------|
| HQ sidebar (catalog group) | "Tax Types" nav item | `/hq/[brandSlug]/tax-types` | HQ SSO users of the org |
| Product sidebar 分類 card | lookup dropdown (+"manage" hint) | `/hq/[brandSlug]/tax-types` | HQ SSO |
| Shop settings tax section | default-type Select | (in place) | Shop SSO |

### Breadcrumbs

| Screen | Crumbs |
|--------|--------|
| Tax Types list | HQ › {brand} › Tax Types |

### Deep-link / back-link behaviour

Editor Dialog/Sheet closes back to the list (no route change); unknown `brandSlug` → existing HQ 404 boundary; cancel discards; successful save toasts + refreshes list query. Settings 409 keeps the form open with the previous value restored.

## Authorization matrix

### Roles involved

| Role key | Display | Source | Notes |
|----------|---------|--------|-------|
| hq_user | HQ SSO user (org member) | SSO / `belongsToUserOrg` | ProductType-idiom org scope; **no finer role check exists today** (Q11) |
| shop_user | Shop SSO user | SSO shop scope | settings + order views |
| pos_device | POS via workstation LAN / device token | device pairing | rings orders, reads previews |
| ws_device | Workstation device token | pairing | sync UP/DOWN only |
| customer | Public customer | session/public | menu, checkout, own orders |

### Action × Role matrix

| Action | hq_user | shop_user | pos_device | ws_device | customer |
|--------|---------|-----------|------------|-----------|----------|
| TaxType CRUD (#1–9) | ✅ (org-scoped) | ❌ | ❌ | ❌ | ❌ |
| Assign tax type to Product / MenuProduct | ✅ (org-scoped) | ❌ | ❌ | ❌ | ❌ |
| Change tax settings fields (#10) | ❌ | ✅ (shop-scoped) | ❌ | ❌ | ❌ |
| Flip `prices_include_tax` with open shift | ❌ (409) | ❌ (409) | — | — | — |
| Read tax types via menu/branch payloads | ✅ | ✅ | ✅ (scoped) | ✅ (scoped) | ✅ (public subset) |
| See per-rate breakdown on order surfaces | ✅ | ✅ | ✅ | ✅ | ✅ (own order) |

### Policy ↔ UI gate cross-check

| Action | Backend gate | Frontend gate |
|--------|--------------|---------------|
| TaxType CRUD | `TaxTypePolicy` (org scope) + `ResolveBrandFromSlug` | Nav item only in HQ layout (brand context) |
| Delete in-use tax type | 409 `TAX_TYPE_IN_USE` + RESTRICT FK | Alert dialog with usage counts, "Deactivate instead" |
| prices_include_tax mid-shift | 409 `TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT` | Settings switch shows blocking Alert on 409 |
| Cross-brand tax_type_id on product/settings | FormRequest brand-ownership rule (422/403) | Lookup only lists own brand's types |

### Role-switch verification checklist

- [ ] shop_user sees no Tax Types nav (HQ-only) — not just a 403.
- [ ] pos_device / customer cannot reach any tax-types route (401/403).
- [ ] Cross-org brandSlug on every tax-types endpoint → 403 (Pest per endpoint).
- [ ] Settings PATCH scoped by shopSlug — foreign shop → 403.

## User journeys

### Journey 1 — HQ admin sets up Japanese tax types

**Persona:** HQ catalog admin, brand with legacy 10% single rate, goal: become 軽減税率-correct.

**Happy path:** (1) HQ sidebar → Tax Types → sees 3 seeded/backfilled types. (2) Verifies 標準 10/10, 軽減 10/8, 非課税 0/0; edits names via locale tabs. (3) Products list → filters food → sidebar 分類 card → picks 軽減; beer products → 標準; done when every non-default product is assigned (rest inherit branch default).
**Alternate:** brand with no legacy rate → empty state → "create from template" via seeder values.
**Edge/error:** duplicate code → inline 422 under the code field; delete in-use type → 409 alert with counts + deactivate CTA; cross-org brand URL → 403 page.

### Journey 2 — Cashier rings a mixed takeaway order (the proof case §1.2)

**Persona:** POS staff; takeaway order: 1 bentō + 1 canned beer.

**Happy path:** (1) Create order, type=takeaway. (2) Add bentō → line stamps 8%; add beer → line stamps 10%. (3) Cart summary shows `8%対象 / 10%対象` groups + per-group tax. (4) Checkout + cash → receipt prints both groups, ※ on the bentō line, single tax line per rate, totals match cloud to the yen.
**Alternate:** same order switched to dine-in mid-way → all lines re-resolve to 10% and totals recompute (§7).
**Edge/error:** workstation offline → Go engine computes identical figures from synced tax_types; coupon applied → discount split pro-rata across the two groups; void the beer → only the 10% group shrinks.

### Journey 3 — Shop manager flips tax-included pricing

**Persona:** Shop manager adopting 総額表示.

**Happy path:** (1) Shop Settings → Tax section. (2) Toggles `prices_include_tax` with all shifts closed → saves; new orders snapshot included-mode and clients show tax-inside totals.
**Alternate:** sets `service_charge_tax_rate` = 10 → next checkout taxes the service charge separately (§8 step 3).
**Edge/error:** a till session is open → 409 → Alert "close/settle all shifts first" (links to till dashboard); flag unchanged.

### Cross-journey checklist

- [ ] Every happy-path step maps to an endpoint in the inventory (#1–14).
- [ ] Every error path has a 4xx in the endpoint detail (422 duplicate/range, 409 in-use, 409 mid-shift, 403 scope).
- [ ] Navigation steps map to Sitemap entry points.
- [ ] Every role is covered by a journey or is passive-visibility (pos_device/ws_device/customer consume read-only payloads — Journey 2).

## Field lifecycle

### TaxType (NEW)

| Field | Added? | Default | Displayed on | Editable on | Editable by | Validation | Omnify prop |
|-------|--------|---------|--------------|-------------|-------------|------------|-------------|
| code | ✅ | — | list, editor, CSV | editor (create only) | hq_user | required, ≤50, unique/brand | String length:50 |
| name | ✅ | — | list, editor, dropdowns | editor | hq_user | required ja (locale fill) | String translatable |
| rate_dine_in | ✅ | — | list, editor, receipts (derived) | editor | hq_user | required, 0–100, 2dp | Decimal 5,2 |
| rate_takeaway | ✅ | — | 〃 | editor | hq_user | required, 0–100, 2dp | Decimal 5,2 |
| is_default | ✅ | false | list badge | editor | hq_user | one per brand (DB-enforced: `UNIQUE(default_marker)` + brand row-lock, Decision 9) | Boolean |
| is_active | ✅ | true | list, toggle | toggle-status | hq_user | — | Boolean |

### Product / MenuProduct

| Field | Added? | Default | Displayed on | Editable on | Editable by | Validation | Omnify prop |
|-------|--------|---------|--------------|-------------|-------------|------------|-------------|
| Product.tax_type_id | ✅ | null (inherit) | products list column, sidebar, CSV | product sidebar, CSV import | hq_user | exists+same-brand+active | Association nullable RESTRICT |
| MenuProduct.tax_type_id | ✅ | null (inherit) | menu item editor | menu item editor | hq_user | 〃 | Association nullable RESTRICT |

### ShopOrderSetting

| Field | Added? | Default | Displayed on | Editable on | Editable by | Validation | Omnify prop |
|-------|--------|---------|--------------|-------------|-------------|------------|-------------|
| default_tax_type_id | ✅ | null | shop settings | shop settings | shop_user | same-brand+active | Association nullable RESTRICT |
| prices_include_tax | ✅ | false | shop settings, order surfaces (mode chip) | shop settings | shop_user | bool + **409 open-shift guard** | Boolean |
| service_charge_tax_rate | ✅ | 0 | shop settings | shop settings | shop_user | 0–100 | Decimal 5,2 |
| close_report_tax_breakdown | ✅ | true | shop settings, pos-web settings | shop settings + pos-web settings editor | shop_user (+ pos device via LAN settings endpoint) | bool | Boolean |
| tax_rate | ❌ (dropped P6) | 0 | (until P6) | (until P6) | shop_user | — | removed in Phase 6 |

### CustomerOrder / CustomerOrderItem (snapshots — written by engine, never user-editable)

| Field | Added? | Default | Displayed on | Editable | Validation | Omnify prop |
|-------|--------|---------|--------------|----------|------------|-------------|
| order.is_tax_included | ✅ | false | order detail, receipts, clients | ❌ system-stamped at create | — | Boolean |
| item.tax_type_id | ✅ | null | (join for labels) | ❌ engine-stamped | — | Association nullable RESTRICT |
| item.tax_rate | ✅ | null | order detail lines, receipts (※) | ❌ engine-stamped | — | Decimal 5,2 nullable |
| item.tax_amount | ✅ | 0 | breakdown groups | ❌ engine-stamped | — | Decimal 15,2 |
| order.tax_amount | existing | 0 | everywhere today | ❌ = Σ per-rate groups (semantics preserved) | — | unchanged |

### CustomerInvoice (manual table) / TillSession

| Field | Added? | Notes |
|-------|--------|-------|
| invoice.tax_breakdown | ✅ | JSON `[{rate, taxable, tax}]`; old rows keep legacy columns |
| invoice.seller_registration_number | ✅ | T+13 snapshot slot — column in Phase 1 only (Q5). **The seller-side SOURCE field (settings storage) + entry UI are explicitly deferred to a follow-up plan**; until then the column stays null and receipt/invoice templates render the T13 slot only when a value is present |
| invoice.items_json[].tax_* | ✅ | per-line snapshot copied from order items |
| till_session.prices_include_tax_at_open | ✅ | stamped at open (currency-snapshot pattern) |

### Orphaned field audit

| Field | Why not touched | Currently editable at | Acceptable? |
|-------|-----------------|-----------------------|-------------|
| ShopOrderSetting.service_charge_rate | rate itself unchanged; only its *tax* is new | shop settings | ✅ |
| customers.tax_code | buyer's VAT/MST id — invoice recipient concern, not rate resolution | customer form | ✅ |
| CustomerOrder.discount_amount / coupon fields | consumed by step 2 allocation, not redefined | coupon flow | ✅ |
| Menu.service_type | menu visibility filter, ≠ order_type (§3.5) | menu editor | ✅ |

### Field lifecycle cross-check

- [ ] Every editable cell has a matching input in a screen detail block.
- [ ] Every displayed cell has a matching line/column in a screen or output surface.
- [ ] Every NEW writable field has a FormRequest rule (#2, #10, #13).
- [ ] `name` uses `<Input translatable />` (convention #2).
- [ ] NOT NULL w/o default: `code`, `name`, both rates → required in the request table (#2). Snapshot columns are nullable/defaulted → old rows safe.

## Key decisions

### Decision 1 — Two-rate tax type chosen at order time (spec locked #1)
**Chose:** TaxType carries `rate_dine_in` + `rate_takeaway`; the number is picked only when `order_type` is known. **Rejected:** single % on product; % per order type. **Why:** only representation covering "bentō 8% takeaway / beer always 10%" in one order (§1.2 proof case).

### Decision 2 — Global tax-included flag at shop settings, snapshotted per order (locked #2); service charge taxed with its own configurable rate (locked #3); FK on Product not SKU, toppings follow parent, MenuProduct override nullable=inherit (locked #4); `spot` taxed as dine-in (locked #5).
As settled by the product owner — recorded here so executors don't reopen them.

### Decision 3 — Immutable per-line snapshot
**Chose:** stamp resolved type/rate/amount on each line at add-time; re-resolve only on order_type change. **Rejected:** joining live tax_types at read time. **Why:** rate edits must never rewrite history (invoice bug is the cautionary tale, §6.3); offline mirror needs self-contained rows.

### Decision 4 — One rounding rule: half-up at currency step, once per rate group
**Chose:** `RoundingMode::step()`-based half-up shared by PHP/Go/TS. **Rejected:** keeping the 3 existing rules. **Why:** インボイス mandates once-per-rate rounding (§1.3); today's divergence is bug #4.

### Decision 5 — Deactivate over delete
**Chose:** RESTRICT FKs everywhere + 409 guard; `toggle-status` is the operational path once a type has been used. **Rejected:** SET NULL on historical items (loses labels), hard delete. **Why:** compliance data; historical joins must survive.

### Decision 6 — No CSV import/export for tax-types themselves
**Chose:** skip `import/export` routes from the ProductType block clone; keep lookup/bulk-delete/restore/toggle-status. **Why:** 3–5 rows per brand, seeded; CSV value is on *products* (`tax_type_code` column, gap #29). Revisit if a multi-brand rollout needs it.

### Decision 7 — Backfill before drop, per-branch defaults
**Chose:** Phase 1 creates tax types from distinct legacy per-branch rates, stamps `default_tax_type_id` per branch; `tax_rate` drops only in Phase 6 after verification. **Why:** legacy rate is per-branch, tax types are per-brand (§5#15); old workstations must keep working mid-rollout (§11).

### Decision 8 — No per-rate aggregate columns on `till_sessions`; breakdown-toggle scope
**Chose:** shift/Z reports derive per-rate figures from order-item snapshots at report time; `till_sessions` gains only the `prices_include_tax_at_open` stamp. `close_report_tax_breakdown` gates the **thermal** close report only — the backend Z-report PDF always includes the breakdown (it's the audit document). **Rejected:** denormalized per-rate tax columns on `till_sessions` (spec §5#11 "consider"). **Why:** snapshots make aggregates derivable and always consistent; denormalized aggregates create a second source of truth to reconcile. Revisit only if report queries become measurably hot.

### Decision 9 — Brand-default tier lives on `TaxType.is_default`, NOT on BrandOrderPolicy
**Chose:** resolver tier 4 (§7) = the brand's `TaxType` row with `is_default = true` (single-per-brand); `BrandOrderPolicy` gains **no** tax field in this plan.

**Single-default is DB-enforced, not just service-enforced.** Service-level "set true atomically clears the previous default" is necessary but insufficient — two concurrent create/update requests can each clear-then-set and commit **two** `is_default = true` rows, making tier 4 pick nondeterministically and silently mis-tax every inherit-chain order for the brand. Two guards, both required:
1. **DB uniqueness.** MySQL has no partial/filtered indexes, so add a generated marker column `default_marker` = `brand_id` when `is_default = true` else `NULL`, with a plain `UNIQUE(default_marker)` index (NULLs don't collide → non-defaults are unconstrained; at most one default per brand can ever commit). A concurrent second setter hits a duplicate-key error and rolls back instead of creating a phantom second default.
2. **Serialized flip.** `TaxTypeService::setDefault()` runs the clear-old + set-new inside one transaction that first takes a row lock on the parent brand (`Brand::whereKey($brandId)->lockForUpdate()` / `SELECT … FOR UPDATE`), so concurrent flips serialize rather than deadlock-racing on the unique index. The lock also covers the read-modify-write when toggling a default OFF.

This turns "two brand defaults" from a possible silent-mis-tax state into an impossible one at the storage layer. A concurrency test (parallel `is_default=true` writes → exactly one survives) gates the slice. **Rejected:** a `BrandOrderPolicy.default_tax_type_id` column (spec §3.6/§6.4 named it as a candidate "nếu cần"). **Why:** one source of truth for the brand default — two competing brand-level defaults would need their own precedence rule; the *branch* default stays on `ShopOrderSetting.default_tax_type_id` (null = fall through to tier 4). If a future plan needs per-brand policy overrides, BrandOrderPolicy remains available.

## Alternatives considered

Spec PHẦN 9: **A** settings-only two rates (can't do a per-item rule — rejected), **B** single-rate tax types per original note (can't solve item×order-type conflict — insufficient), **C** Japanese-standard two-rate + snapshots + grouped rounding (**chosen**), **D** generic ERP tax-rule engine with date ranges (over-engineering for restaurants — rejected until真 multi-country need).

## Risks & mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| A 5th calculator surface missed → figures drift | Medium | High | Appendix B is the exhaustive surface table (30-file grep, audit round 5); TESTS cross-check every row; shared fixtures assert cloud=Go=TS to the yen |
| Old workstation + new cloud (or reverse) breaks | Medium | High | Additive-only payloads; Go zero-value defaults → fall back to default tax type; deploy order §11; no renames of `tax_amount` |
| Backfill mis-maps per-branch rates → brand types | Low | High | Backfill is idempotent + logged; drop deferred to Phase 6 behind explicit verification task; `--dry-run` on the in-flight command |
| Rounding regressions vs. today's receipts | High (expected) | Medium | Intentional: 3 rules → 1; golden-file tests define the new truth; release note for operators |
| Seed direction wrong (note reversed vs law) | Low | High | Open Q1 blocks the seeder task until customer confirms |
| 52-case split test rewrite hides a behaviour change | Medium | Medium | Port cases 1:1 first (same inputs, recomputed expectations), then add mixed-rate cases; backend/TS mirrors share scenario tables |
| `EffectiveOrderPolicyService` cache serves stale tax defaults | Low | Medium | Reuse existing invalidation hooks; add hooks for new fields + Pest coverage |

## Open questions

Tracked in [README.md — Open questions](README.md#open-questions) (Q1, Q3, Q4, Q6–Q13 with adopted defaults). Q1 (seed direction) is the only one that must be answered by the **customer**; all others have defaults an executor can build against.

## References

- `TAX_FEATURE_PLAN.md` (đã xoá #2188 — git history) — §1 Japanese standard · §3 current state (file:line) · §5 33 gaps · §6 data design · §7 resolver · §8 engine · §10 phases · §11 rollout · Appendix A tests · Appendix B surfaces
- 国税庁 軽減税率 / 適格簡易請求書 requirements (summarized in spec §1.1–1.3)
- plan-031/032 (guards), plan-036 (Z-report), plan-038 (invoice), plan-021/033 (split-by-items), issue #463 (nullable inherit idiom)
