---
title: Tax types — Japanese consumption tax
category: guide
tags: [tax, tax-type, consumption-tax, invoice, plan-043, issue-1099]
summary: "Brand-scoped tax types each carrying ONE rate, the menu-first resolution order, immutable per-line snapshots, group-once rounding, tax-included pricing, and the phased rollout that ends by dropping legacy tax_rate."
related: [business-time]
---

# Tax types — Japanese consumption tax (軽減税率 / インボイス)

> Operator + admin + developer guide for the consumption-tax model
> (plan-043, redesigned by **#1099** on 2026-07-26). Brand-scoped **tax
> types** each carrying **ONE rate**, immutable per-line tax snapshots,
> group-once rounding (インボイス), a global tax-included pricing flag, and a
> dedicated service-charge tax rate. Consumption context (店内 vs 持ち帰り)
> is a MENU concern — the takeaway menu overrides its items to the reduced
> type; the tax master never branches on order type.

Plan: [`plans/plan-043/`](../../plans/plan-043/) —
[DESIGN.md](../../plans/plan-043/DESIGN.md) is the decision record.
`SYSTEM-FLOW.md` đã bị xoá (#2336): nó mô tả mô hình hai mức thuế trên một tax
type, thứ đã bị gỡ ở #1099, mà vẫn được trang này giới thiệu là "the full
walkthrough". Git history giữ nguyên file.
Spec gốc `TAX_FEATURE_PLAN.md` đã superseded (#1099) và bị xoá khỏi cây (#2188 — xem git history); tài liệu này LÀ nguồn đúng.

---

## What it is

A tax type is a **pure rate** (#1099): 標準 10 · 軽減 8 · 非課税 0. Japanese
law taxes the same food item at 10% eaten in and 8% taken away — but that is
a property of **which menu line the customer ordered from**, not of the tax
itself. The takeaway menu carries REDUCED overrides on its food items
(`MenuProduct.tax_type_id`, resolver tier 1); the dine-in menu inherits the
product's STANDARD base. A mixed order (bentō from the takeaway line + beer)
still taxes each line differently *in the same order* — because each line
anchors to its own menu line.

| Tax type | rate | Used for |
|---|---|---|
| **標準 (Standard)** — default | **10%** | every dine-in line; alcohol; non-food goods |
| **軽減 (Reduced)** | **8%** | food/non-alcoholic drinks via the TAKEAWAY MENU override |
| **非課税 (Exempt)** | **0%** | tax-exempt brands |

The resolver never sees the order type — `TaxResolver::resolveForLine`
literally has no such parameter. **An order_type flip does not re-price
anything**: the rate rides the menu line the customer ordered from
(`SingleRateTaxContractTest` pins both directions).

### What makes that premise hold: the menu the cashier is looking at

"The rate rides the menu line" is only correct while each order type is actually
served the matching menu. That is enforced one layer up, by the **`service_type`
gate** (#463 / #481 / #1745): a menu declares `DineIn`, `Takeaway` or `Both`,
and every ordering surface asks for menus of the type its order needs.

| order_type | `?service_type=` sent | menus listed |
|---|---|---|
| `dine_in` (Tại chỗ) | `DineIn` | `DineIn` + `Both` + legacy `NULL` |
| `spot` (Nhanh) | `DineIn` | same as dine-in — a counter sale is still eaten in |
| `takeaway` (Mang đi) | `Takeaway` | `Takeaway` + `Both` + legacy `NULL` |
| no active order | *(nothing)* | every menu — nothing to gate on yet |

Applied by `Shop\MenuController::validatedServiceType` (Cloud) and
`local_pos_menus.go` (workstation LAN); pos-web resolves the value in
`src/app/pos/lib/menu-service-type.ts`.

**The gate filters; it does not tell the cashier what they are looking at.**
That was the whole of #1756. Two cases leave the list unlabelled even with the
gate working: with **no active order** there is nothing to gate on, so DineIn
and Takeaway menus are listed side by side (and `pickActiveMenu` auto-selects
one by time window, so the cashier may never have chosen the menu they are
ringing up against); and a `Both` menu shows under every order type by
definition. The POS therefore badges each menu with its **effective** service
type, on both the dropdown rows and the trigger.

Getting that value to the screen needed all three layers, because each held it
and none emitted it: Cloud sent only the raw nullable column (`NULL` = "inherit
the master", unrenderable) until `MenuService::masterServiceTypeSubquery`
started stamping `master_service_type` so `MenuResource` can resolve
`effective_service_type`; the workstation stored the already-resolved value but
used it purely in a `WHERE`. **Absent stays absent** — a POS talking to an
older backend or an un-resynced mirror shows no badge rather than defaulting to
`Both`, since the badge is a claim about which rate the line will take.

**`spot` was ungated until #1745**, and that was a money bug rather than a
cosmetic one: it is both the create-order dialog's default and the column
default, so the most-used path could list — and time-window auto-pick — a
Takeaway menu and take 8% on food eaten in. The rate then snapshots onto the
line immutably, so no later order-type edit repairs it.

Two consequences worth knowing before relying on this:

- **A `Both` menu is exempt from the split by definition.** If most menus are
  `Both`, the gate lists nearly everything whatever the order type, and the 8% /
  10% separation lives or dies on HQ setting `service_type` per menu.
- **A `spot` order that is really takeaway now takes 10%.** Deliberate — erring
  toward the standard rate is the safe direction — and the cashier's escape
  hatch is to pick 持ち帰り / "Mang đi" explicitly.

Tax types are **brand-scoped** (per-brand `[brand_id, code]` uniqueness). Rates
snapshot onto each order line at add time, so editing a tax type's rate later
**never** rewrites historical orders, invoices, or reports.

---

## Setup (HQ admin + shop manager)

### 1. Create / edit tax types (HQ)

Screen: **HQ sidebar › Catalog › Tax Types** — `/hq/[brandSlug]/tax-types`.

- The seeder/backfill ships **標準 10 (default) · 軽減 8 · 非課税 0**.
- Editor fields: `code` (immutable after create), translatable `name` (ja/en/vi),
  `rate` (0–100, 2dp), `is_default`, `is_active`.
- Exactly **one default per brand** — setting `is_default` on a type clears the
  previous default automatically (`TaxTypeService`).
- **Deactivate, don't delete.** Deleting a type that's in use returns
  **409 `TAX_TYPE_IN_USE`** (with product / menu-product / branch-default
  counts). Use `toggle-status` to deactivate — that blocks *new* assignment but
  keeps historical references valid (RESTRICT FKs).
- Editing a rate is allowed anytime; it affects **future** order lines only.

### 2. Assign types to products (HQ)

Screen: **Products list → product detail → sidebar 分類 card**.

- Pick a tax type from the dropdown, or leave the first option
  **"デフォルトを使用 (inherit)" = null** to inherit.
- Only active types of the same brand are assignable.

**Inheritance chain** (highest priority first —
[`TaxResolver::resolveForLine`](../../backend/app/Services/Customer/TaxResolver.php)):

```
1. MenuProduct.tax_type_id       (this item, in this menu)
2. MenuMenuSection.tax_type_id   (this section, in this menu)   ← #1218
3. Menu.tax_type_id              (the whole menu)               ← #1218
4. Product.tax_type_id           (the product / combo)
5. ShopOrderSetting.default_tax_type_id  (branch default)
6. TaxType.is_default            (brand default)
   ↓ nothing configured (fresh org mid-rollout)
   legacy tax_rate fallback  (transition only)
```

Combos are just Products → assign the combo's tax type on the same card.

**The menu wins over the product, on purpose (#1218).** Tiers 2 and 3 sit ABOVE
`Product.tax_type_id`, so setting a takeaway menu to 8% re-rates every line of
it — **including a product whose own tax type is 非課税 / 0%**. That is the
ruling, not an oversight: a menu set wrongly is a human error, and putting these
tiers below the product would have made them do nothing at all, because once a
brand is seeded with the standard types almost every product already carries one.

To keep ONE item exempt inside a taxed menu, set that item's own tax type on its
row — tier 1, which still beats everything below it.

**Where each tier is stored, and why.** Tier 2 lives on the **pivot**
(`menu_menu_sections.tax_type_id`), never on `menu_sections`. A section is N:N
with menus and is reused heavily — in the dev database, 139 pivot rows share
just 15 distinct sections across 29 menus — so a column on the section itself
would push one menu's takeaway rate into every other menu that shows it.

| Set it | Endpoint |
|---|---|
| Whole menu | `PATCH /api/v1/hq/{brandSlug}/menus/{menu}/tax-type` |
| Section in this menu | `PATCH /api/v1/hq/{brandSlug}/menus/{menu}/sections/{menuSection}/tax-type` |
| One item in this menu | `PATCH /api/v1/hq/{brandSlug}/menus/{menu}/products/{menuProduct}/tax-type` |

`null` on any of them clears that tier and the line inherits from the next one
down. All three require the type to belong to the brand and be **active** (422
otherwise); deactivating a type blocks NEW assignment only — lines already
pointing at it keep resolving through it, which is why the resolver applies no
`is_active` filter at any tier.

**The workstation never walks these tiers.** The menu feed collapses tiers 1-4
into the single `menu_items.tax_type_id` column it ships to the register
(`CustomerMenuService::transformMenu`), so an offline till resolves exactly what
Cloud would. There is deliberately no second tier-walk in Go to drift out of
step — the collapse order is pinned by
`tests/Feature/Customer/MenuFeedTaxCollapseTest.php`, and the shared golden
fixture `tax_resolution_golden.json` (byte-identical in both repos) documents it
in `tier_collapse_note`.

### 3. Branch settings (shop manager)

Screen: **Shop Settings › 税 / Tax section** — `/shop/[shopSlug]/settings`.
API: `PATCH /api/v1/shops/{shopSlug}/settings/order`.

| Field | Meaning |
|---|---|
| `default_tax_type_id` | Branch default (tier 3). Must be same-brand + active (422 otherwise). null = fall through to brand default. |
| `prices_include_tax` | 総額表示 mode (see below). **Mid-shift flip blocked (409).** |
| `service_charge_tax_rate` | Tax rate (%) applied to the service charge, independent of item rates. |
| `tax_rounding_mode` | plan-045 — `round` (half-UP, **not** banker's), `ceil`, or `floor`. **Snapshotted onto each order at creation, immutable** — changing it never re-rounds history. |
| `tax_rounding_decimals` | plan-045 — decimal places for the tax step; `null` falls back to the currency step (JPY/VND = 0). Snapshotted with the mode. |
| `close_report_tax_breakdown` | Gate the per-rate block on the **thermal** close report (Z-report PDF always includes it). |

---

## 酒類 (alcohol) — no system concept since 2026-07-26

> **OPERATOR DECISION 2026-07-26: the alcohol concept was removed entirely.**
> An alcoholic product is an ordinary product — the operator assigns it a tax
> type on the **product** or via a **menu override**, exactly like any other
> product. The system does not enforce 酒税法 for you; getting the catalog
> right (with the 税理士) is an operator responsibility.

---

## Combo rule — 一体資産 vs 一括譲渡

**Fixed-component combos** (no customer choice): manually assign the tax type per
the 一体資産 rule — a single-priced food + non-food/alcohol set qualifies for
**軽減 (8% takeaway)** only when **price ≤ ¥10,000 tax-excl AND the food portion
is ≥ 2/3** (by 売価 or 原価). Failing either → **標準 (10%)**. The system only
*warns* when a combo's components straddle tax types; the operator makes the
call (Q9).

**Customer-choosable combos** are **never 一体資産** per the NTA — they are
一括譲渡 (「顧客や事業者が選択可能な場合は…一体資産に該当せず、一括譲渡に該当します」),
so a whole-line 8% on a set containing alcohol would be illegal under-taxation.
Assign the standard tax type to the combo product (or menu override) yourself
whenever its selectable components include alcohol.

> **Open question Q14.** If the customer + 税理士 require 8% on the *food portion*
> of an alcohol-containing choice-set, that needs 一括譲渡 あん分 (per-part
> apportionment — one line split into two rate portions), which breaks the
> 1-line-1-rate architecture and is deferred to a follow-up plan.

---

## Tax-included pricing (総額表示 — `prices_include_tax`)

When ON, menu prices **already include** tax and the engine **extracts** the 内税
instead of adding it on top:

```
excluded (flag OFF):  tax_group = round((subtotal − discount) × rate/100)
                      total     = Σ(subtotal − discount) + Σ tax + service charge + sc tax
included (flag ON):   tax_group = gross − round(gross / (1 + rate/100))
                      total     = Σ gross + service charge   (tax shown as 内税 only, not re-added)
```

The mode is **snapshotted onto each order at creation** (`is_tax_included`), and
onto each till session at shift open (`prices_include_tax_at_open`).

**Mid-shift guard:** flipping `prices_include_tax` while any till at the branch
has an open shift returns **409 `TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT`** — a shift
must not span two tax modes or its report can't reconcile. **Close/settle all
shifts first**, then flip.

By contrast, **rate changes and default-type changes ARE allowed mid-shift**
(per-line snapshots protect settled data — Q6). Admin-web shows an advisory to
do them outside shift hours anyway; treat that as best practice.

---

## Rounding

**Group-once everywhere** (backend PHP, workstation Go, pos-web, customer-web):
tax rounds **once per rate group** (JPY/VND step = 1, USD = 0.01) —
端数処理は税率ごとに1回, the インボイス requirement. Per-line rounding is
forbidden.

**The rounding MODE is configurable since plan-045** — `tax_rounding_mode`
(`round` = half-up, `ceil`, `floor`) + `tax_rounding_decimals` on
ShopOrderSetting, snapshotted per order at creation and immutable afterwards.
Boundary behaviour is pinned by `OrderPricingOracleTest`: ¥1,234 @10% = 123.4 →
round 123 / ceil 124 / floor 123, and ¥1,235 → 123.5 → round **124** (half-UP,
not banker's rounding). The default `round` + currency-step decimals is
byte-identical to the old fixed half-up rule. Example of the forbidden
per-line rounding:

```
WRONG (per-line):   3 × round(¥333 × 0.08 = 26.64 → 27) = ¥81
RIGHT (per-group):  round(¥999 × 0.08 = 79.92)          = ¥80
```

> **New receipts may differ from the old ones by a yen or two. This is
> intentional** — the old system had three divergent rounding rules; plan-043
> collapses them to this one correct rule.

**Per-line `tax_amount` snapshots are allocated, not independently rounded.**
Each rate group's once-rounded tax is distributed across its lines by
**largest-remainder** (`OrderPricingCalculator::allocateGroupTax`), so the group
above stamps `¥27 + ¥27 + ¥26 = ¥80` — **Σ line == the group tax**, never the
per-line `¥81`. This is what lets every surface that SUMs the line snapshots
(Z-report, revenue dashboards, `tax_breakdown`, VAT invoice lines) reconcile to
`order.tax_amount` while staying インボイス-compliant. The only expected gap
between `order.tax_amount` and Σ line is the **service-charge tax**, an
order-level charge that owns no line.

### Cách chuẩn hoá thương số — và vì sao KHÔNG dùng `round()` của PHP (#2082)

`roundHalfUpToStep` phải quyết định ở biên `.5`, mà `value / step` trong dấu phẩy
động hiếm khi rơi đúng biên: `0.145 / 0.01` ra `14.499999999999998`, tức một biên
thuế thật sẽ rơi XUỐNG và **thu thiếu**. Nên thương số được chuẩn hoá trước.

Chuẩn hoá ấy từng là `round($q, 9)` bên Cloud và `math.Round(q*1e9)/1e9` bên máy
trạm. Chúng **không phải cùng một phép toán**, và chênh nhau từ
`|value/step| ≳ 4,5×10⁶` — với bước `1` (VND/JPY) đó là hoá đơn từ ₫4,5 triệu,
tức một bàn ăn bình thường. Máy trạm luôn lệch **về phía 0** so với Cloud: thu
thiếu thuế, trả thiếu tiền hoàn.

Đo từng bước trên cùng đầu vào cho thấy chỗ rẽ không nằm ở phép nhân/chia:

```
q      = 9294522.4999999981     ← hai bên BẰNG NHAU
q*1e9  = 9294522499999998       ← hai bên BẰNG NHAU, và là số nguyên chính xác
Go   math.Round → 9294522499999998   (đồng nhất — đúng IEEE)
PHP  round()    → 9294522500000000   (DỊCH một số nguyên chính xác)
```

`round()` của PHP không phải phép làm tròn IEEE: nó âm thầm làm tròn lại về
khoảng 15 chữ số có nghĩa. Vì vậy **đừng "bắt Go tái hiện `round()` của PHP"** —
đó là xây trên cát, PHP đổi `round()` ở bản sau là parity vỡ lại y hệt.

Cả hai phía nay dùng cùng một phép chuẩn hoá **khai được bằng thập phân**: snap
về 15 chữ số có nghĩa qua round-trip `%.14e` ↔
`strconv.FormatFloat(v, 'e', 14, 64)`. Đây là chuyển đổi làm-tròn-đúng ở cả hai
ngôn ngữ (đo 54.000 thương số: giống hệt nhau 100%), nên nó là phép ĐỒNG NHẤT
chứ không phải bản xấp xỉ một hàm nội bộ.

Việc đổi cũng sửa một chỗ **Cloud tự mâu thuẫn**: nhiễu từ `round()` khiến nửa âm
chính xác ở `|q| ≳ 5×10⁶` (ví dụ `-6642241.5`) ra `-6642242` — xa 0 — trong khi
cùng luật `floor(q + 0.5)` ở biên độ nhỏ cho `-0.145 → -0.14`, tức về phía +∞.
Một luật, hai chiều. Nay một chiều ở mọi biên độ.

> ⚠️ Hệ quả còn để ngỏ: half-up theo `floor(q + 0.5)` **không đối xứng qua 0**
> (`123.5 → 124` nhưng `-123.5 → -123`), nên một khoản hoàn tiền ở đúng biên
> `.5` không đảo ngược chính xác khoản đã thu. Đó là luật đang có và tài liệu này
> mô tả đúng nó; câu hỏi có nên chuyển sang half-away-from-zero hay không được
> theo dõi riêng ở #2117.

Hợp đồng giữa hai engine nằm ở `backend/tests/Fixtures/rounding_golden.json`,
byte-identical với `workstation/internal/service/testdata/`.

---

## Where discounts and fees enter the tax base

Discounts and fees are **not** a second tax model. They decide **what enters each
rate group**; they never change how a group is rounded.

| Khoản | Vào nền chịu thuế thế nào |
|---|---|
| Giảm giá (coupon · khuyến mãi · giảm tay) | chia **pro-rata theo nhóm mức** rồi mới tính thuế — coupon ¥1.000 trên đơn 8%+10% thành ví dụ ¥620 trừ nền 8% và ¥380 trừ nền 10%. **Mẫu số là gross CÒN SỐNG** (`(quantity − refunded_quantity) × đơn giá gộp`, #2240): đơn chưa hoàn gì thì trùng gross thô, nhưng khi một nhóm mức đã bị hoàn thì phần giảm từng ngồi trên nó **di cư** sang các nhóm còn giữ — hệ quả trực tiếp của mô hình đánh-giá-lại ngay dưới. Không di cư, phần giảm kẹt trên nhóm rỗng cho thuế thiếu và một nhóm **thuế ÂM** trên `tax_breakdown` của khách. Cùng trọng số ở mức nhóm (`priceGroups`) và mức dòng (`allocateLineTaxes`) — một nguồn (`survivingGrossByRate`), nên Σ thuế dòng == thuế đơn giữ theo cấu trúc |
| Phí phục vụ | mang **mức thuế riêng** (`service_charge_tax_rate`) và nhập vào nhóm mức đó — món giao có thể 軽減 8% trong khi PHÍ giao là dịch vụ 10% |
| Tiền tip | 不課税 — không thuộc nhóm mức nào, và nằm ngoài `total_amount` (BR-P03) |

### Hoàn hàng làm nền chịu thuế co lại — và coupon được ĐÁNH GIÁ LẠI (#2079)

Một dòng hoàn tiền không chỉ trừ tiền hàng: nó làm **giỏ hàng sống** nhỏ đi, và
điều kiện của coupon được xét lại trên giỏ mới. Coupon tụt dưới
`min_order_subtotal` thì **về 0**; coupon phần trăm thì tính lại trên giỏ đã co.

Đây là **đánh giá lại điều kiện**, KHÔNG phải **phân bổ theo tỉ lệ** — hai mô
hình cho hai con số khác nhau, và hệ thống chọn cái thứ nhất:

| Mô hình | Coupon ¥500 (ngưỡng 0), đơn 2×¥1.000, hoàn một món |
|---|---|
| **Đánh giá lại** (đang dùng) | giỏ còn ¥1.000 vẫn đủ điều kiện ⇒ vẫn giảm đủ **¥500** |
| Phân bổ theo tỉ lệ | khoản giảm chia đôi ⇒ món giữ lại chỉ được **¥250** |

Mô hình đang dùng nhất quán với đường **huỷ món** (#550) và với chính điều khoản
coupon: nếu giỏ còn lại vẫn thoả điều kiện thì coupon vẫn hứa đúng ngần ấy.

Hệ quả vận hành đáng biết: hoàn một món có thể làm khách **mất toàn bộ coupon**
nếu giỏ còn lại tụt dưới ngưỡng. Đó là cố ý — nếu không, một coupon cố định trở
thành quà biếu lách ngưỡng.

#### Vì thế thuế của dòng HOÀN tính trên nền GỘP, không phải nền đã trừ giảm (#2182)

Chọn **đánh giá lại** buộc theo một hệ quả mà lần đầu cài đặt đã bỏ sót: khi trả
lại một món, coupon **không đi theo món ấy** — nó dồn hết sang phần hàng còn
giữ. Nên phần phải hoàn cho khách là **giá gộp của món đó cộng thuế trên gộp**:

```
mua:       2 × 1.000 − 500 = 1.500 + thuế 150 = 1.650   khách trả
trả 1 món: còn 1.000 − 500 =   500 + thuế  50 =   550   khách còn nợ
⇒ hoàn 1.100 = 1.000 (gộp) + 100 (thuế trên gộp)
```

Bản đầu lấy `items.tax_amount` — con số **đã** bị trừ coupon pro-rata — làm nền
hoàn. Đó là trộn hai mô hình: thuế hoàn thì phân bổ theo tỉ lệ, coupon thì đánh
giá lại. Hai nền không triệt tiêu nhau, nên hoàn HẾT một giỏ có coupon vẫn còn
đọng thuế:

| | dòng sống | dòng hoàn | `tax_amount` của đơn |
|---|---:|---:|---:|
| nền cũ (đã trừ giảm) | +100 +100 | −75 −50 | **75** trên một đơn bán 0 |
| nền GỘP | +100 +100 | −100 −100 | **0** |

Không có coupon/khoản giảm thì hai nền **trùng nhau từng đồng** (cùng phép phân
bổ, khoản giảm 0), nên mọi đơn không giảm giá không đổi một xu.

Ba điều dễ làm sai khi đụng vào chỗ này:

- **Nền gộp phải đi qua đúng phép phân bổ largest-remainder**, không được tính
  lại bằng `round(subtotal × rate)` cho từng dòng. Ba dòng ¥1.005 @10% phân bổ
  ra 101/101/100 — dòng thứ ba mang số thuế **không dựng lại được từ chính nó**,
  và Σ các số tính rời không bằng thuế nhóm (`RefundReversesTaxExactlyTest`).
- **Ảnh chụp của dòng hoàn vẫn bất biến.** Bản sửa chỉ đổi cách TÍNH lúc TẠO
  dòng hoàn; không có đường nào viết lại một dòng hoàn đã ghi.
- **Dòng hoàn NULL `tax_rate` bị DROP, không bị ép về rổ 0%** (#2257). Ảnh chụp
  thiếu là input hỏng, và cả HAI phép cùng bỏ dòng ấy với một bộ lọc: phép thuế
  (`applyRefundLines`) và phép kẹp giỏ sống cho coupon (`refundedSubtotalFor`)
  — parity Go `refundLinesFromDB` / `liveGrossSubtotal`. Bỏ ở một chỗ mà giữ ở
  chỗ kia là coupon bị cắt lệch với `total_amount`. Kèm cảnh báo log
  (`order_id` + số dòng): thiếu lộ ra hơn là sai âm thầm (#2067).

Kèm theo: khoản giảm **áp dụng được** bị kẹp bởi giỏ SỐNG (gộp − đã hoàn), không
phải bởi tổng gộp — nếu không, giảm giá TAY (thứ cố ý *không* được đánh giá lại)
vẫn treo trên một giỏ đã trả hết và cho `total_amount` ÂM, đúng triệu chứng
#2114 đã chữa cho coupon. Cột `discount_amount` vẫn giữ số **yêu cầu**; sổ
`order_conditions` giữ số **thực tế** — ranh giới ấy do
`ConditionLedgerEdgeCasesTest` ghim.

Máy trạm đã theo cùng nền từ #2232 (PR ws#260): `order_service_refund.go` lấy
thuế GỘP của dòng gốc qua `allocateLineTaxes(discount=0)` — port đúng phép phân
bổ của Cloud — nên số hoàn trên POS-LAN khớp Cloud cả trước khi sync-UP trả kết
quả. Nợ còn lại: `refund_tax_golden.json` chưa có ca CÓ giảm giá (fixture
byte-identical hai repo, phải đổi đồng thời) — theo dõi ở #2288.

Nền mỗi nhóm khớp họ quy tắc Peppol BR-S-08:

```
taxable(rate) = Σ line_net(rate) − Σ allowance_allocation(rate) + Σ charge(rate)
```

Cả ba khoản đều để lại **dòng trong `order_conditions`** — sổ ghi mọi khoản tiền
ngoài giá sản phẩm, gắn đa hình vào cả đơn lẫn từng dòng món. Dòng `tax` mang
thêm `taxable_base`, tức 税率ごとに区分した対価の額 đã in trên hoá đơn, lưu chứ
không suy lại lúc đọc (#2031 sinh ra chính vì suy lại). Bất biến:

```
total_amount == subtotal + Σ(order_conditions.amount)
```

Tip là ngoại lệ **có chủ đích**: nó sống ở `order_payments.tip_amount` vì gắn vào
từng lần thanh toán (chia bill ⇒ mỗi người tip trên thẻ mình) và cố ý nằm ngoài
`total_amount`. Đưa nó vào sổ sẽ phá vỡ đúng bất biến ở trên.

> Từng có một tầng khái quát hoá riêng cho việc này — plan-049, với bảng
> `order_adjustments` + `order_adjustment_allocations` và trục `tax_mode`
> (`follow_lines_prorata` · `own_rate` · `out_of_scope`). **Đã gỡ hoàn toàn ở
> #2041**: hai chế độ dùng thật đã đúng sẵn ở đường hiện tại, còn hai bảng kia
> mô hình hoá trùng với `order_conditions` mà chưa client nào ghi vào.

---

## 登録番号 — invoice registration number (T+13, #1152)

The 適格請求書発行事業者登録番号 (`T` + 13 digits) prints on receipts, 適格請求書
and 赤伝 **when registered**. Registering is the SHOP'S OWN choice — a
免税事業者 legitimately has no number, nothing warns or nags about its absence
(product ruling 2026-07-28). Since #1153 the ACCEPTED FORMAT follows the
organization's operating country (VN orgs enter their mã số thuế instead) —
see `docs/guide/compliance-profiles.md`.

- **Entry — two levels**: HQ → Brand Settings (brand-wide default) and Shop →
  Settings (per-branch override, for franchise branches that are their own
  legal entity). Both validate `^T\d{13}$`.
- **Resolution**: `branch.invoice_registration_number ??
  brand.invoice_registration_number` (`SellerRegistrationResolver`).
- **~~Snapshot~~ — ĐÃ GỠ ở #1779.** Trước đây `PosInvoiceService` đóng dấu số
  đã resolve vào `customer_invoices.seller_registration_number` lúc phát hành, và
  赤伝 kế thừa từ snapshot đó. **Cả hai class và cả đường phát hành hoá đơn đã bị
  gỡ** (xem mục 適格返還請求書 bên dưới) — hôm nay `SellerRegistrationResolver`
  còn sống nhưng chỉ còn MỘT consumer: feed workstation ở gạch đầu dòng kế tiếp.
  Không còn snapshot nào được ghi ở đâu cả. ⚠️ Docblock của chính resolver vẫn
  còn nhắc nhánh "snapshot lên hoá đơn lúc phát hành" — nhánh đó đã chết theo
  #1779, đừng tin docblock.
- **Printing**: Cloud serializes the resolved value into the workstation
  settings mirror as `seller_registration_number` — the exact key the Go print
  paths already read — so every workstation build prints 「登録番号: …」 from
  its next pull with no binary update. The per-shop toggle
  `show_seller_registration_on_receipt` (default **ON**) gates display; OFF
  serializes an empty string, so old builds behave correctly for free.
- **Why there is NO snapshot on `customer_orders`** (decision 2026-07-28 — lý do
  vẫn đứng, nhưng tiền đề của nó đã mất ở #1779: khi đó còn có một hàng
  適格請求書 để đóng dấu vào; giờ **không còn chỗ snapshot nào**, mọi bản in đều
  lấy giá trị LIVE): the number was snapshotted where a durable LEGAL DOCUMENT is
  minted — the
  適格請求書 row (and the 赤伝 inherits the ORIGINAL invoice's number, which is
  the correct entity for the return). The receipt (適格簡易請求書) prints the
  LIVE resolved value at print time, which matches industry practice (receipts
  render current seller config; document stores snapshot). A T-number is
  effectively permanent (derived from the 法人番号); it only "changes" when a
  DIFFERENT legal entity takes over the shop — an org/brand re-setup, not a
  settings edit — so an order-level snapshot would add a column + offline
  mirror complexity to defend against a case that is operationally a new
  tenant anyway. If a reprint-after-entity-change ever matters, the invoice
  row is the authoritative document to reproduce.

## 適格返還請求書 (赤伝 / return invoice) — ĐÃ GỠ ở #1779

> **ĐÃ GỠ 2026-08 (#1779, PR #1791): không còn 赤伝 tự sinh, và không còn hoá
> đơn nào được LƯU vào DB.** Bản dựng ở #1123 — refund/chargeback trên một đơn
> đã xuất hoá đơn tự phát hành 適格返還請求書 (dual-date, per-rate, ngưỡng
> ¥10.000, số `R{NNNNN}` chung sequence với `invoice_counters`, hook vào cả ba
> funnel đảo tiền, một 赤伝 mỗi khách khi chia bill ở #1236) — bị gỡ **toàn
> bộ** cùng với đường tạo hoá đơn: `PosInvoiceService`,
> `PosReturnInvoiceService`, model `CustomerInvoice`, YAML + module + migration
> CREATE, và các file test ghim nó.
>
> **Đo lại 2026-08-07**: `PosReturnInvoiceService`, `issueForReversal`,
> `original_issued_at`, `threshold_exempt`, `voidInvoiceIfFullyRefunded`,
> `return_invoice_unattributable`, `C3CrossPeriodRefundTest`,
> `ReturnInvoiceSplitPaymentTest` — **zero hit trong `backend/app`,
> `backend/database`, `backend/tests`**. Vài docblock còn nhắc tên
> `PosReturnInvoiceService` (`UnitPriceDriftGuard`, `TillSessionOpenLookup`,
> `JpComplianceProfile`, `config/modules.php`) — đó là xác chữ, không phải class.
>
> **Vì sao gỡ** (quyết định của chủ dự án, đã đọc blast radius ở #1779): hoá đơn
> đỏ **chỉ IN, không lưu DB**. Chấp nhận mất in-lại-từ-DB, void hoá đơn, 赤伝,
> số hoá đơn tuần tự, và VN e-invoice. Tiền + hoàn tiền **không đổi** (đường 赤伝
> vốn đã fail-open, không bao giờ rollback ledger).
>
> **Đường sống duy nhất hôm nay**: in trực tiếp, không ghi bản ghi nào —
> pos-web `red-invoice-dialog.tsx` → `POST /api/lan/print/red-invoice` trên
> workstation (`FormatRedInvoiceTicket`), đọc mirror `orders`/`order_items`.
>
> **Bốn bảng hoá đơn thì CỐ Ý GIỮ LẠI** kèm dữ liệu (`customer_invoices`,
> `customer_return_invoices`, `vn_einvoice_settings`,
> `vn_einvoice_transmissions`) — chứng từ pháp định (帳簿書類 7 năm ở Nhật, 10
> năm ở VN). `tests/Feature/Architecture/InvoiceTablesAreNotDroppedTest.php`
> (#1797) chặn mọi migration `dropIfExists` chạm vào chúng. **Bảng còn không có
> nghĩa là đường ghi còn — đừng dựng lại, nó bị cắt có chủ ý.**
>
> **Nghĩa vụ pháp lý thì KHÔNG mất đi cùng code.** 消法57条の4③ vẫn đòi chứng từ
> hoàn cho một giao dịch đã xuất hoá đơn; sau #1779 việc đó nằm ở người vận
> hành, không còn ở hệ thống. Nếu ngày nào đó phải làm lại, hãy đọc #1779
> trước — nó liệt kê đúng những gì đã bị đánh đổi.

## Backfill / migration — ĐÃ GỠ (#2188)

`tax-types:backfill` and `orders:backfill-tax-snapshots` were **deleted on
2026-08-08** together with the whole `Backfill*` console family (ruling: legacy
không tồn tại — old data is reseeded, never patched at runtime; git history
holds the code). `orders:backfill-tax-snapshots` was run ONE last time on the
dev DB before deletion. What also went with them:

- the **BUG-8 lazy re-stamp** (`applyPricing` re-resolving NULL-rate lines on
  the next mutation, plus the Go mirror in `recalcOrderTotalsTx`) — an
  unstamped line is now **dropped from the rate groups with a warning**, never
  priced at an invented rate (#2067 pattern);
- the `$legacyRate = 0.0` fallback and the stored-subtotal-only single-group
  fallback in `OrderPricingCalculator::forOrder` /
  `computeOrderTotalsFromDB` (Go);
- `reResolveOrderLines()`'s `allowSettled` escape hatch — the settled-order
  freeze now has **no exception**.

`tests/Feature/Architecture/LegacyIdentifierBanTest.php` and the Go
`legacy_identifier_arch_test.go` keep the family from growing back.

### "Mọi đường tạo dòng đều đóng dấu" — câu đó SAI cho tới #2411

Chỗ này từng viết *"every creation path stamps the per-line snapshot at write
time"* như một sự thật. Nó là **ý định**, không phải thứ được cưỡng chế, và hai
đường ghi thật không làm:

| đường ghi | sai kiểu gì | ai thấy |
|---|---|---|
| `transportWorkstationSyncItems` | INSERT dòng **trống**, `reResolveOrderLines` ngay sau đó stamp hộ | không ai — dữ liệu cuối cùng vẫn đúng, nên mọi phép "đếm dòng NULL" đều sạch |
| `transportWorkstationGhostItem` (KDS-bump) | không có bước nào chạy sau ⇒ **NULL vĩnh viễn** | không ai — dòng NULL bị các phép tổng thuế DROP, tức món bán ra không nộp thuế, im lặng |

Cách phát hiện đáng ghi lại: **phép đo dữ liệu không bắt được**. Production 0/175
NULL, `migrate:fresh --seed` 11.311 dòng / 0 NULL, 607 test hẹp xanh — cả ba đều
đúng và cả ba đều đo *dữ liệu đang có*, không đo *đường ghi*. Thứ bắt được nó là
`NOT NULL` áp lên cột: 210 test đỏ ngay ở lượt INSERT (PR #2526 → revert #2530).

Từ #2411: `customer_order_items.tax_rate` là **NOT NULL**, ba đường ghi đóng dấu
qua chung `WritesCustomerOrders::bornLineTaxSnapshot()`, và
`OrderLineEvidence::$taxRateBasisPoints` là `int` chứ không `?int` — 0% là một
tỉ lệ, "chưa biết" thì không.

**Các nhánh `tax_rate === null` ở phía ĐỌC vẫn còn, và đó là cố ý.** Bảng
`order_items` của máy trạm (SQLite, `038_tax_types.sql`) vẫn khai cột nullable,
nên luật DROP-không-ép-về-0% ở trên là hợp đồng parity giữa hai engine. Gỡ phía
Cloud mà không gỡ phía Go là làm lệch parity; việc đó thuộc #2412 (đường compat
của máy trạm), không thuộc migration này.

**If a brand ends up with zero tax types** — `TaxResolver` logs *"no tax type
resolved — line taxed at 0%"* and every line of that brand under-collects — the
fix is **not** a backfill command. Reseed:

```sh
docker compose exec app php artisan provisioning:reconcile --dry-run
docker compose exec app php artisan provisioning:reconcile
```

It is idempotent by `(brand_id, code)`, sweeps every existing brand, and goes
through `BrandBaselineProvisioner` — the SAME path the Platform sync entrypoint
(`UserProvisioner::syncBrands`), branch creation and `BaselineProvisioningSeeder`
use (#2320). Full picture: `docs/guide/tenant-provisioning.md`. **By design there is NO `Brand::created` hook for tax types**
(`AppServiceProvider:514` — hundreds of tests create brands via factory and seed
their own types; a hook would collide on the unique `[brand_id, code]`). Every
brand sits in the zero-type state until the seeder or the Platform entrypoint
runs — so investigate why provisioning never ran for this brand, not a missing
hook.

---

### 3. `php artisan catalog:tax-exempt-brand --brand=<slug> [--off] [--dry-run]`

Issue #1042 option A — points a whole brand's catalog at its 非課税 (0/0) type
so the `prices_include_tax` toggle becomes a **pure display label**: every
product → EXEMPT, menu overrides → null, brand default →
EXEMPT, branch defaults → EXEMPT. **Prices are never touched.** `--off` also
flips the label to 税抜 on every branch. Idempotent; covered by
`TaxExemptBrandCommandTest`.

---

## The arithmetic contract (oracle + typed path)

Since plan-047 T2.12 there are TWO engines that must agree to the minor unit:

- the legacy engine (`WritesCustomerOrders::applyPricing` →
  `OrderPricingCalculator::priceGroups`), and
- the typed resolver (`CustomerOrderPricingResolution`), which REUSES the same
  calculator, `TaxResolver`, and `ToppingSelectionPricer`, and whose persisted
  orders are re-checked by the legacy engine (any drift rolls back).

The arithmetic itself is pinned by **`OrderPricingOracleTest`** (24 cases:
外税/内税, group-once rounding, per-rate separation, all three rounding modes
at the .4 and .5 boundaries, service-charge tax, discount-before-tax, and the
minor-unit contract JPY/VND 1:1 vs USD ×100) plus 8 live-parity cases in
`TypedOrderPricingResolverTest` comparing both engines on one fixture. Change
tax behaviour → those tests are the specification to update FIRST.

### …and a THIRD engine, in another language

The Go workstation resolves tax on its own while offline
(`workstation/internal/service/tax_resolver.go`) — it has to, because the
register prints a receipt and hands it to a customer before Cloud ever sees the
order. If the two walks disagree, the shop collects one amount and books
another, and the gap shows up as an unexplained 過不足 in the shift report rather
than as an error anyone can trace.

That is pinned by a **golden fixture that exists byte-identically in both
repos** — `backend/tests/Fixtures/tax_resolution_golden.json` and
`workstation/internal/service/testdata/tax_resolution_golden.json` — with
each side asserting the same 12 cases against its own resolver
(`TaxResolutionGoldenParityTest` / `TestTaxResolutionGolden_Parity`). Both also
recompute a sha256 over a delimited rendering of the cases and compare it to the
`digest` field stored **inside** the file, so editing a case in one repo alone
fails that repo's test immediately. **Cloud is authoritative**: change Cloud,
its test fails first, then carry the same file across in the same commit.

Three real divergences existed when the fixture was written, all of them money:
the mirror fell back to the legacy `shop_settings.tax_rate` where Cloud stamps
0%; it abandoned the chain when a tier named a not-yet-synced type instead of
continuing to the next tier; and it filtered `is_active` on the brand default,
which Cloud does not.

---

## Rollout / deploy order (spec PHẦN 11)

The phases ship **in order** and rely on additive, backward-compatible payloads
(no feature flag, no version handshake — safety comes from deploy order +
defensive defaults):

```
Phase 1  Cloud schema + engine (legacy tax_rate runs in parallel)
Phase 2  Admin-web — create + assign tax types
Phase 3  Workstation release — SQLite mirror + sync + Go engine
Phase 4  Output surfaces — receipt · shift report · Z-PDF · invoice · email
Phase 5  Client previews — pos-web · customer-web · godx-handy
Phase 6  Cleanup — DROP tax_rate + remove legacy readers   ← GATED
```

**The legacy `tax_rate` column is dropped LAST (Phase 6)** — and only after a
**pre-drop verification gate**: every branch must have a `default_tax_type_id`
AND the workstation fleet must be updated. Old workstations keep working
throughout because every new API field is additive (Go ignores unknown JSON;
missing fields decode to safe zero-values → fall back to the local default tax
type, never panic). `tax_amount` is never renamed.

---

## Deferred items (know before you rely on them)

- **The menu-item override has no admin UI yet — only an API.** This matters
  more than it looks: under the single-rate model (#1099) the menu-item override
  IS the mechanism for charging 8% on takeaway, so until the UI ships, that rate
  can only be set by calling
  `PATCH /api/v1/hq/{brand}/menus/{menu}/products/{menuProduct}/tax-type`
  (`MenuController::updateProductTaxType`, `routes/api/hq/menus.php:45`) directly.
  Admin-web today exposes `tax_type_id` on the **product** pages only — nothing
  under `hq/[brandSlug]/menus/`. A shop that splits its takeaway menu in the UI
  will therefore still bill it at the standard rate.
- ~~インボイス seller registration number entry~~ — **SHIPPED 2026-07-28
  (#1152)**, see the 登録番号 section above.
- **Phase-6 `tax_rate` drop** is gated on the fleet rollout (above) — do not drop
  the column until the verification gate passes.
- ~~No separate refund tax slip~~ — **SHIPPED 2026-07-27 (#1123)**: the
  適格返還請求書 (赤伝) section above. Per-line snapshots are still never
  touched by a refund; the return document is an ADDITIONAL record.

---

## 総額表示 — `prices_include_tax`: giá menu là 税込 hay 税抜 (#2102)

Cờ này quyết định **cách đọc con số giá trong menu**, và từ đó quyết định thuế
nằm TRONG hay NGOÀI con số ấy. Nó **không** đổi cách hiển thị chữ; nó đổi **số
tiền khách trả**.

| Nơi | Tên |
|---|---|
| Cột | `shop_order_settings.prices_include_tax` (`tinyint(1)`, **mặc định 0**) |
| Chụp lên đơn | `customer_orders.is_tax_included` — bất biến từ lúc tạo đơn |
| API | `PATCH /api/v1/shops/{shop}/settings/order` |
| Nhãn | 税込価格 (総額表示) |

### Công thức thật — hai nhánh, một chỗ

`App\Services\Customer\OrderPricingCalculator::groupTaxFor` (dòng ~158):

```php
$pricesIncludeTax
    ? $netGroup - round($netGroup / (1 + $rate / 100))   // 税込 → RÚT thuế ra (内税)
    : round($netGroup * $rate / 100)                      // 税抜 → CỘNG thuế lên (外税)
```

Với giá menu `1500` ở mức 10%:

| Cờ | Thuế | Khách trả |
|---|---|---|
| **BẬT** (税込) | 1500 − round(1500/1.1) = **136** | **1500** |
| **TẮT** (税抜) | round(1500 × 0.1) = **150** | **1650** |

Cùng một con số `1500` trong menu, **chênh 150 đồng tiền thật**. Vì vậy đây không
phải cờ giao diện.

### Ba tính chất phải biết trước khi lật

1. **Chụp bất biến lên từng đơn.** `is_tax_included` được ghi lúc tạo đơn, và
   `recompute` đọc nó trước, chỉ rơi về cờ chi nhánh khi đơn cũ không có. Nên lật
   cờ **chỉ tác động đơn MỚI** — đơn cũ giữ nguyên cách tính. Tốt: không viết lại
   lịch sử. Nhưng cũng nghĩa là đơn đã tính sai thì **sai vĩnh viễn trong sổ**;
   hoàn tiền hay không là quyết định vận hành, không phải việc của code.
2. **Bị chặn 409 khi còn ca thu ngân mở** — `TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT`.
   Cùng họ với rào đổi tiền tệ (plan-031): lật giữa ca sẽ làm hai nửa của một ca
   tính thuế khác nhau, và bản đối chiếu 精算 hết đối chiếu được.
3. **Mặc định cột là `0`.** Với quán Nhật đó là mặc định **sai từ đầu**:
   総額表示義務 bắt giá hiển thị cho khách phải là 税込. Quán mới tạo ở JP sẽ dính
   lại đúng lỗi này cho tới khi mặc định được sửa theo quốc gia của brand.

### Cách hỏng đã gặp thật (#2102)

Giá menu nhập theo **税込** nhưng cờ để **0** ⇒ engine cộng thuế lên trên giá vốn
đã gồm thuế ⇒ **khách bị thu thừa ~10%**.

Đo trên DB dev: 13 đơn thu thừa (¥3.620), 3.261 đơn thuế = 0, 0 đơn đánh dấu 税込.

⚠️ **Con số 13 nhỏ chỉ vì hai lỗi đang che nhau.** 3.261 đơn kia ra thuế 0 vì
thiếu snapshot `tax_rate` (xem #2067 — cùng gốc "tax_types chưa sync"). Khi
tax_types sync đủ, **toàn bộ chúng chuyển sang nhánh cộng-thuế-lên-trên** và mức
thu thừa nhảy vọt. Sửa cờ vì thế gấp hơn vẻ ngoài.

### Chẩn đoán nhanh

```sql
-- quán nào đang đặt gì
SELECT prices_include_tax, COUNT(*) FROM shop_order_settings GROUP BY 1;

-- đơn có bị cộng thuế lên trên không
SELECT id, subtotal, tax_amount, total_amount, is_tax_included
FROM customer_orders WHERE tax_amount > 0 AND (is_tax_included = 0 OR is_tax_included IS NULL)
LIMIT 10;
```

Nếu `subtotal` khớp tổng giá menu **và** `total_amount = subtotal + tax_amount`,
mà giá menu vốn đã gồm thuế ⇒ đang thu thừa.

### Chưa có nút bấm

`admin-web` **không có toggle** cho cờ này — `grep prices_include_tax web/admin/src`
chỉ ra `prices_include_tax_at_open` (ô chụp của TillSession). API có nhận `PATCH`,
nhưng UI không phát. Cùng hình dạng #2017: cơ chế đủ, bề mặt không có.

### RULING — cờ này chỉ được sống ở HAI tầng (chủ dự án, 2026-08-07)

> 税込 hay 税抜 **chỉ được phép ảnh hưởng ở tầng product / menu**, và nó quyết định
> **cách order service tính thuế**. Không được ảnh hưởng ở đâu khác.

Hai tầng hợp lệ, và chỉ hai:

| Tầng | Được làm gì |
|---|---|
| **Product / Menu** | quyết định giá hiển thị trong menu là 税込 hay 税抜 |
| **Order service** | quyết định hướng tính thuế — rút ra (内税) hay cộng lên (外税) |

Mọi tầng khác — biên lai, mail, báo cáo ca, till, resource hiển thị đơn — **đọc số
đã chốt trên đơn**, không được tự diễn giải lại cờ. Lý do: cờ là một câu hỏi về
**giá đầu vào**, trả lời xong một lần lúc tạo đơn và đóng băng vào
`customer_orders.is_tax_included`. Ai đọc lại cờ sau thời điểm đó là mở đường cho
tờ giấy nói khác cái sổ.

⚠️ **Chưa rà soát xong.** Đo 2026-08-07: **34 file** đọc `prices_include_tax` /
`is_tax_included` ở backend + workstation. Nhiều hơn hai tầng trên, nhưng chưa
phân loại từng file thành *hợp lệ* (đọc snapshot của đơn) hay *vi phạm* (diễn
giải lại cờ chi nhánh).

Ứng viên vi phạm cần soi trước:

- `app/Mail/OrderPaidInvoiceMail.php` — nó tự tính `pricesIncludeTax()` với
  fallback về cờ chi nhánh, tức mail có thể hiển thị khác đơn nếu cờ đã đổi.
- `app/Services/Pos/TillSessionService.php`, `ShopTillTrackingService.php` —
  tầng ca thu ngân, đáng lẽ chỉ tổng hợp số đã chốt.
- `Http/Resources/{CustomerOrderResource,KioskOrderResource}.php` — resource hiển
  thị đơn.

Việc rà soát đầy đủ nằm ở issue #2102.

### Thi hành ruling — kết quả rà soát 2026-08-08 (#2108)

Rà **39 file** đọc cờ ở backend + workstation (danh sách đầy đủ ở issue #2108):
**5 vi phạm / 34 hợp lệ**. Đáng ghi: ba ứng viên bị nghi ngờ ban đầu
(`ShopTillTrackingService`, hai Resource hiển thị đơn) hoá ra **sạch** — chúng
đọc snapshot; ba vi phạm thật là các fallback `?? cờ chi nhánh` ở
`OrderPaidInvoiceMail` / `OrderPricingCalculator::forOrder` /
`WritesCustomerOrders::applyPricing`, và hai chỗ Go đọc **cờ sống** sau khi đơn
tồn tại (`createItem` khi AddItems, `RefundItem` — PHP negate theo snapshot còn
Go theo cờ sống, tức cùng một refund hai repo hoàn hai số khác nhau).

Tất cả fallback bị **XOÁ**, không bảo tồn — ruling "legacy không tồn tại"
(2026-08-08, #2188). Đo trước khi xoá: 3.310 đơn dev, **0** đơn NULL
`is_tax_included` (cột NOT NULL, mọi đường tạo đơn đều stamp) ⇒ fallback là mã
chết; xoá không đổi số nào hôm nay nhưng đóng cửa lớp lỗi flip-giữa-chừng.

**Default theo quốc gia** (không đổi schema): `ShopOrderSettingsService::
creationDefaults` — JP ⇒ `prices_include_tax = true` (総額表示義務), qua
`ComplianceProfileResolver`, fail-safe-về-JP (#1153). Chỉ áp lúc tạo row đầu;
giá trị tường minh thắng; row có sẵn không bị đè.

**Rào hai repo**, cùng khuôn `BusinessTimeArchitectureTest`, cả hai đã qua kiểm
đột biến: PHP `TaxModeFlagArchitectureTest` (allowlist 14 file kèm lý do,
shrink-only, cấm tái sinh pattern fallback) · Go `tax_mode_flag_arch_test.go`
(literal + accessor chỉ ở 2 file, exactly-once). Thêm một chỗ đọc trái phép là
đỏ đúng file.
