# Dine-in Print — Phần customer-web (repo này)

> **Bối cảnh (đã xác nhận):** POS/workstation **đã in kitchen ticket + receipt** rồi. In là **fire-and-forget** (broadcast → in ngay, không hàng đợi). Việc cần làm thực chất chỉ là **thêm phiếu hold** ở phía POS. Repo customer-web **không tham gia việc in** — nó chỉ tạo/append order và thanh toán, POS tự phản ứng.

## customer-web cần gì? → Rất ít, và đã xong.

Vì POS in vé khi order tới, thứ duy nhất customer-web phải đảm bảo là **không tạo order/append trùng** (trùng = POS in trùng vé). Đó là 2 thay đổi đã implement:

### ✅ A1 — Idempotency-Key trên POST confirm/append  (ĐÃ LÀM)
[confirm/page.tsx](app/[locale]/dine-in/[shop]/table/[qrToken]/confirm/page.tsx) — `handleSubmit`:
- `idempotencyKeyRef` (crypto.randomUUID) gửi header `Idempotency-Key`; reset theo `itemsSignature`.
- Retry/double-tap → cùng key → BE dedupe → **POS không in trùng kitchen/hold**. Mỗi lần "thêm món" = key mới = lượt in mới (đúng "chỉ in món thêm").
- Mirror pattern `components/checkout-page.tsx`.

### ✅ A2 — Xử lý 422 order đã closed/voided  (ĐÃ LÀM)
- Append vào order đã thanh toán/đóng → hiện `err.body.message` của BE (fallback thân thiện) rồi điều hướng về trang bàn. Ref: ISSUES.md #2.

### ✅ Microcopy "Món đã được gửi tới bếp" trên success modal (ĐÃ LÀM)

### ✅ A3 — Chặn "Xác nhận" khi POS giữ edit-lock  (ĐÃ LÀM)
[confirm/page.tsx](app/[locale]/dine-in/[shop]/table/[qrToken]/confirm/page.tsx) subscribe `useTableSessionRealtime` (sessionId từ localStorage `dine_in_session_{qrToken}`). Khi `editingByStaff` (POS gọi `order.editing-started`) → hiện banner `staffEditingBanner` + disable nút "Xác nhận" (không race đơn với staff). Không cần i18n key mới.

### ❌ A4 (nút "Hoàn tác"/cancel-window) — ĐÃ BỎ
Workstation in ngay khi order sync/nhận (fire-and-forget), không có cửa sổ để hủy → nút Hoàn tác vô nghĩa. Đã revert toàn bộ (không còn dead code).

## KHÔNG cần ở customer-web
- Không render/gửi gì cho việc in.
- Không đánh dấu "món thêm" — mỗi POST đã chỉ chứa món mới (cart clear sau mỗi lần).
- Không đụng luồng payment.

## Trạng thái
A1 + A2 + A3 + microcopy: **done**, lint sạch + tsc pass. Customer-web **không còn việc gì** cho feature print. Xem [PLAN-DINE-IN-PRINT-WORKSTATION.md](PLAN-DINE-IN-PRINT-WORKSTATION.md) cho phần hold ticket (repo workstation-app).
