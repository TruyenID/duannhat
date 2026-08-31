# Plan 050 — Tests

## Fixtures

- **Stripe**: bộ balance_transaction/payout JSON thật (redacted) — payment,
  refund (fee=0), dispute pair + reversal, payout list, unknown type,
  payout net âm.
- **PayPay**: file CSV mẫu THẬT (T1.0) — kỳ thường + 早期振込, dòng orphan,
  encoding gốc (Shift_JIS nếu đúng vậy), fee + fee_tax 10%.
- Mọi số bigint minor units; JPY (step 1) là mặc định, thêm 1 fixture
  currency mismatch (S-17).

## Suites

- `tests/Unit/Services/Settlement/SettlementRowAssemblyTest.php` — net
  assert (S-15), fee_tax per provider (S-16), signed amounts (S-11)
- `tests/Feature/Settlement/StripeBalanceTxnIngestTest.php` — M2 flow, kind
  mapping, dispute append-only (S-08), unknown type (S-13)
- `tests/Feature/Settlement/ReportImportTest.php` — idempotent file (S-01),
  idempotent row cross-file (S-02), all-or-nothing (S-03), connection lệch
  (S-04), orphan + re-match (S-05, S-19)
- `tests/Feature/Settlement/PayoutReconcileTest.php` — Σ khớp/mismatch
  (S-12), payout failed quay về pending (S-10), double-invoke (S-23)
- `tests/Feature/Settlement/AgingReportTest.php` — freeze clock ≥3 timezones
  (#1091, S-22), ngưỡng per-provider
- Contract: report chính thức không bao giờ trả estimate khi settlement đã
  có (G1)
- Guard: sửa settlement reconciled bị chặn (S-24) — mẫu settled-guard test
  plan-043/049
