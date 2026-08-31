# Plan 050 — Tasks

> Thứ tự M1→M2→M3→M4→M5. T1.0 là BLOCKER của M3 (lấy file thật trước khi
> viết importer — G2).

## M1 — Schema + estimate layer

- [ ] T1.0 **[BLOCKER-M3]** Lấy file 精算レポート/取引明細 THẬT từ PayPay merchant panel (sandbox + production nếu có) — xác nhận cột, encoding (Shift_JIS?), row key
- [x] T1.1 `PaymentSettlement`, `GatewayPayout`, `SettlementReportBatch` (+ UNIQUE constraints ở DB). Ban đầu land dưới dạng migration viết tay `2026_07_28_150000_create_gateway_settlement_tables.php` (#1155, `05c62ee6f`); **file đó không còn tồn tại** — `1b918e42d` ("the last eight business tables move into Omnify") chuyển cả ba bảng sang YAML. Vị trí thật hôm nay (đo 2026-08-17 trên `origin/dev`): `schemas/Backend/Payment/{PaymentSettlement,GatewayPayout,SettlementReportBatch}.yaml` → `backend/database/migrations/omnify/2000_01_01_{000144,000102,000143}_*`
- [x] T1.2 `fee_estimate` trên **connection option** `merchant_configuration` (options catalog không có metadata column) + stamp `estimated_fee_minor` lúc attempt succeeded qua `EloquentPaymentPersistence` (G1 contract test chặn settlement layer đọc estimate)
- [x] T1.3 Settled-guard: cấm sửa/xoá settlement `reconciled` (S-24) — model guard + `whileReleasingReconciled()` bypass duy nhất cho S-10

## M2 — Stripe path (API-driven)

- [x] T2.1 Fetch balance_transaction sau `payment_intent.succeeded` (inbox applicator, fail-open) → settlement row (kind=payment) — `StripeSettlementRecorder`
- [x] T2.2 Refund + dispute rows (refund fee=0 per statement S-07; dispute 2 rows + reversal append-only S-08, nối event #1123 dispute pipeline)
- [x] T2.3 `payout.paid` → gateway_payouts + list balance txns theo payout → gắn payout_id → verify Σ (S-12 mismatch, không auto-balance) + `payout.failed` release (S-10)
- [x] T2.4 Verify webhook events subscribed per connection (G6) — lệnh checklist. **Đã ship dưới #1978** (tách khỏi #1155 vì nửa code làm được ngay), merge vào `dev` qua PR #1982: `2c6ac9f8b` lệnh + service, `eed5e3a31` (khớp MỘT PHẦN họ event là `partial`, không phải `ok`), `885dd3193` docs, `5dfec54ac` docblock. Trong cây: `backend/app/Console/Commands/AuditStripeWebhookSubscriptions.php` (`settlements:audit-webhooks --connection= --json --strict`, read-only), `…/Services/Payment/Settlement/StripeWebhookSubscriptionAuditService.php`, danh sách event bắt buộc ở `backend/config/payments.php` (`payments.settlement.required_webhook_events.stripe`), test `backend/tests/Feature/Settlement/StripeWebhookSubscriptionAuditTest.php`, runbook `docs/guide/gateway-settlement.md`. **Còn phải chạy thật** trên connection Stripe production — việc đó thuộc T4.4, không phải phần code này.
- [x] T2.5 Unknown txn type → kind=manual + metadata raw (S-13)

## M3 — PayPay importer (report-driven)

- [ ] T3.1 `payments:import-settlement-report {file} --connection= --dry-run` — batch + file_hash idempotent (S-01), all-or-nothing (S-03), giữ raw file
- [ ] T3.2 Match merchant_payment_id → rows; orphan giữ vĩnh viễn + re-match tự động (S-05, S-19); connection lệch → orphan to (S-04)
- [ ] T3.3 Dòng 振込 → gateway_payouts (S-14 nhiều payout/tháng)
- [ ] T3.4 fee_tax từ cột report (PayPay 10%) — fixture cả 非課税/課税 (S-16)

## M4 — Reconcile + aging + alerts

- [x] T4.1 `settlements:reconcile [--connection=] [--dry-run]` — 2 chiều + orphan re-match (S-05/S-19), idempotent double-invoke (S-23), scheduler daily 06:30 operations_timezone
- [x] T4.2 Aging report per connection (pending_payout theo day-bucket, `SettlementAgingReportService`) + ngưỡng per-provider config (`payments.settlement.*`)
- [x] T4.3 Alerts qua notification platform: orphan-quá-hạn (debounce G4), payout mismatch, payment-treo, estimate drift (S-18)
- [ ] T4.4 **Exit criteria**: report tháng đầu khớp statement gateway 100% (đối chiếu tay, ghi bằng chứng vào plan)
- [ ] T4.5 Runbook ops (vào docs/guide M5): phí PayPay chịu JCT 10% → khấu trừ 仕入税額控除 cần lưu **hoá đơn インボイス của PayPay cho khoản phí** (phát hành trong merchant panel) theo kỳ; phí thẻ 非課税 không cần. Checklist lưu chứng từ per cycle cạnh settlement report. — *Phần CHỮ đã có* ở `docs/guide/gateway-settlement.md` §Ops runbook, nhưng cố ý gắn nhãn "**when M3 lands**": checklist per cycle neo vào report đã import, mà importer là T3.1 chưa có. Còn thiếu đúng phần vận hành đó, không phải phần mô tả.

## M5 — Surfaces

- [x] T5.1 admin-web: trang Settlements per connection (batches, payouts, aging, orphans) — land dưới **#1157** (không phải #1155). Đường dẫn lúc land là `admin-web/…` (thời submodule); sau khi gộp monorepo (`f9cd0156b`, #2306) nó nằm **in-tree** ở `web/admin/src/app/hq/[brandSlug]/settings/payments/settlements/` — đo 2026-08-17 trên `origin/dev`: 9 file (`page.tsx` + 7 `components/` + 1 `lib/settlement-view.ts`)
- [x] T5.2 Export CSV cho kế toán (settlements per kỳ, per kind)
- [x] T5.3 Distill → `docs/guide/gateway-settlement.md` (viết sớm cùng backend M1/M2/M4-core — cập nhật tiếp khi M3/M5 land)
