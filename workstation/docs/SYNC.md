# Sync Engine

## Overview

Sync Engine dam bao du lieu giua ws-app (local) va Omnify Cloud (remote) luon dong bo.
Thiet ke theo nguyen tac **offline-first**: moi thao tac luu local truoc, sync len cloud sau.

## Online/Offline Detection

### Monitor
- HTTP HEAD toi cloud server endpoint moi 10 giay
- Neu that bai: chuyen sang offline mode
- Exponential backoff khi offline: 10s -> 20s -> 40s -> 80s -> max 300s
- Khi online lai: trigger full sync cycle

### Status Events
```
online  -> tat ca tinh nang hoat dong binh thuong + sync active
offline -> tat ca tinh nang van hoat dong, chi khong sync
syncing -> dang dong bo du lieu
error   -> sync gap loi (hien thi cho user)
```

## Outbound Sync (Local -> Cloud)

### Sync UP Resources

| Resource | Operation | Cadence | Cloud endpoint | Handler | Notes |
|---|---|---|---|---|---|
| `order` | `create` | event-driven | PATCH /api/v1/workstation/orders | (TBD) | POS staff creates order |
| `payment` | `create` | event-driven | POST /api/v1/workstation/orders/{o}/payment | (TBD) | Payment recorded locally |
| `customer_order_item` | `update_status` | event-driven (KDS bump trigger) | PATCH /api/v1/workstation/orders/{o}/items/{i}/status | sync_kds_handler.go | KDS staff bumps item status |

### Queue-based Architecture

```
User Action
    |
    v
Business Logic (Order Engine, etc.)
    |
    +---> SQLite (immediate write)
    +---> sync_queue table (enqueue operation)
    
Background Goroutine (khi online):
    sync_queue (FIFO by created_at, priority DESC)
    |
    +---> Serialize payload
    +---> Call Omnify Cloud API
    +---> On success: set synced_at
    +---> On failure: increment attempts, set last_error
    +---> After max_attempts: flag for manual review
```

### Queue Processing Rules

1. Process FIFO (oldest first), high priority first
2. One operation at a time (sequential, khong parallel)
3. Retry voi exponential backoff: 1s -> 2s -> 4s -> 8s -> 16s
4. Max 5 attempts per operation
5. Batch khong qua 50 operations per sync cycle
6. Payments co priority = 1 (xu ly truoc)

### Idempotency

- Moi operation co `idempotency_key` (UUID)
- Cloud server dung key nay de chong duplicate
- Neu cloud tra ve 409 Conflict -> mark as synced (da xu ly roi)

## Inbound Sync (Cloud -> Local)

### Sync DOWN Resources

| Resource | Cadence | Cloud endpoint | Local write target | Notes |
|---|---|---|---|---|
| Menu items | 60s tick | GET /api/v1/workstation/menu/changes | menu_items table | Incremental pull, UPSERT on local |
| Branch info | 5min + on login | GET /api/v1/workstation/branch | (in-memory cache) | Organization, staff, settings |
| `customer_orders` + items | 5s tick | GET /api/v1/workstation/orders?updated_since=<cursor> | orders + order_items tables (post-Sprint-4 aligned legacy) | Cloud recovery; pulls any order updated after cursor |

### Pull Strategy

```
On reconnect / periodic (moi 60 giay khi online):
    |
    v
GET /api/sync?since={last_sync_at}&branch_id={branch_id}
    |
    v
Response: {
    menu_items: [...],      // Updated/new items
    deleted_menu_items: [], // IDs da xoa
    organization: {...},    // Thong tin chi nhanh
    server_time: "..."      // Thoi gian server
}
    |
    v
Apply changes to SQLite:
    - menu_items: UPSERT (INSERT OR REPLACE)
    - deleted items: UPDATE is_active = 0
    - Update last_sync_at = server_time
```

### Sync Priority

| Data | Direction | Frequency | Strategy |
|------|-----------|-----------|----------|
| Menu items | Cloud -> Local | Moi 60s + on reconnect | Cloud wins, full replace |
| Orders | Local -> Cloud | Real-time (queue drain) | Local tao, cloud nhan |
| Payments | Local -> Cloud | Real-time (high priority) | Local tao, idempotent push |
| Order item status bumps (KDS) | Local -> Cloud | Event-driven | KDS staff bumps locally, syncs immediately |
| Org/Branch info | Cloud -> Local | On login + moi 5 phut | Cloud wins |
| Staff list | Cloud -> Local | On login + moi 5 phut | Cloud wins |

## 409 Revert Path on Sync UP

When cloud returns 409 Conflict during KDS item-status sync (e.g., item was voided concurrently):

1. Workstation reverts local `order_items.status` to `previous_status` (captured before the bump)
2. Rolls back `updated_at` to `original_updated_at` (so pull-DOWN cursor still catches genuinely-newer cloud changes)
3. Broadcasts corrective WS event `order_item.status_changed` with:
   - `source: "revert"`
   - `reason: "sync_conflict"`
   - `status` set to the reverted value
4. Marks sync_queue entry as terminal (retryable=false) — cloud is authoritative, no retry will help

This ensures KDS clients see accurate state without manual intervention.

## Pull-DOWN Cursor Management

