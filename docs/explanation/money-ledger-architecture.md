---
title: Money ledger architecture — one sub-ledger per domain
category: explanation
tags: [ledger, payments, accounts-payable, supplier, discounts, adr, architecture]
summary: Architecture decisions — (#1151) every money domain keeps its own sub-ledger, order_payments is AR and takes no further transaction types, GL only when a real accounting need appears; (#2132) price formation (menu promotions, price overrides, free toppings) stays OUT of the order_conditions ledger — its mandatory trace lives on the item snapshot.
related: [payments-overview, gateway-settlement, cashier-shift-recovery]
---

# Money ledger architecture — one sub-ledger per domain

**Approved decision** (Duong, 2026-07-27 · [#1151](https://github.com/godx-jp/godx-tempo/issues/1151)).
Recorded here rather than left inside the issue, because its purpose is to stop
**whoever builds the supplier module later from re-arguing it from scratch** —
and a decision that lives only in an issue is a decision that person will never
find.

## The decision

1. **Do NOT funnel everything into one shared `transactions` table.** Every
   money or goods domain keeps its **own sub-ledger**. This is the familiar ERP
   model (AR / AP / Inventory are separate sub-ledgers) and it is also how
   Stripe does it: charges, refunds and payouts are separate tables, consolidated
   only on the read side in `balance_transactions`.

2. **`order_payments` keeps its role** as **AR** — money collected from
   customers. Every existing guardrail stands on exactly this shape:
   - *ledger-is-truth*: `paid_amount` is **derived** from a `SUM`, not a second
     number written in parallel;
   - the drift detector `php artisan payments:observation-report`;
   - the domain-writer guard (only one layer may write);
   - the Verify pin suite.

3. **When supplier transactions arrive, build a NEW AP module** — do not extend
   `order_payments`:

   | Table | Role |
   |---|---|
   | `purchase_orders` | Supplier orders |
   | `supplier_invoices` | Payables — its own `draft → approved → paid` lifecycle, reconciled against the supplier's invoice |
   | `supplier_payments` | The disbursement ledger — its own statuses and its own approval flow |

   **Hook into what already exists instead of building a parallel structure:**
   `stock_transactions` with `sub_type = purchase` references the PO, so goods
   receipt and payables match on documents (**3-way match**: PO ↔ receipt ↔
   invoice).

   That hook **already exists** and is not something that has to be added:
   `schemas/Backend/Inventory/StockTransaction.yaml` declares `sub_type` as an
   `EnumRef → StockTransactionSubType` (whose value set contains `purchase`), and
   there is already a `(reference_type, reference_id)` index — exactly the column
   pair needed to point one goods receipt back at its purchase order.

4. **The consolidation layer (GL / `journal_entries`): NOT built — YAGNI.** Add
   it only when a real accounting need appears: exporting a general ledger,
   consolidating cash flow, connecting accounting software. When that happens it
   is a **thin, append-only** layer that each sub-ledger posts into — and it
   **never replaces** the operational sub-ledgers.

## Why not one table

Money **collected from customers** and money **paid to suppliers** differ on
almost every axis: the counterparty, the lifecycle, the reference documents, the
tax treatment, the approval process, and even the direction of the cash flow.

Merging them yields a polymorphic table: a thicket of nullable columns, weak
foreign keys (they must point at "one of several kinds"), and two lifecycles'
statuses mixed into one enum. And the worst part is not the ugliness — it is that
**every money guard then has to sprinkle `WHERE type = …` everywhere**. Miss one
place and the drift detector that was watching AR suddenly reads AP too; that is
the seed of a book discrepancy, exactly the kind of bug that makes no sound until
reconciliation day.

Tempo is already consistent with this pattern; it is not inventing it:

| Sub-ledger | Domain |
|---|---|
| `order_payments` | AR — money collected from customers |
| `stock_transactions` · `stock_movements` | Inventory |
| `till_sessions` + cash events | Cash drawer and cashier shifts |

## Checklist for when the AP module is actually built

Not yet — this is a record so that nobody has to redesign it then:

- [ ] Omnify schemas: `PurchaseOrder` / `SupplierInvoice` / `SupplierPayment`
      (plus a `Supplier` master if one does not exist yet)
- [ ] AP approval workflow (`draft → submit → approve → paid`), mirroring the
      `stock_transactions` pattern
- [ ] Link `stock_in` `purchase` ↔ PO for the 3-way match; lot-based receipt
      goes through the existing `MaterialLot` infrastructure (FEFO)
- [ ] A separate ledger guard for AP: `SupplierPaymentLedgerWriter` plus a
      domain-writer guard — do **not** reuse the `order_payments` writer

## Boundaries — read before "improving" this

- Adding a new transaction type to `order_payments` **goes against this
  decision**. If a situation genuinely demands it, open an issue and reopen the
  discussion rather than sneaking it in behind a new `type` column.
- The same applies to building `journal_entries` "just in case" before a real
  accounting need exists: the decision is a **deliberate deferral**, not an
  oversight.

## Ruling #2132 §B — định hình giá KHÔNG vào sổ `order_conditions` (2026-08-09)

Chốt cho [#2132](https://github.com/godx-jp/godx-tempo/issues/2132) §B (tách từ
[#2080](https://github.com/godx-jp/godx-tempo/issues/2080) §1), nhất quán với
ruling [#2162](https://github.com/godx-jp/godx-tempo/issues/2162) ("mọi dòng đều
là tiền") và với ADR #1151 ở trên.

**Quyết định.** Ba cơ chế "giảm tiền vô hình" — khuyến mãi thực đơn (ghi đè
`items.unit_price`, giữ `original_unit_price`), override giá shop/menu lúc
resolve, topping miễn phí N cái đầu (`free_up_to_n`) — **KHÔNG sinh dòng nào
trong `order_conditions`**, không phải `type = discount` cấp đơn, không phải cấp
item, không phải một `type`/`source` mới. Dấu vết của chúng là **bắt buộc** nhưng
sống ở **tầng item-snapshot** (dòng món và dòng topping), không phải tầng sổ.

Ranh giới được đặt tên để không phải quyết lại từng ca:

| Lớp | Định nghĩa | Ví dụ | Đi đâu |
|---|---|---|---|
| **Transaction discount** | tiền giảm **sau khi** dòng đã có giá — nó nằm NGOÀI `subtotal` | coupon, giảm giá tay | dòng `discount` trong sổ (như hôm nay) |
| **Price formation** | cơ chế **quyết định** giá dòng — kết quả của nó CHÍNH LÀ `unit_price`/`topping_subtotal`, tức đã nằm TRONG `subtotal` | khuyến mãi thực đơn, override giá menu/floating, topping miễn phí N | snapshot trên dòng; **không bao giờ** vào sổ |

### Thứ tự sức nặng

1. **Tiền của ba cơ chế ĐÃ nằm trong `subtotal`.** Bất biến
   `total_amount == subtotal + Σ(sổ)` tự kiểm tra được chỉ vì mọi dòng sổ là
   tiền **ngoài** subtotal (`ConditionLedgerEdgeCasesTest.php:110-123`, và
   `Σ(discount) == −discount_amount` tuyệt đối ở `:125-136`). Một dòng ghi lại
   khoản đã phản ánh qua `unit_price` hạ xuống — dù gắn `conditionable =
   CustomerOrderItem` để né phép cộng cấp đơn — là đại diện **kép** của cùng một
   khoản tiền trên cùng một bảng, và mọi reader từ đó phải mang quy ước mềm
   *"nhớ loại trừ khi cộng"*. Đó đúng hình dạng #2074/#2075 vừa trả giá, và đúng
   lý do #2162 đã bác dòng `type = audit`: sổ chứa dòng không tham gia phép cộng
   thì ngừng tự kiểm tra được bằng phép cộng. (Đã kiểm scope thật: cả test bất
   biến — `ConditionLedgerEdgeCasesTest.php:83-85` — lẫn reader sản xuất —
   `OrderTaxBreakdownAggregator.php:71,105` — đều lọc
   `conditionable_type = order`, nên dòng item-level *hôm nay* không lọt vào Σ
   nào; nhưng "an toàn nhờ mọi chỗ đọc đều nhớ lọc" chính là định nghĩa của quy
   ước mềm.)
2. **Bán kính xoá của writer nuốt chúng.** `writeConditions` xoá
   `tax|discount|service_charge` trên **cả hai morph** (đơn VÀ item —
   `WritesCustomerOrders.php:3069-3077`) rồi dựng lại thuần tuý từ
   `PricingResult`; mà `PricingResult` không biết giá niêm yết — nó nhận
   `rateSubtotals` đã net sau price formation. Dòng item-level do một đường ghi
   khác tạo sẽ bị lượt `recalculateTotals` kế tiếp xoá im lặng và **không dựng
   lại được** từ đầu vào của writer; còn bơm giá niêm yết vào pricing chỉ để nhả
   ra dòng thông tin là đảo ngược đúng chiều phụ thuộc mà rào
   `ConditionLedgerDerivesFromPricingTest` canh giữ.
3. **Chi phí bất đối xứng, và nửa còn lại đã xây sẵn.** Quên một chỗ loại trừ ⇒
   sai tiền, im lặng. Hướng snapshot thì đã có hạ tầng: `original_unit_price`
   được stamp cho khuyến mãi (`WritesCustomerOrders.php:1403-1405`, Decision B6),
   báo cáo hiệu quả khuyến mãi **đã đọc snapshot chứ không đọc sổ**
   (`MenuPromotionService.php:97-99`,
   `PromotionRedemptionReads.php:29-30` — `Σ (original − unit) × qty`), và dòng
   topping đã snapshot **giá đầy đủ** khi bị miễn
   (`WritesCustomerOrders.php:1554-1556`: "snapshotting full unit_price
   (free_up_to_n discount lives at line level)"). Việc còn thiếu là đóng hai lỗ
   snapshot, không phải mở một mặt phẳng sổ mới.
4. **Chuẩn ngành xếp ba cơ chế vào định hình giá.** Mô hình POS ARTS/NRF ghi
   price modifier **trên dòng bán** (regular price ↔ actual price + reason
   code), không ghi vào mặt phẳng discount của transaction; IFRS 15 đo doanh
   thu theo transaction price = giá thực bán, chênh với giá niêm yết là
   analytics chứ không phải doanh thu; và chính hệ thống này đã đối xử như vậy:
   thuế tính thẳng trên `unit_price` đã hạ (không có bước "cộng lại rồi trừ
   discount" cho ba cơ chế), tức tầng thuế đã coi chúng là giá, không phải
   khoản giảm.

### Bất biến tổng giữ thế nào

Không thêm dòng ⇒ hai bất biến hiện có giữ **nguyên văn**, không đổi scope,
không thêm điều kiện lọc. Tầng snapshot nhận bất biến riêng của nó, nằm hoàn
toàn ngoài mặt phẳng sum của sổ:

- từng dòng món: `original_unit_price ≥ unit_price`;
- độ sâu khuyến mãi của đơn = `Σ (original_unit_price − unit_price) × quantity`
  — đọc từ dòng, không bao giờ cộng chéo vào `Σ(sổ)`;
- topping: `Σ(order_item_toppings.unit_price × quantity) − topping_subtotal`
  = tổng đã miễn của dòng (gross snapshot − net).

### Hệ quả cài đặt (sub-issue riêng — KHÔNG làm trong #2132)

1. **`original_unit_price` bắt buộc trên mọi dòng món** — bằng `unit_price` khi
   không có cơ chế nào hạ giá; hết nghĩa "NULL = không có gì để nói". Cột đi về
   NOT NULL theo ruling #2188 (cấm thiết kế quanh NULL-snapshot).
2. **Snapshot nguồn giá** (`price_source`: `sku_base | menu | floating |
   menu_promotion`) trên dòng món — trả lời "giá này từ đâu" mà không chạy lại
   logic resolve.
3. **Topping miễn phí đánh dấu được CÁI NÀO miễn**: bổ sung vết charged/waived
   trên `order_item_toppings` (bảng hiện chỉ có `unit_price` gộp —
   `2000_01_01_000133_create_order_item_toppings_table.php:34`); tổng miễn đã
   suy được, đơn vị nào miễn thì chưa.
4. **Chiều ngược lại phải ghim bằng rào**: `writeConditions` không bao giờ mọc
   `source ∈ {menu_promotion, price_override, free_topping}` — một arch test
   tĩnh cùng kiểu `ConditionLedgerDerivesFromPricingTest`.

## Hồ sơ giới hạn #2132 §C — bảy loại giảm giá mô hình không diễn đạt được (2026-08-09)

Ghi để **biết giới hạn**, không phải backlog. Nguồn: #2080 §4, đóng dấu lại ở
#2132 sau ruling §B ở trên.

| # | Giới hạn | Trạng thái |
|---|---|---|
| 1 | **BOGO / mua X tặng Y** — `CouponDiscountType` chỉ `fixed\|percent`, không điều kiện số lượng, không dòng thưởng; làm tay thì mất dấu vết đây là BOGO | không diễn đạt được |
| 2 | **Giảm chỉ áp lên MỘT dòng** (thu ngân bớt tiền cho một món) — schema sổ gắn được `conditionable = CustomerOrderItem`, `writeConditions()` chưa ghi cấp item | khả năng schema có sẵn, đường ghi chưa dùng — xem ghi chú dưới bảng |
| 3 | **Giảm số tiền cố định theo món** ("bớt 100¥ mỗi ly") — `MenuPromotion` chỉ có phần trăm | không diễn đạt được |
| 4 | **Giảm theo bậc số lượng** — không có trường số lượng ở đâu cả | không diễn đạt được |
| 5 | **Miễn phí phí phục vụ / giao hàng** — sổ chỉ có `service_charge` là dòng **CỘNG** (`WritesCustomerOrders.php:3203-3217`), không có cơ chế giảm nó | không diễn đạt được |
| 6 | **Hai coupon cùng lúc** — chặn cứng bằng unique index `coupon_redemptions_order_unique` (`2000_01_01_000034_create_coupon_redemptions_table.php:47`) | **LÀ QUYẾT ĐỊNH, không phải thiếu sót** |
| 7 | **Giảm ràng theo loại đơn** (tại chỗ/mang về) — `PointRewardServiceCondition` có enum nhưng *"v1 CHỈ HIỂN THỊ, KHÔNG CƯỠNG CHẾ (#1514)"* | hiển thị-only có chủ đích |

Ghi chú cho mục 2 — để người cài sau **không** đọc nhầm ruling §B thành lệnh
cấm: giảm tay theo dòng là **transaction discount** (tiền nằm ngoài `subtotal`
vì `unit_price` giữ nguyên giá niêm yết), nên theo đúng ranh giới §B nó **thuộc
về sổ**, ở cấp item. Ngày nào xây, phải định nghĩa lại scope của bất biến
`Σ(discount) == −discount_amount` một cách **tường minh** (cộng cả hai morph
hay tách hai bất biến theo morph — phải chọn, viết test trước), vì mọi phép Σ
hiện có đều lọc `conditionable_type = order` và writer xoá-dựng-lại đã chạm cả
hai morph (`WritesCustomerOrders.php:3069-3077`). Đó là một thiết kế phải mở
issue riêng, không phải một dòng `create()` thêm vào.
