---
title: Realtime phía Cloud — BROADCAST_CONNECTION=log trên production
category: guide
tags: [realtime, broadcast, reverb, ops, production, notifications, workstation]
summary: "Production chạy BROADCAST_CONNECTION=log: mọi broadcast() rơi vào file log rồi dừng. Vì sao creds Reverb theo brand là cấu hình chết, chín sự kiện nào đang rơi và mỗi cái mất gì thật, ba nơi trong cây còn nghe Cloud-realtime, và checklist đủ điều kiện để bật (#2565)."
related:
  - tenant-provisioning
  - workstation-sync-recovery
---

# Realtime phía Cloud — trạng thái thật trên production

## Luật một câu

**Trên production `BROADCAST_CONNECTION=log`.** Mọi `broadcast()` ghi một dòng
vào `storage/logs/laravel-*.log` rồi dừng ở đó — không có server Reverb, không
có biến `REVERB_*`. Không mất dữ liệu: mọi màn hình phụ thuộc đều có đường lui
(poll, hoặc WebSocket LAN của workstation). Nhưng đây là **cấu hình chết mà
không có gì báo động**, nên hồ sơ này tồn tại để lần sau không phải điều tra
lại từ đầu.

Không có việc gấp phải làm. Đây là tài liệu **mô tả**, không phải runbook bật.

## Ảnh chụp production — đo 2026-08-12 (nguồn #2565)

Đây là **ảnh chụp một ngày**, không phải bất biến. Không có test nào ghim nó.

| Đo | Kết quả |
|---|---|
| `config('broadcasting.default')` | `log` |
| Tiến trình Reverb trên máy chủ | không có |
| Biến `REVERB_*` trong `.env` production | không có cái nào |
| Cron `reverb:start` | 0 dòng |
| `brands.reverb_app_id` / `reverb_app_key` | cả 2 brand đều CÓ — trỏ tới server không tồn tại |
| `notification_deliveries` kênh `realtime` | 0 dòng (265/265 là `in_app`) |
| Sự kiện `broadcast()` rơi vào log | 348 (2026-08-12) · 733 (2026-08-11) |

Đo lại khi cần:

```sh
# Trên máy chủ production, trong thư mục app Tempo
php artisan tinker --execute="echo config('broadcasting.default');"
grep -c 'Broadcasting \[' storage/logs/laravel-$(date +%Y-%m-%d).log
```

**Môi trường dev KHÔNG tái hiện được chuyện này**: stack docker có hẳn một
service `reverb` và đặt `BROADCAST_CONNECTION: reverb`
(`docker-compose.yml:115` và `:176-187`), `.env.example:117-126` cũng vậy. Giá
trị `log` chỉ tồn tại trong `.env` của máy chủ production — nó không đến từ repo,
và không workflow deploy nào đặt nó (grep `BROADCAST|REVERB` trong
`.github/workflows/` ra 0 dòng).

## Vì sao creds Reverb theo brand không bao giờ chạy

Chuỗi gọi:

```
RealtimeChannel::send                      backend/app/Services/Notification/Channels/RealtimeChannel.php:43
  → BrandAwareBroadcastManager::broadcastForBrand      .../Broadcasting/BrandAwareBroadcastManager.php:107
    → Config::set('broadcasting.connections.reverb.{app_id,key,secret}', <creds brand>)   :85-87
    → BroadcastManager::queue($event)                                                     :137
```

`Illuminate\Broadcasting\BroadcastManager::queue()` **không** nhận tên
connection. Nó dựng một `BroadcastEvent`, và `BroadcastEvent::handle()` hỏi sự
kiện bằng `method_exists($event, 'broadcastConnections')`; không có thì dùng
`[null]`, tức **connection MẶC ĐỊNH** — ở đây là `log`.

Trong cây này không sự kiện nào tự khai:

```sh
$ grep -rE 'broadcastConnections|broadcastVia' backend/app
$ echo $?
1
```

Không file nào dùng trait `InteractsWithBroadcasting` (grep cũng 0 kết quả).

Nên: `BrandAwareBroadcastManager` ghi đè nhánh `reverb` trong khi sự kiện bay ra
qua nhánh `log`. **Cơ chế đúng, chỉ đang bị tắt bởi connection mặc định** —
không phải lỗi logic. Đừng đi sửa `BrandAwareBroadcastManager`.

Hệ quả thứ hai, dễ đọc nhầm: **`brands.reverb_app_id` có giá trị KHÔNG chứng
minh realtime đang bật.** Creds được cấp lúc tạo brand
(`BrandReverbAppService::provision`, xem [tenant-provisioning](tenant-provisioning.md))
và `GET /me/reverb-config` còn tự vá khi thiếu
(`backend/app/Http/Controllers/Api/V1/Me/ReverbConfigController.php:68-71`).
Chúng là creds cho một server chưa từng tồn tại.

## Chín sự kiện, kênh của chúng, và ai thật sự nghe

Kênh lấy từ `broadcastOn()`, tên lấy từ `broadcastAs()` trong
`backend/app/Events/`.

