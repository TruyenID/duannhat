# Plan 024 — Notes

> Working log for [Stock Management — Auto-Deduct, Inventory Mode, Alert Notifications](README.md). Append-only. Newest entries on top.

Use this file for:
- Decisions made during execution (with reasoning)
- Blockers and how they were resolved
- Context discovered while researching
- Links to relevant code, PRs, conversations

---

## 2026-05-21 — Recipe→SKU output coverage + Production Order auto-derive + pre-made guard

User asked the work to "cover product recipes" (recipe form chỉ chọn Material làm output) and run the full Product → Recipe → ProductionOrder → Lot lifecycle end-to-end. Result landed as one commit (`7adf3378 feat(recipes/production): cover Product-SKU recipes end-to-end`). Highlights:

### Recipe output side now supports Product SKUs
- `/hq/.../recipes/new` + `/recipes/[id]` got an output-type segmented control (Material | Product SKU). SKU mode lets HQ pick N variants via combobox + chip list; on save the service writes `productSku.recipe_id` for each, detaches anything that used to point at this recipe but isn't in the new list.
- BE: `RecipeStoreRequest` / `RecipeUpdateRequest` accept `sku_ids: uuid[]`. `RecipeService::create()/update()` extract that list and call a new `syncOutputSkus()` helper. Cross-brand SKU rejected with 422. `assertReadyForApproval()` now allows submission when the recipe has either a `material_id` OR ≥ 1 attached SKU (previously required material).
- `RecipeResource` emits a compact `skus[]` shape (id + sku + name + product.name) so the FE can paint chips after save without a second round-trip.
- Tests: [tests/Feature/Product/RecipeSkuAttachmentTest.php](../../backend/tests/Feature/Product/RecipeSkuAttachmentTest.php) — 6 cases (create attach, create empty, create cross-brand reject, update attach+detach, update clear-all, update preserve-when-omitted) all green.

### SKU edit Recipe picker visible at every inventory_mode
- Was hidden behind `inventoryMode === "track_stock"` (Plan-024 UX-2). That gating was wrong because Recipe also drives ProductionOrder auto-derive, allergen rollup, and the kitchen BOM regardless of stock tracking. Now the Recipe combobox is always present, and `recipe_id` survives an inventory_mode switch.
- `/hq/.../products/[id]/skus/[skuId]` cleaned up the comment + payload — no more `inventoryMode === "track_stock" ? recipeId : null` filter.

### ProductionOrder auto-derive items from Recipe
- `/shop/.../production/orders/new` only sends warehouse + output_variant + planned_quantity + output_unit (the FE never collected `items` or `recipe_multiplier`). Service now backfills both:
  - `recipe_multiplier` ← `productSku.recipe_multiplier ?? 1` (and the BE request rule moved from `required` → `sometimes`).
  - `items[]` derived from `outputVariant.recipe.ingredients` using `(ingredient.qty / recipe.output_quantity) × planned_quantity × sku.recipe_multiplier` — same scaling as plan-024 Decision 7 / OrderClosingService.
- Default-SKU rows render properly in the variant picker: label changed from `"SKU (SKU)"` to `"Product › Variant (SKU)"` with fallbacks.
- The `output_unit` field is shown but **locked** — auto-fills from `recipe.output_unit`. Lets the operator know the canonical unit without giving them a typo-prone edit point. BR-01 of ProductionOrder requires the SKU to have a recipe; the FE refuses Save when the SKU has none.
- `planned_quantity` ships with a recipe-multiple hint: `"= 2× recipe (1 mẻ = 10 piece)"`. Disambiguates from `recipe.output_quantity` for operators who confused the two.
- Tests: [tests/Feature/Inventory/ProductionOrderAutoDeriveItemsTest.php](../../backend/tests/Feature/Inventory/ProductionOrderAutoDeriveItemsTest.php) — 4 cases (auto-derive happy, recipe_multiplier scaling, recipe-less SKU empty, caller-supplied items override) all green.

### ProductionOrder NVL deduction moved from `complete()` → `start()`
- The dialog "Bắt đầu sản xuất? Nguyên liệu sẽ được trừ khỏi kho" and the schema doc both promised that NVL drops at the **start** of production; the code was deducting only at `complete`. Operators reporting status mid-production saw drifted inventory.
- `ProductionOrderService::start()` now opens a DB transaction, emits a `stock_out` for the planned items, links it to the order, then flips status to `in_progress`. `complete()` only emits the SKU `stock_in` and stamps timestamps. `start()` signature gained `$startedById: string` — controller passes `$request->user()->id`.
- Tests: `ProductionOrderTest` got a new `describe('start')` block asserting (a) `stock_out` lands at start, (b) `stock_in` is still NULL at that point, (c) material `StockLevel` decremented by planned qty. The existing `describe('complete')` test now seeds the start-time `stock_out` before calling complete, matching the new ordering.

### Pre-made SKU double-deduct guard (NOTES.md 2026-05-21 option 1 — now IMPLEMENTED)
- `OrderClosingService::emitMaterialConsumptionTransaction()` got a per-item guard: snapshot post-sale `StockLevel(SKU, warehouse)`, reconstruct pre-sale qty as `post_sale + sold`, and skip the recipe walk when pre_sale > 0 AND a StockLevel row exists. Missing row → never-stocked SKU → recipe still deducts (canonical Plan-024 G3 path).
- Tests: [tests/Feature/Customer/OrderClosingPreMadeSkipRecipeTest.php](../../backend/tests/Feature/Customer/OrderClosingPreMadeSkipRecipeTest.php) — 4 cases matching scenarios (a)/(b)/(c)/(d) recorded below all green.
- Existing `OrderClosingMaterialDeductionTest` + `OrderClosingVoidedItemMaterialTest` adjusted: warehouses switched to `allow_negative_sales: true` and SKU stock seeds dropped to 0 — that's the canonical "made-to-order, no upstream batch" setup the existing tests were modeling (the prior 50/100 stock seeds were just there to satisfy Phase 1, not because the SKU was pre-made). 11 + 1 tests pass post-adjust.

### UX fixes from the E2E session
- `/hq/.../products/new` required `cost_price`: a marker (`*`), inline error message, and a client-side guard inside `handleSubmit` so the operator gets a friendly red note instead of the raw `SQLSTATE[23000] cost_price cannot be null` toast when they forget the field.
- `i18n` keys updated in ja/en/vi for all new strings (output_kind, output_skus, output_unit, planned_qty hints, common.remove, etc.).

