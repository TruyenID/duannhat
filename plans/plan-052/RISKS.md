# Plan 052 — Rủi ro

| # | Rủi ro | Likelihood | Impact | Mức |
|---|---|---|---|---|
| PR1 | Double-print chứng từ tiền (2 bản gốc) | Trung bình | Cao (pháp lý インボイス + gian lận) | 🔴 |
| PR2 | Phá offline-first của đường workstation | Trung bình nếu ẩu | **Thảm hoạ** (quán không in được khi mất mạng) | 🔴 |
| PR3 | Firmware/protocol variance (CloudPRNT versions, SDP rev) | Cao | Trung bình | 🟠 |
| PR4 | Hai nguồn cấp số Bản in #N rẽ nhánh | Trung bình | Trung bình (audit sai) | 🟠 |
| PR5 | Ledger volume (mỗi bill 2-4 jobs × mọi quán) | Chắc chắn | Thấp-Trung | 🟡 |
| PR6 | Scope creep (label/KDS/template editor) | Cao | Tiến độ | 🟡 |

## 🔴 PR1 — Double-print chứng từ tiền

ACK-lost là trạng thái KHÔNG THỂ phân biệt "đã in" vs "chưa in" từ xa. Chốt:
retry matrix per kind (P-05) — chứng từ tiền không bao giờ auto-retry;
`needs_attention` bắt người quyết; mọi bản ≥2 mang 「Bản in #N」 nên kể cả
quyết sai cũng không tạo được 2 bản gốc giống hệt. Test khoá matrix.

## 🔴 PR2 — Offline-first workstation

Ledger là JOURNAL, không phải gate (P-08 arch test: critical path in local
không gọi Cloud). Deploy thứ tự backend-trước-workstation như mọi plan. Nếu
nghi ngờ ở bất kỳ điểm nào: đường cũ thắng, ledger nhận muộn.

## 🟠 PR3 — Protocol variance

CloudPRNT 1.x vs 2.x (HTTP vs MQTT), SDP theo firmware rev. M4 chỉ target
CloudPRNT HTTP + SDP HTTP (poll-based, phổ nhất); MQTT ngoài scope. Mỗi máy
thật pilot trước khi ghi vào docs hướng dẫn mua máy; matrix máy đã test
nuôi trong docs/guide.

## 🟠 PR4 — Một nguồn số N per payment

Quán có thể có receipt printer ws_lan HÔM NAY, mai đổi epos_http → nguồn N
đổi theo transport. Rule P-12: N theo transport hiện tại của máy receipt;
migration transport = sync số N hiện có (đọc max từ print_history) trước khi
flip. Test chuyển transport giữa chừng.

## 🟡 PR5 — Volume

Partition theo tháng không cần ngay; index (branch_id, status) + retention
payload P-17 là đủ cho quy mô hiện tại; đo lại ở M5.

## 🟡 PR6 — Scope

README out-of-scope + issue con nếu phát sinh. Label printer flow giữ
nguyên đường cũ cho tới khi có nhu cầu thật.
