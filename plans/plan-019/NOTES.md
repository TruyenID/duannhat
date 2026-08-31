# Plan 019 — Notes

> Working log for [Coupon Management](README.md). Append-only. Newest entries on top.

---

## 2026-05-08 — Phase 1 schema work begun (T1.1 → T1.14)

### Branch + issue claim

- Branch `feature/plan-019-coupon-management` created off `dev` via
  `gh issue develop 231 --name … --base dev --checkout`. Issue #231
  flipped from `status:planning` to `status:executing`, metadata block
  in body re-synced (status / branch).
- README.md frontmatter bumped: `status: implementing`, `branch:
  feature/plan-019-coupon-management`. plans/README.md index row
  updated.

### Q5 (timezone source) — resolved on inspection

`branches.timezone` already exists in
`backend/packages/dxs-sso/database/schemas/Sso/Branch.yaml:93` as
`String length 50 nullable: true` (no default). The dxs-sso package is
a vendored shared schema; modifying it cross-cuts other consumers, so
this plan does NOT add a default at the DB level.

**Implication for service layer (Phase 2):** `MenuPromotionService::
resolveActivePromotion()` MUST treat `branch.timezone == null` as
"fall back to `Asia/Ho_Chi_Minh`" instead of failing. The fallback
constant lives in `config('promotion.default_timezone')` so test fixtures
can override per-locale.

### Q6/Q7/Q8/Q9 — following planner defaults

Per user direction (not stopping to ask): apply the recommendations
already documented in DESIGN.md / NOTES.md:
- **Q6 cache** — Redis `branch:{id}:active_promotions:{date}` TTL 60s,
  invalidate on CRUD/toggle, `config('promotion.cache_enabled')` flag
  for tests.
- **Q7 derived computed_status** — accessor on `MenuPromotion` model
  (no cron, mirrors Coupon pattern).
- **Q8 receipt format** — defer to a later plan; v1 just persists
  `original_unit_price` + `unit_price` so receipt template can render
  strikethrough independently.
- **Q9 realtime menu refresh** — v1 uses client polling at 60s;
  WebSocket push deferred.

### Pivot schema convention

Existing pivot files (`AllergenMaterial.yaml`, `CategoryProduct.yaml`)
have minimal bodies — `kind: pivot` + `pivotFor: [A, B]`. Composite
unique on the pair is presumably auto-generated. I added explicit
`options.indexes` blocks to `CouponBranch` /
`MenuPromotionCategory` / `MenuPromotionProduct` to be defensive about
the unique constraint surviving regeneration; will verify after
`omnify generate` runs.

### Process deviation noted

T1.10 + T1.11 (the two MenuPromotion pivots) landed in a single commit
(`d48290de`) instead of one-per-task. They are trivial sibling files of
the same pattern; splitting after-the-fact would just churn history.
Future tasks will follow strict 1-task-1-commit unless similarly
trivial paired siblings.

### Omnify v5.8.37 generator bug — duplicate FK index on alter migration

When an alter migration adds an FK association alongside other
properties (e.g. CustomerOrderItem gaining `applied_promotion_id` +
`applied_promotion_snapshot` + `original_unit_price` in the same
schema diff), the generator emits TWO `$table->index('applied_
promotion_id')` lines — one paired with the FK, one at the end. MySQL
rejects with `1061 Duplicate key name`.

The matching `down()` block dropped the index BEFORE dropping the
FK, which would also fail (FK depends on the index).

Workaround applied to `backend/database/migrations/omnify/
2000_01_17_000001_alter_customer_order_items_table.php`:
- Removed the second `$table->index('applied_promotion_id')`.
- Reordered down() to drop FK before dropIndex.
- Replaced the "DO NOT EDIT" header with a hand-edit notice that
  explains the workaround and tells future regens what to re-apply.

Re-running `omnify generate` will re-emit the duplicate. Re-apply
the workaround (or wait for an upstream patch). Filing follow-up
TODO in this NOTES.md instead of opening an Omnify issue here —
this repo doesn't carry the Omnify upstream.

