---
title: Workstation Cloud API — feed pull-DOWN và nhóm endpoint
category: reference
tags: [workstation, sync, api, cloud]
summary: "Nhóm endpoint /api/v1/workstation/*, cách ĐẾM tại chỗ thay vì tin số chép lại, pairing không nằm trong namespace này, và các endpoint chưa bao giờ được cài đặt."
related:
  - workstation-sync-recovery
---

# Workstation Cloud API — feed pull-DOWN và nhóm endpoint

Tách khỏi `CLAUDE.md` (#2303) vì chỉ cần khi làm đúng mảng workstation, trong khi
`CLAUDE.md` nạp ở mọi phiên và mọi agent con. Nội dung dưới đây giữ NGUYÊN VĂN.

**Cloud API** (`/api/v1/workstation/*`) — nguồn chân lý là
`backend/routes/api/workstation.php`; **đếm tại chỗ, đừng tin con số chép lại**:

```sh
grep -cE "Route::(get|post|put|patch|delete)\(" backend/routes/api/workstation.php   # 65, đo 2026-08-07
```

Bảng dưới chỉ liệt kê NHÓM, cố ý không liệt kê từng endpoint: bảng endpoint trong
file luôn-nạp không bao giờ theo kịp route file — bảng cũ ở đây liệt 11 endpoint
mà vẫn tự nhận là đầy đủ, và con số tổng đi kèm nó (61) đã lệch mất 4 route
trước khi có ai nhận ra (#2029).

| Nhóm | Nội dung |
|---|---|
| Catalog pull-DOWN | `menu`, `menu/handy`, `menu-catalog`, `branch`, `lots`, `sync-manifest` (#1175: conditional GET, 304 + version map per feed) |
| Replica pull-DOWN | `payment-methods`, `customers`, `menu-schedules`, `peripheral-devices` (CRUD), `printers`, `print-templates` + `/{kind}/versions/{version}`, `staff`, `coupons`, `menu-promotions`, `effective-payment-options` + `/matrix` |
| Orders sync-UP | `orders` (GET/POST), `orders/{id}`, `orders/replay-offline` (#1097 signed), item `status`/`refund` |
| Order lifecycle | 15 hành động dưới `orders/{id}/` (init · update · void · confirm · checkout · items · coupon · merge-table · refund…) — replay LAN-offline, idempotent trên id do workstation cấp |
| Payments | `payments`, `payments/{id}/status`, `payments/{payment}/{confirm,fail}`, `payments/{payment}/attribution` (plan-044 R2 endpoint D) |
| Till / ca thu ngân | `till`, `till-sessions/active`, `till-denominations`, `till-tender-{categories,types}`, `till/sessions`, `.../cash-events`, `.../close`, `.../abandon` |
| Sổ quan sát sync-UP | `alerts` (#1806 S3 / #2695 — ảnh chụp alert đang mở), `cash-device-transactions` (#2878 — lượt thu ở máy 釣銭機, gồm cả lượt HỎNG), `cash-device-inventory` (#2879 — 在高 tại ranh ca), `cash-device-errors` (#2882 — sự cố có dấu thời gian), `money-overwrites` (#2885 — bằng chứng lệch tiền) |
| Kéo log theo yêu cầu | `log-requests` (GET — yêu cầu treo của CHÍNH thiết bị gọi; RỖNG là ca thường), `log-records` (POST — lô đã lọc theo allowlist) — #2901 |
| Khác | `print-jobs` (plan-052 T1.2 — chỉ GHI NHẬN, hàng đợi in do workstation sở hữu), `tables/{table}/status`, `keys/rotate` (#1093), `self-revoke` |

**Nhóm "sổ quan sát" khác nhóm sync-UP nghiệp vụ ở MỘT điểm quyết định**: nó
**không đi qua `sync_queue`**. Hàng đợi mang các thao tác phải tới nơi theo thứ
tự và có dead-letter; hai endpoint này mang sự kiện đã xảy ra, idempotent ở đầu
Cloud, và chậm vài phút không hỏng gì — nhưng chiếm chỗ của một `payment.create`
thì có. Cả hai **fail-open**: hỏng ở đây không được chặn vòng đồng bộ.

Ba endpoint `cash-device-*` idempotent theo khoá TỰ NHIÊN, không theo `id`:

| Endpoint | Khoá | Ghi chú |
|---|---|---|
| `cash-device-transactions` | `(peripheral_device_id, glory_transaction_id)` | trọng tài khi gửi lại là `machine_seq_no` **do adapter phát** — không phải đồng hồ máy trạm, vốn trôi sau nhiều ngày offline |
| `cash-device-inventory` | `(peripheral_device_id, till_session_id, count_phase)` | Cloud **tự cộng** `total_minor` từ `denominations` trừ `uncertain_denominations`; KHÔNG nhận tổng từ thiết bị |
| `cash-device-errors` | `(peripheral_device_id, error_title, occurred_at)` | MỘT LẦN XẢY RA = MỘT HÀNG; `cleared_at` tới ở lượt đẩy sau và ĐÓNG sự cố |

Lô `max:50` ở cả hai đầu: vượt ngưỡng thì Cloud trả 422 và **cả lô rơi**.

Ba endpoint này **không đi qua `sync_queue`** — xem đoạn trên. Toàn bộ ngữ nghĩa
sổ + phép đối soát ba chân: [`guide/cash-device-observation.md`](../guide/cash-device-observation.md).

**Cặp `log-*` (#2901) là nhóm DUY NHẤT chạy theo chiều "Cloud hỏi trước".** Mọi
endpoint khác ở đây do máy trạm tự quyết lúc nào gọi; cặp này bắt đầu bằng một
YÊU CẦU do người điều tra tạo ở HQ, còn máy trạm chỉ tự nhận ở nhịp sync kế
tiếp. Lý do phải cài ngược như vậy: Cloud **không gọi vào máy trạm được** — nó
nằm trên LAN của quán, sau NAT. Bốn điểm dễ cài sai:

| | |
|---|---|
| `GET log-requests` trả `[]` | ca **THƯỜNG**, không phải lỗi. Log chỉ rời quán khi có người hỏi |
| `POST log-records` | idempotent theo `(device_id, local_id)`, unique ở tầng DB; gửi lại ⇒ `duplicates++`, **không** ghi đè |
| `level: "debug"` | **422 cả lô** — bộ lọc ở nguồn hỏng thì cả lô đáng ngờ |
| `message` ngoài allowlist | **bỏ một dòng**, `rejected++`, lô vẫn 202 |

Bảng allowlist là hợp đồng hai đầu:
[`reference/workstation-log-allowlist.md`](workstation-log-allowlist.md).

**Cái gì đi qua cổng manifest, cái gì không (#1175 → #2712).** Mọi feed replica
tĩnh nay có version riêng trong `sync-manifest` — kể cả `print-templates`,
`print-images` và `expected-build`, ba feed mà trước #2712 **không có caller
nào** ở chế độ manifest (chỉ đường full-pull dự phòng gọi chúng). Ba feed CỐ Ý
vẫn poll mỗi 5 s vì không version hàng nào nói thay được: `till-sessions/active`
(force-abandon/expire phía Cloud, plan-032), `effective-payment-options`
(+ `/matrix` — là kết quả ĐÁNH GIÁ chính sách, không phải một phạm vi hàng) và
`customers` (con trỏ `?since=` + trần 1000 hàng/trang). Nguồn: `FEED_KEYS` trong
`SyncManifestService` ↔ `manifestFeeds()` trong `sync_manifest.go`.

**Pairing KHÔNG nằm trong namespace này.** Không có `POST /workstation/pair` —
workstation dùng endpoint chung `POST /api/v1/devices/pair` như mọi thiết bị khác
(xem `workstation/internal/handler/routes.go`).

Các endpoint `sync/pull`, `sync/push`, `menu/changes`, `heartbeat`, `config`
**chưa bao giờ được cài đặt** — workstation pull menu/branch trực tiếp và tự theo
dõi kết nối. `workstation/docs/CLOUD_API.md` vẫn còn đặc tả chúng (#1323).

**Cross-namespace pulls.** The workstation client also calls `/api/v1/tms/zones`
and `/api/v1/tms/tables` (TMS namespace) on the 60s tick — Cloud-owned tables
that workstation mirrors into local SQLite so kiosk/customer LAN endpoints
read without a round-trip. See `workstation/internal/service/sync_pull.go`
for the full pull-DOWN matrix.
