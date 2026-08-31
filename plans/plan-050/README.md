---
plan: 050
title: Gateway settlement & payout reconciliation (phí gateway + đối soát tiền về)
slug: gateway-settlement-reconciliation
issue: 1155
status: partial
branch: ""
created: 2026-07-28
updated: 2026-08-17
parent: plan-048
---

# Plan 050 — Gateway settlement & payout reconciliation

Sub-ledger **quán ↔ công ty thanh toán** (Stripe / PayPay): phí gateway thật
per giao dịch, đối soát payout (tiền về tài khoản) 2 chiều, aging "tiền đang
treo ở gateway". Đây là quy trình chuẩn quốc tế (gross vs net settlement,
3-way reconciliation: sổ mình ↔ statement gateway ↔ bank) mà mọi merchant
platform đều phải có.

## Trạng thái 2026-08-17 — 14/21, phần còn lại chặn bởi PayPay

`status` chuyển `draft` → `partial` từ 2026-08-06 (#1977): nhãn `draft` nói plan
chưa được xây, trong khi đo lại trên `dev` thì backend đã chạy. Đo lại
2026-08-17 trên `origin/dev`, có mặt trong cây:

- **3 bảng** — `backend/database/migrations/omnify/2000_01_01_000102_create_gateway_payouts_table.php`,
  `…_000143_create_settlement_report_batches_table.php`, `…_000144_create_payment_settlements_table.php`
  (nguồn: `schemas/Backend/Payment/{GatewayPayout,SettlementReportBatch,PaymentSettlement}.yaml`
  — migration viết tay ban đầu của T1.1 đã được `1b918e42d` chuyển sang Omnify)
- **service** — `backend/app/Services/Payment/Settlement/`. Năm service lõi của
  plan-050: `SettlementAgingReportService`, `SettlementAlertService`,
  `SettlementFeeEstimator`, `SettlementReconciliationService`,
  `SettlementRowAssembler`, cộng thư mục `Stripe/` và
  `StripeWebhookSubscriptionAuditService` (T2.4). Thư mục nay còn 4 lớp **không
  thuộc plan-050**, đến từ việc khác — đừng đọc chúng thành phạm vi plan này:
  `ForeignSettlementMarker` (#2864), `SettlementAttributionMigrator` (#2893),
  `DuplicateConnectionConsolidator` (#3070), `UnresolvedOwnershipBackfill` (#2410).
- **lệnh** — `backend/app/Console/Commands/ReconcileSettlements.php`
  (`settlements:reconcile`, schedule daily ở `backend/routes/console.php:289`) và
  `AuditStripeWebhookSubscriptions.php` (`settlements:audit-webhooks`, T2.4 —
  read-only, KHÔNG có schedule vì nó gọi API Stripe mỗi lần chạy).
  `MarkForeignSettlements.php` cùng thư mục là của #2864, không phải plan-050.
- **7 route API** — `backend/routes/api/hq/settlements.php` (index · batches ·
  payouts · aging · export · batches/export · payouts/export)
- **màn admin-web** — T5.1, nay in-tree ở `web/admin/…` sau khi gộp monorepo; xem TASKS.md

Còn hở: M3 nguyên khối (T3.1–T3.4) chặn bởi **T1.0** — chưa có file 精算レポート
THẬT từ PayPay merchant panel, nên không viết importer được; cộng T4.4 (exit
criteria đối chiếu tay tháng đầu, cần connection thật + 1 tháng dữ liệu) và T4.5
(phần VẬN HÀNH của runbook インボイス phí PayPay — phần chữ đã viết, checklist per
cycle neo vào report đã import nên chờ T3.1). Tất cả đều chờ dữ liệu/vận hành,
không phải chờ code.

**T2.4 KHÔNG còn hở** (bản trước của mục này ghi sai): checklist webhook đã ship
dưới **#1978** ngày 2026-08-06 (PR #1982, `2c6ac9f8b`…`5dfec54ac`, đã là ancestor
của `origin/dev`) — lệnh `settlements:audit-webhooks` + service + test +
runbook. Lượt đồng bộ tracker #1977 chạy trước lúc #1978 đóng nên bỏ sót nó.

`branch` để rỗng: `feature/plan-050-gateway-settlement` **chưa từng tồn tại**
(đo lại 2026-08-17: `git branch -a --list '*plan-050*'` và
`git ls-remote origin 'refs/heads/*plan-050*'` đều rỗng). Code land qua các nhánh
`issue-*` (T5.1 qua #1157, T2.4 qua #1978), như mọi thứ khác trong repo này.

## Ranh giới (quan trọng nhất)

```
order_conditions               =  quán ↔ KHÁCH      (khách nợ bao nhiêu — hoá đơn)
order_payments (AR, #1151)     =  quán ↔ KHÁCH      (khách đã trả bao nhiêu, lúc nào)
payment_settlements (plan-050) =  quán ↔ GATEWAY    (gateway giữ phí bao nhiêu, net bao nhiêu)
gateway_payouts (plan-050)     =  GATEWAY → BANK    (tiền về tài khoản đợt nào, khớp không)
```

- **Phí gateway KHÔNG BAO GIỜ vào order** — doanh thu và thuế của đơn tính
  trên gross khách trả; phí là chi phí bán hàng (支払手数料) bên settlement.
- **Không có phí ẩn cho khách** — by construction: Σ adjustments = hoá đơn.
- Không GL (ADR #1151) — nhưng mapping kế toán sẵn: doanh thu = orders, thu
  hộ = payments, tiền về = payouts, chênh = phí + tiền treo.

## Nguyên tắc vàng

1. **Estimate ≠ sự thật**: fee_rate khai trên catalog chỉ để dashboard; số
   book vào sổ LUÔN từ gateway (Stripe balance transaction / PayPay 精算
   report). Không bao giờ tự nhân % rồi coi là phí thật.
2. **Thuế của phí lấy từ statement, không đoán**: phí thẻ JP = 非課税
   (chuyển nhượng nợ), phí PayPay = dịch vụ chịu thuế 10% — khác nhau per
   provider, per hợp đồng.
3. **Import idempotent tuyệt đối**: report chạy lại không double row.
4. **Mọi lệch phải kêu**: 2 chiều (mình có mà gateway không có / gateway có
   mà mình không có) đều alert, kiểu observation-report plan-048.

## Documents

- [DESIGN.md](DESIGN.md) — 3 lớp (estimate / phí thật / payout), schema 3
  bảng, data flow Stripe webhook + PayPay report import, đối soát 2 chiều
- [EDGE-CASES.md](EDGE-CASES.md) — S-01…S-24: refund/dispute lệch kỳ, payout
  hold/âm, import trùng, orphan rows, 早期振込…
- [RISKS.md](RISKS.md) — rủi ro xếp hạng + exit criteria
- [TASKS.md](TASKS.md) — M1–M5
- [TESTS.md](TESTS.md) — fixture per provider, invariant sums

## Phụ thuộc

- plan-048: provider-event inbox (nhận `payout.paid`), connection/snapshot
  trên `order_payments` — dùng lại nguyên, không sửa.
- plan-049: đã gỡ hoàn toàn ở #2041 (không còn phụ thuộc nào).
- #1123 dispute events: dispute fee ¥1,500 của Stripe về qua balance txn —
  nối vào cùng bảng settlement.