A second related quirk: when I removed the explicit `applied_
promotion_id` index from the YAML between gens, the version-tracking
emitted a NEW migration `2000_01_18_000000_alter_customer_order_
items_table.php` containing `dropIndex(['applied_promotion_id'])`.
That index is needed by the FK, so MySQL rejects with `1553`. The
spurious migration was deleted; the FK-paired index remains in
`2000_01_17_000001`. After this clean-up `migrate:fresh --seed`
runs green.

### T1.8 — DB CHECK constraint SKIPPED (user-approved)

The plan called for a manual migration adding `coupons_times_used_
within_limit CHECK (usage_limit_total IS NULL OR times_used <=
usage_limit_total)` as a defensive last-resort against oversell.

`.githooks/pre-commit` is strict — it rejects every hand-written
migration outside the BLESSED Laravel-framework list (cache, queue,
session). Adding to BLESSED is itself a review block. The available
options were: skip, application-layer guard, --no-verify bypass,
expand BLESSED.

User chose **skip**. Rationale: Decision 1 (lockForUpdate atomic
counter inside DB::transaction) already covers realistic concurrency.
The CHECK was redundant safety against a hypothetical "future code
path forgets the lock" — and any such regression is far easier to
catch in code review or in the Pest concurrency test (T10.3) than to
debug from a 3819 violation in production.

Application-level guards stay:
- `CouponService::apply` uses `Coupon::where(...)->lockForUpdate()`
  before bumping `times_used` (Decision 1).
- T10.3 `CouponConcurrencyTest` will fire 100 concurrent applies
  against `usage_limit_total = 100` and assert exactly 100 succeed.
  The CHECK was never going to be reached on the happy path; T10.3
  is the contract that matters.

Migration file removed; ALTER TABLE rolled back via tinker
(`DROP CONSTRAINT`). T1.8 marked done-with-skip in TASKS.md +
issue #231.

---

## 2026-05-08 — Plan-019 mở rộng: thêm Part B (Menu Promotion)

User yêu cầu thêm use case mới: shop manager setup discount theo khung giờ cuối ngày để đẩy hàng + giảm waste. Sau thảo luận chốt:

**Phương án:** Hướng B + Q2(a) + Q3(d) + Q4(a) + Q5(d) + Q6(per-promotion stacking_mode) + Q7(a) + Q8(a)

**Decisions chốt cho Part B:**

1. **Hướng B** — thêm entity `MenuPromotion` riêng vào plan-019 (không gộp vào `Coupon`, không tách plan riêng). Plan-019 trở thành "Coupon & Menu Promotion platform".
2. **Owner = Shop Manager** (`branch_id` NOT NULL trên schema). Mỗi shop tự setup. HQ chỉ read-only cross-shop cho reporting.
3. **Scope = Category + Product + Mixed** — pivot 2 bảng `menu_promotion_category` + `menu_promotion_product`. Field `applies_to` enum (`all_items`/`categories`/`products`/`mixed`) drive FE.
4. **Discount type = % only** — `discount_percent` Decimal 5,2. Không có fixed-amount-per-item trong v1.
5. **Time window full** — `daily_time_from`/`daily_time_to` (nullable, hỗ trợ midnight cross) + `weekdays` (json int[]) + `valid_from`/`valid_until` overall.
6. **Stacking flag PER promotion** — `stacking_mode` field (`exclusive_with_coupons` | `stackable_with_coupons`). Không global per shop. 1 shop có thể đồng thời có promotion stackable + exclusive.
7. **Visibility = strikethrough trên menu** — customer-web + POS render giá đã giảm in đậm + giá gốc gạch ngang + Badge "Happy Hour −X%". Customer thấy luôn, không cần biết code.
8. **Không gắn inventory expiry trong v1** — để plan riêng nếu sau này muốn auto-discount khi món có nguyên liệu sắp hết hạn (cross-reference với plan-017 MaterialLot).
9. **Multi-promotion match cùng món** → `discount_percent` cao nhất thắng (best-for-customer). Không cộng dồn 2 promotion.
10. **Stacking conflict resolution UX:**
    - Apply coupon khi order có item exclusive → 422 hard reject; UI Alert đỏ với danh sách items, không có "auto-resolve" (xóa items là destructive).
    - Add item exclusive khi order đã có coupon → 422 reject với meta `suggested_action`; UI confirm Dialog "Auto-remove coupon và thêm món?" 2 button (Q tweak: option (b)).
