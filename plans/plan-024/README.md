---
plan: 024
issue: 268
title: "Stock Management — Auto-Deduct, Inventory Mode, Alert Notifications"
slug: stock-management
status: shipped
branch: "plan-024-stock"
created: 2026-05-20
updated: 2026-08-05
landed_via: >-
  merged to dev (feature branch deleted); tracker closed. TASKS.md
  checkboxes are NOT the completion signal — plan-028 sits at 0/123 and plan-051
  at 0/15 while both shipped (#1842). Verified by: no feature branch remains,
  plus a closed tracker or the plan's subject being present in the tree.
---

# Plan 024 — Stock Management — Auto-Deduct, Inventory Mode, Alert Notifications

> Close the 6 known gaps in the existing stock infrastructure (built by plan-017/018/022): add `inventory_mode` flag to ProductSku, gate stock-out on it, deduct recipe Materials on order paid, allow-negative-on-shortage with alert, wire NotificationService into StockAlert, and add inline threshold-edit on the alerts page. Backend + admin-web only. Preserves everything that already works.

## Status

- **Current:** `reviewing` (second-pass enhancements landed 2026-05-21)
- **Created:** 2026-05-20
- **Approved:** 2026-05-20 by user
- **Execute started:** 2026-05-20
- **Owner:** @lamtailoi2
- **Branch:** `plan-024-stock`

### 2026-05-21 second-pass session — bugs surfaced + fixed during E2E

Manual E2E walk (material → product → recipe → stock-in → lot receive → transfer → menu → customer order → admin close) surfaced six bugs touching plan-024 code paths. All admin-side bugs were fixed in this session; two customer-web bugs were noted only (out of scope per the original plan). Full details in [NOTES.md](NOTES.md).

- **Backend correctness**
  - `StockTransferService` enum vs string comparison — stock-out / stock-in transactions stuck in `pending` on approve/receive. Fixed by `=== StockTransactionStatusEnum::Pending`.
  - `WarehouseController::store` left `branch_id` null on shop-scoped POST → new warehouses missing from list. Now defaults to the resolved shop branch.
  - `StockLevelUpdateRequest` lacked `min_stock ≤ max_stock` cross-field rule (plan-024 TESTS.md Validation 2). Closed.
  - `CustomerOrderController::confirm()` endpoint missing — customer-web takeaway orders landed in `pending` with no way for admin to confirm. Added `POST /confirm` + service method `confirmOrder()`.
- **Admin-web UI**
  - `Combobox` value encoding pushed newly-created items below cmdk's visibility cutoff. Switched to `display·id` encoding. Affects all stock pickers + lot receive form.
  - Stock transfer line items: FE sent `quantity` but backend expects `sent_quantity`. Mapper added in the service.
  - Unit field locked to readonly + auto-fill from `material.yield_unit` / `"pcs"` for SKUs (per plan-024's "input is always base unit" rule).
  - Warehouse create dialog: surfaced the `allow_negative_sales` toggle on create (it was hidden, causing 422 on every POST). Description moved into a "?" popover.
  - Transfer detail confirm modals rewritten with proper title + DialogDescription + dynamic CTA per action, localized in ja/en/vi.
  - Transfer detail labels disambiguated: "Ghi chú phiếu" (header) vs "Ghi chú dòng" (per-item table).
- **Tests**
  - Added `StockAlertNotificationDispatchTest` (4 scenarios — G5 dispatch, audience scope, NotificationService throws, silent resolution).
  - Added `OrderClosingVoidedItemMaterialTest` (2 scenarios — voided items skip Phase-2 material deduction).
  - Flipped the "pinned current behaviour" allow-negative test that pinned the missing `min > max` validation; now asserts 422.
  - Full plan-024 suite: **48/48 passing (120 assertions, ~9s)**.
- **Customer-web bugs noted (NOT fixed — out of plan-024 scope)**
  - `CustomerMenuService` query uses `'active'` lowercase vs enum value `'Active'` → 404 on every customer menu fetch.
  - Branch timezone resolver falls back to UTC instead of Asia/Tokyo when `branches.timezone` is null → schedule windows mis-match during normal Tokyo business hours.

## Motivation

The stock domain has substantial infrastructure shipped by plan-017 (lot tracking), plan-018 (operational features), and plan-022 (correctness fixes): Warehouse, StockLevel, StockTransaction (with submit/approve/cancel workflow), StockMovement, StockAlert (auto-create/resolve), StockCount (full + partial workflow), StockTransfer, plus admin-web pages for all of them.

However, a deep audit of the existing implementation against the user's stated needs surfaced six concrete gaps:

1. **`ProductSku` has no `inventory_mode`** — every order close drains stock for ALL SKU items, including made-to-order dishes where stock tracking is meaningless. Causes false negative stock and noisy alerts.
2. **`OrderClosingService` records Recipe→Material genealogy edges but never writes the corresponding Material stock movements** — `recordSalesGenealogy()` (OrderClosingService.php:188-261) walks `Recipe→Materials` for FEFO preview only; no `StockTransaction` / `StockMovement` / `StockLevel` update lands for the materials. The ingredient ledger drifts from reality on every sale.
3. **`StockTransactionService.completeTransaction` is strict hard-stop on shortage** — throws `InsufficientStockException` and rolls back the order close. For high-volume restaurants, this blocks POS flow when stock momentarily goes below zero. Users want allow-negative + alert behaviour.
4. **`StockAlert` records are created but no notification fires** — alert rows land in the DB but no in-app or email notification is sent. Staff has to actively poll the alerts page to discover problems.
5. **Stock alerts page is read-only** — to edit a min/max threshold you have to navigate from alerts → stock levels → level detail. Slow when triaging a stack of alerts.
6. **No tests cover the planned Material-via-Recipe deduction path** — because the path doesn't exist yet.

This plan addresses ONLY these six items. Everything else in the stock domain stays as-is.

## In scope

- **G1 — `ProductSku.inventory_mode` enum** (`track_stock | made_to_order`, default `made_to_order`): schema YAML edit, omnify regen (migration, model cast, types), admin-web ProductSku form field.
- **G2 — `OrderClosingService` gates SKU stock-out on `inventory_mode`**: skip stock-out for `made_to_order` SKUs (current behaviour drains stock indiscriminately).
- **G3 — `OrderClosingService` deducts Recipe → Material on close for `track_stock` SKUs**: walk `order.items → productSku.recipe.ingredients`, build a second `stock_out / sales_material_consumption` `StockTransaction` per ingredient material. Targets the same warehouse used for the SKU stock-out (uses existing `getDefaultWarehouse()`). FEFO lot allocation kicks in automatically via existing `StockTransactionService` pre-pass.
- **G4 — `Warehouse.allow_negative_sales` boolean (default `false`)**: when true, `StockTransactionService.completeTransaction` no longer throws on sales-flow shortage; it writes the negative quantity, fires an `out_of_stock` `StockAlert`, and continues. Manual stock-out / disposal transactions remain strict (unchanged).
- **G5 — `NotificationService` wired into stock alert creation**: when `StockAlert` is created (low_stock or out_of_stock), dispatch a notification to **warehouse managers** of the affected warehouse, following the `ExpiryAlertService` pattern (try/catch wrapper, idempotency key).
- **G6 — Inline threshold-edit action on stock-alerts page**: add a sheet/popover on each alert row that calls existing `PUT /stock-levels/{id}` with new `min_stock`/`max_stock`/`alert_enabled`; if `quantity >= new_min`, the existing active alert auto-resolves.
- **Tests**: Pest Feature/Unit coverage for every new path. Specifically auto-deduct happy path, allow-negative behaviour, notification dispatch (mocked), threshold-edit re-evaluation.
- **Docs**: update `backend/docs/explanation/inventory-domain.md` and `backend/docs/explanation/stock-management.md` for the two new policies; add the `inventory_mode` semantics to the ProductSku reference.

## Out of scope

- **Stock transfer feature work** — already shipped under plan-017/018, no changes needed.
- **HQ-scoped stock routes** — shop-scoped only per locked decision.
- **Cross-shop transfers** — explicitly deferred.
- **VariantUnit/MaterialUnit ratio conversion** — input is always base unit per locked decision; conversion is plan-022 territory.
- **Per-lot stock count items for lot-tracked materials** — pre-existing plan-017 gap, documented as a follow-up (see open question below). Not in this plan.
- **Role-based policy hardening** — `StockTransactionPolicy.approve` currently lets any org member approve; warehouse `auto_approve_*` flags are the de-facto gate today. Tighter explicit policy is deferred.
- **Recipe-missing handling beyond skip+warn** — if a `track_stock` SKU has no recipe, we silently skip material deduction and log a warning. No alert, no block.
- **Reorder suggestions** — not in scope (user opted out).
- **POS / customer-web / tms-app / workstation-app changes** — backend + admin-web only.

## Success criteria

- [ ] Setting `inventory_mode=made_to_order` on a ProductSku stops the auto stock-out at order close (verified by Pest test).
- [ ] Setting `inventory_mode=track_stock` on a ProductSku triggers Recipe-based Material deduction at order close (verified by Pest test that asserts `StockLevel.quantity` decreases for each ingredient).
- [ ] Closing an order on a warehouse with `allow_negative_sales=true` succeeds even when Material stock is short, writes negative `StockLevel.quantity`, and creates an `out_of_stock` `StockAlert` (verified by Pest test).
- [ ] Closing an order on a warehouse with `allow_negative_sales=false` continues to throw `InsufficientStockException` on shortage (unchanged behaviour, verified by Pest test).
- [ ] Every newly created `StockAlert` dispatches a notification through `NotificationService` to all warehouse managers, with idempotency key `stock_alert:{alert_id}` (verified by Pest test using a fake `NotificationService`).
- [ ] On the admin-web stock-alerts page, an inline action opens a sheet that updates `min_stock` / `max_stock` / `alert_enabled` via `PUT /stock-levels/{id}` and reflects auto-resolve when the new threshold drops below current quantity (verified by Pest browser test).
- [ ] All existing stock tests stay green (no regressions).
- [ ] `php artisan test --compact` is green; `pnpm typecheck` is green; `pnpm lint` is green.
- [ ] `pint --dirty --format agent` clean.

## Dependencies

- **Plan-017** (shipped) — Material/Recipe schemas + FEFO lot allocation in `StockTransactionService`. New material deductions ride the existing FEFO pre-pass.
- **Plan-022** (reviewing) — material correctness fixes. Plan-024 builds on top; if plan-022 finishes first, recipe-based deduction inherits the unit-conversion + dual-BOM fixes from there. If it doesn't, we still ship — recipe-ingredient quantities are stored in base unit already.
- **Plan-008 / 012 / 023** (shipped) — `NotificationService.dispatch` + the `stock.alert.out` / `stock.alert.low` priorities are already registered. Notification templates need a quick check (may or may not exist for these keys yet).

## Open questions

- [ ] **OQ-1 — Per-lot stock count items for lot-tracked materials**: `StockCountService.addItems` snapshots `system_quantity` from the first matching `StockLevel` row, ignoring lot_id. For materials with multiple active lots this is non-deterministic. Pre-existing gap, **not addressed in plan-024**. File a follow-up ticket after this plan ships.
- [ ] **OQ-2 — Notification template seed**: do `stock.alert.low` and `stock.alert.out` templates already exist in the seeded `NotificationTemplate` table (from plan-023 M8)? If not, the plan must add a seeder task. Verify in Phase 2 discovery.
- [ ] **OQ-3 — Existing data migration for ProductSku.inventory_mode**: default `made_to_order` is conservative (no behaviour change for un-tagged SKUs). However, shops that ALREADY relied on the implicit "all SKUs are stock-tracked" behaviour will see stock-outs stop firing on order close after deploy. Need a one-paragraph migration note in docs + a release-notes line.

## Files in this plan

- [DESIGN.md](DESIGN.md) — approach, decisions, alternatives
- [NOTES.md](NOTES.md) — working log, decisions, blockers

## Related

- plan-017 README (đã archive — xem git history) — material lot tracking foundation
- plan-018 README (đã archive — xem git history) — material operational features
- [plan-022/README.md](../plan-022/README.md) — material correctness fixes (in review)
- [plan-023/README.md](../plan-023/README.md) — notification completeness pass (in implementing) — provides notification platform plan-024 depends on
