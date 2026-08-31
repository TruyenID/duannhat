# Plan 050 — Rủi ro

| # | Rủi ro | Likelihood | Impact | Mức |
|---|---|---|---|---|
| G1 | Book estimate làm sự thật → báo cáo lãi sai | Trung bình | Cao | 🔴 |
| G2 | PayPay report format không như giả định | **Cao** | Trung bình | 🔴 |
| G3 | Import không idempotent → double/thiếu row | Trung bình | Cao | 🟠 |
| G4 | Alert noise → ops bỏ qua alert thật | Cao | Trung bình | 🟠 |
| G5 | Scope creep sang bank-leg/GL | Cao | Tiến độ | 🟡 |
| G6 | Webhook payout thiếu (Stripe event chưa bật) | Thấp | Trung bình | 🟡 |

## 🔴 G1 — Estimate bị dùng làm sự thật

**Cơ chế hỏng.** Dashboard hiện "phí ước tính" đẹp → ai đó lấy số đó làm
báo cáo lãi/hoà giải thuế → hợp đồng rate đổi/campaign phí 0% của PayPay →
sai hàng loạt, phát hiện lúc khai thuế.

**Mitigation.** Estimate tách cột riêng, label "ước tính" ở MỌI surface; API
report chính thức CHỈ đọc settlements; test contract: endpoint report không
trả estimated khi settlement đã có. Exit criteria M4: report tháng đầu khớp
statement gateway 100% (đối chiếu tay 1 lần).

## 🔴 G2 — PayPay report format

**Cơ chế hỏng.** Chưa có spec public ổn định cho 精算 CSV; viết importer
theo giả định → file thật khác cột/encoding (Shift_JIS!) → M3 đổ.

**Mitigation.** M1 chặn: lấy FILE THẬT từ merchant panel sandbox/production
TRƯỚC khi viết importer (task T1.0 riêng, blocker tường minh); importer
validate header + all-or-nothing per file (S-03); giữ raw file (hash + lưu
trữ) để replay khi sửa parser. Nếu format per-merchant khác nhau → parser
per version, chọn theo header.

## 🟠 G3 — Idempotency import

UNIQUE (provider, external_ref) ở DB (không chỉ app-level), file_hash
unique, S-01/S-02 test bắt buộc, double-invoke reconcile (S-23). Bài học
từ inbox plan-048: idempotency phải nằm ở constraint, retry là mặc định.

## 🟠 G4 — Alert noise

Orphan do payment offline sync muộn (S-19) là noise tự nhiên → alert orphan
có debounce (chỉ kêu khi orphan > N ngày), re-match tự động trước khi kêu.
Aging alert theo ngưỡng per-provider (Stripe 7 ngày, PayPay theo chu kỳ +
buffer) — cấu hình, không hardcode. Mọi alert phải actionable: kèm
connection, số tiền, lệnh xử lý gợi ý.

## 🟡 G5 — Scope creep

Bank-leg (sao kê), GL export, phí UberEats: đều "cùng mẫu làm luôn đi" —
KHÔNG. Ranh giới trong README; issue con nếu cần.

## 🟡 G6 — Stripe events chưa bật

`payout.paid`/`charge.refunded` phải có trong webhook endpoint config của
connection — checklist M2 + lệnh verify events đã subscribe; thiếu event →
đối soát chiều A tự phát hiện (payment treo pending_payout quá hạn) nên
không mất tiền, chỉ chậm.