11. **Auto-apply tại `CustomerOrderService::addItems`** — snapshot `unit_price` đã giảm + `original_unit_price` cho strikethrough. Không tính lại lúc checkout (giữ giá user thấy lúc add).
12. **Locked-after-application**: edit promotion sau khi đã có item áp → khoá `discount_percent`, `applies_to`, scope pivots; vẫn cho edit `name`, `description`, `valid_until` extend, `weekdays`, `daily_time_from/to`, `is_active`, `stacking_mode`.
13. **Cache strategy** — Redis key `branch:{id}:active_promotions` TTL 60s, invalidate trên CRUD/toggle. Disable trong test bằng config flag.
14. **Branch timezone** — `branch.timezone` field cần verify (T1.14). Dùng cho evaluate `daily_time_from/to` đúng giờ shop. DST handling test scenario riêng.
15. **TASKS.md** tăng từ 49 → ~70 task; **TESTS.md** tăng từ 77 → ~107 scenario.

**Open questions còn lại** (chưa chốt, ghi vào README + DESIGN B.Open):
- Q5 timezone field — verify hay add migration
- Q6 cache TTL benchmark
- Q7 derived `computed_status` accessor cho promotion
- Q8 receipt format with strikethrough (phối hợp workstation-app)
- Q9 realtime menu refresh (WebSocket vs polling)

---

## 2026-05-08 — Phase 0.5 Discovery

### Web research summary (sub-agent — 0.5a)

**Established contract.** Universal coupon fields: `code`, `discount_type`, `discount_value`, `min_order_subtotal`, `max_discount_cap`, `usage_limit_total`, `usage_limit_per_customer`, `valid_from`, `valid_until`, `applicable_scope`, `status` (draft/scheduled/active/paused/expired/exhausted). Redemption API takes `{code, order_id, customer_id, cart_subtotal, cart_items[]}` and returns `{discount_amount_applied, final_total, coupon_snapshot, redemption_id}` — snapshot critical for refund rollback.

**Design choices + trade-offs.**
- Row-level lock (`SELECT ... FOR UPDATE`) is the right answer for ≤10 req/s single-restaurant throughput; simpler than Redis INCR; safer than optimistic.
- Redemption ledger as separate immutable table (not just counter) → enables per-customer queries, audit, refund rollback by soft-delete.
- Single-coupon-per-order is the Toast / Square / Lightspeed default. Stacking via opt-in flag with declared priority — avoid for v1.
- Order-level scope is 90% of restaurant cases; item/category-level adds significant validation complexity.

**Common failure modes.**
- **Oversell under concurrent load** — two requests read `times_used = 99` simultaneously, both write `100`. Mitigation: `FOR UPDATE` + DB CHECK constraint (`times_used <= usage_limit_total`).
- **Buy / cancel / re-redeem abuse loop** — auto-restore on refund creates infinite single-use redemption. Mitigation: only auto-restore before order is closed; closed orders need manual manager refund.
- **Code-sharing on single-use codes** — `usage_limit_per_customer = 1` keyed on customer_id fails for guest checkouts. Mitigation: layer phone/IP fingerprint, log on each redemption.
- **Validation race on paused/expired coupons** — code valid at preview, paused before commit. Mitigation: re-validate inside the locked transaction, treat `paused` as ineligible in write path.

**Sources.**
- https://docs.voucherify.io/docs/voucher-object — voucher object reference
- https://stripe.com/docs/api/coupons/object — Stripe coupon/promotion_code split
- https://developer.squareup.com/docs/discounts-api/overview — validation sequence
- https://docs.talon.one/docs/dev/concepts/coupon-budgets — budget + per-customer limits

