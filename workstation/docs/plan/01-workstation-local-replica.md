# [01] Workstation Local Replica — schema + endpoints + sync engine

> **Phase 1, Prerequisite cho:** [02 Kiosk Integration](02-kiosk-integration.md)
> **Owner:** Backend Go dev (workstation-app)
> **Ước tính:** 12-14 ngày làm việc

**Goal:** Implement workstation thành **local replica** của Cloud cho kiosk operations. Workstation:
1. Expose local endpoints giống Cloud (kiosk gọi qua workstation y như gọi Cloud)
2. Đọc/ghi vào SQLite local (offline-able)
3. Sync 2 chiều với Cloud: UP (orders, payments) và DOWN (menu, tables, zones, devices)
4. Cache auth token

**Tech Stack:** Go 1.25, Wails v3, SQLite (`modernc.org/sqlite`), `gorilla/websocket`.

**Không thay đổi:**
- Backend Laravel (0 thay đổi - tận dụng endpoint có sẵn)
- Schema bảng đã có (`orders`, `order_items`, `menu_items`, `sync_queue`, `settings`)
- Format `device_token` ở Cloud (giữ `Str::random(64)`)

---

## Task 1 — Schema migration: thêm 5 bảng mới

**Files:**
- Create: `migrations/004_local_replica.up.sql`
- Create: `migrations/004_local_replica.down.sql`
- Create: `internal/domain/payment.go`, `internal/domain/table.go`, `internal/domain/zone.go`, `internal/domain/auth_cache.go`

**Mô tả:**

Thêm 5 bảng mới cho Phase 1. Mọi bảng dạng "mirror" của Cloud đều có:
- `id` (UUID, primary key) — cùng UUID với Cloud (sau khi sync)
- `cloud_id` — backup nếu local generate ID trước khi biết Cloud ID (vd: payments offline)
- `synced_at` — null nếu chưa sync, có giá trị nếu đã sync UP/DOWN
- `local_updated_at` — timestamp local cập nhật

### 1.1 `payments` (workstation làm chủ)

| Cột | Kiểu | Mô tả |
|---|---|---|
| `id` | TEXT PRIMARY KEY | UUID generate local |
| `cloud_id` | TEXT | NULL ban đầu, fill sau khi sync UP (nếu Cloud generate ID khác) |
| `order_id` | TEXT | FK ảo tới `orders.id` |
| `payment_method` | TEXT | card/qr/emoney/cash |
| `amount` | INTEGER | yen |
| `status` | TEXT | pending/confirmed/failed |
| `idempotency_key` | TEXT UNIQUE | để retry không tạo duplicate |
| `terminal_response` | TEXT | JSON từ POS terminal |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |
| `synced_at` | DATETIME | NULL = chưa push lên Cloud |

Index: `idx_payments_status`, `idx_payments_synced` (WHERE synced_at IS NULL), `idx_payments_order`

### 1.2 `tables` (Cloud làm chủ, sync DOWN)

| Cột | Kiểu | Mô tả |
|---|---|---|
| `id` | TEXT PRIMARY KEY | UUID từ Cloud |
| `qr_token` | TEXT UNIQUE | Token QR khách scan |
| `name` | TEXT | "Table 1", "T-01"... |
| `zone_id` | TEXT | FK ảo tới zones |
| `status` | TEXT | available/occupied/reserved |
| `capacity` | INTEGER | Số chỗ |
| `cloud_updated_at` | DATETIME | Timestamp Cloud cập nhật |
| `local_synced_at` | DATETIME | Lần sync DOWN gần nhất |

### 1.3 `zones` (Cloud làm chủ, sync DOWN)

| Cột | Kiểu | Mô tả |
|---|---|---|
| `id` | TEXT PRIMARY KEY | UUID từ Cloud |
| `name` | TEXT | "Tầng 1", "Patio"... |
| `sort_order` | INTEGER | |
| `cloud_updated_at` | DATETIME | |
| `local_synced_at` | DATETIME | |

### 1.4 `auth_token_cache` (workstation-only)

| Cột | Kiểu | Mô tả |
|---|---|---|
| `token_hash` | TEXT PRIMARY KEY | SHA-256 của Bearer token (không lưu plain) |
| `device_id` | TEXT | UUID device từ Cloud |
| `device_type` | TEXT | kiosk/pos/tms/workstation |
| `branch_id` | TEXT | |
| `device_info` | TEXT | JSON |
| `verified_at` | DATETIME | Lần verify cuối với Cloud |
| `expires_at` | DATETIME | verified_at + 5 phút |

