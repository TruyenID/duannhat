---
title: Item edit & void policy — item statuses, edits/removals, and their inventory consequences
category: guide
tags: [order, item, void, edit, sku, inventory, kds, pos, workstation]
summary: >
  The platform-wide invariants for editing and removing order items: five fixed
  statuses, edits only while pending, a SKU is never changed in place (void plus
  add new), voiding an item that is already being prepared requires a real
  reason, and the inventory-drift warning attached to the
  allow_item_edit_any_status flag. Enforced identically in Cloud, workstation
  and pos-web — same-SKU stacking included since #2623: MỘT luật, không còn
  cửa sổ thời gian hay rào phiếu bếp ở phía Cloud (§2b).
related:
  - guide/tax-types.md
  - explanation/inventory-domain.md
status: shipped 2026-07-27 (#1148) + 2026-07-28 (plan-051 — #1149 void matrix/VoidReason master + #1150 stock_deduction_timing)
---

# Item edit & void policy

Settled by the product decision of 2026-07-27 (#1148, tightened in two waves).
Every layer — Cloud (`WritesCustomerOrders`), the workstation Go engine, and the
pos-web UI — enforces **the same rule set**; all three carry pinning tests. The
one deliberate exception is same-SKU stacking, where the layers hold different
evidence about what the kitchen has been told: **§2b**.

## 1. Five item statuses — fixed platform-wide

`pending → preparing → ready → served` (free movement among the four active
statuses, including backwards, so a mistaken KDS tap can be corrected) plus
`voided` (terminal, reachable only through the void flow). This list is **not
configurable** — which matches industry practice (Toast, Square and Simphony all
use three to five fixed statuses) — because item status is the language that
money, inventory and sync all speak. The full analysis, including when dynamic
statuses would ever be warranted, is in the comments on #1149.

The only related per-shop setting is `default_order_item_status` — the status an
item is CREATED in (a shop that does not use KDS can set it to `served`).

## 2. Edit and removal rules

| Operation | `pending` | `preparing` / `ready` / `served` |
|---|---|---|
| Edit qty / note / toppings | ✅ | ❌ **absolutely not** — no flag reopens this |
| Change SKU (variant) in place | ❌ **never** | ❌ |
| Void (cancel the item) | ✅ (reason optional) | Only when the flag is ON, and a **real reason is mandatory** |
| Remove (DELETE, no reason) | ✅ | ❌ (a junk reason returns 422) |

> **"Edit" means a human changing a line that already exists. It does NOT mean
> BR-OI06 stacking a NEW order onto that line** — see §2b. The two are easy to
> confuse because both end with a larger `quantity`, and confusing them is how
> #2522 sat unexplained: the merge was read as forbidden, so nobody asked why it
> was not happening.

- **The SKU is immutable on a line**: a different variant is a different physical
  item, so the only route is **void (with a reason) plus add a new item**. The
  server returns 409 for the `product_sku_id` / `menu_product_sku_id` keys on an
  update, in Cloud and over LAN alike (`ErrItemSKUImmutable`). pos-web keeps its
  dialog UX: picking a different variant makes the client ADD the new line first
  (so a failure loses nothing) and then VOID the old line with the reason
  `pos.void_reason.sku_change`.
- **Nothing about an item that has reached the kitchen can be edited** — "ask the
  kitchen, confirm, then void and add a new item". The void dialog for items at
  `preparing` or beyond gets its own warning (scope of #1149).
- **A real void reason** means non-empty and not one of the junk defaults
  (`Removed by staff`, `voided_by_workstation`) — violations return 422.

## 2b. Same-SKU stacking (BR-OI06) — the FIRE boundary, not the status (#2522)

Ordering the same dish again is not an edit. BR-OI06 stacks it onto the existing
line instead of opening a second one, so four helpings read as `× 4` rather than
as four separate orders.

**The boundary that decides this is whether the line has been SENT TO THE
KITCHEN, not what status it is in.** That is the industry model (Toast, Square,
Lightspeed): tapping a dish twice before *Send* increments the quantity; after
*Send* the new units are a new firing. Item status is a poor stand-in for it —
at a shop whose `default_order_item_status` is `served`, every line is born past
`pending` and status stops carrying any information at all.

**MỘT luật, hai tầng** (#2623 — trước đây hai tầng bất đối xứng có chủ đích).

Cả Cloud lẫn máy trạm gộp **không giới hạn**: không cửa sổ thời gian, không rào
"đã có phiếu bếp". Tín hiệu chung là `printed_quantity` theo từng dòng, và nó
khép kín qua bốn bước:

1. Cloud gộp ⇒ `quantity` của dòng tăng.
2. Máy trạm pull DOWN: upsert `order_items` cập nhật `quantity` và **không**
   đụng `printed_quantity` (`sync_pull.go`, `DO UPDATE SET` không liệt kê cột đó).
3. `onOrderMerged` kích `fireKitchenForOrder`, in đúng phần chênh
   `quantity − printed_quantity`.
4. Máy trạm sync UP `printed_quantity` mới về `customer_order_items`.

Nên bếp luôn được báo **đúng phần đơn vị mới**, dù việc gộp xảy ra ở tầng nào.

### Còn chi nhánh không có máy trạm?

Câu hỏi này từng là lý do giữ rào, và phép đo cho thấy nó không phải rủi ro:
**Cloud không bao giờ phát phiếu bếp.** Hàng `print_jobs` kind `kitchen` chỉ tới
Cloud qua `POST /workstation/print-jobs` — sync UP nhật ký in của máy trạm, vốn
sở hữu hàng đợi in (DESIGN §1b). Không lời gọi `CloudPrntEnqueueService::enqueue`
nào tồn tại trong `backend/app/`.

Nói cách khác **phiếu bếp chỉ tồn tại ở nơi có máy trạm**, mà ở đó
`printed_quantity` là nguồn có thẩm quyền. Không có topology nào vừa in bếp vừa
thiếu người đóng dấu — nên rào cũ chưa bao giờ bảo vệ chi nhánh không-máy-trạm;
nó chỉ làm Cloud dè dặt ở đúng nơi đã đủ thông tin để không cần dè dặt.

Quán born-`pending` vẫn đi đường trả sớm cũ, byte cho byte. Cùng lượt bỏ rào,
`orders.same_sku_merge_window_seconds` / `ORDER_SAME_SKU_MERGE_WINDOW_SECONDS`
và cổng công bố `KitchenTicketLookup` đã bị **xoá** — theo #2188, cấu hình và
cổng bắc cầu chết cùng lúc với lý do tồn tại của chúng.

### Why this went wrong once

人形町店 C-6, 2026-08-12: a customer ordered the same bowl of pho four times over
24 seconds through the table QR. Cloud created **four lines and four kitchen
slips**, and the shop read that as one guest placing four orders. The same four
taps entered on the workstation would have produced one line of `× 4`.

Cause: Cloud's BR-OI06 query required `status = pending`, and the shop is
born-served — so the merge could never fire there, for any dish, ever. The fix
is §2b's rule; the reproduction lives in
`tests/Feature/Customer/DineInSameSkuMergeTest.php`.

## 3. Per-status void matrix + VoidReason master (plan-051, shipped 2026-07-28)

The boolean `allow_item_edit_any_status` has been replaced by
**`shop_order_settings.item_voidable_statuses`** (Json): the shop ticks which
statuses may be VOIDed. `pending` is always ticked and cannot be turned off (the
resolver unions it in); `served` can be ticked but defaults to OFF (the
recommended route there is the plan-045 refund). Null falls back to the old flag
(true → all four, false → pending only), so no backfill is needed at deploy; the
old flag is kept for ONE release, for workstation builds that predate the list.
The canonical resolver lives in exactly one place per stack:
`VoidableStatusResolver` (Cloud) ↔ `ResolveVoidableStatuses` (Go) — identical
semantics, pinned on both sides.

**VoidReason master** (brand-scoped, HQ `/hq/{brand}/void-reasons`): staff PICK a
reason from a list (labels in ja/en/vi, with `requires_note` forcing an extra
note) instead of typing free text. `stock_effect` decides what happens to
inventory when an already-deducted item is voided: `restock` (return to the
original lot plus a genealogy reversal) · `waste` (no return — the material was
really consumed, tagged for reporting) · `none` (comp — the item is still
served). A non-pending void still requires a real reason: either a valid
`void_reason_id` OR real text (junk still returns 422); a missing note where
`requires_note` is set returns 422 `VOID_NOTE_REQUIRED`; a status outside the
matrix returns 409 `ITEM_STATUS_NOT_VOIDABLE`.

**Stock deduction timing** (`stock_deduction_timing`, #1150): `on_close` (the
default, byte-identical to the old behaviour) / `on_preparing` (when the kitchen
accepts — including items CREATED at `preparing` or later in a shop without KDS)
/ `on_add`. Deduction is per ITEM with a per-line marker
(`customer_order_items.stock_deducted_at` plus `stock_out_transaction_id`) —
idempotent, safe to change mid-day, and revising qty while pending on an
already-deducted line becomes a delta adjustment.

⚠️ **The inventory-drift warning is now CONDITIONAL** (admin-web): it appears
only when the matrix opens `preparing` or beyond **AND** timing is `on_close` —
a shop on `on_preparing` no longer drifts, because materials are deducted when
the kitchen accepts and a later void is handled per `stock_effect`.

## 4. The payment window — prepaid differs from pay-after

- **Not yet paid** (order `open` / `awaiting_confirmation`): the whole rule table
  in section 2 applies.
- **Already paid** (order `closed`): takeaway and kiosk prepay **close the order
  the moment it is paid in full** — the kitchen cooks AFTER the order is closed.
  On a closed order **nothing can be edited and nothing can be voided** (not even
  a still-pending item): every change goes through an **item refund** (plan-045
  `refundItem`, a negative line plus a reason) → add the new item → collect or
  refund the difference.

## 5. Shipped / remaining

**#1149 + #1150 = plan-051, shipped in full on 2026-07-28** (section 3). What
remains is recorded in the plan: dropping `allow_item_edit_any_status` entirely
once the workstation fleet is on a build that parses the list (P5 T5.3); a waste
report built from the transaction tag; and a fourth option, `reserve_on_add`, if
a real need appears.

## Enforcement references

Cloud: `WritesCustomerOrders::updateItem/voidItem/removeItem` ·
Workstation: `order_service_pos.go` (`ErrItemSKUImmutable`,
`ErrItemEditRequiresPending`, `ErrVoidReasonRequired`) ·
pos-web: `order-cart.tsx` (`canEdit` pending-only, `canVoid` driven by the flag) ·
Pins: `OrderItemVoidTest`, `OrderItemToppingsWriteTest`,
`WorkstationItemMutationTest`, `order_service_allow_any_status_test.go`,
`order_service_update_selection_test.go`.
