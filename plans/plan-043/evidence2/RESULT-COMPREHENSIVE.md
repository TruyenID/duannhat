# Plan-043 + Plan-045 — COMPREHENSIVE E2E evidence

> ## ⚠️ SUPERSEDED IN PART — read this before trusting anything below
>
> The **two-rate tax type** (`rate_dine_in` / `rate_takeaway` chosen by
> `order_type`) was **removed on 2026-07-26 (#1099)**. A tax type is ONE rate;
> the MENU decides the consumption context. Every mention of it below is a
> record of what was built, **not an instruction**.
>
> Still true and still shipped: immutable per-line snapshots, rounding ONCE per
> rate group (インボイス), 総額表示 mode, service-charge rate, per-rate output,
> the workstation Go engine.
>
> Current truth: [`docs/guide/tax-types.md`](../../../docs/guide/tax-types.md).

**Ngày:** 2026-07-16 · **Backend** :5400 · **admin-web** :5430 · **pos-web** :5440.
**3 lớp bằng chứng:** (1) **suite tự động** (Pest+Go) = backbone phủ toàn bộ ~176 scenario; (2) **curl live** = HTTP contract; (3) **Playwright** = UI thật.

---

## LỚP 1 — Suite tự động (backbone, phủ toàn bộ scenario matrix)

| Suite | Kết quả |
|---|---|
| plan-043 core (TaxType CRUD/concurrency/menu-override/audit, TaxResolver, ConsumptionTaxCalculator, Product tax, CSV, guards, breakdown-semantics, seeders, backfill, brand-seeding) | **103 passed** |
| plan-045 + invoice (TaxRoundingConfig, RefundOrderItem, InvoiceTaxBreakdown/MoneyGuards/StoreValidation/ToppingSnapshot, InvoiceAllocator, CouponTaxRecompute, WorkstationTaxRecompute, OrderBroadcastTax, OrderPaidInvoiceMail) | **47 passed** |
| engine + settings (OrderPricingCalculator, ShopOrderSettings, MoneyRoundingCanonical) | **104 passed** |
| workstation Go (Tax/Round/Pricing/Refund/GroupTax) | **ok** (8 pkg) |

→ Toàn bộ 126 scenario plan-043 (§1.2 proof, group-once, coupon pro-rata, void/update/order-type re-resolve, per-rate receipt/invoice/Z-report, backfill, workstation Go parity) + ~50 scenario plan-045 đều có test map + **xanh**.

---

## LỚP 2 — curl live (HTTP contract)

### plan-043 engine (deterministic, service thật)
```
A. Inheritance chain: Tier1 menu-override RED→10% | Tier2 product EXEMPT→0% | Tier3/4 fallback→10%
B. Proof §1.2: takeaway bento(RED)→8% + beer(STD)→10%  (2 rate groups)
C. Group-once: per-line-summed=¥81 (WRONG) vs group-once=¥80 (RIGHT)  [3×¥333@8%=79.92]
D. Service charge tax: ¥1000 × 10% = ¥100
```

### plan-043 CRUD / validation / errors / authz
```
POST create (translatable ja/en/vi)  → 201     PUT update → 200 (Pest)   lookup(active) → 200 count=4   DELETE unused → 204
POST missing rates → 422   dup code STANDARD → 422   rate>100 → 422
GET tax-types no-token → 401   GET /hq with pos token → 401 (device/shop token không vào HQ)
DELETE default/in-use → 409  code=TAX_TYPE_IN_USE
PATCH /shops/sjk/settings/order prices_include_tax (shift OPEN) → 409 code=TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT
```

### plan-045 rounding config + lock
```
PATCH tax_rounding_mode=banker → 422 (enum)   decimals=5 → 422 (>3)   decimals=-1 → 422 (<0)
PATCH tax_rounding_mode=floor (no shift) → 200
PATCH tax_rounding_mode=ceil (shift OPEN) → 409 code=TAX_ROUNDING_LOCKED_OPEN_SHIFT
```
> Lưu ý: settings enum dùng tên canonical `round`/`ceil`/`floor`; `round_down`/`half_up` là alias chỉ-đọc cho snapshot → PATCH bằng alias trả 422 (đúng, không phải bug).

### plan-045 refund edge cases
```
qty=0 → 422   qty="abc" → 422   qty=999 (exceeds) → 422   wrong item → 404   unauthenticated → 401
valid refund qty=1 → 201 (tạo dòng âm)   refund chính dòng-âm → 422 (CANNOT_REFUND_REFUND_LINE)
```

---

## LỚP 3 — UI (Playwright, admin-web thật)

- **`p43-taxtypes-admin.png`** — HQ 税区分: 非課税 0/0 · 軽減税率 店内10%/持ち帰り8% · 標準税率 10/10 [デフォルト].
- **`p45-tax-rounding-tab.png`** — 税/Tax settings: デフォルト税区分, 税込価格(内税), サービス料税率, 精算レポート税率内訳, **税額の端数処理=四捨五入 + note "snapshot lúc tạo đơn, không viết lại đơn cũ"**, 端数の桁数, 割り勘の端数処理.
- **`p43-order-detail-tax.png`** — **1 ảnh phủ CẢ 2 plan**:
  - plan-043: 税額 **10%対象 ¥4,140 / 内消費税 ¥414** (per-rate), **サービス料の税額 ¥24**, 税別 mode.
  - plan-045: **税端数処理 四捨五入・小数0桁** (rounding snapshot trên đơn), item **返金済 1/1** (refunded_quantity), dòng **返金 Matcha Latte −¥580** + 返金理由 (refund-as-negative-line).

---

## Bugs
**KHÔNG có bug mới.** 2 điểm nghi ban đầu đã điều tra → đều đúng thiết kế:
- `/pos/settings/order` không chặn tax-mode mid-shift → **đúng** (endpoint đó scope riêng close-report toggles; guard nằm ở `/shops/.../settings/order`, đã verify 409).
- Refund không thấy ledger ở order → **đúng** (condition `type=refund` gắn vào dòng âm, append-only).

## Ghi chú
- Test tạo tax type `E2ETEST` (đã xoá), 1 dòng refund test trên ORD-2026-0001 (hiển thị trong ảnh order-detail — bằng chứng đẹp). Token đã thu hồi.
- Reproduce: `shot.cjs` / `oc.cjs` (Playwright dev-token cookie).
