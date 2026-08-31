---
plan: 019
issue: 231
title: Coupon & Menu Promotion
slug: coupon-management
status: shipped
branch: feature/plan-019-2-coupon-frontend
created: 2026-05-08
updated: 2026-08-05
landed_via: >-
  merged to dev (feature branch deleted). TASKS.md checkboxes are NOT the
  completion signal — plan-027 sits at 0/250 while godx-kds is a live shipping app
  (#1818). Verified by: no feature branch remains, plus a closed tracker or the
  plan's subject being present in the tree.
---

# Plan 019 — Coupon & Menu Promotion

> Hệ thống discount 2 lớp. **(A) Coupon** — code-driven do brand admin tạo, áp ở order-level, customer/staff nhập code (giảm tiền hoặc %). **(B) Menu Promotion (Happy Hour)** — auto-apply do shop manager setup tại shop của mình, giảm % giá item theo khung giờ trong ngày, áp cho category/product whitelist hoặc all items, hiển thị strikethrough giá gốc + Badge "Happy Hour" trên menu. Mỗi promotion có flag `stacking_mode` (`exclusive_with_coupons` / `stackable_with_coupons`) do shop manager chọn lúc setup. Counter coupon atomic + rollback khi void trước close.

## Status

- **Current:** `draft`
- **Created:** 2026-05-08
- **Owner:** _(assign)_

## Motivation

Brand cần công cụ marketing đa chiều:

1. **Coupon code-driven (Part A)** — chạy promo brand-wide với code `WELCOME10` cho khách online / khách báo code tại POS. Hiện `CustomerOrder.discount_amount` là free-form input, staff tự nhập, không có audit, không giới hạn lượt dùng, không có lịch promo.
2. **Menu Promotion / Happy Hour (Part B)** — shop muốn tự setup khung giờ giảm giá cuối ngày để đẩy hàng (giảm waste, kích cầu giờ thấp điểm) mà không cần khách biết mã. Hiện không có cơ chế giảm giá time-of-day theo món; staff phải tay đổi giá menu — error-prone, không audit.

Plan này build 2 entity riêng nhưng integrated:
- `Coupon` (brand admin tạo, code-driven, order-level, có lifecycle scheduled→active→expired/exhausted, usage cap toàn cục + per-customer, time window, scope brand-wide hoặc giới hạn shop)
- `MenuPromotion` (shop manager tự setup tại shop, auto-apply theo time-of-day + weekday, scope category/product whitelist hoặc all items, % giá từng item, hiển thị strikethrough trên menu)

Hai cơ chế kết hợp được: mỗi promotion có flag `stacking_mode` để quyết định có cộng dồn được với coupon không.

## In scope

### Part A — Coupon (code-driven, brand-scoped)

- Schema `Coupon` (Omnify YAML): `code` (unique theo brand), `discount_type` (`fixed` | `percent`), `discount_value`, `max_discount_cap`, `min_order_subtotal`, `usage_limit_total`, `usage_limit_per_customer`, `valid_from`, `valid_until`, status (`draft`/`paused` storable; `scheduled`/`active`/`expired`/`exhausted` derived), `name`+`description` (translatable), `times_used`, `brand_id`.
- Schema `CouponRedemption` (immutable ledger): `coupon_id`, `customer_order_id` unique, `customer_id` nullable, `discount_applied_amount`, `coupon_snapshot` (json), `redeemed_at`, `released_at`.
- Schema `CouponBranch` (pivot whitelist shops; trống = brand-wide).
- Add nullable `coupon_id` + `coupon_code_snapshot` columns to `CustomerOrder`.
- HQ admin CRUD: `GET/POST /hq/{brand}/coupons`, `GET/PUT/DELETE /{coupon}`, `POST /{coupon}/pause` + `/resume`.
- Shop redemption: `POST /shops/{shop}/orders/{order}/apply-coupon`, `DELETE .../coupon`.
- Customer preview: `POST /customer/coupons/preview` (stateless).
- Hook vào `CustomerOrderService::checkout/voidOrder/cancel`: atomic apply + auto-rollback khi void TRƯỚC closed. Closed orders không tự rollback.
- HQ admin UI: 4 màn (list, create, detail với tab redemption history, edit). `@godxjp/ui` thuần. Translatable dùng `<Input/Textarea translatable={{locales}} />` (KHÔNG layout 4-Card).
- POS UI: section "Mã giảm giá" trong `payment-dialog.tsx` (mini Dialog apply + chip remove).
- Customer-web UI: input "Mã giảm giá" trong `checkout-page.tsx` với debounced preview.

### Part B — Menu Promotion (auto-apply, shop-scoped)

- Schema `MenuPromotion` (Omnify YAML, **shop-scoped**): `branch_id`, `name`+`description` translatable, `discount_percent` (0<x≤100), `applies_to` enum (`all_items`/`categories`/`products`/`mixed`), `daily_time_from`+`daily_time_to` (nullable, hỗ trợ midnight cross), `weekdays` (json int array 1-7, null = mọi ngày), `valid_from`+`valid_until` overall, `stacking_mode` enum (`exclusive_with_coupons`/`stackable_with_coupons`), `is_active` boolean, soft-delete.
- Schema `MenuPromotionCategory` (pivot menu_promotion × category).
- Schema `MenuPromotionProduct` (pivot menu_promotion × product).
- Add 3 columns to `CustomerOrderItem`: `original_unit_price` nullable, `applied_promotion_id` nullable FK, `applied_promotion_snapshot` json nullable.
- Shop manager CRUD: `GET/POST /shops/{shop}/promotions`, `GET/PUT/DELETE /{id}`, `POST /{id}/toggle`.
- HQ read-only cross-shop list cho reporting: `GET /hq/{brand}/promotions`.
- Customer menu API extension: mỗi item response kèm `active_promotion: {id, discount_percent, ends_at}` nếu món đang trong promotion window.
- `MenuPromotionService::resolveActivePromotion(branch, product, categories[], at)` — match window + scope, chọn `discount_percent` cao nhất khi nhiều promotion match.
- Hook vào `CustomerOrderService::addItems()`: tự auto-apply promotion lúc add → ghi `original_unit_price`, set `unit_price = original × (100-percent)/100`, set FK + snapshot.
- **Stacking enforcement**:
  - `CouponService::apply()`: nếu order có item nào `applied_promotion.stacking_mode = exclusive` → 422 `coupon_excluded_by_active_promotion`.
  - `addItems()`: nếu order đã có `coupon_id != null` + new item promotion `exclusive` → 422 `cannot_add_promotion_item_with_coupon`. UI POS/customer-web hiện confirm Dialog "Auto-remove coupon để add món?" → nếu confirm, FE gọi release-coupon trước rồi gọi lại addItems.
- Shop admin UI: 4 màn Shop scope (list, create form với scope picker + stacking_mode radio + time-of-day + weekdays Toggle Group + DatePicker range, detail với tab "Báo cáo redemption", edit).
- HQ admin UI: 1 màn read-only cross-shop list (filter shop, sort theo discount tổng).
- POS UI: `menu-catalog.tsx` + `order-cart.tsx` render strikethrough giá gốc + Badge "HH −20%" cho item trong promotion window.
- Customer-web UI: menu card render strikethrough + Badge; Dialog xử lý error `coupon_excluded_by_active_promotion`.

### Tests + Tooling

- Pest tests: feature (Coupon CRUD/apply/release/preview/race + Promotion CRUD/auto-apply/stacking), browser (HQ list/form, Shop promotion form, POS happy-hour render + apply, customer-web preview), arch (model/service base extension).
- Seeders qua service (idempotent): `CouponSeeder` + `MenuPromotionSeeder`.

## Out of scope

### Coupon (Part A)
- **Multi-coupon stacking.** v1 áp 1 coupon/order; replace nếu apply coupon mới.
- **Order-level item scope.** Coupon chỉ tính trên subtotal duy nhất.
- **Free-item BOGO.** v1 chỉ fixed amount + percent của subtotal.
- **Auto-best-coupon.** Customer/staff phải nhập code.
- **Pre-paid voucher / gift card.** Khác model — phase sau.
- **Anonymous walk-in usage_per_customer.** Walk-in chỉ dùng được coupon `usage_limit_per_customer = 0`.
- **Auto-rollback khi closed order bị refund.** Closed = immutable. Refund flow ngoài plan này.
- **Cron auto-transition status.** Status derived realtime, không cần job.

### Menu Promotion (Part B)
- **Fixed-amount-per-item discount.** v1 chỉ percent (`5,000₫ off mỗi món` không hỗ trợ).
- **Brand-wide promotion.** Mỗi promotion bind vào 1 shop; nếu brand muốn apply cho nhiều shops thì shop manager mỗi shop tự setup. (Có thể mở rộng về sau.)
- **Bundle promotion** ("buy 2 get 1 free", "combo set giá fixed"). Khác mô hình; plan riêng.
- **Inventory expiry trigger.** v1 không gắn với `MaterialLot.expiry_date` hoặc `is_available` low-stock — đó là plan riêng (Q8a).
- **Multi-promotion stacking trên cùng 1 món.** Nếu 2 promotion match cùng 1 món, chỉ áp `discount_percent` cao nhất (best-for-customer) — không cộng dồn 2 promotion.
- **Promotion priority field.** Không cho shop manual rank thứ tự; rule "highest discount wins" cứng.
- **Customer-targeted promotion** ("VIP only 30% off"). v1 promotion áp cho mọi customer, không lọc theo customer tier.
- **Promotion announcement / push notification.** Không tự gửi push khi happy hour bắt đầu — phase sau qua notification platform.

## Success criteria

### Coupon (Part A)
- [ ] Brand admin tạo `WELCOME10` (10% off, cap 50k, 7 ngày, 100 lượt, 1/khách) và thấy trong list.
- [ ] POS staff apply `WELCOME10` vào order subtotal 200k → discount 20k, `discount_amount` set, redemption row ghi.
- [ ] Customer-web nhập `WELCOME10` ở checkout → preview hiện "−20.000₫" → confirm → `times_used += 1`.
- [ ] 100 redemption concurrent → đúng 100 success + (N−100) reject 422 `coupon_exhausted`. Không oversell.
- [ ] Same customer redeem lần 2 → 422 `coupon_already_used_by_customer`.
- [ ] Order voided (status ≠ closed) → redemption soft-deleted, `times_used -= 1`, redeem lại được.
- [ ] Order đã `closed` rồi voided → counter KHÔNG bị giảm.
- [ ] `valid_until` qua → 422 `coupon_expired`; paused → 422 `coupon_paused`; subtotal < min → 422 `coupon_min_subtotal_not_met`.

### Menu Promotion (Part B)
- [ ] Shop manager tạo `Happy Hour 9-11pm` 20% off cho category "Đồ uống" + "Tráng miệng", `daily_time_from=21:00`, `daily_time_to=23:00`, weekdays mọi ngày, `stacking_mode=stackable_with_coupons`.
- [ ] 21:30 customer-web menu → item "Trà chanh" (category "Đồ uống") response có `active_promotion: {discount_percent: 20, ...}`; item card render `~~25.000₫~~ **20.000₫**` + Badge "Happy Hour".
- [ ] 21:30 POS add item "Trà chanh" vào order → `original_unit_price=25000`, `unit_price=20000`, `applied_promotion_id` set.
- [ ] 23:01 (qua khung) add cùng món → `unit_price=25000`, FK null (không hưởng nữa).
- [ ] Item add 22:50 (trong khung), order checkout 23:30 (đã qua khung) → giữ giá đã giảm (snapshot).
- [ ] Order có item promotion `stackable` + apply coupon `WELCOME10` → cả 2 áp. Subtotal = sum(item.unit_price already discounted) → coupon trừ thêm 10% trên subtotal đó.
- [ ] Order có item promotion `exclusive` + cố apply coupon → 422 `coupon_excluded_by_active_promotion`.
- [ ] Order đã có coupon + cố add item promotion `exclusive` → 422 `cannot_add_promotion_item_with_coupon` + UI confirm Dialog "Auto-remove coupon để add món?".
- [ ] Multi-promotion match 1 món (drinks 20% + all-items 10%) → chỉ áp 20% (best-for-customer).
- [ ] Daily window cross midnight (`from=21:00, to=02:00`): tại 23:30 và 01:30 đều match.
- [ ] Promotion delete khi đã có 1 order item áp → 409 (suggest deactivate thay vì delete).
- [ ] HQ org-manager GET `/hq/{brand}/promotions` → thấy promotions cross-shop của brand (read-only).

### Tooling
- [ ] Pest test suite all green (`cd backend && php -d memory_limit=-1 vendor/bin/pest --compact`).
- [ ] Type check + lint của 3 web app pass (`pnpm typecheck && pnpm lint`).

## Dependencies

- `CustomerOrder.discount_amount` (đã có).
- `Brand`, `Branch`, `Customer`, `Category`, `Product`, `MenuProductSku` schemas (đã có).
- `ResolveBrandFromSlug` + `ResolveShopFromSlug` middleware (đã có).
- `Branch.timezone` field — cần để evaluate `daily_time_from/to` đúng theo giờ shop. Confirm tồn tại; nếu thiếu, T1.x add field này (đề xuất default `Asia/Ho_Chi_Minh`).
- `@godxjp/ui` components: `Input translatable`, `Textarea translatable`, `DatePicker` (range mode), `Combobox` multi-select, `Select`, `Switch`, **`TimePicker`** (cho daily_time_from/to), **`ToggleGroup`** (cho weekdays multi-select). Nếu thiếu → file issue + `// TODO(godx-tempo-ui#NNN)` (convention #6).
- Hard depend on plan-014 (`Menu Schedule`) NOT — promotion độc lập với menu schedule (menu schedule thay đổi tập món hiển thị; promotion chỉ thay đổi giá trên món đang hiển thị).

## Open questions

### Coupon (Part A)
- [ ] **Q1 — `applicable_branch_ids` lưu json column hay pivot table `coupon_branch`?** Đã chốt pivot.
- [ ] **Q2 — Code casing?** Đã chốt: uppercase A-Z 0-9 _-, case-insensitive lookup, FE auto-uppercase input.
- [ ] **Q3 — Atomic counter pattern?** Đã chốt: `lockForUpdate()` + DB CHECK constraint defensive.
- [ ] **Q4 — POS apply-coupon timing?** Đã chốt: apply được khi `open/dining/pending/checkout`; reject `paying/closed/voided`.

### Menu Promotion (Part B)
- [ ] **Q5 — Promotion timezone source?** Mỗi shop dùng `branch.timezone` (Asia/Ho_Chi_Minh / Asia/Tokyo / ...). Cần verify `branches` table có field này; nếu thiếu, add migration.
- [ ] **Q6 — Cache active promotions per branch (Redis)?** Đề xuất TTL 60s + invalidate on CRUD. Cần benchmark: query trực tiếp DB mỗi addItem có gây bottleneck không khi POS busy?
- [ ] **Q7 — Promotion auto-pause khi `valid_until` qua?** Hiện thiết kế: derived check (mỗi resolve query bỏ qua promotion đã qua). Có cần job định kỳ set `is_active=false` để admin UI hiển thị status đúng không? Đề xuất: derived `computed_status` accessor giống Coupon.
- [ ] **Q8 — Receipt format khi có promotion?** Receipt printing flow ở workstation-app. v1: ghi `original_unit_price` + `unit_price` cho mỗi line item; receipt template tự render strikethrough nếu có. Cần phối hợp với workstation-app team — có thể defer thành plan riêng.
- [ ] **Q9 — Realtime menu refresh khi promotion bắt đầu/kết thúc?** Customer đang xem menu lúc 20:59:30 → 21:00 happy hour bắt đầu, có cần WebSocket push để FE refresh giá không? Đề xuất v1: client polling mỗi 60s; WebSocket push là nice-to-have.

## Files in this plan

- [DESIGN.md](DESIGN.md) — architecture, data model, API surface, screens, sitemap, authorization, journeys, field lifecycle, decisions
- [NOTES.md](NOTES.md) — discovery findings, working log

## Related

- `backend/docs/explanation/customer-domain.md` — order lifecycle + discount_amount formula
- `schemas/Backend/Product/CustomerOrder.yaml` — order schema (BR-O04 = host của discount)
- `schemas/Backend/Product/CustomerOrderItem.yaml` — item schema (sẽ thêm `original_unit_price` + promotion FK)
- `schemas/Backend/Product/PaymentMethod.yaml` — sibling pattern: brand-wide với `branch_id` nullable
- `schemas/Backend/Product/Category.yaml` + `Product.yaml` — promotion scope FK targets
- Plan 014 — `MenuSchedule` (time-based menu hiển thị; promotion độc lập)
- Plan 015 — `recalculateTotals` topping subtotal (related to order totals math)
