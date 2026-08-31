# Kế hoạch tích hợp Workstation ↔ Kiosk (Local Replica Model)

> **Ngày khởi tạo:** 2026-05-19
> **Phạm vi Phase 1:** Workstation thành **local replica** của Cloud cho kiosk operations. POS-web defer Phase 2.
> **Trạng thái:** Plan only — chưa code.

---

## 1. Triết lý kiến trúc

> **Workstation = local replica của Cloud cho data của quán.** Kiosk đọc/ghi vào workstation. Workstation sync 2 chiều với Cloud trong nền.

- **Orders + Payments**: workstation **làm chủ** — kiosk tạo order/payment thẳng vào SQLite của workstation, không đợi Cloud
- **Menu, Tables, Settings**: **Cloud làm chủ** — workstation pull định kỳ về SQLite, kiosk đọc local
- **Auth**: Cloud làm chủ — workstation cache token đã verify
- **Sync UP**: workstation đẩy order/payment lên Cloud qua `sync_queue` (đã có sẵn)
- **Sync DOWN**: workstation pull từ Cloud (polling) + WebSocket cho menu real-time

```
                 ┌──────────────────────────────────────────┐
                 │   CLOUD BACKEND (Laravel + MySQL)         │
                 │   GIỮ NGUYÊN - 0 thay đổi                │
                 │   Endpoints có sẵn:                       │
                 │   - POST /api/v1/devices/pair             │
                 │   - GET  /api/v1/kiosk/me                 │
                 │   - GET  /api/v1/shops/{slug}/menus       │
                 │   - GET  /api/v1/shops/{slug}/tables      │
                 │   - GET  /api/v1/tms/{zones,tables}       │
                 │   - POST /api/v1/kiosk/payments           │
                 │   - ...                                   │
                 └────────────────┬─────────────────────────┘
                                  │
                ┌─────────────────┼─────────────────┐
                │ sync UP         │ sync DOWN       │ WS push
                │ (orders,        │ (polling 60s,   │ (menu.updated,
                │  payments)      │  full pull)     │  device.revoked)
                ▼                 ▼                 ▼
        ┌──────────────────────────────────────────────────┐
        │ WORKSTATION (Go + Wails + SQLite)                 │
        │                                                    │
        │  LAN HTTP :8080 - expose local endpoints           │
        │    GET  /api/v1/kiosk/me           → token cache  │
        │    GET  /api/v1/kiosk/orders       → SQLite local │
        │    POST /api/v1/kiosk/payments     → SQLite + queue│
        │    GET  /api/v1/customer/tables/.. → SQLite local │
        │    ...                                             │
        │                                                    │
        │  SQLite tables (đã có + thêm mới):                 │
        │    orders, order_items, menu_items (đã có)         │
        │    payments, tables, zones, auth_token_cache (mới) │
        │                                                    │
        │  Sync engine:                                      │
        │    UP   - implement pushToCloud() (đang stub)      │
        │    DOWN - polling + WS upstream                    │
        └──────────────────┬───────────────────────────────┘
                           │ mDNS _ws-app._tcp.local.
                           │
                       ┌───┴───┐
                       │ Kiosk │  pair Cloud 1 lần,
                       │       │  gọi mọi API qua workstation
                       └───────┘
```

## 2. Phân chia ownership theo entity

| Entity | Source of truth | Sync direction | Cách sync |
|---|---|---|---|
| `orders` | **Workstation** | UP (workstation → Cloud) | sync_queue → POST /api/v1/kiosk/orders |
| `order_items` | **Workstation** | UP | Cùng với orders |
| `payments` | **Workstation** | UP | sync_queue → POST /api/v1/kiosk/payments + /confirm + /fail |
| `menu_items` | **Cloud** | DOWN | WS event `menu.updated` → GET /api/v1/shops/{slug}/menus |
| `tables` | **Cloud** | DOWN | Polling 60s GET /api/v1/shops/{slug}/tables |
| `zones` | **Cloud** | DOWN | Polling 60s GET /api/v1/tms/zones |
| `shop_settings` | **Cloud** | DOWN | Polling 5 phút |
| `devices` (auth) | **Cloud** | DOWN | WS event `device.revoked` + lazy fetch khi verify |
| Token cache | Workstation (local-only) | — | Verify qua Cloud, cache 5 phút |

**Conflict rule:**
- Orders/Payments: **Workstation thắng** (vì là source of truth)
- Menu/Tables/Devices: **Cloud thắng** (vì là source of truth, sync DOWN override local)

## 3. Hiện trạng workstation (verify ngày 2026-05-19)

### Đã có sẵn (giữ nguyên)

| Thành phần | File | Trạng thái |
|---|---|---|
| LAN HTTP server :8080 | [`internal/handler/server.go`](../../internal/handler/server.go) | Chạy |
| Middleware `lanOnly` | [`internal/handler/middleware.go`](../../internal/handler/middleware.go) | Có |
| WebSocket Hub | [`internal/handler/ws.go`](../../internal/handler/ws.go) | Có, chưa relay Cloud |
| mDNS `_ws-app._tcp.local.` | [`internal/discovery/mdns.go`](../../internal/discovery/mdns.go) | Có |
| ESC/POS printer (USB + TCP) | [`internal/printer/`](../../internal/printer/) | Có |
| Bảng `orders`, `order_items`, `menu_items` | ``migrations/001_initial_schema.sql`` | Có |
| Bảng `sync_queue` | ``migrations/001_initial_schema.sql`` | Có |
| Settings table (`device_token`, `cloud_api_url`) | ``migrations/002_device_auth.sql`` | Có |
| Connectivity monitor (10s) | [`internal/service/connectivity.go`](../../internal/service/connectivity.go) | Có |

