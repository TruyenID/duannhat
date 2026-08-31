---
plan: 025
issue: 277
title: Product Review
slug: product-review
status: shipped
branch: feature/plan-025-product-review
created: 2026-05-21
updated: 2026-08-05
landed_via: >-
  merged to dev (feature branch deleted). TASKS.md checkboxes are NOT the
  completion signal — plan-027 sits at 0/250 while godx-kds is a live shipping app
  (#1818). Verified by: no feature branch remains, plus a closed tracker or the
  plan's subject being present in the tree.
---

# Plan 025 — Product Review

> Replace hardcoded mock ratings on customer menu with real binary (thumbs up/down) reviews collected after order payment; display recommend % + review count from aggregate data.

## Status

- **Current:** `draft`
- **Created:** 2026-05-21
- **Updated:** 2026-05-22
- **Owner:** _(assign)_

## Motivation

Customer menu currently displays `94% (526)` as hardcoded mock social proof (`rating = 94` in `menu-list-item.tsx`, `?? 94` / `?? 526` in `featured-items-carousel.tsx`, deterministic hash for count). No review collection UI, no DB table, no API exists. This plan builds the full review pipeline: collect binary ratings from customers post-payment, aggregate per product, and replace mocks with real data.

## In scope

- Omnify schema: `ProductReview` entity + `ReviewSentiment` enum + 2 aggregate columns on `Product`
- Backend service: `ProductReviewService` (submit with transaction + lock, reviewable query, recommend helper)
- Backend API: 2 customer-facing public endpoints (reviewable items list, batch review submit)
- Customer-web: review submission sheet (dine-in `paid-view` + takeaway `order-success`)
- Customer-web: replace mock rating/review count on menu cards with real aggregate data
- i18n (ja/en/vi) for all new UI strings

## Out of scope

- Edit/delete review by customer
- Admin moderation UI / review listing page
- Display review comment text on menu (aggregate % only)
- Star rating system (binary thumbs up/down only)
- Reconciliation cron job for aggregate drift (optional future)
- Per-SKU rating (per-Product only)
- Customer login requirement (dine-in is anonymous via QR)

## Success criteria

- [ ] `POST /api/v1/customer/orders/{id}/reviews` creates ProductReview rows and atomically updates Product aggregate (`review_up_count`, `review_total_count`)
- [ ] `GET /api/v1/customer/orders/{id}/reviewable` returns order items with `already_reviewed` flag
- [ ] Unique constraint on `customer_order_item_id` prevents double-vote; duplicate submissions are skipped (not errored)
- [ ] `CustomerMenuService` populates `rating` (recommend %) and `reviewCount` from Product aggregate
- [ ] Menu cards hide rating badge when `reviewCount = 0` (no "0%" display)
- [ ] OrderReviewSheet renders post-payment (dine-in + takeaway) with skip option
- [ ] All Pest Feature/Unit test scenarios from TESTS.md pass
- [ ] `pnpm typecheck` + `pnpm lint` clean (0 new errors)

## Dependencies

- `CustomerOrder` / `CustomerOrderItem` schemas (existing, plan-004)
- `Product` schema (existing)
- Customer-web `paid-view.tsx` + `order-success/page.tsx` (existing)
- Customer-web `MenuItem` type with `rating?` / `reviewCount?` fields (existing in `data/menu.ts`)

## Open questions

- [ ] Comment text field: include in v1 schema (nullable, hidden from menu) or defer entirely?
- [ ] Review window: only immediately after payment, or allow return via order_id anytime (if order is `closed`)?
- [ ] Rate-limit for POST review endpoint (throttle config)?

## Files in this plan

- [DESIGN.md](DESIGN.md) — approach, decisions, alternatives
- [NOTES.md](NOTES.md) — working log, decisions, blockers

## Related

- [plan-019](../plan-019/README.md) — Coupon & Menu Promotion (reference: menu payload overlay pattern via `CustomerMenuService`)
- plan-004 (đã archive — xem git history) — Dine-in Order, Payment & Transaction Flow (order lifecycle, payment endpoints)