| Sự kiện (`broadcastAs`) | Kênh Cloud | Ai nghe kênh đó trong cây | Đường lui khi không có Reverb |
|---|---|---|---|
| `sync.poke` (`WorkstationSyncPoke.php:42,47`) | private `workstation.sync.{branch}` | workstation | tick 5s có sẵn — `sync_pull.go:65` |
| `order.paid` (`OrderPaid.php:40,45`) | public `customer.order.{id}` | **không còn ai** — customer-web đã thay bằng poll | `use-order-settlement.ts` (poll thích ứng) |
| `payment.recorded` (`OrderPaymentRecorded.php:42,47`) | public `customer.order.{id}` | như trên | như trên |
| `order_item.status_changed` (`OrderItemStatusChanged.php:38,43`) | private `branch.{id}.kds-events` | KDS, **chỉ ở chế độ cloud-fallback** — `app/kds/src/services/realtime/cloud-echo.ts:68` | KDS poll 15s — `app/kds/src/hooks/use-orders.ts:32` |
| `order_voided` (`OrderVoided.php:45,50`) | private `branch.{id}.pos-events` | **không ai** — chỉ có callback authorize ở `backend/routes/channels.php:87` | pos-web nghe `order_voided` của workstation qua LAN — `web/pos/src/hooks/use-workstation-socket.ts:190` |
| `order.item-added` (`OrderItemAdded.php:45,51`) | public `table-session.{id}` | **customer-web** — `web/customer/hooks/use-table-session-realtime.ts:113` | **không có poll** — khách phải tự refresh |
| `order.editing-started` (`OrderEditingStarted.php:39,45`) | public `table-session.{id}` | customer-web — `use-table-session-realtime.ts:117` | không có; khoá sửa của POS không hiện ra cho khách |
| `order.editing-ended` (`OrderEditingEnded.php:37,43`) | public `table-session.{id}` | customer-web — `use-table-session-realtime.ts:122` | như trên |
| `notification.received` (`NotificationReceived.php:47,58`) | private `user.{id}.notifications` | admin-web — `web/admin/src/hooks/notifications/use-notification-realtime.ts:110` | `pollFallback` sau 10s mất kết nối — cùng file `:102-108,122` |

### Số đếm ngày 2026-08-12 (ảnh chụp, nguồn #2565)

| Sự kiện | Số | Thiệt hại thật |
|---|---:|---|
| `sync.poke` | 188 | **không**. `pullIntervalManifest = 5s` và workstation kéo bằng ETag; comment ở `workstation/internal/service/sync_pull.go:63-64` nói đúng vai của poke: *"Poke (sync_poke.go) only makes this tick arrive EARLY via the kick channel; it is never a condition for the tick to run."* Chậm thêm tối đa 5 giây. |
| `order.paid` | 97 | **không**. Kênh `customer.order.{id}` không còn client nào; customer-web đã bỏ `use-order-paid-realtime` và thay bằng poll thích ứng (`web/customer/hooks/use-order-settlement.ts:6-10`). pos-web lấy `order_paid` từ workstation qua LAN. |
| `order_item.status_changed` | 24 | **không** khi KDS nối workstation qua LAN (đường thường). Nếu KDS đang chạy cloud-fallback: mất push, còn poll 15s. |
| `order.item-added` | 23 | **có, nhỏ**. Đây là kênh của **khách dine-in**, không phải pos-web/KDS: thiết bị thứ hai trong cùng phiên bàn không thấy món vừa thêm cho tới khi khách tự refresh. |
| `order_voided` | 11 | **không**. Kênh Cloud `pos-events` không có subscriber nào trong cây. |
| `payment.recorded` | 5 | **không**. Cùng lý do với `order.paid`. |

> **Đính chính so với mô tả ban đầu ở #2565.** Issue xếp `order.item-added` và
> `order_voided` chung nhóm "pos-web/KDS nghe qua LAN của workstation". Đọc mã
> thì không phải: `order.item-added` bay trên `table-session.{id}` và người nghe
> duy nhất là customer-web (không có poll lui), còn `order_voided` bay trên
> `branch.{id}.pos-events` mà **không client nào trong cây subscribe**. Kết luận
> "không thiệt hại tiền" vẫn đúng, nhưng lý do khác — và cái mất thật (khách
> dine-in nhiều thiết bị) nằm ở dòng `order.item-added`.

## Ba nơi còn nghe Cloud-realtime — và điều đó có nghĩa gì

| Nơi | File | Hành vi khi không nối được |
|---|---|---|
| Chuông thông báo admin-web | `web/admin/src/hooks/notifications/use-notification-realtime.ts` | trả `{ pollFallback: true }` sau 10s mất kết nối (`:102-108`), hoặc ngay lập tức khi thiếu `app_key` (`:75-78`) — thiết kế sẵn để tụt về polling |
| Phiên bàn dine-in (khách) | `web/customer/hooks/use-table-session-realtime.ts` | `catch` ghi `console.warn` (`:134`) rồi thôi — **không có poll lui**, khách refresh tay |
| KDS ở chế độ cloud | `app/kds/src/services/realtime/cloud-echo.ts` (chọn bởi `dispatcher.ts:29-34`) | `isConnected=false` ⇒ poll 15s (`app/kds/src/hooks/use-orders.ts:32`) |

