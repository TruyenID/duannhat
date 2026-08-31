# Chính sách khi sửa code — TempoFast

Đọc cùng `CLAUDE.md` ở gốc và CLAUDE.md/AGENTS.md của thư mục bạn đang chạm. Đây là những
điểm chết người đã trả giá thật.

## Thứ tự lệnh bắt buộc

- **Artisan chạm DB** → `docker compose exec app php artisan …`. Native từ host trỏ vào
  MySQL của Herd, một database khác, và im lặng không làm gì.
- **Backend test** → native: `cd backend && php -d memory_limit=-1 vendor/bin/pest --compact`
  (xem `test-policy.md` để biết phạm vi).
- **Omnify codegen** → `npm install` **trước** `npm run omnify:gen`, không phải tuỳ chọn.
  `node_modules` lệch lock nghĩa là chạy generator **cũ hơn** cái repo pin, và generator cũ
  **ghi đè code đã đúng thành sai** — im lặng, lẫn trong đống file generated. Sau regen:
  đọc kỹ diff, **luôn revert** `backend/docs/contributing/omnify-architecture.md` (nó nhúng
  đường dẫn tuyệt đối của máy người chạy), và **kiểm có sinh migration hay không** — không
  có file mới trong `backend/database/migrations/omnify/` nghĩa là generator đã nuốt, không
  phải "không cần đổi gì".

## Thời gian nghiệp vụ

Mọi "hôm nay" / biên ca / khung giờ menu / hạn dùng / báo cáo theo ngày phải đi qua
**`BusinessClock::forBranch($branchId)`**. Shop ở Việt Nam (UTC+7) và Nhật (UTC+9) trên một
backend UTC — "hôm nay" không toàn cục. Cấm: `APP_TIMEZONE=Asia/Tokyo`, `now()->toDateString()`,
`Carbon::today()` không tham số, `whereDate(..., now())` trần, `CURDATE()`/`CURRENT_DATE`,
đọc `SetTimezone::ATTRIBUTE` trong business logic. Test phụ thuộc thời gian phải đóng băng
đồng hồ và assert ở ≥3 timezone.

## Tiền

Snapshot thuế/giá trên order line là **bất biến** — sửa rate không được ghi lại lịch sử.
Làm tròn **một lần cho mỗi nhóm thuế suất** (half-up, theo bước của đồng tiền) rồi chia về
từng dòng theo largest remainder. Money attribution theo ca đi qua
`order_payments.till_session_id`.

## Local config vs production

Cấm trong file được commit: `"true"` ghi cứng cho cờ bypass/debug; nới guard
`NODE_ENV`/`APP_ENV` thành env mà production có thể đặt (đã ship 3 lần trong một PR rồi bị
revert); file pin host/URL/credential của một máy. Cờ nguy hiểm ship dạng `${FLAG:-false}`.

## web/admin

Mọi HTTP call phải qua `apiFetch` — mở rộng nó, đừng fork. Mọi mutation phải có
`toast.success` khi thành công **và** `toast.error` khi thất bại; mutation im lặng là
review-block.

## Quy ước chung

- **Không emoji** trong UI/code/output — dùng icon `lucide-react`.
- Commit theo Conventional Commits: `fix(scope): …`.
- **Monorepo — không còn submodule** (#2306): mọi app sửa và commit THẲNG vào repo này.
  Thay đổi cắt ngang (schema → regen backend → regen client) gói trong MỘT commit.
