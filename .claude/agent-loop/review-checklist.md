# Checklist review — TempoFast

Đi hết danh sách này ngoài phần nền chung của skill. Mỗi mục ở đây tương ứng một chỗ đã
cháy thật trong repo.

## Tiền — chặn nếu sai

- `order_payments`, refund / 赤伝 (適格返還請求書), till session, thuế: snapshot **bất biến**
  có bị ghi lại không?
- Làm tròn có đúng "**một lần cho mỗi nhóm thuế suất**" (half-up, bước theo đồng tiền) rồi
  chia về dòng theo largest remainder?
- Đảo tiền: có idempotent theo từng reversal row không? Webhook giao lại là chuyện thường —
  thiếu khoá theo id sự kiện là hoàn tiền hai lần.
- Ca/精算: money attribution còn đi qua `order_payments.till_session_id` không?

## Thời gian nghiệp vụ — chặn nếu sai

Mọi "hôm nay"/biên ca/khung giờ/hạn dùng phải qua `BusinessClock::forBranch($branchId)`.
Thấy `now()->toDateString()`, `Carbon::today()` không tham số, `whereDate(...)` trần,
`CURDATE()`/`CURRENT_DATE`/`CURRENT_TIME`, hay đọc `SetTimezone::ATTRIBUTE` trong business
logic → `issue (blocking)`.

Lý do đây là mục số hai: `TillSession.business_date` từng lấy ngày UTC, nên **mọi ca mở
00:00–09:00 JST** (chín tiếng mỗi ngày) bị đóng dấu sang ngày hôm trước và kéo lệch cả
Z-report / 精算 / doanh thu ngày.

## Local config vs production — chặn nếu sai

- Cờ bypass/debug ghi cứng `"true"` trong file được commit.
- Guard `NODE_ENV`/`APP_ENV` bị nới thành env mà production có thể đặt (ví dụ một
  `NEXT_PUBLIC_*_BYPASS`) — đúng downgrade này đã ship 3 lần trong một PR rồi bị revert.
- File commit pin host/URL/credential của một máy (một `public/__config.json` mang URL
  trycloudflare từng được serve công khai).
- WIP lẫn vào commit không liên quan.

## Frontend

- **web/admin**: mọi HTTP qua `apiFetch`; mọi mutation có `toast.success` **và**
  `toast.error`. Thiếu là chặn.
- **Không emoji** trong UI/code/output — phải là icon `lucide-react`.

## Schema / codegen

- Sửa schema mà **không có migration mới** trong `backend/database/migrations/omnify/` →
  dấu hiệu generator đã nuốt (lỗi đã biết: thêm index vào `options.indexes` của entity đã
  tồn tại thì bị nuốt nhưng vẫn ghi là đã áp dụng). Chặn và nói rõ.
- `Association` trên entity `kind: pivot` sinh `withPivot` sai tên cột → mọi query qua
  relation đó chết. Relation phải được dựng lại ở model editable, không gọi `parent::`.
- YAML phải dùng `type: Int`, không phải `type: Integer` (Integer emit ra `string` trong TS).

## Quy trình

- Tiêu đề PR theo Conventional Commits.
- Thân PR có ghi **lệnh test thật đã chạy** và kết quả thật, không phải "đã test".