Index: `idx_auth_cache_expires` (WHERE expires_at > now để cleanup)

### 1.5 `shop_settings` (Cloud làm chủ, sync DOWN, key-value)

| Cột | Kiểu | Mô tả |
|---|---|---|
| `key` | TEXT PRIMARY KEY | `tax_rate`, `service_charge`, `currency`, `timezone`... |
| `value` | TEXT | JSON value |
| `cloud_updated_at` | DATETIME | |
| `local_synced_at` | DATETIME | |

**Checklist:**
- [ ] Viết migration up/down
- [ ] Tạo Go structs trong `internal/domain/`
- [ ] sqlc queries cho từng bảng (file `internal/store/queries/`)
- [ ] Run `wails3 generate bindings` sau khi thêm domain
- [ ] Test: chạy migrate fresh, kiểm tra 5 bảng tồn tại
- [ ] Commit

---

## Task 2 — Local endpoints cho kiosk

**Files:**
- Create: `internal/handler/local_kiosk.go`
- Create: `internal/handler/local_customer.go`
- Create: `internal/handler/local_tms.go`
- Modify: `internal/handler/routes.go`

**Mô tả:**

Implement các endpoint kiosk gọi tới, **đọc/ghi từ SQLite local**.

### 2.1 `GET /api/v1/kiosk/me`

Logic:
1. Đọc Bearer token từ header
2. Hash SHA-256 → lookup `auth_token_cache`
3. Nếu cache hit + chưa expire → trả device info từ cache
4. Cache miss/expired → forward `GET <cloud>/api/v1/kiosk/me` → cache response → trả
5. Cloud unreachable + cache expired → trả 503 hoặc cache stale với header `X-Auth-Cached: stale`

### 2.2 `GET /api/v1/kiosk/orders?table_id=<uuid>`

Logic:
1. Verify token (Task 3 middleware)
2. SELECT * FROM `orders` WHERE table_number maps to table_id + status IN ('open', 'preparing') ORDER BY created_at
3. Include order_items qua join
4. Trả về JSON giống Cloud schema (Eloquent Resource format)

### 2.3 `POST /api/v1/kiosk/payments`

Body: `{order_id, method, amount, idempotency_key, terminal_response?}`

Logic:
1. Verify token + Idempotency-Key
2. Check `payments` WHERE idempotency_key = ? — nếu có → return existing (idempotent)
3. INSERT INTO `payments` với status=pending, synced_at=NULL
4. INSERT INTO `sync_queue` với entity_type='payment', operation='create', payload=JSON, idempotency_key
5. Trả về payment object như Cloud

### 2.4 `GET /api/v1/kiosk/payments/{id}/status`

SELECT FROM `payments` WHERE id = ? → trả status + thông tin

### 2.5 `POST /api/v1/kiosk/payments/{id}/confirm`

Logic:
1. UPDATE `payments` SET status='confirmed', terminal_response=?, synced_at=NULL WHERE id=?
2. UPDATE `orders` SET paid_at=now WHERE id=payment.order_id
3. INSERT `sync_queue` với operation='confirm'
4. Trả payment object

### 2.6 `POST /api/v1/kiosk/payments/{id}/fail`

Tương tự confirm nhưng status='failed', không update orders.paid_at.

### 2.7 `GET /api/v1/customer/tables/{qrToken}`

SELECT FROM `tables` WHERE qr_token = ? → trả table info + zone

### 2.8 `GET /api/v1/tms/zones`

SELECT FROM `zones` ORDER BY sort_order

### 2.9 `GET /api/v1/tms/tables`

SELECT FROM `tables` (có thể filter theo zone_id query param)

**Lưu ý chung:**
- Response JSON phải **giống schema Cloud** (Eloquent Resource format) để kiosk không phải đổi parse logic
- Đối chiếu với client code [`app/kiosk/src/types/models/base/Device.ts`](../../../app/kiosk/src/types/models/base/Device.ts) etc.

**Checklist:**
- [ ] 9 endpoints với handler riêng
- [ ] Validate input
- [ ] Trả lỗi 401/403/404 đúng format Cloud
- [ ] Unit test mỗi endpoint
- [ ] Integration test: gọi từ client → response giống gọi Cloud
- [ ] Commit

---

## Task 3 — Auth middleware + token cache

**Files:**
- Modify: `internal/handler/middleware.go`
- Create: `internal/service/auth_cache.go`

**Mô tả:**

Middleware verify Bearer token cho mọi request `/api/v1/kiosk/*`, `/api/v1/tms/*`, `/api/v1/customer/*`.

