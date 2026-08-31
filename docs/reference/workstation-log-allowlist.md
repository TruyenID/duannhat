# Allowlist log máy trạm (#2901)

**Đây là NGUỒN CHÂN LÝ.** Hai đầu cùng đọc bảng dưới: máy trạm lọc theo nó
**trước khi gửi**, Cloud kiểm **lại** theo nó lúc nhận. Cloud không tin máy
trạm đã lọc đúng — hai lớp, cùng một luật.

## Luật đọc bảng

| Tình huống | Cloud làm gì |
|---|---|
| `message` **có** trong bảng | nhận dòng |
| `message` **không** có trong bảng | **bỏ dòng đó**, `rejected++`, cả lô vẫn **202** |
| attr **có** trong ô "Attr được phép" của đúng message ấy | giữ attr |
| attr **không** có | **bỏ attr đó**, dòng vẫn lưu |
| mức `debug` | **422 cả lô** — xem lý do bên dưới |

Ba hành vi khác nhau, và sự khác nhau là cố ý:

- Một `message` lạ chỉ nghĩa là **chưa ai khai nó**. Làm rơi cả lô vì một dòng
  chưa khai là mất bằng chứng của những dòng đã khai đi cùng lô.
- Một attr lạ cũng vậy, nhưng ở mức nhỏ hơn — dòng vẫn còn giá trị khi thiếu
  một trường.
- Một dòng `debug` tới nơi thì **bộ lọc ở nguồn đã hỏng**, nên mọi dòng khác
  trong lô cũng đáng ngờ. Đó là lý do nó 422 chứ không bị bỏ im lặng.

`rejected_count` của yêu cầu là **tín hiệu phải mở rộng bảng này**. Không có nó
thì người điều tra chỉ thấy một câu trả lời ngắn và tưởng quán chạy sạch.

## Vì sao ALLOWLIST chứ không blocklist

Đo trên cây Go ngày 2026-08-16: **305 thông điệp log khác nhau**, và các trường
đính kèm gồm `name` (348 chỗ), `note` (77), `customer_id` (20), `email` (17),
`address` (17), `phone` (15), `qr_token` (11), `customer_takeaway_name` (11).

Blocklist (bắt regex email/điện thoại, xoá các trường tên đã biết) **fail-open**:
thêm một trường PII mới ở bất kỳ đâu trong 305 chỗ đó thì nó tự chảy lên Cloud,
không ai biết. Repo này đã trả giá đúng kiểu ấy ở **#2220** — 11 cặp token còn
sống + 287 `qr_token` + PII khách vào git, và **revert không thu hồi được**.

Allowlist fail-closed: thêm một dòng log mới ⇒ mặc định **không** lên Cloud cho
tới khi có người khai vào đây.

Đánh đổi phải nói thẳng: người điều tra sẽ **thiếu** một số dòng và phải bổ
sung bảng rồi chờ lượt sau. Chấp nhận được — chiều ngược lại là rò PII khách
không thu hồi được.

## Vì sao đợt đầu KHÔNG có trường lỗi tự do (`err` / `error`)

Đây là quyết định đắt nhất của đợt này, nên ghi rõ để người sau đọc được.

Gần như mọi dòng `warn`/`error` của máy trạm đính kèm `"err", err`. Chuỗi đó là
**văn bản tự do do bên thứ ba sinh ra**: lỗi HTTP có thể mang nguyên thân phản
hồi (feed `customers` là một feed thật, và nó chở tên/điện thoại/email khách),
lỗi SQLite có thể nhắc lại giá trị cột vi phạm ràng buộc. Không có cách nào
khai trước rằng một chuỗi lỗi sẽ không chứa PII, nên đưa nó vào allowlist là
đưa một **lỗ blocklist** vào giữa một cơ chế allowlist.

Cái mất ít hơn vẻ ngoài: `sync push failed` với `id` · `entity` · `retryable`
đã trả lời được "dòng nào, thuộc loại gì, có thử lại không", và từ `id` là tra
được sang Cloud. Cái thiếu là *vì sao* — và đó là việc của một trường **có cấu
trúc** (mã lỗi, HTTP status) mà máy trạm hiện chưa phát. Khi nào phát thì khai
vào đây; đừng khai `err`.

## Đợt đầu — bốn nhóm

Chốt theo **nhu cầu điều tra đã có thật**: sync · alert · thu tiền mặt (Glory)
· in ấn. Bắt đầu hẹp, bổ sung dần.

`—` nghĩa là thông điệp đó **không mang attr nào được phép** (chính thông điệp
+ dấu thời gian đã là dữ liệu).

