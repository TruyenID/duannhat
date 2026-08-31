# Plan-024 Code Review

**Branch**: `plan-024-stock`
**Reviewer**: Claude Code (automated review)
**Date**: 2026-05-20
**Verdict**: WARNINGS — no critical blockers; safe to merge after addressing warnings

---

## Summary

Plan-024 (Stock Management: Auto-Deduct, Inventory Mode, Alert Notifications) is functionally complete. All six G-items are implemented. The backend logic is sound and the four bugs documented in NOTES.md (BUG-1 through BUG-4, plus T0.6 submit-fix) are correctly resolved. No critical issues were found. There are four warnings (two frontend conventions, one missing OA doc, one pinned failing validation) and four informational notes.

---

## G-Item Verification

| Item | Status | Notes |
|------|--------|-------|
| **G1** `ProductSku.inventory_mode` | **DONE** | YAML schema added, omnify regen'd, admin-web SKU page has Select field, i18n keys in ja/en/vi |
| **G2** `OrderClosingService` gates stock-out on `inventory_mode` | **DONE** | Phase 1 filter correctly checks `TrackStock->value`; made_to_order SKUs skipped |
| **G3** Recipe→Material deduction at order close | **DONE** | `emitMaterialConsumptionTransaction()` aggregates per-material qty with output_quantity scaling matching Decision 7 amendment; BUG-4 (soft-deleted materials) fixed |
| **G4** `Warehouse.allow_negative_sales` | **DONE** | Schema YAML, regen'd, `WarehouseController::updateSettings` OA spec documents it, `StockTransactionService::completeTransaction()` gates on sub_type + flag; force-creates `out_of_stock` alert; BUG-1 ($allowShort) and BUG-2 (nullable min_stock) fixed |
| **G5** `StockAlertNotificationObserver` dispatches alerts | **DONE** | Already shipped by plan-023 M1; T2.2 and T3.5 correctly marked obsolete; no double-dispatch added; T0.5 note confirms deliberate non-action |
| **G6** Inline threshold-edit sheet on stock-alerts page | **DONE** | `StockLevelThresholdSheet` component built, wired in `stock-alert-table.tsx`, `StockLevelService::reEvaluateActiveAlertForLevel()` added + called from `update()`, `StockAlertResource` exposes `stock_level_id`; TASKS.md T5.3a/T5.3b markers stale (code is complete) |

---

## Issues

### Warnings

| # | File | Line | Issue | Suggested Fix |
|---|------|------|-------|---------------|
| W1 | `admin-web/src/app/shop/[shopSlug]/stock/alerts/components/stock-alert-table.tsx` | ~3 | Imports `MoreHorizontal` from lucide-react. Project convention (per AGENTS.md sibling check and DESIGN.md S3 spec) is `EllipsisVertical`. | Replace `MoreHorizontal` with `EllipsisVertical` in the import and JSX usage. |
| W2 | `admin-web/src/app/shop/[shopSlug]/stock/alerts/components/stock-level-threshold-sheet.tsx` | 44 | `StockLevelThresholdSheetProps` is declared as `interface` (not `export interface`). AGENTS.md rule: "Export the XxxProps type." This breaks the ability for parent components to reuse the type. | Change `interface StockLevelThresholdSheetProps` → `export interface StockLevelThresholdSheetProps`. |
| W3 | `backend/app/Http/Controllers/Api/V1/HQ/ProductSkuController.php` | ~303–327 | The `@OA\Put` spec for `PUT /hq/{brandSlug}/skus/{sku}` does not list `inventory_mode` in the `requestBody` properties. The field is accepted by `ProductSkuUpdateRequest` and works at runtime, but the OpenAPI doc is incomplete, which will cause swagger-generated clients to omit the field. | Add `@OA\Property(property="inventory_mode", type="string", enum={"made_to_order","track_stock"})` to the requestBody schema in the controller docblock. |
| W4 | `backend/tests/Feature/Inventory/StockTransactionAllowNegativeTest.php` | ~276–308 | TESTS.md Validation 2 (`min_stock > max_stock` → expects HTTP 422) is intentionally pinned to assert HTTP 200 because BUG-3 (`StockLevelUpdateRequest` lacks cross-field validation) is left unfixed. The test comment acknowledges this but the gap means the validation regression will not be caught if someone adds the rule later without updating the test expectation. | Either fix BUG-3 now (add `'lte:max_stock'` rule to `StockLevelUpdateRequest`) and flip the assertion to 422, or open a tracked issue so this is not forgotten. |