**Logic:**

1. Đọc Bearer từ header
2. Hash SHA-256 → key cho cache
3. Lookup `auth_token_cache`:
   - **Hit + chưa expire (verified_at + 5 phút)** → set context (device_id, type, branch_id) → next
   - **Miss hoặc expired**:
     a. Forward `GET <cloud>/api/v1/kiosk/me` với Bearer header
     b. Cloud trả 200 → INSERT/UPDATE auth_token_cache → next
     c. Cloud trả 401 → DELETE cache entry, trả 401
     d. Cloud unreachable + có cache (kể cả expired):
        - Trả stale (set header `X-Auth-Stale: true`) — kiosk vẫn dùng được offline
4. Branch validation: nếu device.branch_id ≠ workstation.branch_id → trả 403

**Invalidation:**
- WS event `device.revoked` từ Cloud (Task 5) → DELETE cache entries có device_id tương ứng
- Cleanup goroutine: DELETE entries expired > 1 giờ mỗi 10 phút

**Checklist:**
- [ ] Middleware function + apply cho local endpoint group
- [ ] `AuthCacheStore` interface + SQLite implementation
- [ ] Forward verify với timeout 5s
- [ ] Cleanup goroutine
- [ ] Hook để Task 5 invalidate qua WS event
- [ ] Test: cache hit không gọi Cloud
- [ ] Test: cache miss → gọi Cloud → cache
- [ ] Test: Cloud down + cache stale → vẫn cho qua với header warning
- [ ] Test: Cloud trả 401 → cache xóa, kiosk nhận 401
- [ ] Commit

---

## Task 4 — Sync engine UP (implement `pushToCloud()`)

**Files:**
- Modify: `internal/service/sync_service.go` (đang là stub)
- Create: `internal/service/sync_push_handlers.go`

**Mô tả:**

Worker chạy mỗi 5 giây (đã có goroutine), đọc từ `sync_queue` và push lên Cloud.

**Logic worker:**

1. SELECT * FROM `sync_queue` WHERE synced_at IS NULL AND next_retry_at <= now ORDER BY priority DESC, created_at LIMIT 50
2. Cho mỗi row, gọi handler theo `entity_type` + `operation`:

| entity_type | operation | Cloud endpoint |
|---|---|---|
| order | create | POST /api/v1/kiosk/orders (tạm — chưa có, có thể skip Phase 1) |
| payment | create | POST /api/v1/kiosk/payments với Idempotency-Key |
| payment | confirm | POST /api/v1/kiosk/payments/{id}/confirm |
| payment | fail | POST /api/v1/kiosk/payments/{id}/fail |

3. Mỗi handler:
   - Build HTTP request từ payload JSON + Bearer (workstation token)
   - Timeout 15s
   - **Success (2xx)**: UPDATE sync_queue SET synced_at=now; nếu response có cloud_id → UPDATE entity table SET cloud_id=?
   - **4xx (trừ 408, 429)**: DELETE sync_queue entry + log audit (không retry — vì client error)
   - **5xx, network error, 408, 429**: 
     - Tăng `attempts`
     - Set `next_retry_at = now + min(2^attempts seconds, 5 min)`
     - Nếu attempts ≥ max_attempts (5): move vào audit log, mark dead-letter trong sync_queue

**Lưu ý quan trọng — Idempotency:**

Tất cả handler `payment` PHẢI gửi `Idempotency-Key` header (lấy từ `sync_queue.idempotency_key`). Nếu workstation crash giữa lúc gửi và nhận response → retry không tạo 2 payment ở Cloud.

**Order push (Phase 2):**

Hiện kiosk không tạo order qua API (kiosk chỉ list orders, không create). Order create là từ TMS hoặc POS-web. Phase 1 không cần push order — chỉ payments. Nhưng giữ infrastructure để Phase 2 mở rộng.

**Checklist:**
- [ ] Implement `pushToCloud()` thay stub
- [ ] Handler riêng cho payment.create, payment.confirm, payment.fail
- [ ] Idempotency-Key support
- [ ] Exponential backoff với jitter
- [ ] Dead-letter handling
- [ ] Test: tạo payment offline → bật Cloud → sync lên trong 10s
- [ ] Test: Cloud down → retry với backoff, không spam
- [ ] Test: Cloud 422 → entry xóa, không retry
- [ ] Test idempotency: crash giữa gửi/nhận → retry không duplicate
- [ ] Commit

---

## Task 5 — Sync engine DOWN (polling + WebSocket)

**Files:**
- Create: `internal/service/sync_pull.go`
- Create: `internal/service/ws_upstream.go`

