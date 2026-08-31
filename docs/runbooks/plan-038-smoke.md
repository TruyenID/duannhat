---
title: Plan-038 manual smoke runbook
category: guide
tags: [runbook, smoke-test, qa, printing, plan-038]
summary: "The 19 cashier-facing plan-038 scenarios gathered into one printable execution checklist for a QA pair on the shop floor."
related: [printing]
---

# Plan-038 manual smoke runbook

> The 19 cashier-facing scenarios from
> plan-038 TESTS (đã archive — xem git history) gathered into
> one execution checklist. Print this page, hand it to the QA pair on the
> shop floor, and sign each row as you go. Anything that can be eyeballed
> from a paper slip lives here; pure-code assertions stay in the unit
> suites.

## Pre-flight (do once, before scenario 01)

| ✓ | Step | Notes |
|---|------|-------|
| ☐ | Cashier tablet on the same LAN as the workstation | Confirm `http://workstation.local:8080/api/lan/health` returns 200 |
| ☐ | Workstation Wails app open + paired | `/devices` shows `kitchen_printer`, `bar_printer`, `receipt_printer` all `online` |
| ☐ | KDS tablet paired + dashboard open | Connection badge green |
| ☐ | `kds_show_only_fired` toggle decision recorded | Default **OFF**; flip ON only for new shops where staff is trained |
| ☐ | Paper rolls topped on Star + Epson | Star = kitchen + bar (Shift_JIS). Epson = receipt (UTF-8). |
| ☐ | Mobile handy tablet paired (for the regression suite) | Optional but recommended |
| ☐ | Test customer "Cty ABC" exists with `tax_code='0312345678'`, phone `0901234567` | Pre-seed via admin-web Customers |
| ☐ | `branches.invoice_prefix` set to `HN1` for the test branch | Otherwise scenarios 17–19 fail on a different prefix |

Capture device IDs + serials in your QA log so the sign-off table at the
bottom maps back to specific hardware.

---

## Section A — Print fidelity (5 scenarios)

### A1 — Kitchen ticket on Star — diacritics + cut

1. Open pos-web `/pos`, add **Phở bò** ×1, **Cá lóc nướng** ×1, **Bánh mì** ×1.
2. Press **Gửi bếp** in the order cart.
3. Inspect the kitchen ticket on the Star printer.

Assertions:
- ☐ "Phở", "Cá lóc", "bằng", "bánh mì" all render with full diacritics
  (`bằng` must show the combining mark — Windows Yu Gothic fallback used
  to drop it; the M PLUS 2 + Shift_JIS pipeline is the fix).
- ☐ Cut command executes — slip falls cleanly.
- ☐ Header shows `T-12 · #N` where `N` = today's per-station ticket
  counter.

### A2 — PAID slip on Epson — QR + currency

1. Pay the order in full with cash → `PaymentReceiptDialog` opens.
2. Click **In biên lai** → first slip prints on Epson.

Assertions:
- ☐ Title row reads `DA THANH TOAN`.
- ☐ Total formatted with Vietnamese thousands separator
  (`100.000` or `100,000` depending on locale cookie).
- ☐ `Con lai = 0` line is **suppressed** (clean settle).
- ☐ Cut executes.

### A3 — Split-equal 3-way

1. Reopen a fresh 240,000 đ order. Open **Tách bill → Chia đều → 3**.
2. Settle row 1 cash → slip prints with `Khach 1/3`.
3. Settle rows 2 + 3 in turn.

Assertions:
- ☐ Each slip's `Khach` row reads `1/3`, `2/3`, `3/3` respectively.
- ☐ Per-bill totals each = 80,000 đ.
- ☐ Each slip carries a distinct ticket number (`#N`, `#N+1`, `#N+2`).

### A4 — "In lại" after Gửi bếp returns 422

1. On an order whose items are all `sent_to_kitchen`, press **Gửi bếp**
   again.

Assertions:
- ☐ Pos-web shows the toast `pos.kitchen.all_printed` ("Tất cả món đã in").
- ☐ Audit log on the workstation records `order.fire` with
  `{"printed":0,"errors":0}` and no paper emits.

### A5 — End-to-end by_amount 250k → 3 customers

1. Open a 250,000 đ order. **Tách bill → Theo số tiền**.
2. Set rows to 100,000 / 80,000 / 70,000. Pick cash / qr / cash methods.
3. Settle rows 1 → 2 → 3 in order.
4. From `SplitBillReceiptDialog`, select **all 3** rows → press **In biên
   lai (3)**.

Assertions on the FIRST round of slips:
- ☐ Each slip's `Khach` row reads `Người 1 (1/3)`, `Người 2 (2/3)`,
  `Người 3 (3/3)`.
- ☐ Item table is EMPTY (by_amount strips items — `San pham` column
  header may print but no rows below).
