# Plan 050 — Design

## 1. Ba lớp

```
L1 ESTIMATE  (lúc thanh toán — dashboard only, không bao giờ book)
   payment_gateway_options.metadata.fee_estimate {percent, fixed_minor, tax_mode}
   → payment_attempts.estimated_fee_minor (nullable, denorm)

L2 PHÍ THẬT per giao dịch  (authoritative, từ gateway)
   Stripe: webhook charge.succeeded → fetch balance_transaction (fee, fee_details, net)
   PayPay: import 精算/取引明細 CSV theo chu kỳ, match merchant_payment_id
   → payment_settlements rows

L3 PAYOUT  (tiền về tài khoản)
   Stripe: payout.paid webhook + GET /balance_transactions?payout=po_xxx
   PayPay: 振込 line trong report (chu kỳ tháng / 早期振込)
   → gateway_payouts + gắn payout_id vào settlements
   Invariant: Σ net(settlements của payout) = payout.net
```

## 2. Schema (3 bảng)

### `payment_settlements` — một row per money-event phía gateway

Không chỉ payment: refund, dispute, dispute-fee, phí account-level đều là
row (mẫu Stripe balance transaction — mọi thứ là typed transaction).

```
├── id                       uuid pk
├── connection_id            fk payment_gateway_connections
├── provider                 stripe | paypay
├── kind                     payment | refund | dispute_withdrawal |
│                            dispute_reversal | dispute_fee | account_fee | manual
├── order_payment_id         fk nullable   -- null cho account_fee/manual
├── payment_attempt_id       fk nullable
├── gross_minor              bigint signed  -- âm cho refund/dispute
├── fee_minor                bigint signed  -- phí gateway (âm = hoàn phí)
├── fee_tax_minor            bigint         -- thuế của phí (PayPay 10%, thẻ JP 0)
├── net_minor                bigint signed  -- gross − fee − fee_tax (assert)
├── currency                 char(3)
├── source                   api | report | manual
├── external_ref             string unique-per-provider  -- balance_txn id / report row key
├── report_batch_id          fk nullable settlement_report_batches
├── payout_id                fk nullable gateway_payouts
├── provider_settled_at      datetime      -- theo lịch của GATEWAY (#1091: không phải business time của quán)
├── status                   pending_payout | reconciled | orphan | mismatch
└── created_at / updated_at
UNIQUE (provider, external_ref)            -- idempotency import
```

### `gateway_payouts` — một row per lần gateway chuyển tiền

```
├── id / connection_id / provider
├── external_payout_id       string unique-per-provider
├── expected_arrival_date    date nullable
├── paid_at                  datetime nullable
├── gross_minor / fee_minor / net_minor / currency
├── status                   pending | in_transit | paid | failed | hold
├── reconciled_at            datetime nullable  -- set khi Σ settlements khớp
├── bank_ref                 string nullable    -- đối chiếu sao kê (giai đoạn sau)
└── metadata json
```

### `settlement_report_batches` — audit mỗi lần import file

```
├── id / connection_id / provider
├── cycle_label              "2026-07" | "2026-07-early-1"
├── file_hash                sha256 UNIQUE      -- import trùng file = no-op
├── row_count / matched_count / orphan_count
├── imported_by_id / imported_at
└── status                   imported | partially_matched | failed
```

## 3. Data flow

### Stripe (API-driven, gần realtime)

1. `charge.succeeded` / `payment_intent.succeeded` (inbox plan-048 có sẵn)
   → job fetch `balance_transaction` → settlement row `kind=payment`,
   `source=api`, status `pending_payout`.
2. `charge.refunded` → balance txn của refund → row `kind=refund` (gross âm;
   fee theo statement — Stripe không hoàn phí gốc, row refund fee=0).
3. Dispute (#1123 đã có event): `charge.dispute.created` → row
   `dispute_withdrawal` + `dispute_fee`; WON → `dispute_reversal`.
4. `payout.paid` → tạo `gateway_payouts` → list balance txns theo payout →
   gắn `payout_id` cho từng settlement → verify Σ → `reconciled` hoặc
   `mismatch` + alert.

### PayPay (report-driven, theo chu kỳ)

1. Ops tải 精算レポート/取引明細 CSV từ merchant panel (M3 xác nhận format
   thật — chưa public spec ổn định; importer viết theo file mẫu của
   merchant).
2. `payments:import-settlement-report {file} --connection= --dry-run` →
   batch row; mỗi dòng match `merchant_payment_id` → settlement row
   `source=report`.
3. Dòng 振込 trong report → `gateway_payouts` + gắn payout_id cùng lúc.
4. Không match được → row `orphan` + alert (KHÔNG drop).

### Đối soát 2 chiều (lệnh + scheduler)

```
php artisan settlements:reconcile [--connection=] [--dry-run]
```

- Chiều A (mình → gateway): `order_payments` succeeded quá N ngày
  (per-provider config) chưa có settlement row → alert "gateway chưa báo
  tiền này".
- Chiều B (gateway → mình): settlement `orphan` / payout `mismatch` → alert.
- Aging report: tiền theo trạng thái `pending_payout` (đang treo ở gateway)
  per connection — manager thấy "còn bao nhiêu chưa về".
- Alert đi qua notification platform (plan-008/012) như dispute #1123.

## 4. Estimate layer (L1)

- `fee_estimate` khai trên `payment_gateway_options` (per option — thẻ 3.6%,
  PayPay theo hợp đồng); stamp `estimated_fee_minor` lúc payment succeeded.
- Chỉ hiện ở dashboard dạng "ước tính"; mọi report chính thức đọc
  settlements. Khi settlement về, hiện luôn chênh estimate↔thật (drift =
  hợp đồng đổi rate mà catalog chưa update → nhắc ops).

## 5. Business time

`provider_settled_at`, `expected_arrival_date` là **lịch của gateway**
(JST với Stripe JP/PayPay) — KHÔNG phải business time của quán (#1091).
Lưu nguyên giá trị gateway đưa + timestamp UTC; report settlement group
theo lịch gateway, không dùng `BusinessClock` của branch.

## 6. Out of scope

- Đối chiếu sao kê ngân hàng (bank leg, camt.053/CSV bank) — giai đoạn sau,
  `bank_ref` để sẵn chỗ.
- GL/kết chuyển kế toán (ADR #1151).
- Tự động tải report PayPay (nếu sau này có Partner API thì thay importer,
  schema không đổi).
- Phí platform delivery (UberEats…) — cùng mẫu, làm khi có kênh.
