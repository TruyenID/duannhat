---

plan: 022
issue: 257
title: "Material System — Correctness Fixes & UX Hardening"
slug: material-system-correctness
status: shipped
branch: "feature/plan-022-material-correctness"
created: 2026-05-12
updated: 2026-08-05

landed_via: >-
  merged to dev (feature branch deleted); tracker closed. TASKS.md
  checkboxes are NOT the completion signal — plan-028 sits at 0/123 and plan-051
  at 0/15 while both shipped (#1842). Verified by: no feature branch remains,
  plus a closed tracker or the plan's subject being present in the tree.
---

# Plan 022 — Material System — Correctness Fixes & UX Hardening

> Fix 9 logic bugs và 6 UX gaps trong hệ thống Material/Batch/Lot/Recipe/Unit/Stock đã ship trong plan-017. Deep-dive analysis phát hiện các lỗi từ unit conversion stub, dual BOM drift, best-effort genealogy tới production lot invisible expiry. Plan này chỉ cover **correctness** — operational features (reservation, split, timeline, substitution) nằm trong plan-018 (đã archive — xem git history).

## Status

- **Current:** `draft`
- **Created:** 2026-05-12
- **Owner:** @Alexdev257
- **Branch:** _(TBD — `feature/plan-022-material-correctness`)_
- **Predecessor:** plan-018 (đã archive — xem git history) (draft) — features plan, runs BEFORE this plan
- **Foundation:** plan-017 (đã archive — xem git history) (shipped) — the codebase this plan fixes
- **Reference:** [material-system-deep-dive.md](../material-system-deep-dive.md) — full gap analysis

## Execution order

```
plan-017 (shipped) → plan-018 (features) → plan-022 (THIS — correctness fixes)
```

Plan-022 runs **after** plan-018. This plan fixes fundamental bugs in the plan-017 code. Plan-018 features (reservation, split, timeline, etc.) are already shipped by this point and automatically benefit from these fixes.

## Motivation

Plan-017 shipped 97 tasks, 80 Pest tests, 260 assertions — nhưng deep-dive analysis + code audit phát hiện:

### Logic errors (code đã ship, chạy sai)

| # | Lỗi | Impact | Ref |
|---|---|---|---|
| 1 | `MaterialBatchService::deriveItemsFromRecipe()` đọc `Material.components`, **KHÔNG đọc Recipe** | Batch bypass Recipe approval workflow. Hai BOM drift. | §5.2 |
| 2 | `calculateBaseQuantity()` return 1:1 (stub) | Receive `2 bag_25kg` → ghi `qty=2` thay vì `50000g`. Toàn bộ ledger sai. | §5.11 |
| 3 | `recordSalesGenealogy()` ghi ALL active lots | Recall blast radius 5-10x. Vi phạm FSMA-204. | §5.1 |
| 4 | Production lot `expiry_date = null` | BTP shelf life ngắn (dashi 3h) vô hình với alert + FEFO. | §5.3 |
| 5 | `cancel()` chỉ accept Draft/Pending | Batch `in_progress` (đã trừ NVL) không cancel được → stuck. | §5.4 |
| 6 | Yield validation không phân biệt raw/produced | Raw material accept `yield_unit` vô nghĩa. Produced thiếu `yield_unit` → output lot unit undefined. | §11 |
| 7 | Không có auto-expire cron | Lot past expiry vẫn `active` → FEFO tiếp tục pick lot hết hạn. | §5.3 |
| 8 | `start()` FEFO chỉ pick 1 lot per item | `firstWhere('qty_on_hand', '>=', planned_qty)` — nếu không có lot đủ lớn → NULL stamp dù tổng stock đủ. | code |
| 9 | Batch tạo được mà không cần Recipe approved | Không có guard "recipe phải approved trước khi batch submit/approve". | code |

### UX gaps (REVIEW.md flags)

| # | Gap | Ref |
|---|---|---|
| 10 | Receive form UUID input (not usable) | REVIEW #11 |
| 11 | HQ list pagination broken page 2+ | REVIEW #12 |
| 12 | Force-dispose no confirm dialog | REVIEW #13 |
| 13 | Rate-limit test missing | REVIEW #10 |
| 14 | MaterialUnit settings page deferred | T9.4 |
| 15 | Trace entry page deferred | T9.3 |

## Deep-dive coverage

This plan covers the following items from [material-system-deep-dive.md](../material-system-deep-dive.md):

| Deep-dive ref | Item | Plan-022 task |
|---|---|---|
| Nhóm A — A1 | Real-time FEFO sales-edge genealogy | T8 |
| Nhóm A — A2 | `Material.shelf_life_days` + production expiry | T5 |
| Nhóm A — A3 | Cancel batch from in_progress reverse stock | T7 |
| Nhóm A — A4 | Unify Recipe.ingredients ↔ Material.components | T4 |
| Nhóm A — A5 | Auto-expire cron | T6 |
| Nhóm A — A6 | MaterialUnit ratio conversion | T1 |
| Nhóm D — D1 | Receive form Combobox | T10 |
| Nhóm D — D2 | HQ list pagination | T11 |
| Nhóm D — D3 | Force-dispose confirm dialog | T12 |
| Nhóm D — D4 | Rate-limit test | T13 |
| Nhóm D — D5 | MaterialUnit settings page | T14 |
| Nhóm D — D6 | Trace entry page | T15 |
| Nhóm H — H1 | Notification template recall | T16 |
| Nhóm H — H2 | Notification template expiry | T16 |
| §11 | Yield validation raw vs produced | T2 |

## In scope — 5 Phases

> **PR sequencing** (see `TASKS.md` "PR strategy" section): Phase 1 ships as **4 PRs** — PR-1 (T1) → PR-2 (T4.2 → T3 → T4.3-T4.4) → PR-3 (T4.5 drop column, 1 sprint later) → PR-4 (T2). T4.2 must run before T3 — otherwise every batch creation 422s on deploy.

### Phase 1 — Foundation (MUST first, mọi thứ phụ thuộc vào đây)

| Task | Hạng mục | Effort |
|---|---|---|
| **T1** | Implement `MaterialUnit.ratio` conversion trong toàn bộ stock pipeline (+ pre-deploy guard, StockCount audit) | 1-2 ngày |
| **T4.2** | Migration: auto-create Recipe from `Material.components` (runs BEFORE T3) | 4 giờ |
| **T3** | Recipe ↔ Batch integration: batch đọc Recipe, require approved | 1 ngày |
| **T4.3-T4.5** | Service rewire + frontend cleanup + drop `components` column (1 sprint soak between) | 1-2 ngày |
| **T2** | Yield validation: raw material vs produced material (depends on T4) | 4 giờ |

### Phase 2 — Safety (food safety + data safety)

| Task | Hạng mục | Effort |
|---|---|---|
| **T5** | `Material.shelf_life_days` + computed expiry cho production lot | 4-6 giờ |
| **T6** | Auto-expire cron: `status=expired` khi `expiry_date < today` | 4 giờ |
| **T7** | Cancel batch từ `in_progress`: reverse stock + reversal genealogy | 1 ngày |

### Phase 3 — Correctness (trace accuracy)

| Task | Hạng mục | Effort |
|---|---|---|
| **T8** | Sales-edge FEFO: precise genealogy thay best-effort | 1 ngày |
| **T9** | `start()` FEFO preview: multi-lot split khi 1 lot không đủ | 4 giờ |

### Phase 4 — UX gaps

| Task | Hạng mục | Effort |
|---|---|---|
| **T10** | Receive form: Combobox material/warehouse | 4 giờ |
| **T11** | HQ material-lots list: pagination wire-up | 2 giờ |
| **T12** | Confirm dialog cho force-dispose | 2 giờ |
| **T13** | Rate-limit Pest test cho `/trace/*` | 1 giờ |
| **T14** | MaterialUnit settings sub-page | 4 giờ |
| **T15** | Trace entry page `/hq/[brand]/trace` | 4 giờ |

### Phase 5 — Notification + verification

| Task | Hạng mục | Effort |
|---|---|---|
| **T16** | Notification templates (recall + expiry) | Blocked plan-008 |
| **T17** | Full regression test suite | 2 giờ |

**Total estimate:** ~12-18 ngày dev (1 person)

## Out of scope

- **Operational features** (reservation, split, allergen warehouse, timeline, drill, yield report, substitution, return, workstation lots, CoA) → plan-018 (đã archive — xem git history) (runs before this plan)
- **Supplier entity + PO workflow** → plan-019
- **Full HACCP CCP** (IoT thermometer, structured codes, inspector identity) → plan-020
- **Barcode / RF scanner** → separate plan
- **Lot-preserving warehouse transfer** → separate plan
- **Lot-grain stock count** → separate plan

## Success criteria

### Phase 1 — Foundation
- [ ] Receive `2 bag_25kg` (ratio=25000) → `qty_on_hand = 50000g`, `stock_levels.quantity = 50000g`
- [ ] Raw material (no recipe) rejects `yield_unit`. Produced material requires `yield_unit` + matching MaterialUnit
- [ ] Create batch → reads `Recipe.ingredients` (not `Material.components`)
- [ ] Batch submit rejects if Recipe not `approved`
- [ ] `Material.components` column dropped. `grep -r "components" backend/app/` returns 0 hits (except migration)

### Phase 2 — Safety
- [ ] Batch complete for material `shelf_life_days=3` → output lot `expiry_date = today+3`
- [ ] `php artisan material-lots:auto-expire` → lots past expiry flip to `expired`
- [ ] Cancel batch from `in_progress`: lot qty restored, reversal genealogy edges written

### Phase 3 — Correctness
- [ ] Order with 3 active peanut lots → only 1 genealogy edge (FEFO-first lot). Recall lot #3 does NOT affect this order
- [ ] `start()` with item needing 12kg, lot #1 has 5kg, lot #2 has 8kg → stamps both lots (preview)

### Phase 4 — UX
- [ ] Receive form uses Combobox. Pagination page 2+ works. Force-dispose shows confirm.

### Phase 5 — Verification
- [ ] All existing plan-017 + plan-018 tests green (no regression)
- [ ] `pnpm typecheck && pnpm lint` clean

## Dependencies

- **plan-017** (shipped) — the codebase this plan fixes
- **plan-018** (should be shipped) — features that build on plan-017, this plan's fixes benefit them
- **plan-008** (shipped, Phase A) — blocks T16 notification templates
- **Omnify codegen** — T4 (unify BOM) requires schema changes + regen

## Open questions

- [x] **T4 migration strategy.** ~~Auto-migrate `Material.components` → `Recipe.ingredients`, or require manual re-entry?~~ **Resolved 2026-05-14:** DB is empty per code audit ([NOTES.md](NOTES.md)), so auto-migration is the safe path-of-least-resistance with zero production data risk. Migration script (T4.2) auto-creates approved Recipe from any non-empty `components`.
- [x] **T1 backfill.** ~~Existing `stock_levels` rows have qty in mixed units. Backfill?~~ **Resolved 2026-05-14:** DB empty → no backfill needed. Added T1.7 pre-deploy guard that aborts migration if any lot rows exist in non-base unit (safety net for staging/prod with seeded data).
- [ ] **T6 auto-expire timing.** 8am JST (1h after alert cron) — ops gets alert first, then lot expires. Confirm. (Race window concern noted in DESIGN — `pickLotsForConsumption` uses `lockForUpdate` so per-row consistency holds even if cron flips status mid-FEFO.)
- [x] **T7 genealogy on cancel.** ~~Delete original edges, or append reversal edges?~~ **Resolved:** Append (FSMA-204 append-only policy). T7.2 added to update `TraceService` to filter `source_event_type='reversal'` out of recall blast radius.
- [ ] **T8 batched-SKU exact trace.** Path A in T8.1 traces batched SKUs via source batch's `stock_out_transaction_items`. This requires knowing which batch produced the SKU sold — needs `produced_by_batch_id` lookup logic on the SKU/material at order-close time. Confirm the linkage: do we use "most recent completed batch for the material" or do we need an explicit SKU→batch pointer (workstation-app D.1 follow-up)?

## Files in this plan

- [DESIGN.md](DESIGN.md) — schema diffs, migration strategy, flow diagrams, sequencing
- [NOTES.md](NOTES.md) — research + working log

## Related

- [material-system-deep-dive.md](../material-system-deep-dive.md) — source analysis
- plan-017 (đã archive — xem git history) — foundation (shipped)
- plan-018 (đã archive — xem git history) — features (runs before this plan)