- ☐ Headline `Tong` matches the row's amount, not the order total.
- ☐ `Da thanh toan` line matches the row's amount.

Assertions on the REPRINT:
- ☐ Each reprinted slip's header carries `BAN IN #2`.
- ☐ Slips emit with ~200 ms gap (no overrun jam).

---

## Section B — Edge cases (5 scenarios)

### B1 — Sync lag → force-pull on Gửi bếp

1. SSH into workstation. `systemctl stop workstation-app-sync` (or
   manually pause the puller from the Wails Sync screen).
2. From pos-web in cloud-mode, create a brand-new order (workstation has
   not seen it yet).
3. Click **Gửi bếp** within 1 s of order creation.

Assertions:
- ☐ Slip prints successfully.
- ☐ Workstation logs show `force-pull` invocation followed by `MarkItemPrinted`.

### B2 — Bar printer unplugged → partial fire

1. Power-off the bar printer.
2. Place an order with **2 kitchen items + 1 bar item** in pos-web.
3. **Gửi bếp**.

Assertions:
- ☐ Kitchen slip prints (2 items).
- ☐ Bar slip does NOT print.
- ☐ Pos-web shows the partial toast (warning, not error) listing
  `printer_group: "bar"` failure.
- ☐ Audit log carries `{"source":"pos-web","printed":2,"errors":1}`.

### B3 — Empty paper roll mid-print

1. Tear out the receipt paper roll while Epson is mid-feed (or eject the
   roll before pressing **Thu**).
2. Trigger a payment.

Assertions:
- ☐ Pos-web surfaces a generic `pos.kitchen.fire_failed` toast.
- ☐ Audit log records the Star driver `paper_out` error.
- ☐ Payment row stays `confirmed` (print failure must NOT roll back the
  database write).

### B4 — Mobile handy regression smoke

1. From the mobile handy app, place a 1-item order on table T-04 and
   press **Gửi bếp**.

Assertions:
- ☐ Kitchen ticket prints — proves the dispatcher refactor (plan-038
  T1.3) didn't regress handy fire.
- ☐ Audit row carries `{"source":"handy",...}` (not `"pos-web"`).

### B5 — Workstation Wi-Fi blip (KDS realtime not affected by 5 s window)

1. Disconnect workstation Wi-Fi for ~5 s.
2. Press **Gửi bếp** from pos-web (still LAN-reachable via mDNS).
3. Reconnect KDS Wi-Fi.

Assertions:
- ☐ Kitchen slip prints (LAN to workstation is fine).
- ☐ KDS card shows up via WS replay on reconnect (within the 60 s
  window).

---

## Section C — KDS realtime (3 scenarios)

### C1 — Cold latency p95 < 800 ms over 10 fires

1. On 1 KDS tablet + 1 cashier tablet on the same LAN, with the KDS
   dashboard open and `kds_show_only_fired` either ON or OFF (record
   which).
2. Fire 10 different orders. Stopwatch from **Gửi bếp** click to KDS
   card visible.
3. Record each latency.

Assertions:
- ☐ Sort timings ascending. The 9th value (90th percentile) **must** be
  < 800 ms; p95 (interpolated between samples 9 and 10) must too.
- ☐ Median should sit ~250–400 ms.

| # | Latency (ms) | Notes |
|---|---|---|
| 1 |   |   |
| 2 |   |   |
| 3 |   |   |
| 4 |   |   |
| 5 |   |   |
| 6 |   |   |
| 7 |   |   |
| 8 |   |   |
| 9 |   |   |
| 10 |   |   |

### C2 — KDS sleep 30 s + backfill

1. Sleep the KDS tablet for 30 s (screen lock).
2. Fire 3 orders during the sleep window.
3. Wake the tablet.

Assertions:
- ☐ All 3 cards appear within 2 s of wake (`?since=` cursor backfill
  works).
- ☐ Cards appear in firing order (not reverse).

### C3 — KDS reconnect AFTER replay window

1. Disconnect KDS Wi-Fi.
2. Wait **120 s** (twice the 60 s buffer).
3. Reconnect.

Assertions:
- ☐ KDS does NOT receive the buffered events (expected — past the 60 s
  window).
- ☐ Next periodic poll (≤ 60 s) backfills via the regular
  `GET /api/v1/kds/orders` call.

---

## Section D — Debt / "Ghi nợ" (3 scenarios)

### D1 — Ghi nợ toàn bộ

1. Sign cashier in. Pair a new 500,000 đ order with customer **Cty ABC**.
2. **PaymentDialog → Ghi nợ toàn bộ**.

Assertions:
- ☐ A single `PHIEU GHI NO` slip emits on the receipt printer.
- ☐ Slip carries `Cty ABC`, `MST: 0312345678`, `SDT: 0901234567`, the
  signature line "Khach hang xac nhan da nhan no".