**Mô tả:**

2 cơ chế chạy song song.

### 5.1 Polling worker (60 giây/lần)

```
Loop mỗi 60s:
  if !cloud_reachable: skip
  
  // Tables
  resp = GET <cloud>/api/v1/shops/{slug}/tables (với workstation token)
  for each table in resp.data:
    UPSERT INTO tables (id, qr_token, name, zone_id, status, capacity, cloud_updated_at, local_synced_at)
  
  // Zones
  resp = GET <cloud>/api/v1/tms/zones
  for each zone in resp.data:
    UPSERT INTO zones
```

### 5.2 Polling worker (5 phút/lần)

```
Loop mỗi 5 phút:
  // Shop settings
  resp = GET <cloud>/api/v1/shops/{slug}/settings/order
  for each key, value in resp:
    UPSERT INTO shop_settings
  
  // Shop info
  resp = GET <cloud>/api/v1/shops/{slug}
  UPSERT vào settings table (key='shop_info', value=JSON)
```

### 5.3 WebSocket upstream client

```
Connect: ws://<cloud>/ws?token=<workstation_device_token>
Subscribe: ["menu", "device", "order"]  // theo Cloud spec

Event handlers:
  - menu.updated:
    → trigger full sync: GET <cloud>/api/v1/shops/{slug}/menus
    → UPSERT INTO menu_items
    → broadcast event xuống LAN WS clients
  
  - menu.deleted:
    → DELETE FROM menu_items WHERE cloud_id = ?
  
  - device.revoked:
    → DELETE FROM auth_token_cache WHERE device_id = ?
    → close WS connections của device đó
  
  - order.status_changed (cho orders bị POS-web cập nhật từ xa):
    → UPDATE orders SET status = ?, synced_at = now WHERE cloud_id = ?
    → broadcast xuống kiosk

Reconnect: exponential backoff 1s → 30s
Ping mỗi 30s
```

### 5.4 Schema mapping

Cloud trả về JSON với schema Laravel Eloquent. Workstation phải parse + map sang struct Go + INSERT/UPDATE SQLite. **Đảm bảo các cột Cloud → cột workstation đúng**.

VD cho tables:
```json
Cloud: { "id": "uuid", "name": "T-01", "zone_id": "uuid", "status": "available", "qr_token": "abc", "updated_at": "..." }
↓
Workstation: UPSERT tables (id=..., name=..., zone_id=..., status=..., qr_token=..., cloud_updated_at=..., local_synced_at=now)
```

**Checklist:**
- [ ] Polling worker 60s + 5 phút
- [ ] WS upstream với reconnect + ping
- [ ] Event handlers (menu, device, order)
- [ ] Schema mapping cho mỗi entity
- [ ] Tận dụng `Last-Modified` hoặc `If-Modified-Since` nếu Cloud hỗ trợ (giảm payload)
- [ ] Test: sửa table trên Cloud → workstation pull được trong 60s
- [ ] Test: sửa menu trên Cloud → kiosk thấy menu mới trong 2s (qua WS)
- [ ] Test: revoke device trên Cloud → workstation invalidate cache + close WS kiosk
- [ ] Test: Cloud WS down → reconnect tự động khi up lại
- [ ] Commit

---

## Task 6 — Update mDNS TXT records

**Files:**
- Modify: `internal/discovery/mdns.go`

**Mô tả:**

Bổ sung TXT records cho mDNS service `_ws-app._tcp.local.` để kiosk filter đúng workstation của branch.

| Key | Value | Đã có? |
|---|---|---|
| `version` | `0.1.0` | Yes |
| `hostname` | `tokyo-main-ws1` | Yes |
| `branch_id` | UUID | Yes (verify lại) |
| `name` | `Branch Tokyo - WS1` | Cần thêm |
| `proxy_url` | `http://192.168.1.10:8080` | Cần thêm |

**Checklist:**
- [ ] Update `NewMDNSAdvertiser` đọc từ settings
- [ ] Re-advertise khi settings đổi
- [ ] Test: `dns-sd -B _ws-app._tcp` thấy đủ TXT
- [ ] Commit

---

## Task 7 — LAN-only endpoints (giữ chỗ cho Phase 2 POS-web)

**Files:**
- Create: `internal/handler/lan_health.go`
- Create: `internal/handler/lan_print.go` (stub, full implement Phase 2)

**Mô tả:**

2 endpoint LAN-only:

### `GET /api/lan/health` (không auth)