### Informational

| # | File | Issue |
|---|------|-------|
| I1 | `backend/app/Observers/StockAlertNotificationObserver.php:64` | `'min_stock' => (float) $alert->min_stock` silently casts `null` to `0.0` when an alert is fired from the allow-negative path (stock level has no configured threshold). Notification recipients will see "Min stock: 0" which is technically inaccurate — there was no threshold, not a threshold of zero. Consider passing `null` and handling in the notification template, or omitting the key when null. |
| I2 | `plans/plan-024/TESTS.md` | **Authz 7** ("notification audience scoped to warehouse W, not warehouse X manager") has no corresponding Pest test. Low risk since `NotificationService::Audience::byRole()->scopedTo()` is tested by plan-023, but coverage gap is documented. |
| I3 | `plans/plan-024/TESTS.md` | **Side effect 3** ("alert resolution fires no notification") has no corresponding Pest test. |
| I4 | `plans/plan-024/TASKS.md` | T5.3a and T5.3b are marked `[ ]` but the code (`stock-alert-table.tsx` dropdown item wired to `StockLevelThresholdSheet`) is fully implemented. Task tracker is stale — mark both complete before closing the plan. |

---

## Bug Verification (NOTES.md 2026-05-20)

| Bug | Status | Verified At |
|-----|--------|-------------|
| **T0.6** `submit()` never called after `create()` | FIXED | `OrderClosingService.php` — both Phase 1 and Phase 2 transactions have explicit `submit()` calls |
| **BUG-1** `pickLotsForConsumption()` throws on residual demand | FIXED | `StockTransactionService.php` — `$allowShort=false` default param; `$remaining > 0 && $allowShort` branch appends null-lot line |
| **BUG-2** `StockAlert.min_stock` NOT NULL breaks allow-negative alert | FIXED | `schemas/Backend/Inventory/StockAlert.yaml` — `nullable: true` added; migration regen'd |
| **BUG-3** `min_stock > max_stock` accepted without 422 | NOT FIXED (pre-existing, documented) | See W4 above |
| **BUG-4** Soft-deleted material causes close to throw | FIXED | `OrderClosingService::emitMaterialConsumptionTransaction()` uses `Material::whereIn()` (no `withTrashed`) to filter aggregated IDs |

---

## Test Coverage Assessment

- **42 scenarios** in TESTS.md; browser tests (T7.x) require live browser runner (Playwright/Dusk), not CI-executable without setup.
- **Happy path**: 1–10 all covered across `ProductSkuInventoryModeTest`, `OrderClosingInventoryModeTest`, `OrderClosingMaterialDeductionTest`, `StockTransactionAllowNegativeTest`, `StockLevelThresholdReevaluationTest`.
- **Edge cases 1–6**: All covered, including FEFO allocation, voided items, no-recipe SKUs, negative-qty after shortage.
- **Authz 1–6**: All covered. Authz 7 (notification audience scoping) not covered — see I2.
- **Side effects 1, 4**: Covered. Side effect 3 not covered — see I3.
- **Validation 2**: Pinned as bug (see W4).
- **Error handling 3, 4**: Covered in `OrderClosingMaterialDeductionTest`.

---

## Files Changed (for PR reviewers)