### Nhóm 1 — đồng bộ (sync)

| Message | Attr được phép |
|---|---|
| `sync engine started` | — |
| `sync engine stopped` | — |
| `sync puller started` | — |
| `sync puller stopped` | — |
| `sync poke connected` | — |
| `sync poke disconnected — pull ticks unaffected, reconnecting with backoff` | — |
| `sync manifest available — manifest-driven pull active` | — |
| `sync manifest unavailable — falling back to legacy full pull (old Cloud?)` | — |
| `sync push failed` | `id` · `entity` · `retryable` |
| `sync row dead-lettered` | `id` · `reason` |
| `sync throttled — backing off` | `retry_after` · `cooldown_until` |
| `sync: no handler` | `key` · `entity_id` |
| `sync: invalid payload` | `key` |
| `sync: non-retryable failure` | `key` · `entity_id` |
| `sync_queue purged` | `rows` |
| `device token cleared after cloud 401 — sync stopped, workstation must re-pair` | — |
| `cascade dead-lettered order children` | `order_id` · `rows` |
| `cascade dead-lettered till children` | `session_id` · `rows` |
| `upsert order failed` | `order_id` |
| `pull cursor was ahead of Cloud's clock — healing` | `key` · `was` · `now` · `ahead_by` |
| `heal cursor failed` | `key` |
| `customer_orders cursor stalled on a full page — stepping past it; rows sharing this second may be skipped` | `cursor` · `stepped_to` · `rows` · `limit` |
| `bulk order pull — auto-print suppressed (likely Cloud re-seed/backfill)` | `firing` · `tick` · `max` |
| `stamp feed version` | `feed` |
| `stamp manifest version` | — |
| `sync_pull menu_catalog` | — |
| `sync_pull menu_schedules` | — |
| `sync_pull promotions` | — |
| `sync_pull coupons` | — |
| `sync_pull customers` | — |
| `sync_pull staff` | — |
| `sync_pull printers` | — |
| `sync_pull peripheral_devices` | — |
| `sync_pull payment_methods` | — |
| `sync_pull tender_types` | — |
| `sync_pull tender_categories` | — |
| `sync_pull denominations` | — |
| `sync_pull effective_payment_options` | — |
| `sync_pull till` | — |
| `sync_pull till_sessions` | — |

> `sync_pull <feed>` là thông điệp **ghép chuỗi** trong Go
> (`slog.Warn("sync_pull "+name, ...)`). Allowlist là khớp **nguyên văn**, nên
> mỗi feed phải có một dòng riêng ở đây — không có ký tự đại diện, và đó là cố
> ý: một mẫu `sync_pull *` sẽ tự nhận cả feed nào đó thêm sau này mà không ai
> đọc lại danh sách.

### Nhóm 2 — cảnh báo (alert)

| Message | Attr được phép |
|---|---|
| `alert raised` | `kind` · `subject` · `severity` · `audience` |
| `alerts purged (closed rows past retention)` | `rows` |

> `subject` an toàn vì mọi chỗ gọi `Raise()` đều truyền **định danh máy**: id
> đơn, mã giao dịch Glory, `receipt_printer`, `pairing`, `build`, `ws_client`.
> Đã kiểm toàn bộ chỗ gọi ngày 2026-08-16. Nếu có ngày ai đó truyền tên khách
> vào `subject`, dòng này phải được xét lại — nên nếu bạn đang thêm một
> `AlertKind`, hãy đọc lại câu này.

### Nhóm 3 — thu tiền mặt (釣銭機 / Glory)

| Message | Attr được phép |
|---|---|
| `cash drawer: opened for cash payment` | `order` |
| `cash drawer: kick failed` | `order` · `reason` |
| `cash drawer: could not read payment methods` | `order` |
| `phục hồi lượt thu tiền mặt sau restart` | `session` · `order` · `glory_txn` · `payment` |
| `không ghi được phiên thu tiền mặt — lượt này sẽ không phục hồi được nếu máy trạm tắt` | `session` · `order` |
| `không đóng được phiên thu tiền mặt` | `session` |
| `không ghi được sổ máy thu tiền` | `session` |
| `không ghi được sự cố máy thu tiền` | `title` |
| `không đóng được sự cố máy thu tiền` | `title` |
| `không đóng dấu được mã giao dịch 釣銭機 — lượt này sẽ không hỏi lại được máy nếu máy trạm tắt` | `session` · `glory_txn` |
| `đã đối soát phiên thu tiền mặt còn dở từ lần chạy trước` | `count` |
| `đối soát phiên 釣銭機 thất bại (không chặn khởi động)` | — |
| `enqueue payment.create (cash_changer)` | `payment` |