```
Response: {
  status: "ok",
  workstation_name: "Branch Tokyo - WS1",
  branch_id: "uuid",
  version: "0.1.0",
  cloud_connected: true|false,
  server_time: "2026-05-19T..."
}
```

### `POST /api/lan/print/payment-receipt` (auth Bearer)

Phase 1 implement basic structure, Phase 2 sẽ full integrate với POS-web. Kiosk không gọi endpoint này (kiosk tự in qua Star SDK).

**Checklist:**
- [ ] Health endpoint
- [ ] Print endpoint với format basic (full Phase 2)
- [ ] Test: curl health từ máy LAN khác → response OK
- [ ] Commit

---

## Task 8 — Wails UI: status dashboard

**Files:**
- Create: `frontend/src/pages/SyncStatus.tsx`
- Modify: `frontend/src/App.tsx`
- Create: `internal/service/sync_stats.go` (Wails binding)

**Mô tả:**

Trang status cho admin quán xem:

- **Cloud connection**: online/offline + last sync
- **Sync UP**: pending queue depth, dead-letter count, last successful push
- **Sync DOWN**: last menu/tables/zones pull timestamps
- **Auth cache**: số token cached, hit rate
- **WS upstream**: connected/reconnecting + events nhận trong 5 phút
- **Recent activity** (last 20): payments created, orders received, menu changes

**Actions:**
- Force sync now (manual trigger)
- Clear auth cache
- View dead-letter entries

**Checklist:**
- [ ] Collect metrics in-memory
- [ ] Wails binding `GetSyncStats()`, `ForceSync()`, `ClearAuthCache()`
- [ ] React page với React Query auto-refresh 5s
- [ ] Style theo `@godxjp/ui`
- [ ] Test
- [ ] Commit

---

## Task 9 — Documentation + Swagger

**Files:**
- Modify: `internal/handler/openapi-workstation.yaml`
- Create: `docs/LOCAL_REPLICA.md`
- Modify: `docs/LOCAL_SERVER.md`

**Mô tả:**

Document:
- Local replica architecture (ownership matrix)
- Sync rules (UP/DOWN, polling intervals)
- Endpoint catalog (local vs forwarded)
- WS event handling
- Schema mapping (Cloud ↔ workstation)

**Checklist:**
- [ ] Viết `docs/LOCAL_REPLICA.md`
- [ ] Update Swagger với local endpoints
- [ ] Update `docs/LOCAL_SERVER.md` với WS event taxonomy
- [ ] Commit

---

## Task 10 — Integration test E2E

**Files:**
- Create: `internal/handler/local_replica_e2e_test.go`

**Scenarios:**

1. **Pair flow (forward Cloud)**: kiosk gọi POST `/devices/pair` qua workstation → forward Cloud → nhận token

2. **Auth cache happy path**: kiosk gọi `/kiosk/me` qua workstation 5 lần liên tiếp → chỉ 1 lần hit Cloud (lần đầu), 4 lần sau cache hit

3. **Cloud down + auth cache stale**: cache 1 token → tắt Cloud → kiosk vẫn gọi `/kiosk/me` được với header `X-Auth-Stale: true`

4. **Payment offline**: tắt Cloud → kiosk POST `/payments` → workstation INSERT local + queue → trả response 200 ngay; bật Cloud → trong 10s payment có ở Cloud DB

5. **Menu real-time**: Cloud push WS event `menu.updated` → workstation pull menu → broadcast WS xuống LAN client → kiosk thấy menu mới

6. **Device revoked**: HQ revoke device trên Cloud → WS event → workstation xóa cache → kiosk request tiếp theo bị 401

7. **Tables sync DOWN**: sửa table.name trên Cloud → trong 60s workstation cập nhật → kiosk thấy tên mới

**Checklist:**
- [ ] Setup test harness (mock Cloud server)
- [ ] 7 scenarios chạy xanh
- [ ] CI hook
- [ ] Commit

---

## Definition of Done

- [ ] 10 task có commit riêng
- [ ] `make test` pass
- [ ] Wails UI dashboard hiển thị đúng metrics
- [ ] Demo: kiosk thực tế gọi qua workstation, fail-over khi Cloud down
- [ ] Document bàn giao cho team Kiosk start [02](02-kiosk-integration.md)

## Out of scope (defer Phase 2)

- POS-web endpoints (shops/orders với items, merge, split, coupon)
- Customer find-or-create
- Payment methods sync (kiosk không cần list, chỉ dùng method từ payment request)
- Refund
- Push order create (Phase 1 chỉ push payment)
- Backend sync endpoint chuyên dụng (Phase 2 cân nhắc nếu Option A quá nặng)