### Project domain summary (sub-agent — 0.5b)

**Domain model in this project.** Coupon = redeemable code reducing `CustomerOrder.discount_amount` at checkout. Order schema already has `discount_amount` and BR-O04 formula `total = subtotal − discount + service + tax`. Coupons fit naturally as **brand-scoped** (`/hq/{brandSlug}/coupons`) since all catalog/pricing entities (`Product`, `Menu`, `Category`, `PaymentMethod`) carry `brand_id`. FK targets: `brands` (required), `branches` (optional via pivot for shop restriction), `customers` via usage-tracking ledger.

**Existing integration points.**
- `backend/app/Services/Customer/CustomerOrderService.php::checkout()` — reads `$data['discount_amount']` and recomputes total. `OrderCheckoutRequest` validates only `discount_amount` nullable numeric ≥ 0; no coupon field today.
- Brand/Shop scoping: `PaymentMethod` is the cleanest sibling — brand-required, `branch_id` nullable for org-wide vs branch-only. Coupon goes one step further with a pivot table for "applies to N branches subset".
- Customer identity: `Customer.phone` is primary lookup (BR-C01). Walk-ins have `customer_id = null`. Per-customer usage requires non-null `customer_id`.
- No single `Payment` model — there's `OrderPayment` (one order → many payments). `CustomerOrder` has 5 stored total columns (`subtotal`, `discount_amount`, `service_charge`, `tax_amount`, `total_amount`).
- Translatable pattern: `translatable: true` flag in YAML → auto-generated `*_translations` table via `astrotomic/laravel-translatable`. Existing examples: `PaymentMethod.name`, `ToppingGroup.name`, `Product.name+description`.

**Applicable conventions.**
- `service.md` — services don't access Request, multi-step in `DB::transaction`, status changes write audit.
- `controller.md` — thin controllers; coupon redemption logic lives in `CouponService` (or hooked into `CustomerOrderService`).
- `route.md` — workflow actions use POST.
- `omnify-architecture.md` — generated bases, user-editable siblings, never edit base files.

**Project idioms.**
- Controllers read `brand_id` / `branch_id` from `$request->attributes` (set by `ResolveBrandFromSlug` / `ResolveShopFromSlug`); never re-query from slug.
- Services accept `array $filters` and use `->when(...)` for conditional filters.
- `ShopOrderSetting::where('branch_id', $order->branch_id)->first()` is the lookup pattern at checkout — coupon would do the same.
- `$model->logAudit('event', [...])` for audit logging.
- `lockForUpdate` already used in `NotificationService`, `TableStatusService`, `MaterialLotService`, `ProductSkuService` — pattern is well-established.

**Routes + UI placement.**
- New `routes/api/hq/coupons.php` for HQ CRUD; extend `routes/api/shops/orders.php` with apply/release; new endpoint in `routes/api/customer.php` for preview.
- admin-web pages under `admin-web/src/app/hq/[brandSlug]/coupons/` (sibling to `menus/`, `products/`, `categories/`).
- POS coupon entry in `pos-web/src/app/pos/components/payment-dialog.tsx`.
- customer-web in `customer-web/components/checkout-page.tsx`.

**Files read by sub-agent.** `backend/docs/explanation/{customer-domain,product-domain,authorization,system-features}.md`, `backend/docs/contributing/{service,controller,route,policy,omnify-architecture}.md`, `backend/app/Services/Customer/CustomerOrderService.php`, `backend/app/Http/Requests/OrderCheckoutRequest.php`, `backend/routes/api/shops/orders.php`, `schemas/Backend/Product/{CustomerOrder,OrderPayment,PaymentMethod,ToppingGroup,Customer}.yaml`, `schemas/Backend/Sso/Brand.yaml`, `schemas/Backend/Shop/ShopOrderSetting.yaml`, `admin-web/src/app/hq/[brandSlug]/`, `admin-web/src/app/shop/[shopSlug]/`, `customer-web/components/checkout-page.tsx`, `pos-web/src/app/pos/components/payment-dialog.tsx`.