Nghĩa là: **không đường tiền nào treo vào Cloud-realtime.** Tiền đi qua
workstation (LAN WebSocket + sync queue) hoặc qua poll trạng thái đơn. Vì thế
`BROADCAST_CONNECTION=log` sống được nhiều tháng mà không ai thấy — và cũng vì
thế nó không tự lộ ra: không có báo động nào cho một broadcaster im lặng.

customer-web ghi lại chính chuyện này ngay trong mã, từ plan-050:
`web/customer/hooks/use-order-settlement.ts:6-10` — *"Thực tế nó chưa từng chạy:
BE đặt `BROADCAST_CONNECTION=log` nên event chỉ rơi vào file log."*

## Hai chỗ suy giảm đang chấp nhận

1. Chuông thông báo admin-web **poll thay vì push**.
2. Đồng bộ Cloud→quán chậm **tối đa 5 giây thay vì tức thì**.

Chỗ thứ ba, nhỏ hơn và ít gặp: khách dine-in dùng **nhiều thiết bị** trên cùng
một bàn phải refresh tay để thấy món thiết bị kia vừa thêm, và không thấy khoá
sửa khi POS đang can thiệp vào đơn.

## Muốn bật thật thì cần gì — checklist ops

**Đừng flip `BROADCAST_CONNECTION=reverb` một mình.** Nhánh `reverb` trong
`backend/config/broadcasting.php:33-47` đọc host/port/scheme từ `REVERB_*`, mà
production không có biến nào — kết quả là mỗi lần broadcast đều hỏng, chỉ đổi từ
"im lặng ghi log" sang "im lặng ném exception được report".

Mức độ hỏng, đo trên mã chứ không đoán: **8/9 sự kiện có `ShouldRescue` (#1208)**
nên `BroadcastManager::queue()` bọc chúng trong `rescue()` — exception được
report nhưng **không** trào lên HTTP response, kể cả `OrderPaid` trên đường
thanh toán (`backend/app/Events/OrderPaid.php:25-31`). Cái duy nhất không có là
`WorkstationSyncPoke` (`:33`), và nó được che bằng try/catch ngay tại chỗ dispatch
(`:22-24`). Nên flip một mình **không** làm 500 đường tiền như lo ngại ban đầu
— nó tạo ra một dòng exception liên tục và vẫn 0 sự kiện tới nơi. Vẫn là việc
không nên làm rời rạc.

Đủ điều kiện, theo thứ tự:

1. **Dựng server Reverb + trình giám sát giữ nó sống.** `php artisan reverb:start`
   là một tiến trình dài; crontab production hiện chỉ có `schedule:run` và các
   watcher queue, không có gì trông Reverb.
2. **Biến phía server**: `REVERB_APP_ID` · `REVERB_APP_KEY` · `REVERB_APP_SECRET`
   · `REVERB_HOST` · `REVERB_PORT` · `REVERB_SCHEME` trong `.env` production.
3. **Biến phía trình duyệt**: `REVERB_CLIENT_HOST` · `REVERB_CLIENT_PORT` ·
   `REVERB_CLIENT_SCHEME`. Bước này hay bị quên vì trông như trùng lặp. Hai bộ
   controller trả creds cho client (`.../Me/ReverbConfigController.php:82-84` và
   bản `Device/`) ưu tiên `REVERB_CLIENT_*` rồi mới rơi về `REVERB_HOST`; thiếu
   cả hai thì client nhận host rỗng và mở socket đi đâu không ai biết.
   `docker-compose.yml:123-130` ghi lại đúng cái bẫy này.
4. **Một đường WSS công khai.** CloudFront của Amplify không proxy WebSocket
   (ghi ở `web/customer/hooks/use-order-settlement.ts:19-20`), nên Reverb cần
   domain/route riêng — không dùng chung `tempo.godx.jp` được.
5. **Kiểm creds trong `brands` còn khớp app thật** trên server mới; nếu không,
   xoay bằng `POST /hq/{brandSlug}/settings/reverb/rotate`
   ([notifications-api](../reference/notifications-api.md)).
6. **Chỉ khi đó mới đổi `BROADCAST_CONNECTION`**, và theo dõi log exception ngay
   sau đó.

Hiện chưa có tính năng nào cần realtime phía Cloud, nên đây là danh sách để
dùng khi có nhu cầu thật — không phải việc đang chờ.

## Chỗ chưa có rào

Không có gì bắt được cặp "`BROADCAST_CONNECTION=reverb` + `REVERB_HOST` rỗng".
Một arch test cho cặp này đã được nêu ở #2565 nhưng **chưa làm** — ghi ở đây để
người sau khỏi tưởng nó đã tồn tại.
