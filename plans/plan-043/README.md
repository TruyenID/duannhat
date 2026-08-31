---
plan: 043
title: Tax Types — Japanese Consumption Tax (軽減税率 / インボイス)
slug: tax-types
issue: 483
status: shipped
branch: feature/plan-043-tax-types
created: 2026-07-07
updated: 2026-08-05
landed_via: >-
  merged to dev (feature branch deleted); see the plan's tracking issue
  for how it closed. TASKS.md checkboxes are NOT the completion signal here —
  several plans shipped by a different route than the ladder they describe (#1802).
---

# Plan 043 — Tax Types — Japanese Consumption Tax (軽減税率 / インボイス)

> ## ⚠️ SUPERSEDED IN PART — read this before trusting anything below
>
> The **two-rate tax type** (`rate_dine_in` / `rate_takeaway` chosen by
> `order_type`) was **removed on 2026-07-26 (#1099)**. A tax type is ONE rate;
> the MENU decides the consumption context. Every mention of it below is a
> record of what was built, **not an instruction**.
>
> **Khái niệm thuế rượu cũng đã bị gỡ hoàn toàn ở #1099 (2026-07-26).** Không có
> cờ, không có cột, không có guard nào cho nó — đồ uống có cồn là sản phẩm bình
> thường, gán tax type như mọi sản phẩm khác. Đặc tả cũ đã bị xoá khỏi mọi file
> trong plan này (#2049). **Đừng dựng lại.**
>
> Still true and still shipped: immutable per-line snapshots, rounding ONCE per
> rate group (インボイス), 総額表示 mode, service-charge rate, per-rate output,
> the workstation Go engine.
>
> Current truth: [`docs/guide/tax-types.md`](../../docs/guide/tax-types.md).

> Replace the single per-branch `tax_rate` with brand-scoped **tax types** carrying two rates (dine-in / takeaway), snapshot tax per order line, group + round tax **once per rate group** (インボイス rule), add a global tax-included pricing flag and a dedicated service-charge tax rate — across backend, admin-web, pos-web, customer-web, workstation-app, and godx-handy.

## Status

- **Current:** `draft`
- **Created:** 2026-07-07
- **Owner:** _(assign)_

## Motivation

- Japanese consumption-tax law (軽減税率) makes the final rate the **intersection of item × order type**: eat-in food 10%, takeaway food 8%, exempt 0%. The current model (1 `tax_rate` per branch, order-level `tax_amount`) **cannot represent** a takeaway order containing one bentō (8%) and one beer (10%) — it computes that order wrong today (spec §1.2).
- インボイス制度 (mandatory since 2023-10) requires receipts to show taxable totals + tax **per rate**, mark 8% items with ※, carry the seller's registration number (T + 13 digits), and round **exactly once per rate per invoice**. None of these exist today (spec §1.3).
- 4 parallel tax calculators (cloud PHP, workstation Go, pos-web, customer-web) already disagree (3 different rounding rules, a hardcoded `TAX_RATE` constant, a 10% fallback), and the VAT invoice bug saves `tax_rate = 0` on every invoice (spec §3.3, §3.16, PHẦN 4).

## In scope

- New Omnify entity `TaxType` (brand-scoped, `rate_dine_in` + `rate_takeaway`, default/active flags) + HQ CRUD API/UI, seeded with the 3 Japanese standard types (標準 10/10 · 軽減 10/8 · 非課税 0/0).
- Nullable inheritance FKs: `Product.tax_type_id` → `MenuProduct.tax_type_id` override → branch default (`ShopOrderSetting.default_tax_type_id`) → brand default (resolve chain, spec §7).
- Immutable per-line snapshots on `customer_order_items` (+ workstation `order_items`): resolved `tax_type_id`, `tax_rate`, `tax_amount`; `is_tax_included` flag snapshot on orders.
- New pricing engine (spec §8): group by rate → coupon pro-rata per group → service-charge tax (own rate) → **one rounding per rate group** → single half-up-to-currency-step rounding rule everywhere (backend, Go, pos-web, customer-web).
- Settings: `prices_include_tax` (global flag, mid-shift change guard 409), `service_charge_tax_rate`, `default_tax_type_id`, `close_report_tax_breakdown` toggle.
- Rewrite of split-by-items (backend + 3 mirrors), coupon allocation, void/update/order-type-change recompute paths.
- Per-rate output surfaces: receipt (※ markers, T13 slot), shift/close report, backend Z-report PDF, VAT invoice (per-rate breakdown + インボイス registration column + rate=0 bug fix), order emails + PDF, revenue reports.
- Workstation offline: SQLite migrations, tax_types sync DOWN, menu payload tax fields, Go engine port, legacy cleanup (config tax_rate, coupon recompute bug, kiosk JPY hardcode).
- 7 pre-existing bugs fixed in-passing (spec PHẦN 4), backfill migration from legacy `tax_rate` (per-branch distinct rates → brand tax types), then drop `tax_rate` (Phase 6 only).
- Audit logging for tax config changes; CSV `tax_type_code` column on product import/export; i18n in 6 locations.

## Out of scope

*(verified in spec PHẦN 12 — do not re-litigate during execution)*

- godx-kds, tms-app, godx-kintai, godx-kiosk submodule (kiosk logic lives in workstation `local_kiosk.go`).
- Tips & partial-payment mechanics; Stripe line-item/automatic-tax integration (backend stays the only tax source).
- MenuPromotion allocation changes (discount already baked into `unit_price` → per-line tax is automatically correct).
- Table merge/unmerge (never touches items/totals), quick-order flag, workstation Wails `Orders.tsx`, pos-web payment-dialog & close-shift page (total/remaining only — no tax line), `OrderPaid` / `OrderItemStatusChanged` broadcasts (no money payload).
- Input tax / purchasing (仕入税額控除); order/revenue CSV export (doesn't exist); outbound webhooks; menu cache invalidation (5s pull, no backend cache).
- Feature-flag infrastructure and workstation version handshake (safety comes from additive schema + deploy order — spec PHẦN 11).
- インボイス seller registration number **source field + settings entry UI** — this plan ships only the invoice snapshot column + receipt/invoice T13 slot (Q5: "column now, UI later"); shop-level invoice **list endpoint** (plan-038 T11.3 TBD) and invoice **email sending** via `recipient_email` also remain follow-ups (spec §3.12).

## Success criteria

- [ ] A takeaway order with 1 bentō + 1 beer computes 8% on the bentō and 10% on the beer in the **same order**, identically on cloud, workstation (offline), pos-web preview, and customer-web preview.
- [ ] Tax is rounded exactly once per rate group per order (half-up, currency step) — proven by Pest cases that fail under per-line rounding.
- [ ] Receipts/invoices show per-rate blocks (`8%対象 xxx円 (内消費税 xx)` / `10%対象 …`), ※ on reduced-rate lines, and a slot for the T+13 registration number.
- [ ] Editing a tax type's rate after orders exist changes **nothing** on historical orders, invoices, or reports (per-line snapshot).
- [ ] `prices_include_tax` flag flip is blocked (409) while any till shift is open; both included/excluded modes produce correct totals.
- [ ] VAT invoices persist real per-rate breakdowns (bug "tax_rate = 0" gone).
- [ ] Backfill creates tax types from every distinct legacy branch `tax_rate` and stamps branch defaults **before** the old column is dropped; old workstations keep functioning throughout (additive API).
- [ ] All ~55 affected test files (32 Pest, 23 Go) plus the 52-case `split-by-items.test.ts` are green under the new engine.

## Dependencies

- Omnify codegen (`npm run omnify:gen`, schema v54+); `@godxjp/ui` (already wired in admin-web).
- Plan-030/031/032 till-session machinery (mid-shift guard pattern, `TillSession` snapshot pattern); plan-036 Z-report PDF; plan-038 `customer_invoices`.
- Customer confirmation of the seed rate direction (handwritten note has it backwards — spec §1.1 ⚠️).
- Deploy-order discipline (spec PHẦN 11): cloud → admin data entry → workstation release → outputs → cleanup.

## Open questions

*(spec PHẦN 13 — proposals adopted as defaults; confirm or override at approval. Q2, Q5 already settled.)*

- [ ] **Q1** Seed rate direction: confirm with the customer that eat-in = 10%, takeaway = 8% (handwritten note is reversed vs. Japanese law).
- [ ] **Q3** Coupon `min_order_subtotal`: compared against tax-included or tax-excluded subtotal? → *default: current behaviour (compare vs `subtotal` as stored), decided in Phase 1.*
- [ ] **Q4** Refund: separate tax-adjustment slip? → *default: no separate slip in v1; refund tax derived from per-line snapshot, revisit in Phase 4.*
- [ ] **Q6** Mid-shift guard scope → *default: hard-block only `prices_include_tax` (409); rate/default-type changes allowed (snapshots protect data) with UI warning.*
- [ ] **Q7** In-flight orders at deploy → *default: ship an artisan backfill command (`orders:backfill-tax-snapshots`) so reports are clean from day 1; lazy re-stamp on next recalculation remains the fallback.*
- [ ] **Q8** Workstation min-version enforcement → *default: not built; risk logged (no handshake exists).*
- [ ] **Q9** Combo 一体資産 → *default: manual tax-type assignment on the combo product + ops guide; system only warns when combo components straddle tax types.*
- [x] **Q10** Display pricing when flag OFF in Japan (総額表示) → **RESOLVED 2026-07-10** (see NOTES). Built the menu display rule: the `prices_include_tax` toggle now drives the shown price on pos-web + customer-web menus — OFF shows net + "税抜", ON shows net+tax + "税込" (`menuDisplayPrice` helper, half-up to currency step). Applies to every raw product/SKU/topping sticker; cart/checkout/order totals untouched (already engine-computed). Rate resolved per-product (not per-SKU). Caveat: display treats the flag as a *display* preference (stored = net); a shop running ON with gross-entered prices would double-count — flagged, out of scope.
- [ ] **Q11** TaxType CRUD permission → *default: org-scoped like ProductType; tighten to manager-only later if asked.*
- [ ] **Q12** Service charge base in included mode → *default: gross (matches "X% of the bill" intuition), locked in Phase 1.*
- [ ] **Q13** Sub-bill split receipts → *default: keep suppressing breakdown on sub-bills; per-rate block only on the main bill (インボイス needs it on the primary customer document).*
  - **Sửa một phần 2026-08-17 (#2064) — vế `登録番号` của mặc định trên đã HẾT HIỆU LỰC.**
    Câu *"インボイス needs it on the primary customer document"* giả định **tồn tại**
    một "primary customer document" để trỏ tới. Tiền đề đó chết cùng **#1779**, thứ đã
    gỡ toàn bộ đường phát hành/lưu hoá đơn: với một bàn chia bill, phiếu con là tờ giấy
    **DUY NHẤT** khách cầm về, nên nó *là* chứng từ của khách đó.
    Một cờ `showTaxBreakdown` gác đồng thời **bốn** thứ, và 登録番号 nằm trong đó do
    **tai nạn**: số đăng ký là **danh tính NGƯỜI BÁN** (trường ① của 適格簡易請求書,
    消法57条の4②), không phải thuộc tính của khối thuế theo mức.
    ⇒ **登録番号 (①) nay LUÔN in**, kể cả trên phiếu con.
    ⇒ **Khối theo mức (④⑤) và dấu ※ + chú thích (③) VẪN ẩn** trên phiếu con — không
    phải vì ruling cũ, mà vì phần chia chưa mang ảnh chụp phân bổ **theo từng mức**;
    bật chúng lên mà chưa giải bài toán làm tròn (Σ phiếu con phải khớp phiếu tổng theo
    từng mức, không lệch một đồng) là **sai sổ thuế**, tệ hơn thiếu trường. Việc đó
    theo dõi ở **#2677**.
    Rào hai chiều: `BillTaxBreakdownQrTest` (PHP) · `TestReceipt_SubBillKeepsRegistrationNumber` (Go).

## Files in this plan

- [DESIGN.md](DESIGN.md) — approach, decisions, alternatives
- [NOTES.md](NOTES.md) — working log, decisions, blockers

## Related

- `TAX_FEATURE_PLAN.md` — spec gốc, superseded (#1099) và đã xoá khỏi cây (#2188 — xem git history); nguồn đúng là `docs/guide/tax-types.md`
- plan-030/031/032 (till sessions & guards) · plan-036 (Z-report) · plan-038 (VAT invoice) · plan-019 (coupon/promotion) · plan-021/033 (split-by-items)
- `docs/explanation/order-domain.md`, `docs/explanation/split-by-items.md`, `docs/guide/cashier-shift-recovery.md`
