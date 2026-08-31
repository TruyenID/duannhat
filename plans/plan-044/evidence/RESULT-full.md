# Plan-044 R2 — full E2E evidence (curl + Playwright + DB)

**Ngày:** 2026-07-16 · **Shop:** `sjk` · **Backend:** Cloud (docker :5400) · **UI:** pos-web :5440 (ép `pos_api_mode=cloud`).
**Phương pháp:** shift lifecycle + endpoints plan-044 qua **curl** (HTTP thật, token Sanctum + `X-Shop-Slug`); order/payment tạo bằng **resolver thật của handler** (`openSessionIdForBranch` / `resolveSyncedSessionId`) → stamp y hệt; **DB** (MySQL) làm ground-truth; **Playwright** chụp UI thật.

Tổng: **5/5 scenario PASS**. Kèm **1 bug UI được phát hiện + fix ngay** (ack callout bị bóp cột).

---

## S1 — Gate NO_OPEN_SHIFT (khoá bán hàng khi chưa mở ca)

**curl** (không có ca mở):
```
POST /api/v1/pos/orders  →  HTTP 409
{"message":"No cashier shift is currently open on this till.","code":"NO_OPEN_SHIFT"}
DB: orders on sjk in last 1 min = 0   (không tạo được đơn)
```
**UI** — `s1-gate-redirect.png`: vào `/shop/sjk` → **tự redirect** `/shop/sjk/shift/open`, báo *"chưa có ca nào đang mở. Bạn cần mở ca mới để vào màn hình bán hàng."* → không thể vào POS.
✅ Gate chặn cả API (409) lẫn UI (redirect). Cloud gate = `ResolveOpenTillSession`; workstation LAN gate = T5.2 (đã thêm ở lượt trước).

## S2 — Mở ca + panel đối chiếu gap-preview

**curl** `GET /pos/till/gap-preview`:
```
previous_session: SHIFT-20260716-009   totals: {count:1, cash_amount:800}
gap payment: E2E-GAP ¥800 is_cash=true
```
**UI** — `s2-open-gap-panel.png`: màn Mở ca hiển thị panel **"Đối chiếu thanh toán trong lúc chuyển ca · 1 khoản"** — order ¥800, badge **"Tiền mặt — giữ riêng"**.
✅ Panel liệt kê đúng payment NULL trong cửa sổ gap.

## S3 — Claim gap payment + ack tiền-giữ-riêng

**curl** open ca 2 với `claimed_gap_payment_ids` + `gap_cash_held_separately_ack=true`:
```
BEFORE claim: gap payment till_session_id = NULL
POST /pos/till/sessions  →  HTTP 201   gap_payments_claimed=1
AFTER claim:  till_session_id = S2 (ca mới) ✅   audit: till_session.gap_claim
```
**UI** — `s3-gap-ack.png`: tick khoản tiền mặt → hiện callout bắt buộc **"Xác nhận tiền mặt giữ riêng"** + checkbox *"Tôi xác nhận số tiền mặt trên được giữ riêng, không nằm trong tiền đầu ca."* (gate chặn mở ca tới khi tick).
✅ Claim chuyển NULL → ca mới; audit ghi nhận; UI ack hoạt động.

## S4 — Màn kết ca: tóm tắt paid / unpaid-carry

**curl** `GET /pos/till/sessions/{S1}/order-summary`:
```
paid_orders_count : 1        paid_orders_total : 1200
unpaid_carry_count: 20       (gồm E2E-UNPAID + 19 đơn seed active chưa trả → carry)
```
**UI** — `s4-close-order-summary.png`: card **"Tóm tắt đơn hàng"** — **1** *Đơn đã thanh toán (tính vào ca này)* / **20** *Đơn chưa thanh toán (chuyển ca sau)*.
✅ Kết ca chỉ tính đơn đã trả; đơn chưa trả carry tự nhiên, hiển thị số lượng.

## S5 — Cash-flow neutrality (reconcile chỉ đọc payment.till_session_id)

**curl** `GET /pos/till/sessions/{S1}/reconciliation`:
```
opening_float : 10000
cash_sales    : 1200   (= đúng ¥1200 payment cash gán vào S1)
expected_cash : 11200
```
Payment gap ¥800 (`till_session_id=NULL`) **KHÔNG** vào cash_sales của S1 → reconcile độc lập với đơn chưa gán. ✅ Không rủi ro dòng tiền.

---

## 🐞 Bug UI phát hiện & fix ngay (nhờ test này)

Callout ack tiền-giữ-riêng render **mỗi ký tự 1 dòng** (không đọc được): `@godxjp/ui` Alert là grid 2 cột (icon | 1fr); `AlertTitle`/`AlertDescription` có `col-start-2` nhưng `<label>` ack của tôi thì không → rơi vào cột icon ~16px. **Fix:** thêm `col-start-2` vào label (commit `fix(plan-044): gap-cash ack callout...`). Ảnh sau fix = `s3-gap-ack.png` (đã đọc bình thường).

## Bảng tổng hợp

| Scenario | Data (curl/DB) | UI (ảnh) | Kết quả |
|---|---|---|---|
| S1 gate | 409 NO_OPEN_SHIFT, 0 order | `s1-gate-redirect.png` | ✅ |
| S2 gap-preview | count=1, ¥800 cash | `s2-open-gap-panel.png` | ✅ |
| S3 claim + ack | NULL→S2, audit | `s3-gap-ack.png` | ✅ |
| S4 order-summary | 1 paid / 20 unpaid | `s4-close-order-summary.png` | ✅ |
| S5 reconcile | cash_sales=1200, gap excluded | — | ✅ |

*(Bổ sung: attribution theo phase open/closing/gap đã chứng minh ở `RESULT.md` — payment lúc closing → ca đang kết; gap → ca mới sau claim.)*

## Ghi chú
- Data test nhãn `E2E-*` trên `sjk` (vô hại, xoá được). Đã park ca seed open/closing của sjk để test sạch.
- Token admin test đã thu hồi. Reproduce: `capture.cjs` / `shot.cjs` (Playwright, `pos_api_mode=cloud`).
- Two-way sync workstation⇄Cloud (D2.2/D2.4) đã có Go+Pest test tự động; cần workstation chạy + pair pos-device để test LAN qua UI (ngoài phạm vi run này).