### Cần thêm

| Cần thêm | Mục đích | Phase |
|---|---|---|
| 5 bảng SQLite mới (`payments`, `tables`, `zones`, `auth_token_cache`, `shop_settings`) | Local replica cho kiosk | 1 |
| Local endpoints `/api/v1/kiosk/*` + `/api/v1/customer/tables/*` + `/api/v1/tms/*` | Kiosk gọi vào | 1 |
| Implement `pushToCloud()` (đang stub) | Sync UP orders/payments | 1 |
| Sync DOWN worker (polling) | Pull tables, zones, settings | 1 |
| WebSocket upstream client | Real-time menu updates | 1 |
| Auth token cache + verify forward | Auth không cần online liên tục | 1 |
| LAN-only print endpoint (cho POS-web Phase 2) | In bill | 1 (sẵn cho 2) |
| Update mDNS TXT records | Kiosk filter đúng branch | 1 |

## 4. Phase 1 scope

| Bao gồm | Không bao gồm (Phase 2) |
|---|---|
| Workstation local replica core | POS-web integration |
| Kiosk routing qua workstation | Merge-table, split-bill |
| Sync 2 chiều orders/payments/menu/tables | Coupon, void, refund |
| Auth token cache | Customer lookup (find-or-create) |
| Print endpoint LAN (stub, dùng cho Phase 2) | Inventory, reports |

## 5. Cấu trúc kế hoạch

```
docs/plan/
├── README.md                         ← bạn đang đọc
├── 01-workstation-local-replica.md   ← Go: schema + local endpoints + sync engine
└── 02-kiosk-integration.md           ← Expo/RN: mDNS + routing
```

Phase 2 sẽ có thêm `03-pos-web-integration.md` và mở rộng plan 01.

## 6. Thứ tự thực hiện

```
Tuần 1-2:  [01] Workstation - schema + endpoints + sync UP/DOWN
                                                                
Tuần 3:    [01] Workstation - WS + auth cache + dashboard
           [02] Kiosk - mDNS + routing (song song)

Tuần 4:    Integration test + bug fix
```

Tổng ước tính: **3-4 tuần**.

## 7. Acceptance criteria

- [ ] Kiosk discover workstation qua mDNS trong < 5s
- [ ] Kiosk pair với Cloud 1 lần (vẫn dùng `POST /api/v1/devices/pair` qua workstation forward)
- [ ] Kiosk verify token qua workstation (workstation cache, không gọi Cloud mỗi request)
- [ ] Kiosk đọc menu/tables/zones từ workstation (workstation đã pull từ Cloud)
- [ ] Kiosk tạo payment → workstation INSERT bảng `payments` local → trả response ngay
- [ ] Workstation sync payment lên Cloud trong 5-10s (qua `sync_queue` → `pushToCloud()`)
- [ ] HQ admin sửa menu trên Cloud → workstation nhận WS event → update bảng `menu_items` local → kiosk thấy menu mới trong 1-2s
- [ ] HQ admin sửa table info → workstation pull được trong 60s
- [ ] Rút Internet workstation → kiosk vẫn order/payment được (queue ở workstation)
- [ ] Cắm lại Internet → queue auto-flush trong 30s
- [ ] HQ revoke device → workstation nhận WS event → kiosk request tiếp theo bị 401

## 8. Out of scope (Phase 1)

- POS-web integration (Phase 2)
- Merge-table, split-bill, coupon (Phase 2)
- Customer lookup, find-or-create (Phase 2)
- Inventory / material lots (Phase 2)
- Multi-workstation cùng branch
- mTLS LAN encryption
- Add endpoint mới vào backend (giữ "tận dụng endpoint có sẵn")

## 9. Rủi ro & giảm thiểu

| Rủi ro | Khả năng | Giảm thiểu |
|---|---|---|
| Conflict orders/payments khi workstation push trùng với Cloud edit | Trung bình | Workstation thắng cho orders (rule rõ); Idempotency-Key cho payments |
| Menu Cloud thay đổi structure → workstation parse fail | Thấp | Schema versioning + test khi backend deploy |
| Polling 60s tải Cloud nặng nếu nhiều quán | Thấp (1 quán/workstation) | Phase 2 chuyển sang sync endpoint chuyên dụng |
| Token cache không kịp invalidate khi revoke | Trung bình | WS event + TTL ngắn (5 phút) |
| Schema workstation đi lệch Cloud sau time | Cao | Document mapping, test sync sau mỗi backend migration |
| iOS Bonjour permission từ chối | Cao (kiosk) | NSBonjourServices + test device thật |

## 10. Glossary

- **Local replica**: workstation lưu bản sao data của Cloud trong SQLite, đọc/ghi local trước, sync với Cloud sau
- **Sync UP**: workstation → Cloud (orders, payments mới)
- **Sync DOWN**: Cloud → workstation (menu, tables, devices admin sửa)
- **Source of truth**: nơi quyết định data đúng nhất khi conflict
- **Ownership matrix**: bảng phân chia entity nào ai làm chủ
- **mDNS / Bonjour**: service discovery trong LAN
- **ESC/POS**: protocol điều khiển thermal printer
