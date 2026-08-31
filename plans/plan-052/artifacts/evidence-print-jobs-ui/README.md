# Evidence — Print jobs admin UI (plan-052 M2, #1166)

Ảnh chụp browser thật (SSO thật + backend docker :5400), 2026-07-28.
Bộ đầy đủ 15 ảnh nằm ở scratchpad của phiên làm việc; đây là 8 ảnh chủ chốt.

| File | Chứng minh |
|---|---|
| 01-list-all-statuses-and-both-confidence-badges | Danh sách đủ trạng thái; **2 badge confidence khác nhau trong cùng khung**: 印字確認済み (confirmed) vs 送信のみ（未確認）(sent_only) — P-33 |
| 02-filter-needs-attention | Lọc theo `needs_attention` + ô tổng quan đếm theo trạng thái |
| 04-detail-money-document-no-auto-retry | Chi tiết chứng từ tiền: giải thích rõ vì sao KHÔNG tự in lại |
| 05b-resolve-422-server-reason-required | Resolve thiếu reason → 422 hiển thị rõ |
| 06b-resolve-success-recorded | Resolve thành công, ghi actor |
| 07b-resolve-409-already-printed | Resolve job đã `printed` → 409 với thông báo dễ hiểu |
| 08-detail-kitchen-workstation-owned-auto-retry | Job bếp do workstation sở hữu queue — auto-retry được, KHÔNG có nút retry ở Cloud (§1b) |
| 09-list-locale-en | i18n (bộ đủ có cả vi) |