- ☐ Order status flips to `paid` (treated as settled).
- ☐ `GET /api/v1/shops/{shop}/debts` lists the customer with
  `open_debt_total = 500000`.

### D2 — Trả kèm nợ

1. Pair a new 500,000 đ order with **Cty ABC**.
2. Cash 200,000 → `PaymentReceiptDialog` opens with `remaining=300000`.
3. Click **Ghi nợ phần còn lại** (the dialog shows this CTA when
   remaining > 0 AND customer_id set).

Assertions:
- ☐ Two slips emit: `DA THANH TOAN` (200,000 đ) + `PHIEU GHI NO`
  (300,000 đ).
- ☐ Both payment rows confirmed in `order_payments`.
- ☐ Debt visible on `/debts` for the customer (delta = 300,000 đ added).

### D3 — Settlement

1. Reopen pos-web with cashier still signed in. Open **Tra cứu nợ**.
2. Search "Cty ABC" → row shows 800,000 đ open (500k from D1 + 300k from D2).
3. Click **Thu nợ** on D1's debt → settlement flow takes you to a new
   payment for 500,000 đ.
4. Confirm in cash.

Assertions:
- ☐ Settlement payment row has `metadata.settles_payment_id = <D1 debt id>`.
- ☐ `/debts` now shows D1's debt cleared (open total = 300,000 đ).
- ☐ Settlement slip prints with reference to original `Phieu ghi no`.

---

## Section E — Hoá đơn đỏ · **BỐN KỊCH BẢN CŨ ĐÃ GỠ (#1779, 2026-08-04)**

> Đừng chạy Section E theo bản cũ. Bốn kịch bản E1–E4 kiểm một đường đã bị xoá,
> nên người kiểm thử sẽ báo lỗi giả cho những thứ **cố ý không còn tồn tại**.

Ngày 2026-08-04, theo quyết định của chủ dự án (#1779), toàn bộ đường **hoá đơn
formal** bị gỡ: bảng `customer_invoices` thôi được ghi, `POST /api/lan/print/vat-invoice`
và `VatInvoiceFormDialog` bị xoá, cùng với số hoá đơn tuần tự, void, 赤伝 và
VN e-invoice. Ruling: **hoá đơn đỏ chỉ IN, không lưu DB**.

Vì vậy bốn mục kiểm cũ giờ vô nghĩa:

| Kịch bản cũ | Vì sao không chạy được nữa |
|---|---|
| E1 — Issue from a paid order | `VatInvoiceFormDialog` không còn; không có hàng nào được ghi vào `customer_invoices` để đối chiếu |
| E2 — Monotonic sequence | không còn cấp số tuần tự |
| E3 — Reprint | in lại theo `invoice_id` không còn đường; bản in lại giờ đếm theo `print_jobs` (#1875) |
| E4 — Manager void | không còn trạng thái `voided` để lật, và 410 Gone không còn |

**Thay bằng gì:** đường đang chạy là `POST /api/lan/print/red-invoice`
(`red-invoice-dialog.tsx` ở pos-web) — in phiếu đã trả kèm dòng người mua, có
`payment_id` để nhắm một người trong đơn chia bill.

⚠️ **Kịch bản smoke cho đường mới CHƯA được viết.** Ghi ra đây thay vì im lặng
xoá Section E: chỗ này đang là một lỗ hổng kiểm thử có thật, không phải một mục
đã hoàn thành. Ít nhất cần phủ: bản in lại mang dấu 「BAN IN #N」 đúng số (đếm
theo KIND và theo phạm vi người trả — #1875), và tên người mua lên đúng phiếu
khi chia bill.
---

## Sign-off

After each section, capture the operator's signature + date + photo of
the resulting paper stack. Paper photos go into the QA Drive as
`plan-038/<date>/section-<X>-<scenario>.jpg`; link the folder URL here.

| Section | Date | QA tester | Workstation host | Photo link | All pass? |
|---|---|---|---|---|---|
| A — Print fidelity | | | | | ☐ |
| B — Edge cases | | | | | ☐ |
| C — KDS realtime | | | | | ☐ |
| D — Debt | | | | | ☐ |
| E — VAT invoice | | | | | ☐ |

Once every row is ☑, mark T7.4 done in plan-038 TASKS (đã archive — xem git history) and
append a `## YYYY-MM-DD — Manual smoke signed off` block to
plan-038 NOTES (đã archive — xem git history) with the QA tester's name + photo folder URL.

## When something fails

- File against the umbrella `feature/plan-038-pos-bill-printing` PR with
  the scenario id (`A1`, `D2`, …) and a paper photo. The corresponding
  unit test belongs next to the existing per-task tests in
  `workstation/internal/handler/*_test.go` or
  `web/pos/src/services/*.test.ts`.
- For repeatability, capture the workstation `audit_log` rows between
  the order open and the failing print. Workstation `Reports → Audit`
  view exports CSV.