> `title` là từ vựng **nguyên văn của adapter Glory** (`empty`,
> `billRejectFull`, `needPullOut`, `notReady`, `forbidden`…), không phải văn
> bản tự do — xem `CashDeviceErrorEvent.error_title`.

### Nhóm 4 — in ấn

| Message | Attr được phép |
|---|---|
| `printer dispatcher: unclassified printer_group, defaulting to kitchen_printer` | `printer_group` |
| `printer dispatcher: no device configured for role, not rerouting` | `printer_group` · `role` |
| `printer dispatcher: receipt_printer not configured, falling back to kitchen_printer` | — |
| `device connected` | `device` |
| `connect device failed` | `device` |
| `scan device` | — |
| `auto-print payment receipt failed` | `order` |
| `table-paid slip: order not found locally` | `order` |
| `table-paid slip: no printer configured` | `order` |
| `table-paid slip: printer connect failed` | `order` |
| `table-paid slip: print failed` | `order` |
| `kitchen-ticket force-pull failed` | `order_id` |
| `reprintKitchenForOrder: print failed` | `printer_group` |
| `print counts failed (non-fatal)` | `kind` |
| `print: tax row omitted — the order carries no tax fact to print` | `kind` · `order_id` · `order_code` |
| `print: order has no positive total — the slip prints 0, not a recomputed figure` | `order_id` · `order_code` |
| `print journal reserve failed (non-fatal)` | `kind` · `order` · `payment` |
| `print journal confirm failed (non-fatal)` | `job` · `kind` |
| `print reservation sweep failed (non-fatal)` | — |
| `print reservations abandoned by a previous run` | `rows` · `action` |
| `refresh before print failed (non-fatal)` | `order` · `status` |

> `device` là **tên máy in** do quán đặt trong cấu hình (`receipt_printer`,
> `TM-T88VI`…), không phải dữ liệu khách.

## Thêm một dòng vào bảng này

1. Kiểm từng attr: nó là **định danh máy hay số đếm**, hay là văn bản do người
   /bên thứ ba nhập? Văn bản tự do thì **không** khai.
2. Sửa bảng ở trên.
3. Sửa `backend/config/workstation_log_allowlist.php` cho khớp (xem mục kế).
4. Sửa `logAllowRules` trong `workstation/internal/service/log_allowlist.go`.

Bước 3 và 4 đều **có máy cưỡng chế**, và cả hai đều đối chiếu với **file này**:

| Rào | Ở đâu | Bắt gì |
|---|---|---|
| `WorkstationLogAllowlistMatchesDocTest` | backend | doc ↔ config PHP |
| `TestLogAllowlist_MatchesTheSharedContractDocument` | máy trạm | doc ↔ bảng Go, HAI CHIỀU |

Hai rào cộng lại ràng Go ↔ PHP **bắc cầu qua file này** — và đó là lý do file
này phải là MỘT file. Bản đầu của #2901 được viết bởi hai phiên song song, mỗi
bên tự tạo một `docs/reference/workstation-log-allowlist.md` riêng, mỗi bên có
một rào so chính mình với bản khai của chính mình. **Cả hai đều xanh**, trong
khi máy trạm phát 7 thông điệp mà Cloud vứt im lặng — mất trọn nhóm **in ấn** và
nhóm **thu tiền mặt** khỏi tay người điều tra. Đúng bẫy #2860: hai bản sao của
một từ vựng, không có chỗ nào so hai bản với nhau.

## Cloud đọc bảng này từ đâu

Bảng trên là **bản khai**. Bản Cloud thật sự chạy là
`backend/config/workstation_log_allowlist.php`, và
`backend/tests/Feature/Architecture/WorkstationLogAllowlistMatchesDocTest.php`
**phân tích chính file markdown này** rồi so từng message + từng attr với config
— lệch một ký tự là đỏ.

Vì sao không đọc thẳng file markdown lúc chạy: đường deploy
(`.github/workflows/deploy-xserver.yml`) `rsync` **chỉ thư mục `backend/`** lên
máy chủ, nên `docs/` ở gốc repo **không tồn tại trên production**. Một allowlist
đọc từ file không có ⇒ rỗng ⇒ Cloud từ chối mọi dòng, im lặng, và tính năng
chết đúng lúc cần nhất. Vẫn một nguồn chân lý — chỉ là nguồn được **cưỡng chế
bằng test** thay vì bằng `file_get_contents()` trên một đường dẫn không tới nơi.
