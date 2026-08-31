---
plan: 016
title: Edit toppings on pending cart line
slug: edit-cart-toppings
status: shipped
branch: feature/plan-016-edit-cart-toppings
created: 2026-05-05
updated: 2026-08-05
depends_on: plan-015
landed_via: >-
  merged to dev (feature branch deleted). TASKS.md checkboxes are NOT the
  completion signal — plan-027 sits at 0/250 while godx-kds is a live shipping app
  (#1818). Verified by: no feature branch remains, plus a closed tracker or the
  plan's subject being present in the tree.
---

# Plan 016 — Edit toppings on pending cart line

> Lift the `Out of scope` from Plan 015 Decision 2: let staff edit topping selections on a cart line **as long as it's still `pending`** (not yet fired to the kitchen). When the line is `preparing` / `ready` / `served`, fall back to the existing void+re-add path.

## Status

- **Current:** `implementing`
- **Branch:** `feature/plan-016-edit-cart-toppings` (off `feature/plan-015-pos-topping-picker`)
- **Created:** 2026-05-05
- **Depends on:** plan-015 (uses `OrderItemTopping` + `validateAndPriceToppings` + `ProductOptionsDialog`)

## Decisions chosen

- **Pricing on edit:** **fresh** — re-snapshot from current `topping_group_item_skus.extra_price` at edit time. Customer is editing now, current menu price is fair. Frozen would confuse when admin changes prices mid-shift.
- **Edit button visibility:** show on **every pending line** (not just lines that already have toppings) — UX consistency, staff doesn't have to remember a conditional rule. Dialog handles "no topping groups" gracefully.
- **Audit log:** reuse the existing `updateItem` audit; no separate "topping edit" event. Granularity matches `qty edit` and `note edit`.

## In scope

- Backend: extend `CustomerOrderService::updateItem` to accept `toppings[]`, gate on `status=pending`, atomic replace OrderItemTopping rows, recompute totals.
- Backend: extend the `PATCH /shops/{}/orders/{}/items/{}` request validation to mirror addItem's topping rules.
- Frontend: ProductOptionsDialog gains an `edit` mode (pre-populated selections, "Save" button instead of "Add to cart").
- Frontend: OrderCart shows an Edit button on every pending line; tap opens the dialog.
- i18n: 2 new keys per locale (vi / en / ja).
- Tests: Pest feature tests for the new path (happy + 409 on preparing + validation reuse).

## Out of scope

- KDS recall flow (line is `preparing+`) — workstation-app territory, separate plan.
- Edit qty + topping in one request — keep concerns separate.
- Bulk edit across multiple lines — not requested.
- Audit log granularity — current updateItem audit suffices.

## Files

- (No DESIGN.md / TESTS.md / NOTES.md — this plan is small enough that the README + TASKS cover the contract.)

## Related

- Depends on plan-015 (POS topping picker + cart grouping). Must merge plan-015 first; plan-016 PR rebases onto dev when plan-015 lands.