Workstation maintains a cursor for cloud order recovery:

- **Cursor key**: `sync.customer_orders.last_pulled` in settings table
- **First pull**: Empty cursor → cloud returns last 30 days (default)
- **Subsequent pulls**: Cursor advances to `max(updated_at)` of returned orders
- **Status change during upsert**: If pull-DOWN detects that an order item's status changed from cloud's value, broadcasts WS event `order_item.status_changed` with `source: "pull_down"` so KDS clients (and POS) see the change in real time

Example cursor progression:
```
First:  no cursor    → cloud returns orders updated since (now - 30 days)
Pull 1: cursor=2026-04-01T10:00:00Z → only orders updated after 2026-04-01T10:00:00Z
Pull 2: cursor=2026-04-01T11:30:00Z → only orders updated after 2026-04-01T11:30:00Z
```

## Conflict Resolution

### Menu Items: Cloud Always Wins
- Menu chi duoc chinh sua tren cloud (admin panel)
- ws-app chi doc va cache
- Khi sync: `INSERT OR REPLACE` tat ca menu items tu cloud
- Items xoa tren cloud: set `is_active = 0` local

### Orders: Last-Write-Wins
- Truong hop xung dot rat hiem (1 workstation / 1 quan)
- Neu xay ra: so sanh `updated_at`, moi hon thang
- Cloud gan `server_updated_at` khi nhan order
- Log conflict de review sau

### Payments: Never Overwrite
- Payment da ghi local khong bao gio bi ghi de
- Push len cloud voi idempotency key
- Cloud de-duplicate bang idempotency key
- Neu cloud cung co payment cho order do: log warning

### Soft Deletes
- Khong bao gio DELETE record
- Orders: `status = 'cancelled'`
- Menu items: `is_active = 0`
- Tranh xung dot delete-vs-update

## Authentication

### Token Management
- Reuse pattern tu `godx/internal/auth`:
  - Login qua Omnify SSO
  - Luu access_token + refresh_token trong `~/.ws-app/credentials`
  - Auto-refresh token khi gan het han
  - Khi token het han va offline: van hoat dong (chi khong sync)

### API Headers
```
Authorization: Bearer {access_token}
X-Branch-ID: {branch_id}
X-Device-ID: {device_uuid}
X-App-Version: {ws_app_version}
```

#### `X-App-Version` — hop dong lien repo (#2123 tang D)

Go **phat**, Laravel **tieu thu**. Ai sua mot dau phai doc muc nay.

| Ben | Noi | Hanh vi |
|---|---|---|
| Go | `internal/cloudhttp/version_transport.go` | Boc `http.DefaultTransport` MOT lan luc khoi dong (`cmd/*/main.go`, SAU khi mo DB). Gan `X-App-Version: config.Version`. |
| Laravel | `AuthenticateDevice` · `AuthenticateSsoOrDevice` | Header **tuy chon** — client cu khong gui van hop le. Cloud trim + chan 64 ky tu, roi lam moi `devices.device_info.app_version` cua thiet bi ma token dinh danh (`source = heartbeat`). |

**Cong la "token CUA CHINH MAY TRAM", khong phai "co Authorization".**
May tram chuyen tiep token cua thiet bi KHAC thuong xuyen — `CloudVerifier` xac
thuc kiosk/kds/tms/pos-web bang cach gui token cua chinh client do toi
`GET /api/v1/devices/me`. Gan header len nhung request do se ghi phien ban cua
may tram vao hang cua kiosk, o muc tin cay cao nhat, de vinh vien len gia tri
`pairing` that. Nen wrapper so token voi token cua chinh may tram
(`settings.device_token`) va **fail-closed**: chua pair / khong khop ⇒ khong gan.

**Quy uoc bi cuong che bang test** (`internal/cloudhttp/no_custom_transport_test.go`):

1. **Khong duoc dat `Transport:` rieng ngoai package `cloudhttp`.** Wrapper chi
   phu client roi ve `http.DefaultTransport`; mot Transport rieng am tham cat
   client do khoi header, va chi bao chi **dem thieu** dung phan luu luong do.
   Can that thi boc `versionHeaderTransport{base: ...}` va them file vao danh
   sach mien tru NGAY TRONG bai test, kem ly do.
2. **Moi `cmd/*/main.go` phai goi `cloudhttp.InstallVersionHeader(...)`.**

Khong phu (co y): `websocket.Dialer` khong di qua `http.DefaultTransport`;
`POST /devices/pair` chua co token — phien ban luc pair da di trong payload
`device_info.app_version`.

## Error Handling

| Error | Action |
|-------|--------|
| Network timeout | Retry voi backoff |
| 401 Unauthorized | Refresh token, neu fail -> prompt re-login |
| 409 Conflict | Mark as synced (cloud da co data) |
| 422 Validation error | Log error, skip operation, alert user |
| 500 Server error | Retry voi backoff |
| Max retries exceeded | Flag operation, show in Sync page |

## Monitoring

### Sync Status UI (Sync page)
- Trang thai hien tai: online/offline/syncing
- So operations trong queue
- Thoi diem sync cuoi cung
- Danh sach operations that bai (cho manual retry)
- Sync history log (recent 100 operations)