### Backend — Schema / Codegen
- `schemas/Backend/Product/ProductSku.yaml` — `inventory_mode` field added
- `schemas/Backend/Inventory/Warehouse.yaml` — `allow_negative_sales` field added
- `schemas/Backend/Inventory/StockAlert.yaml` — `min_stock` made nullable
- `schemas/Backend/Inventory/StockTransactionSubType.yaml` — `sales_material_consumption` value added
- `schemas/Shared/Enum/ProductSkuInventoryMode.yaml` — new enum file

### Backend — Generated (do not hand-edit)
- `backend/database/migrations/*_add_inventory_mode_to_product_skus_table.php`
- `backend/database/migrations/*_add_allow_negative_sales_to_warehouses_table.php`
- `backend/database/migrations/*_make_min_stock_nullable_on_stock_alerts_table.php`
- `backend/app/Enums/ProductSkuInventoryModeEnum.php`
- `backend/app/Enums/StockTransactionSubTypeEnum.php` (amended)

### Backend — Application Code
- `backend/app/Services/Customer/OrderClosingService.php` — G2, G3, T0.6, BUG-4
- `backend/app/Services/Inventory/StockTransactionService.php` — G4, BUG-1, BUG-2
- `backend/app/Services/Inventory/StockLevelService.php` — G6 (`reEvaluateActiveAlertForLevel`)
- `backend/app/Http/Requests/ProductSkuUpdateRequest.php` — G1 validation
- `backend/app/Http/Controllers/Api/V1/HQ/ProductSkuController.php` — G1 OA (incomplete — see W3)
- `backend/app/Http/Controllers/Api/V1/Inventory/WarehouseController.php` — G4 OA + inline validation
- `backend/app/Http/Resources/StockAlertResource.php` — G6 `stock_level_id` field

### Backend — Tests
- `backend/tests/Feature/Catalog/ProductSkuInventoryModeTest.php`
- `backend/tests/Feature/Customer/OrderClosingInventoryModeTest.php`
- `backend/tests/Feature/Customer/OrderClosingMaterialDeductionTest.php`
- `backend/tests/Feature/Inventory/StockTransactionAllowNegativeTest.php`
- `backend/tests/Feature/Inventory/StockLevelThresholdReevaluationTest.php`
- `backend/tests/Browser/Shop/StockAlertsThresholdTest.php` (requires browser runner)
- `backend/tests/Browser/Hq/ProductSkuInventoryModeTest.php` (requires browser runner)

### Admin-web — Components
- `admin-web/src/app/shop/[shopSlug]/stock/alerts/components/stock-level-threshold-sheet.tsx` — G6
- `admin-web/src/app/shop/[shopSlug]/stock/alerts/components/stock-alert-table.tsx` — G6 wiring
- `admin-web/src/app/hq/[brandSlug]/products/[id]/skus/[skuId]/page.tsx` — G1 form field
- `admin-web/src/app/shop/[shopSlug]/warehouses/components/warehouse-form-dialog.tsx` — G4 toggle

### Admin-web — i18n
- `admin-web/src/locales/ja.json` — `inventory_mode`, `threshold_sheet.*`, `allow_negative_sales`
- `admin-web/src/locales/en.json` — same keys
- `admin-web/src/locales/vi.json` — same keys

### Admin-web — Generated (Omnify)
- `admin-web/src/types/generated/*.ts` — TypeScript types regen'd from YAML
- `admin-web/src/hooks/api/use-stock-levels.ts` (if regenerated) — mutation hooks

---

## Action Items Before Merge

1. **(W1)** Fix `MoreHorizontal` → `EllipsisVertical` in `stock-alert-table.tsx`
2. **(W2)** Export `StockLevelThresholdSheetProps` interface
3. **(W3)** Add `inventory_mode` to the OpenAPI requestBody spec in `ProductSkuController`
4. **(W4 / BUG-3)** Decide: fix cross-field validation now, or create a tracked issue
5. **(I4)** Mark T5.3a and T5.3b complete in `TASKS.md`