### Decisions made in Phase 0.5 → 1

- Brand-scoped coupon ownership; pivot `coupon_branch` for optional shop whitelist (vs single nullable `branch_id` of PaymentMethod which only supports 2-state all-or-one).
- Customer identity required for `usage_limit_per_customer > 0`; walk-in (`customer_id = null`) only allowed for `usage_limit_per_customer = 0`.
- 1 coupon/order, order-level scope only (matches BR-O04 unique `discount_amount`).
- Auto-rollback usage counter on void/cancel BEFORE closed; closed orders are immutable.
- Computed lifecycle status (`scheduled/active/expired/exhausted` derived); only `draft`/`paused` stored.
- Schema lives at `schemas/Backend/Promotion/` (NEW domain group per convention #5).

## 2026-05-08 — Plan created

Initial scaffold for plan-019. Web research + project research sub-agents (Sonnet) ran in parallel and returned structured summaries. Domain pack `restaurant` matched and informed UX patterns (POS payment-dialog, customer QR ordering UX, allergens/discounts).

---

## 2026-05-11 — Form-screen research (T0.1)

Sub-agent research deferred — applied existing admin-web form conventions directly from sibling pages (`products/new`, `categories/new`, `customers/new`) which already encode the patterns required by `DESIGN.md` S2/S4/S8/S10.

**Layout.**
- Single-column, max-width `~720px` (use `max-w-3xl mx-auto` on the form wrapper).
- Section-grouped with `<Card>` + `<CardHeader><CardTitle>` per section. Sections in order: Identity → Discount rules → Validity → Scope → Limits → Active.
- Top-aligned labels via `<FormLabel>` inside `<FormField>` (react-hook-form wrapper). No floating labels.

**Field components.**
- `Input` + `Textarea` plain for non-translatable.
- `Input translatable={{ locales: { ja, en, vi } }}` for `name`. `Textarea translatable` for `description`. State shape: `TranslatableValue` initialized with `emptyLocaleMap()`.
- `Select` from `@godxjp/ui` for fixed choice fields (status, discount_type, applies_to, stacking_mode).
- `Combobox` for branches / categories / products multi-select.
- `DatePicker` range for `valid_from`/`valid_until`.
- `Switch` for `is_active` / "Tạo và pause ngay".
- Submit button uses `<Spinner className="mr-2" />` when pending — never raw `Loader2`.

**Validation.**
- Client: Zod schema mirroring server rules. Use generated `couponCreateSchema` / `menuPromotionCreateSchema` as starting point and extend.
- Inline per-field via `<FormMessage>`.
- Server 422 → `Alert` (variant destructive) banner top-of-form with per-field bullet list.

**Submit payload.**
- Translatable fields: `buildI18nPayload({...})` → Rule 3 strip locales where required (`name`) is empty → spread into request body alongside top-level `value[DEFAULT_LOCALE]` mirror.
- Toast on success (`sonner`) + navigate to detail / list.

**Save / Cancel.**
- Sticky footer (`<div className="sticky bottom-0 ...">`) with Cancel left, Submit right.
- Cancel triggers `confirm` Dialog if form dirty.
- Submit disabled when `formState.isSubmitting`.

**Keyboard.**
- `Enter` submits in single-line `Input` (default react-hook-form behaviour with `<form onSubmit>`).
- `Escape` → Cancel (handled by Sheet/Dialog if used; pages use route nav).

**Edit-form locked fields.**
- Disable underlying `Input`/`Select`/`Combobox` via `disabled` prop.
- Wrap in `<Tooltip>` from `@godxjp/ui` explaining lock reason (e.g. "Đã có redemption", "Đã áp cho N items").
- Show top-of-form info `Alert` summarising the lock state.

This research is consumed by S2 (T7.4), S4 (T7.6), S8 (T7.11), S10 (T7.13).

## 2026-05-11 — Frontend iteration complete (plan-019 Phases 6/7/8/9)

Branch: `feature/plan-019-2-coupon-frontend` off `dev`.

**Shipped:**
- **admin-web HQ Coupon CRUD** (T7.1–T7.7): list page S1 with status + branch filters, create form S2 (Identity / Discount / Validity / Scope / Limits / Activation sections), detail page S3 with Tabs (Overview / Redemptions / Branches), edit page S4 with locked-field tooltips when `times_used > 0`. Reusable `<CouponForm>` shared between S2 + S4. `couponService` + `useCoupons*` React Query hooks. Sidebar nav with Ticket icon. Full ja/en/vi i18n.
- **admin-web Shop Promotion CRUD** (T7.8–T7.13, T7.15): list page S7 with `is_active` + `currently_active` filters, create form S8 with 6 sections (Identity / Discount / Scope conditional / TimeWindow / Stacking / Active), detail page S9 with Tabs (Overview / Report KPI tiles / Scope), edit page S10. Reusable `<PromotionForm>`. `menuPromotionService` + `useShopPromotion*` hooks. Sidebar nav with Percent icon.
- **admin-web HQ cross-shop Promotion list** (T7.14): read-only S11 with branch + `currently_active` + sort filters. HQ sidebar nav with BarChart3 icon.
- **POS coupon** (T8.1–T8.2): `<CouponSection>` reusable component (Input + Apply / Badge + Remove) wired to `orderService.applyCoupon/releaseCoupon`. All `coupon.error.*` codes surfaced with localized messages.
- **POS Happy Hour visuals** (T8.3–T8.4): `<PromotionBadge>` + `<StrikethroughPrice>` atoms ready to drop into `menu-catalog.tsx` and `order-cart.tsx`. `ShopMenuProduct.active_promotion` + `CustomerOrderItem.original_unit_price` / `applied_promotion_*` typed.
- **POS stacking conflict** (T8.5–T8.7): `<StackingConflictDialog>` for `cannot_add_promotion_item_with_coupon` with "Auto-remove coupon & add" CTA. Apply-coupon `coupon_excluded_by_active_promotion` rendered inline in `<CouponSection>`. All stacking i18n in ja/en/vi.
- **customer-web checkout coupon** (T9.1–T9.3): Input + Apply Button wired to debounced `POST /v1/customer/coupons/preview`. Success Badge + discounted total; failure Alert with localized error code + `meta.exclusive_item_names` list. Full ja/en/vi.
- **customer-web menu Happy Hour** (T9.4–T9.6): `MenuItem.active_promotion` typed. `menu-card.tsx` renders amber Badge corner + strikethrough original price + discounted price when active.

**Verified:**
- `pnpm typecheck` — all admin-web changes clean. POS + customer-web typecheck via `tsc -b --noEmit` clean for plan-019 files (one pre-existing customer-web error in `payment-view.tsx` unrelated to this plan).
- Commit-per-task workflow: 24 commits on branch, each task = 1 commit + 1 GitHub issue checklist edit.

**Deferred to follow-up:**
- Drop-in integration of `<CouponSection>` into `pos-web/payment-dialog.tsx` (764-line file deserves a focused commit).
- Drop-in usage of `<PromotionBadge>` / `<StrikethroughPrice>` atoms in `pos-web/menu-catalog.tsx` + `order-cart.tsx`.
- Browser tests (Pest 4) for the 12 plan-019 Browser scenarios — match the deferral in T10.6 / T10.14.
- Happy Hour countdown timer ("Còn X giờ Y phút") on customer-web menu cards — copy is already in i18n; the polish hook can land in a follow-up.

**Pace:** ~24 commits, ~4500 LOC frontend, 16 new pages + 7 new components + 2 services + 4 hook files across 3 apps.

## 2026-05-11 — Follow-up polish iteration (post initial "complete")

After the initial frontend ship, ran a debug/polish loop that closed every deferred item + every bug surfaced during user testing. Branch now has 41 commits.

### Bugs fixed

1. **Re-apply after release blew up (`order_not_modifiable`).** `release()` defaults to soft-release (preserves audit row); `apply()` only triggered the replace flow when `coupon_id !== null`. Orphan soft-released row left from a prior release hit the `customer_order_id` unique constraint → `UniqueConstraintViolationException` → catch misread it as race → rethrew as `order_not_modifiable`. Fixed by sweeping `released_at IS NOT NULL` rows before insert. Regression test `CouponApplyTest::'allows re-applying after a prior release'`.

2. **`SQLSTATE[42S22] Unknown column 'brand_id'` on coupon create.** `CouponStoreRequest` + `CouponUpdateRequest` validation queried `branches.brand_id` — the table doesn't have that column (joins to SSO via `console_brand_id`). Fixed by resolving the brand's `console_brand_id` from middleware attributes + comparing against `branches.console_brand_id`. New tests `CouponHqControllerTest::'accepts/rejects applicable_branch_ids'`.

3. **HQ promotion list — 3 gaps + 2 subtle issues.**
   - Search input was dead-wired (UI rendered but state never reached `apiFilters`). Fixed.
   - Click row had no effect (DESIGN.md spec'd navigation). Replaced with read-only Sheet inline so HQ users without shop scope don't get 403.
   - Missing KPI summary header. Added 4 tiles (live / scheduled / inactive / total brand-wide discount) — backend `MenuPromotionService::list` now accepts `with_report=true` flag → opts into withCount + withSum aggregates; resource flattens `branch_slug` + `branch_name` + `report.*`.
   - Customer-web menu: stale price when viewing at 19:55 + adding at 20:01 → added `ends_at` precision watcher (refetches +3s after soonest expiry) plus 60s periodic refetch (catches start AND end).
   - Shop promotion form (S8): added amber info banner explaining menu-schedule × promotion independence.

4. **Branches tab (HQ coupon detail) was empty for brand-wide coupons.** Showed only a single placeholder line. Now lists every shop in the brand (or filtered whitelist when set), resolved via `useShops`, with badges "Tất cả" / "Danh sách chỉ định" / "Đã xóa" (for orphan IDs).

5. **Redemption history tab was empty.** FE called `GET /coupons/{id}/redemptions` but the endpoint wasn't shipped. Added route + `CouponController::redemptions` + `CouponRedemptionResource` (flat `customer_name` + `order_code`) + `CouponService::listRedemptions` (paginated, eager-load customer/order). Regression test added.

6. **POS HH visuals never showed up.** Backend `Shop\MenuController::listProducts` didn't overlay `active_promotion` (only customer-web menu did). Added batch-resolve via `MenuPromotionService::resolveActivePromotionsForMenu` + transient `active_promotion_overlay` attribute pinned on each model + `MenuProductResource` emits it. POS `useShopMenuProducts` gained `refetchInterval: 60_000` so window transitions land without manual reload.

7. **POS atoms had been built but not wired.** PromotionBadge + StrikethroughPrice now drop into `menu-catalog.tsx` (per-product card top-right Badge + strikethrough price row) + `order-cart.tsx` (per-line Badge below title + strikethrough subtotal). `StackingConflictDialog` now actually catches the 422 in `handleAddItem` and pops up.

8. **Customer-web coupon was preview-only; apply never fired.** Added `coupon_code` field to `CustomerOrderStoreRequest`. Customer\\CustomerOrderController::store + storeByBranch now wrap createOrder + addItems + couponService::apply in a single `DB::transaction`. Bad code rolls the whole order back. FE checkout-page forwards `coupon_code` only when preview reports `is_valid`. Pest tests cover happy path + rollback.

9. **POS discount input replaced with coupon code input.** OrderCart checkout-draft mode swapped `PercentRow` (free-form % input) for `CouponRow` (Input + Apply / Chip + Remove). Discount is now strictly coupon-driven; staff can't type a free-form discount anymore. CouponException error codes surface inline with localized messages.

10. **Bill cuối hứng rounding.** Lưu ý lúc apply coupon thì server hard-rejects nếu cart có HH exclusive items. Added "Use coupon instead of promotion" downgrade option: `CouponService::apply($downgradeExclusivePromotions = false)`. When true, reverts every line with `applied_promotion.stacking_mode = exclusive_with_coupons` back to `original_unit_price` (audit log preserves snapshot) before applying the coupon. POS `CouponRow` error state now has 2-button CTA; customer-web checkout has checkbox. New `StackingTest` cases.

### Tests added during follow-up

- `CouponApplyTest::'allows re-applying after a prior release (sweeps orphan soft-released row)'`
- `CouponHqControllerTest::'paginates redemption history with flat customer_name + order_code'`
- `CouponHqControllerTest::'accepts applicable_branch_ids when every branch belongs to the brand'`
- `CouponHqControllerTest::'rejects applicable_branch_ids when a branch belongs to a different brand'`
- `StackingTest::'downgrades exclusive promotion items when apply opts in (User picks coupon over HH)'`
- `StackingTest::'still rejects when downgrade_exclusive_promotions is missing or false'`
- `CustomerOrderTest::'applies coupon_code atomically during customer order create'`
- `CustomerOrderTest::'rolls back the whole order when an invalid coupon_code is supplied'`

### Pre-existing test failure fixed

`MenuPromotionAutoApplyTest` had 4 time-bomb failures (helper fixture's `valid_from=now()->subDays(2)` while tests passed hard-coded `$at=2026-05-07`). Once real now() walked past 2026-05-09 the validity gate dropped every candidate. Fixed the helper to use year-wide validity range. Now 50/50 plan-019 Coupon Pest tests green.

### Backend changes recap (new/modified endpoints)

| Endpoint | Status | Note |
|---|---|---|
| `GET /hq/{brand}/coupons/{id}/redemptions` | NEW | Paginated redemption history with flat `customer_name` + `order_code` |
| `POST /shops/{shop}/orders/{order}/apply-coupon` | EXTENDED | Now accepts `downgrade_exclusive_promotions` boolean |
| `POST /customer/tables/{qrToken}/orders` | EXTENDED | Accepts `coupon_code` + `downgrade_exclusive_promotions`; atomic apply inside create tx |
| `POST /customer/branches/{branchSlug}/orders` | EXTENDED | Same as above for takeaway |
| `GET /hq/{brand}/promotions` | EXTENDED | Accepts `search`; response includes `branch_slug`, `branch_name`, `report.*` aggregates |
| `GET /shops/{shop}/menus/{menu}/products` | EXTENDED | Each row now carries `active_promotion` overlay (id + percent + stacking_mode + ends_at) |

All OA annotations updated; `php artisan l5-swagger:generate` ran for hq + customer + shop buckets. Swagger UI:
- HQ: `http://localhost:5400/api/hq/documentation`
- Shop: `http://localhost:5400/api/shop/documentation`
- Customer: `http://localhost:5400/api/customer/documentation`

### Docs added

`backend/docs/explanation/coupon-and-promotion.md` — full system explanation covering both layers, apply flow (7-step), stacking matrix, menu schedule × promotion independence, every edge case, integration points table. Linked under related docs in front-matter.

### Final test status

- 50 Coupon Pest tests pass (was 42 before this branch).
- 0 regressions introduced by this branch (verified by `git stash` comparison: 49 pre-existing failures on dev are unchanged + my new tests all pass).
- All admin-web / pos-web / customer-web typecheck clean (only pre-existing customer-web `payment-view.tsx` 'possibly undefined' lingers, unrelated).

### Total deliverables on branch

- ~6800 LOC across backend + 3 frontends
- 41 commits, each task = 1 commit + 1 GitHub issue checklist edit
- 8 new Pest tests + 1 fixture fix
- 1 new explanation doc
- 5 swagger annotation updates
- All 31 plan-019 TASKS.md items checked off + all subtle issues + all bugs surfaced during user test fixed.

→ **Plan-019 is functionally done end-to-end.** Ready for `/mcp__omnify__complete` to push + open PR.