### What's still open

- Multi-locale label support in `RecipeResource::skus[].product` (currently returns the default-locale name only; would need to honor `Accept-Language`).
- ProductionOrder cancel-after-start should reverse the `stock_out` — current `cancel()` only allows Draft/Pending → Cancelled. Out of scope for this pass.
- Decision 8 (per-shop auto-approve gates) — the new pre-made guard doesn't interact with the approval state machine because skipped recipes never create a transaction. No change needed but worth noting if a future flag inverts the heuristic.

---

## 2026-05-21 — Design gap surfaced during user Q&A — pre-made SKU double-deduct

User asked: *"if track_stock SKU is pre-made (cooked ahead of time) and 2 units remain unsold at end of day, is the wasted material tracked?"*

Walking through the code revealed a real correctness gap when **Production Batch flow combines with track_stock + recipe**. Not in plan-024 scope to fix — recorded for a follow-up plan.

### Three legitimate ways material gets consumed today

| Path | Where it lands | Trigger |
|---|---|---|
| Sales — order close | `OrderClosingService::close()` step 2 ("emitMaterialConsumptionTransaction") | `track_stock` AND `made_to_order` SKUs alike, if `recipe_id` is set |
| Pre-made production | `ProductionBatchService::start()` | Manual batch start. Trừ material, cộng SKU. |
| Waste / spoilage / shrinkage | `DisposalService` | Manual phiếu hủy |
| Lệch system vs thực tế | StockCountService approval → adjustment_in/out | Stock count manager approval |

### The gap

When all 3 conditions hold:
1. SKU is `inventory_mode=track_stock` AND has `recipe_id`
2. Stock is pre-made via Production Batch (material deducted, SKU stock incremented)
3. Order closes and sells from that pre-made stock

…then `OrderClosingService::close()` step 2 runs the recipe walk anyway because it has no way to know "material was already committed via batch for this SKU instance". Net effect: **material gets deducted twice** for any sale of pre-made tracked SKUs.

The G3 path was designed for the canonical case (made_to_order SKU + recipe → trừ material at sale). When G2 (track_stock SKU) reuses the same logic and there's also a batch upstream, the two correctness paths collide.

### Out-of-scope but worth recording

- The "lost waste" sub-case (bếp nấu 10, bán 8, dư 2 spoiled) is **handled correctly when the operator uses Production Batch + Disposal** for the 2 dư. The gap is purely the double-deduct on the 8 that DID sell.
- The shop-small operator's normal workflow (no Production Batch, SKU is `made_to_order`) doesn't hit this gap at all. Recipe deduction once on sale, end of story. The 2 cái dư cuối ngày are operator-tolerated waste that the system doesn't see — fine for low-margin shops.

### Suggested fix shapes (for follow-up plan)

Pick one:

1. **Skip recipe step when SKU has on-hand stock at close time.** In `emitMaterialConsumptionTransaction()`, before walking the recipe, check `StockLevel(SKU, warehouse).quantity > 0` at sale time. If true → SKU was pre-made, skip recipe (material already committed at batch time). Smallest change, but heuristic.

2. **Track per-batch deduction on the StockTransaction sales line.** New flag `recipe_already_deducted` on the `sales` stock_out, populated when the SKU's quantity was supplied by a recent `ProductionBatch` completion. The close step reads this flag and skips recipe. Cleaner, more lines.

3. **Lock SKU to one path.** Tighten the schema: if `inventory_mode=track_stock` AND `recipe_id IS NOT NULL`, require the SKU to declare which path commits material (batch vs sale). Drift becomes a validation error. Strictest, breaks existing data.

User question that triggered this finding was about waste tracking in general. The waste-tracking story is fine (Disposal + StockCount cover it); the gap is the double-deduct, and only when production batch is involved. File as **PLAN-024 follow-up issue** once a user actually hits it in production.

### Direction agreed in this Q&A — apply option 1 in a future phase

User picked **option 1 (skip recipe when SKU has on-hand stock at close time)** as the intended direction. **Not implemented in this phase** — recorded so a future plan can pick it up:

- Smallest behavioural change, lowest blast radius.
- Heuristic is acceptable for the operational reality (shops that pre-make ahead of time have on-hand stock; shops that nấu theo đơn don't).
- Caveat to handle in implementation: the check must run AFTER the SKU stock-out has been applied (so the on-hand level reflects pre-sale state), OR use `lockForUpdate` to snapshot the pre-close quantity. Otherwise a sale of 1 that drains the stock to 0 reads "no on-hand" mid-transaction and re-deducts material — defeating the fix.
- Test scenarios needed: (a) pre-made SKU with on-hand → recipe skipped, (b) made-to-order SKU with 0 on-hand → recipe still deducts, (c) mixed order (1 of each kind) → only the on-hand line skips recipe, (d) on-hand=0 due to allow-negative-sales prior drain → recipe still deducts (matches reality: material has not been committed by any upstream batch).

---

## 2026-05-21 — Bug-fix follow-up to third E2E session

User asked to fix all 4 issues surfaced earlier today: BUG-STOCK-001, BUG-UI-002, Decision 8 UX, BUG-UI-001 reproductions. All four shipped + verified live in this session.

### BUG-STOCK-001 — Full-scope stock count never populates items — FIXED

- **Files:** [backend/app/Services/Inventory/StockCountService.php](../../backend/app/Services/Inventory/StockCountService.php)
- **Fix:** Added `snapshotFullScope()` invoked from `create()` when `scope=full`. Iterates every `StockLevel` row in the warehouse with `quantity > 0`, materialises one `StockCountItem` per row carrying the current `system_quantity`. Partial-scope path unchanged.
- **Verified live:** fresh seed → `POST /stock-counts {scope: full}` returned **22 items** (was 0 pre-fix), each with `system_quantity` populated from current StockLevel.

### BUG-UI-002 — Warehouse Edit dialog missing auto-approve toggles — FIXED

- **Files:**
  - [web/admin/src/app/shop/[shopSlug]/warehouses/components/warehouse-form-dialog.tsx](web/admin/src/app/shop/[shopSlug]/warehouses/components/warehouse-form-dialog.tsx) — added 4 toggles (`auto_approve_stock_in/out/batch/disposal`) + 1 numeric input (`disposal_approval_threshold`), extracted `PolicyToggle` helper component.
  - [web/admin/src/services/warehouse-service.ts](../../web/admin/src/services/warehouse-service.ts) — extended `WarehouseCreateInput` so create-time can ship all 5 fields.
  - i18n: 10 new keys × 3 locales (ja/en/vi) under `shop.warehouses.form.auto_approve*` + `disposal_approval_threshold*`.
- **Verified live:** dialog now exposes "Phê duyệt tự động" section with help popovers per toggle.

### Decision 8 UX — Flip seeded `auto_approve_stock_out` default to true — FIXED

- **Files:** [backend/database/seeders/LocalDevSeeder.php](../../backend/database/seeders/LocalDevSeeder.php) — `seedWarehouses()` now sets `auto_approve_stock_out=true` on every main warehouse. Comment explains the rationale: canonical sale-by-order demo (close order → SKU StockLevel decrements immediately) shouldn't need a manual approval detour in dev. Production warehouses can still opt out via the Warehouse Edit dialog (now possible thanks to BUG-UI-002 fix).
- **Verified live:** after `migrate:fresh --seed`, all 6 seeded warehouses report `stock_out=Y` (was `N` for non-Plan017/Plan022 warehouses pre-fix).

### BUG-UI-001 — Combobox cmdk fuzzy-match empty list — FIXED (third pass)

- **Files:**
  - [web/admin/src/services/catalog-lookup-service.ts](../../web/admin/src/services/catalog-lookup-service.ts) — `ProductSkuLookup` now declares optional `product?: { id, name }` (the API already returned this; the type just didn't capture it).
  - [web/admin/src/components/shared/item-row-editor.tsx](../../web/admin/src/components/shared/item-row-editor.tsx) — SKU picker display now leads with product name: `"Phở Bò — Large (PHO-BO-L)"` instead of just `"Large (PHO-BO-L)"`. Searching by product name no longer returns zero hits.
  - [web/admin/src/app/shop/[shopSlug]/stock/counts/[id]/components/add-items-dialog.tsx](web/admin/src/app/shop/[shopSlug]/stock/counts/[id]/components/add-items-dialog.tsx) — switched encoding from bare `variant:UUID` to `display·variant:id` (U+00B7 sentinel) so cmdk command-score sees the readable text first; same pattern as `item-row-editor.tsx`. Label also shows product name now via the same join.
- **Root cause confirmed:** cmdk filters by `value` field. UUID-leading values pushed the score below the visibility cutoff for substring queries that land deeper in the string. Display-leading values fix it for all substring searches (product name, variant name, SKU).
- **Verified live:** Add-items dialog and stock-transactions/new SKU picker both surface "Phở Bò — Large (PHO-BO-L)" and "Phở Bò — Regular (PHO-BO-R)" when searching `"Phở Bò"`, `"PHO-BO-L"`, or `"PHO-BO"` (was empty pre-fix).

### Verification

- **Pest:** plan-024 + OrderClosing suites unchanged — 25 pass / 17 pre-existing fail (WarehouseFactory.branch_id baseline). No regressions.
- **`pnpm typecheck`:** clean for all touched files.
- **`pnpm lint`:** 0 new warnings/errors in touched files (pre-existing warnings in unrelated files untouched).
- **`vendor/bin/pint --dirty`:** pass.

### Follow-up

Not fixed in this session (out of scope, recorded for tracking):
- BUG-CUST-001 (customer-web `CustomerMenuService` status case mismatch) — customer-web owner.
- BUG-CUST-002 (timezone fallback UTC) — customer-web owner.
- The full-scope snapshot currently skips zero-quantity StockLevel rows. If operators want to *count to zero* (e.g. record a stockout finding), they need partial-scope + manual add. Reasonable trade-off; flag if it bites.

---

## 2026-05-21 — Third E2E session (UI walk-through of 3 flows)

Tested in order: (1) toggle `inventory_mode=track_stock` on a SKU, (2) full sale-by-order with tracking, (3) stock count (kiểm kê). Setup: `migrate:fresh --seed`, brand `betoya`, branch `sby` (渋谷店), warehouse `渋谷店 メイン倉庫`, SKU PHO-BO-L (Phở Bò Large).

### Pest baseline first (before UI)

- `tests/Feature/Catalog/ProductSkuInventoryModeTest.php` — **6/6 pass** (12 assertions). Backend G1 contract solid.
- `tests/Feature/Customer/OrderClosingInventoryModeTest.php` + `OrderClosingMaterialDeductionTest.php` + `OrderClosingWithLotsTest.php` — **19/19 pass** (46 assertions). Backend G2/G3 + plan-022 genealogy intact.
- `tests/Feature/Inventory/StockCountTest.php` — **17/17 fail**, same `WarehouseFactory.branch_id` FK constraint baseline as main. Not plan-024 scope. Already documented at NOTES.md:148.

### Flow 3 — Enable `inventory_mode=track_stock` on PHO-BO-L

- Path: `/hq/betoya/products/{pho-bo}/skus/{lon}` → "Chế độ tồn kho" combobox → "Theo dõi tồn kho" → Cập nhật.
- Result: `PUT /skus/{id}` → 200. DB: `product_skus.inventory_mode = "track_stock"`. Optional Công thức (Recipe) selector appears when track_stock is selected but is not required (saves with null recipe_id).
- **UX bug surfaced — leftover state on navigation:** clicking the navigation breadcrumb while form has unsaved changes auto-accepts the beforeunload dialog (cypress/devtools artifact) instead of warning the user. Not a plan-024 issue; just noted.

### Flow 2 — Sale-by-order → stock deduction (full E2E)

Pipeline executed:
1. Stock-in 10× PHO-BO-L via API (`POST /shops/sby/stock-transactions` + `submit`). Warehouse `auto_approve_stock_in=true` → auto-completed. StockLevel = 10.
2. Customer order via `POST /customer/branches/sby/orders` → ORD-2026-2396 (status=pending, total ¥3,050).
3. Admin order detail UI → click **Xác nhận** → `POST /orders/{id}/confirm` → 200, status pending → open. **BUG-ADMIN-001 fix verified working (third confirmation in this codebase).**
4. Click **Hoàn tất** → dialog → Xác nhận thanh toán → status open → paying.
5. PATCH items: pending → preparing → ready → served (`PATCH /orders/{id}/items/{itemId}`).
6. `POST /orders/{id}/payments` (cash, ¥3,050) → 201. Order status → **closed**.
7. `OrderClosingService::close()` correctly created a `StockTransaction` (sub_type=sales, ref→order_id, note "Auto stock-out for order ORD-2026-2396") and `submit()`-ed it (plan-024 T0.6 fix verified).
8. Because `auto_approve_stock_out=false` on this warehouse (seeded default), the TX landed in **pending**. **This is the documented behaviour from Decision 8 — not a bug.**
9. Approved via UI: `/shop/sby/stock/transactions/{id}` → **Approve** button → confirm dialog ("Stock impact cannot be undone") → status pending → completed.
10. Final: StockLevel **9.0000** ✅ (10 − 1).

**Decision 8 demonstration is genuinely useful operationally** — having an approval gate per sales-stock-out is plausible for shops that want audit, and the UI flow (Phiếu kho → click TX → Approve) is clean. Future docs/UI could surface this expectation more clearly so operators aren't surprised that StockLevel doesn't drop the instant an order closes.

### Flow 1 — Stock count (kiểm kê)

- Path: `/shop/sby/stock/counts` → Tạo phiếu kiểm kê.
- **BUG-STOCK-001 — Full-scope count never populates items:**
  - The create dialog labels the Full scope as **"Toàn bộ — chụp toàn bộ khi tạo"** (snapshot everything on create).
  - Actual behaviour: backend `StockCountService::create()` only writes the parent row; it does NOT iterate `StockLevel` rows for the warehouse. Items remain empty.
  - Frontend: `canAddItems = isInProgress && scope === Partial` ([page.tsx:129](../../web/admin/src/app/shop/[shopSlug]/stock/counts/[id]/page.tsx)) — so for a Full-scope count, the **Thêm mục** button never appears, AND `Bắt đầu kiểm kê` transition does not populate either.
  - **Net effect:** every Full-scope stock count is permanently empty and can only be submitted with zero items / zero impact. The label is therefore misleading.
  - Suggested fix: either (a) implement a snapshot step in `StockCountService::create()` for `scope=full` (iterate StockLevels in warehouse, insert StockCountItem rows with `system_quantity` per level — same logic the `addItems()` path uses on a per-row basis), or (b) change the label to "Toàn bộ — duyệt chênh lệch cho tất cả SKU/Material" and rely on operator using a different workflow. Option (a) is what the label promises.

- Verified Partial-scope path E2E:
  - Create → Bắt đầu → Thêm mục (PHO-BO-L) → snapshot system_quantity=9 (correctly read from current StockLevel). UI shows SL hệ thống=9, SL kiểm input, Chênh lệch auto-computed.
  - Operator types 8 (shrinkage scenario, -1) → Lưu (`POST /stock-counts/{id}/update-items`) → Gửi (submit, status counting → submitted) → Duyệt (approve, status submitted → approved, completed_at set).
  - Backend emits a sibling `StockTransaction` (sub_type=adjustment_out, ref→stock_count_id, qty=1, note "Adjustment out from stock count SC-20260521-002"). Same `auto_approve_stock_out=false` gate applies → TX lands in pending.
  - Approved via UI → status completed. Final StockLevel **8.0000** ✅ (9 − 1).

### BUG-UI-001 reproduced on a third call site

The Combobox/cmdk fuzzy-match bug (NOTES.md line 56) is observable on at least three admin-web pages:
1. ~~`material-lots/receive` (fixed in this branch)~~.
2. ~~`stock/transactions/new` material picker (fixed in this branch)~~.
3. **New finding:** `stock/transactions/new` **SKU picker** — typing "PHO-BO-L" or "Phở Bò" against ~70-item lookup result shows an empty listbox. Workaround: scroll the unfiltered list manually. The encoding fix in [item-row-editor.tsx](../../web/admin/src/components/shared/item-row-editor.tsx) didn't cover this entry point.
4. **New finding:** `stock/counts/{id}` Add-Items dialog (`add-items-dialog.tsx`) — typing any substring returns empty suggestions. Same cmdk fuzzy-match root cause (UUID-led `value` field).

Recommend a single `encodeComboValue(display, id)` helper exported from `@godxjp/ui` Combobox itself rather than reinventing per call site. Three+ pages now use the same pattern; centralising prevents drift.

### BUG-UI-002 — Warehouse Edit dialog missing auto-approve toggles

- `/shop/sby/warehouses` → Sửa → dialog exposes: Mã, Loại, Tên, Địa chỉ, Allergen policy fields, and ONE inventory-policy switch ("Cho phép tồn kho âm khi bán hàng" = `allow_negative_sales`).
- Missing: `auto_approve_stock_in`, `auto_approve_stock_out`, `auto_approve_batch`, `auto_approve_disposal`, `disposal_approval_threshold`. All these exist on the model + service + Type, but the Edit dialog never surfaces them.
- Impact: a shop manager cannot change approval policy via UI. They'd have to use API or DB. Given Decision 8 explicitly relies on this flag to drive whether order-close → instant deduct vs queued approval, this is a real gap.
- Suggested fix: extend `warehouse-form-dialog.tsx` to include the four `auto_approve_*` switches and the `disposal_approval_threshold` number field. Mirror the SmartHR-style toggle grouping under a "Phê duyệt tự động" section.

### Decision 8 — recommendation for the UX

Three observations point to one direction: (a) Flow 2 surprise about TX-pending, (b) Flow 1 surprise about adjustment-pending, (c) BUG-UI-002 makes the flag unreachable. Together they suggest the default seeded behaviour (`auto_approve_stock_out=false`) is operationally non-obvious. Two possible improvements (pick one):
1. Flip seeded default to `true` so the demo data demonstrates the "instant deduct" path — keeps Decision 8 valid but improves first-impression UX.
2. Keep `false` as default but surface "X stock-out phiếu đang chờ duyệt" badge in the shop header so operators don't lose track. Plus BUG-UI-002 fix to expose the flag.

Recorded for follow-up — not in plan-024 scope.

---

## 2026-05-21 — Customer-web bugs found during E2E (NOT fixed in this session)

User flagged that customer-web is out of plan-024 scope. Listing two pre-existing customer-side bugs for follow-up:

### BUG-CUST-001 — CustomerMenuService status filter case mismatch

- **Where**: [backend/app/Services/Customer/CustomerMenuService.php:40](../../backend/app/Services/Customer/CustomerMenuService.php) (also line 39 in another method).
- **What**: query is `->where('status', 'active')` (lowercase) but `MenuStatusEnum::Active` value is `'Active'` (TitleCase). The seeded menus all have `status = 'Active'` → query never matches → 404 "No active menu found." on every `/customer/branches/{slug}/menu` and `/customer/tables/{qrToken}` request.
- **Reproduce**: GET `http://localhost:5400/api/v1/customer/branches/sjk/menu` returns 404.
- **Suggested fix**: `->where('status', MenuStatusEnum::Active->value)` (or `->where('status', 'Active')`).
- **Impact**: customer-web takeaway/dine-in landing pages cannot load any menu — appears as "Không tải được menu" on the customer-facing UI.

### BUG-CUST-002 — Customer-web menu fetch ignores branch timezone

- **Where**: same service, [`resolveBranchTimezone()` at line 499](../../backend/app/Services/Customer/CustomerMenuService.php). When branch.timezone is NULL (the default for seeded shops in this org), the service falls back to `config('app.timezone')` which is `'UTC'` in the docker-compose env.
- **Symptom**: even after fixing BUG-CUST-001, the menu schedule filter compares the UTC `H:i:s` (e.g. `03:17`) against schedule windows that were seeded in Tokyo local time (`11:00-14:30`, `17:00-22:00`). At UTC pre-noon, no schedule matches → menu still 404s during normal Tokyo business hours.
- **Suggested fix**: either default the fallback to `Asia/Tokyo` (the operational reality of the user base), OR backfill `branches.timezone` for seeded branches in `MockDataSeeder`/`DashboardSeeder`, OR change `MockDataSeeder` to materialise branch.timezone explicitly.
- **Impact**: same as BUG-CUST-001 — customer-web cannot load menus from a fresh dev seed unless the developer happens to be in UTC and operating during 11am UTC.

Both bugs are out-of-scope for plan-024 (customer-web changes explicitly excluded — see README "Out of scope"). Recorded here so the customer-web owner has a paper trail.

---

## 2026-05-21 — Admin-side bug fixed during E2E session

### BUG-ADMIN-001 — Order detail "Xác nhận" 404 — FIXED in this session

- **Symptom**: customer-web takeaway orders land in `pending` status. On the admin order detail page (`/shop/{slug}/orders/{id}`) the header shows a "Xác nhận" button calling `POST /api/v1/shops/{slug}/orders/{id}/confirm` → 404.
- **Root cause**: `order-service.ts` already had `confirm()` method but with a TODO comment "Backend has no dedicated confirm endpoint yet — for now the call will 404." Backend transitions `pending → open` only via tightly-coupled paths (table-pairing or `init` on Open). No way for staff to confirm a customer-submitted takeaway order from admin.
- **Fix shipped in this session**:
  1. `CustomerOrderService::confirmOrder()` — new public method asserting status=Pending → updates to Open + audit log.
  2. `CustomerOrderController::confirm()` — new controller action with OpenAPI annotations.
  3. `routes/api/shops/orders.php` — registered `POST /api/v1/shops/{shopSlug}/orders/{customerOrder}/confirm`.
  4. `web/admin/src/services/order-service.ts` — removed the "no endpoint" TODO comment.
- **Verification**: re-issued `POST .../confirm` against the test order, expect 200 + order.status=open. (Tested below in the same session.)
- **Out of scope but recorded**: customer-web side has 2 pre-existing bugs (see BUG-CUST-001/002) that are not fixed.

---

## 2026-05-21 — UI bug surfaced during E2E test session

**Context**: Manual E2E flow (material → product → recipe → stock-in → lot receive → transfer → menu → customer order). Material picker combobox on `/shop/{slug}/material-lots/receive` (and likely other Combobox call sites that consume `materials/lookup`) fails to surface materials created in the same session.

### BUG-UI-001 — `Combobox` material filter doesn't match newly created materials — FIXED in this session

- **Where**: `MaterialLotReceiveForm` material picker — and reproduced on `Stock transactions / new` material picker before user manually clicked through.
- **What happens**: The `materials/lookup` API returns the new material (verified — payload contains `Bún tươi E2E3` with full `yield_unit: g`). The picker dropdown displays a filtered list, but search by name (`Bún tươi`), substring (`E2E3`), or SKU (`MABNZLNL`) skips the new row even though older seeded materials match the same substring.
- **Root cause**: `@godxjp/ui`'s `Combobox` is a thin wrapper around `cmdk`, which filters by `value` field using `command-score` fuzzy matching. The two callers were both encoding the UUID into `value`:
  - `material-lots/receive/page.tsx`: `value: m.id` — query "E2E3" lands mid-UUID, command-score ≈ 0 → hidden.
  - `components/shared/item-row-editor.tsx`: `value: "${prefix}:${uuid}|${display}"` — UUID before display still dominates the score for early-character matches.
  Seeded materials happened to have keyword-rich SKUs (`BETOKITCHEN-MA-WHEAT-FLOUR`) so a substring like "BETOKITCHEN" landed at the head of their value strings → matched. New materials with random-prefix SKUs (`MABNZLNL`, `MACR2IY5`) had no such head match → invisible.
- **Fix shipped**: Encode `display{SEP}id` (display leads) and recover id via `slice(lastIndexOf(SEP) + 1)`. Verified by typing "FIX-TEST" (display substring) and "MACR" (SKU prefix) — both surface the freshly-created material in the dropdown.
- **Files**:
  - `web/admin/src/app/shop/[shopSlug]/material-lots/receive/page.tsx` — added `encodeComboValue`/`decodeComboValue` helpers.
  - `web/admin/src/components/shared/item-row-editor.tsx` — flipped the `prefix:id|display` encoding to `display·prefix:id` (covers stock transactions, transfers, disposals, production orders).
- **Workaround used in this session**: user manually scrolled/clicked the material in the dropdown — once selected the form proceeds normally.
- **Suspected cause** (to confirm): client-side `Combobox` may be capping/sorting the seed list and the new material falls below the visible cap, OR the filter normalizer is mis-handling Vietnamese diacritics for freshly-created rows whose accent stripping happens differently from seeded rows.
- **Reproduce**: create a material via HQ UI → navigate to `/shop/{slug}/material-lots/receive` → open material picker → type new material's name. Expected: appears at top. Actual: missing.
- **Impact**: blocks E2E lot receive when shop operator wants to use just-created HQ material. Workaround exists but is awkward.
- **Action**: file follow-up. Inspect `@godxjp/ui` `Combobox` filter function + `useMaterialLookup` cache (likely `staleTime: 5 * 60 * 1000` keeps an older lookup snapshot before the new material is included — though manual cache invalidation may be missing).

---

## 2026-05-20 — Implementation complete

**Branch**: `plan-024-stock`
**Commits**: 16 (98eb1f49 → 1c16c62f)
**Status transition**: `implementing` → `reviewing`

### Verification

- **Pest sweep (plan-024 + OrderClosingWithLots)**: 47 passed, 106 assertions, 0 failures.
- **Pest sweep (full inventory + customer suites)**: 327 passed (was 321 on main → **+6 net passes from plan-024**, no new regressions). The 160 remaining failures are pre-existing `WarehouseFactory.branch_id` FK constraint issues — same baseline as main, unrelated to plan-024.
- **`pint --dirty --format agent`**: clean.
- **`pnpm typecheck`**: green for all plan-024-touched files. 3 pre-existing errors in `web/admin/src/app/hq/[brandSlug]/materials/[id]/page.tsx` remain — unrelated to plan-024 (looks like leftover work in materials domain).
- **`pnpm lint`**: 0 new warnings in plan-024-touched files.
- **Swagger regen**: `hq-api-docs.json` + `shop-api-docs.json` updated with the new `inventory_mode` + `allow_negative_sales` fields.
- **Phase 4 code review** (fresh sub-agent): WARNINGS only, no critical issues. 4 warnings + 4 informational. W1/W2/W3/I1/I4 fixed in commit f8f81b24. W4 (BUG-3 pre-existing `min_stock > max_stock` cross-field validation) and I2/I3 (missing tests for plan-023-provided audience scoping + silent-resolution) deferred — see REVIEW.md.
- **Phase 5 browser verification**: SKIPPED in this session — the admin-web dev server is up but no test-user session is available. Browser test scaffolds (T7.1-T7.5) are committed at backend/tests/Browser/ as `->skip(...)` placeholders per the project convention; un-skip and exercise via Playwright when a seeded test environment is available.

### Decisions adopted vs DESIGN.md plan

All 8 Decisions (1–8) honoured. Two minor amendments documented mid-execution:

- **Decision 7 amendment**: material deduction formula scaled by `recipe.output_quantity` to match the existing `recordSalesGenealogy()` arithmetic (preserves ledger ↔ genealogy consistency). The plan text said "plain `qty_per_serving × order_qty`" but the precise formula is now `(ingredient.quantity / recipe.output_quantity) × order_item.quantity`.
- **G5 source of truth correction**: plan said "extract a private `notifyOnAlert` method"; reality is plan-023 M1 already shipped this as `StockAlertNotificationObserver`. Plan-024 leaves the observer untouched and inherits the dispatch automatically when `StockAlert::create()` fires from G4. Audience: `Audience::byRole('warehouse_manager')->scopedTo($warehouse)` (SSO role, NOT `WarehouseMember.role='manager'` as the plan originally said — but the SSO role IS the operationally correct one).

### Risks pinned for follow-up

- **BUG-3** — `StockLevelUpdateRequest` lacks `min_stock <= max_stock` cross-field validation. Pre-existing; not in plan-024 scope. Tests pin current behaviour with a comment. Action: file follow-up issue with `'lte:max_stock'` rule + flip test assertion to 422.
- **Browser tests un-skip** — 6 scaffolded `->skip(...)` browser tests at backend/tests/Browser/ need real Playwright/Dusk runner.
- **Existing `OrderClosingWithLotsTest` genealogy gap**: the pre-existing test that doesn't seed `track_stock` would have broken; mitigated via `$lastMaterialConsumptionTransactionId` fallback anchor in close(). Confirmed passing.

### What ships

- **G1 — ProductSku.inventory_mode** enum (`made_to_order` default, `track_stock` opt-in) — schema YAML + omnify regen + admin-web SKU edit form + FormRequest validation + OpenAPI doc.
- **G2 — OrderClosingService gates SKU stock-out** by `inventory_mode`; also fixes a pre-existing latent bug (`create()` never followed by `submit()`) so the stock-out actually completes.
- **G3 — Recipe → Material auto-deduction** via new `emitMaterialConsumptionTransaction()` step in `OrderClosingService::close()`. Filters soft-deleted materials. Skip+warn for missing recipe or empty ingredients.
- **G4 — Warehouse.allow_negative_sales** opt-in for sales-flow shortages. `StockTransactionService::completeTransaction()` + `pickLotsForConsumption()` both gain the `$allowShort` path. Force-creates `out_of_stock` alert (superseding any existing `low_stock`).
- **G5 — left alone** (plan-023 M1 already shipped). Plan-024 just inherits via the observer.
- **G6 — inline threshold-edit sheet** on `/shop/{shopSlug}/stock/alerts` via new `StockLevelThresholdSheet`. Backend `StockLevelService::reEvaluateActiveAlertForLevel()` auto-resolves the active alert when the new threshold passes quantity.

### Docs delivered

- `backend/docs/explanation/inventory-domain.md` — BR-S01 exception, sub-types table, "Plan-024 — Auto-deduct on order close" section, "How Stock Changes" row.
- `backend/docs/explanation/stock-management.md` — BR-S01 amendment.
- `backend/docs/reference/inventory-mode.md` (NEW) — full enum + behaviour reference.

### Next step

PR: `/mcp__omnify__complete` will push the branch and open a PR against `main` linking back to issue #268. Reviewer should read DESIGN.md → REVIEW.md → diff in that order.

---

## 2026-05-20 — T3 test sweep complete

### Test sub-agent delivery
Sub-agent (Sonnet, 1382s wall, 47k tokens) wrote 42 plan-024 tests across 6 files. All 42 pass.

### 4 production bugs surfaced — 3 fixed, 1 documented as pre-existing

1. **BUG-1 (FIXED)** — `pickLotsForConsumption()` always threw `InsufficientStockException` when residual demand exceeded availability, making the T2.1 allow-negative branch dead code. Fix: added `$allowShort` param; when true, emit a `material_lot_id=null` residual line instead of throwing. `splitStockOutItemsByFefo` now passes `$allowNegativeSales` down.
2. **BUG-2 (FIXED)** — `StockAlert.min_stock` was NOT NULL. The G4 force-out_of_stock alert path could pass null when no threshold was configured. Fix: amended `schemas/Backend/Inventory/StockAlert.yaml` to make `min_stock` nullable + regen + migrate.
3. **BUG-3 (pre-existing, NOT FIXED)** — `StockLevelUpdateRequest` has no cross-field `min_stock <= max_stock` validation. Documented in tests; out of plan-024 scope (would have required deciding whether to introduce a `lte:max_stock` rule and how it interacts with partial updates).
4. **BUG-4 (FIXED)** — soft-deleted material in `Recipe.ingredients` made the close throw. Fix: `emitMaterialConsumptionTransaction` now filters aggregated material IDs against `Material::whereIn` to drop missing/soft-deleted entries (logged as warning, same as recipe-missing per Decision 7).

### Pre-existing OrderClosingWithLots regression averted

T2.4's `inventory_mode` gate would have broken existing `OrderClosingWithLotsTest` (genealogy edges absent because default `made_to_order` skips the SKU stock-out, leaving no anchor id). Fix: added per-call `$lastMaterialConsumptionTransactionId` state in `OrderClosingService`. The genealogy step now anchors on `stockTransaction?->id ?? materialConsumptionTransaction?->id ?? order->id` in that order, so genealogy always fires when at least one item has a recipe — independent of `inventory_mode`.

### Regression count baseline

`tests/Feature/Inventory + tests/Feature/Customer` failure counts:
- Pre-plan-024 (on main): **166 failed / 321 passed**
- Post-plan-024 (current branch): **160 failed / 327 passed**

→ NO new regressions. 6 pre-existing failures now pass (likely thanks to the `StockAlert.min_stock` nullable migration). The 160 remaining failures are all pre-existing test infrastructure issues — `WarehouseFactory` generating `branch_id` without seeding a Branch row first → FK constraint violation. Independent of plan-024; recommend filing a follow-up.

---

## 2026-05-20 — Phase 0 discovery (T0.1–T0.4) — major scope updates

After reading the actual codebase, several plan assumptions need to change. None of these change the user's stated intent — they change WHICH tasks deliver it.

### Resolved during T0.1–T0.4

- **T0.1 — notification templates:** `stock.alert.low` + `stock.alert.out` ARE seeded by `backend/database/seeders/SystemNotificationTemplateSeeder.php`. ✅
- **T0.2 — SKU edit form path:** `web/admin/src/app/hq/[brandSlug]/products/[id]/skus/[skuId]/page.tsx` (existing, fully functional). ✅
- **T0.3 — `useUpdateStockLevel`:** confirmed at `web/admin/src/hooks/api/use-stock-levels.ts:38-49`. ✅
- **T0.4 — Warehouse settings UI:** lives in `web/admin/src/app/shop/[shopSlug]/warehouses/components/warehouse-form-dialog.tsx` (NOT a separate /settings route — toggles are in the Edit dialog alongside auto-approve flags). ✅

### Newly discovered — G5 (notification on alert) IS ALREADY DONE

`backend/app/Observers/StockAlertNotificationObserver.php` exists (plan-023 M1) and:
- Observes `StockAlert::created`
- Dispatches `stock.alert.low` / `stock.alert.out` via `NotificationService`
- Audience: `Audience::byRole('warehouse_manager')->scopedTo($warehouse)` (SSO role 'warehouse_manager', not WarehouseMember.role='manager')
- Idempotency key: `{type}:{alert->id}` (functionally equivalent to plan's `stock_alert:{alert_id}`)
- Try/catch with `Log::warning` on dispatch failure
- Inbox collapse via `aggregation_key`

**Implications:**
- T2.2 (extract `notifyOnAlert` private method) → SKIP. Observer is cleaner than inline dispatch and already shipped.
- T3.5 (notification dispatch test) → likely already covered by plan-023 tests; will verify when running test sweep. If a plan-024-specific test for the NEW out_of_stock alert from G4 isn't covered, add it then.
- DESIGN.md G5 wording is wrong in detail (says "we add notifyOnAlert" — actually we leverage existing observer). Doc fix below.

### Newly discovered — pre-existing OrderClosingService bug

`OrderClosingService::close()` calls `$stockTransactionService->create([...])` (line 69) but NEVER calls `submit()` or `approve()`. The transaction is left in `Draft` state and never executes `completeTransaction()`. Result: today, closing an order:

- Creates a `StockTransaction` row in Draft
- Sets `order.stock_out_transaction_id` to point at that Draft row
- Records sales-edge GenealogyLink (plan-022 T8.1 — uses FEFO preview, no real stock change)
- **Does NOT decrement `StockLevel.quantity` for any SKU**
- **Does NOT trigger any `StockAlert`**

The OrderClosingWithLotsTest tests only assert genealogy edges, never `StockLevel.quantity` changes. So this drift is invisible to the current test suite.

**Implications for plan-024:**
- G2 (gate SKU stock-out by inventory_mode) is meaningless if nothing deducts today. T2.4 needs to also call `submit()` after `create()` so the auto-approve flag actually kicks in. This is a pre-existing bug fix; it's the minimum change required to make G3 work at all.
- The risk row in DESIGN.md about "existing SKUs default to made_to_order → shops that relied on implicit stock tracking see stock-outs stop firing" is moot — nothing was firing before.
- T2.5 (recipe → material deduction) must now ALSO `submit()` the new material transaction.

### Plan adjustments queued for next commit (T0.x outcome)

1. **Strike T2.2 + T3.5** (G5 notification observer already shipped by plan-023). Mark as `[x]` with note "Already done by plan-023 M1 (StockAlertNotificationObserver)".
2. **Modify T2.4** to also call `submit()` after `create()` to make the existing SKU stock-out actually execute. This fixes a pre-existing latent bug discovered during discovery — without it G2/G3 deliver no observable change.
3. **Modify T2.5 step 6** to be explicit: the new material `StockTransaction` is created AND submitted, so `auto_approve_stock_out=true` (the default for `getDefaultWarehouse()` fallback) means it runs `completeTransaction` synchronously.
4. **DESIGN.md G5 approach paragraph** update: replace "extract a private `notifyOnAlert` method" with "rely on the existing `StockAlertNotificationObserver` (plan-023 M1) — verify audience matches plan-024 intent (`byRole('warehouse_manager')` SSO role rather than `WarehouseMember.role='manager'` warehouse-member role; the SSO role is the operationally correct one because that's how shop managers are tagged in this codebase)".
5. **DESIGN.md Decision 5** add a follow-up note that the actual audience implementation differs from the plan text — it's `Audience::byRole('warehouse_manager')->scopedTo($warehouse)`, which is the existing convention.
6. **README.md Success criterion 5** is already met by plan-023 — mark as ✅ verified-not-built. The remaining work is just ensuring the new out_of_stock alert path (from G4 allow-negative) ALSO fires the observer correctly. Since `StockAlert::create()` triggers the observer regardless of caller, this is automatic.

### Recipe schema reminder

`Recipe.ingredients` is a `Json` column (array of `{material_id, quantity, unit}` shapes) — NOT a separate RecipeIngredient table. `recordSalesGenealogy()` already walks this structure with `is_array($recipe->ingredients)` guard. T2.5 should mirror this access pattern exactly.

`output_quantity` + scaling: `recordSalesGenealogy` uses `$scale = $itemQty / max($outputQty, 1e-9)`. Plan Decision 7 said "plain `qty_per_serving × order_qty`" but the existing genealogy code applies output-quantity scaling. T2.5 should match the genealogy formula (preserves consistency between the genealogy edges and the actual stock movement). Updating Decision 7 to reflect: `material_qty = (ingredient.quantity / recipe.output_quantity) × order_item.quantity`.

---

## 2026-05-20 — Review pass (post-draft)

Review surfaced 6 items. Resolution:

- **R1 — OrderClosingService material claim corrected.** README/DESIGN previously implied no material code exists today; in fact `recordSalesGenealogy()` (OrderClosingService.php:188-261) already walks Recipe→Materials for FEFO *preview* edges (no `StockLevel` writes). Wording fixed; T2.5 now explicitly leaves genealogy intact.
- **R2 — stock-alert-table.tsx has no action dropdown today.** Split T5.3 into T5.3a (add `EllipsisVertical` actions column) + T5.3b (wire "Configure threshold" item).
- **R3 — StockTransactionSubType existing-values list corrected.** Actual values: `purchase, sales, production, transfer_in, transfer_out, return, disposal, adjustment_in, adjustment_out, other`. DESIGN.md updated.
- **R4 — `auto_approve_stock_out=false` behaviour resolved via Decision 8.** Picked option (b): document as operational requirement, no code override. If the flag is off, material `StockTransaction` lands in `submitted` state, order still closes, no `StockLevel` change until a manager approves. Risk row + test scenario added. T8.1 docs to call this out explicitly.
- **R5 — Idempotency key `stock_alert:{alert_id}` kept as-is.** Sync dispatch today means the key does no de-dup work, but it's pinned for forward-compatibility (future queued retries on the same alert id remain idempotent). Documented inline in DESIGN.md G5 approach.
- **R6 — Plain formula committed via Decision 7.** No dependency on plan-022. Material consumption = `ingredient.qty_per_serving × orderItem.quantity`. TESTS Edge 3 rewritten to assert the plain formula. If plan-022 later introduces `recipe_multiplier`, that plan rewrites the aggregation in a single localised change.

Additional verified during review (closed an Open Question):
- `useUpdateStockLevel` already exists at `web/admin/src/hooks/api/use-stock-levels.ts:38-49` — T5.1 downgraded to "reuse existing hook".

Status remains `draft` until items 1–4 land in code; can flip to `ready` after T0.x verifications complete.

---

## 2026-05-20 — Plan created

Initial scaffold via `/mcp__omnify__plan`. Branch already exists as `plan-024-stock` (created before this skill ran).

### Phase 0.5a — Web research summary

Square / Shopify / Toast / Lightspeed patterns (compressed):

- **Inventory mode**: Square uses `track_inventory: boolean` (per variant, per-location overridable). Shopify uses `inventory_policy: deny|continue` (per variant). Neither uses an enum, but ERPs (NetSuite, Restaurant365) do for forward-compatibility. We picked enum.
- **Allow-negative**: Shopify puts it on the variant (`inventory_policy: continue`). Some multi-warehouse ERPs put it on the location. We picked warehouse-level (matches restaurant operational reality).
- **Recipe deduction timing**: Dominant = payment/close. Toast partial-fires at kitchen-fire with void-reversal. We picked order-paid (matches locked decision).
- **Alert lifecycle**: SaaS standard = 3-state (active → acknowledged → resolved). Shopify Stocky uses daily digest. Existing schema is 2-state — we keep it; acknowledged-state is a possible follow-up.
- **Threshold edit UX**: Shopify Stocky uses inline-editable column. Polaris design guidelines recommend sheet/drawer for multi-field thresholds. We picked sheet (4 fields, including the master switch).

Common failure modes flagged:
- Ghost deductions on voided items (mitigated: deduction at close, after void-state is final).
- Double-fire on payment retry (mitigated: existing `OrderClosingService` idempotency guard via `status=closed` check).
- Alert storm on threshold oscillation (mitigated: `idempotency_key` per-alert).

Full sources in research artifact (not committed):
- https://developer.squareup.com/docs/inventory-api/how-it-works
- https://help.shopify.com/en/manual/products/inventory/setup/selling-when-out-of-stock
- https://k-series-support.lightspeedhq.com/hc/en-us/articles/4407509542043

### Phase 0.5b — Project research summary

Existing stock infrastructure is mature (plan-017/018/022). Audit identified 6 concrete gaps:

1. ProductSku has no `inventory_mode` flag (schema gap).
2. `OrderClosingService` doesn't deduct Materials via Recipe (logic gap).
3. `StockTransactionService` hard-stops on shortage (policy gap — user wants allow-negative).
4. `StockAlertService` doesn't dispatch notifications (notification gap).
5. Alerts page has no threshold-edit UI (UI gap).
6. `WarehouseMember`-based audience for notifications is more precise than `ExpiryAlertService` org-wide pattern.

Project conventions confirmed:
- `DB::transaction` + `lockForUpdate` mandatory in services (backend/docs/contributing/service.md).
- `ChecksWarehouseContext` trait gates manager vs staff (backend/docs/contributing/policy.md).
- `NotificationService.dispatch` already has `stock.alert.out` / `stock.alert.low` in `DEFAULT_PRIORITIES`.
- `ExpiryAlertService` is the canonical reference pattern for try/catch + idempotency_key + audience query.
- Admin-web uses `@godxjp/ui` + TanStack Query (admin-web/AGENTS.md).

### Open questions from research (carried into DESIGN.md)

- OQ-1: per-lot stock count items (pre-existing plan-017 gap) — deferred.
- OQ-2: notification template seed for `stock.alert.low` / `stock.alert.out` — verify in Phase 0 of execution.
- OQ-3: existing-data migration impact for ProductSku.inventory_mode default — release notes only.
- Phase 2 path verifications for admin-web (SKU edit form, warehouse settings, useUpdateStockLevel hook).
