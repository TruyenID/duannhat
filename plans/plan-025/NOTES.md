# Plan 025 — Notes

> Working log for [Product Review](README.md). Append-only. Newest entries on top.

Use this file for:
- Decisions made during execution (with reasoning)
- Blockers and how they were resolved
- Context discovered while researching
- Links to relevant code, PRs, conversations
- Anything you want future-you (or another contributor) to know

---

## 2026-05-22 — Implementation complete

- **Branch**: `feature/plan-025-product-review`
- **Commits**: 7 (schema+codegen, service, API+menu, review-UI, display-mocks-removed, tests, final)
- **Tests**: 26 passing (0 new failures in full suite; 63 pre-existing unrelated failures)
- **TypeScript**: clean (`npx tsc --noEmit` exits 0)
- **Pint**: clean

### Decisions made during execution

1. Service placed at `app/Services/Customer/ProductReviewService.php` (hand-written, not Omnify sibling) since the review business logic is customer-specific and doesn't need generated CRUD
2. `CustomerOrderStatus::Closed` (not `Paid`) is the gate — this matches the existing pattern
3. Used `toEqual()` instead of `toBe()` in Pest tests for integer DB column comparisons — SQLite returns strings from columns without explicit casts
4. Menu item `id` field is `$menuProduct->id` (not `$product->id`), so menu integration tests use `->first()` since we know only one product is in the test menu
5. Browser tests (T6.11) skipped — no Pest browser infrastructure in this project; all flows are covered by Feature tests + manual verification

### Notes on pre-existing test failures

The 63 pre-existing failures are in:
- `RecurringNotificationDispatcherTest` (missing dependency)
- `RecurrSmokeTest` (missing vendor package)
- `BrandScopedApiTest` / `NotificationScheduleAdminController` (unrelated domain)

None were introduced by plan-025.

---

## 2026-05-22 — Plan rebuilt to Omnify template

Rebuilt all plan files from the 2026-05-21 draft to follow the standard Omnify plan template format. Added:
- YAML frontmatter in README
- Full endpoint detail blocks in DESIGN
- Screen detail blocks
- Authorization matrix (simple — all public endpoints)
- User journeys (dine-in + takeaway)
- Field lifecycle (ProductReview new + Product aggregate additions)
- Sitemap
- Alternatives considered + Risks & mitigations
- Cross-checked TESTS.md against DESIGN endpoint/screen inventory

No design decisions changed — same architecture, same API surface, same data model.

## 2026-05-21 — Plan created

**Status**: `draft` (not yet implementing)

### Context

- Customer menu hardcodes `rating = 94` + deterministic hash review count (not `Math.random()` — uses char-code hash for stability).
- Mock locations: `menu-list-item.tsx:41`, `featured-items-carousel.tsx:108-110`, `menu-card.tsx:91` (optional gate already correct).
- `data/menu.ts` already declares `MenuItem.rating?: number` and `reviewCount?: number` — backend just needs to populate them.
- `CustomerMenuService` already overlays `active_promotion` per plan-019 — same pattern for `rating`/`reviewCount`.
- Customer order endpoints use order UUID as opaque token (no auth): `show`, `applyCoupon`, `paymentIntent`. Review endpoints follow the same pattern.

### Decisions locked during planning

1. **Auth via order_id** (opaque token, no login) — dine-in is anonymous (QR scan).
2. **Gate via `customer_order_item_id` unique** — proof of purchase + one vote per item.
3. **Per-Product granularity** (not per-SKU) — avoids data fragmentation.
4. **Binary thumbs up/down** — maps to "% recommend" UI; simpler than star rating.
5. **Denormalized aggregate on Product** (`review_up_count`, `review_total_count`) — avoids GROUP BY on every menu request.
6. **Hide badge when `review_count = 0`** — no misleading "0%".

### Open questions (to resolve before execution)

- [ ] Include `comment` text field in v1 schema? (Plan includes it as nullable, hidden from menu.)
- [ ] Review window: only immediately post-payment, or anytime if order is `closed`?
- [ ] Throttle config for POST review? (Standard Laravel `throttle:` middleware?)
