# Local Server (LAN API)

## Overview

ws-app chay mot HTTP + WebSocket server tren LAN de tablet, dien thoai, va cac thiet bi khac trong quan co the dat order va nhan real-time updates.

## Server Configuration

- Bind: `0.0.0.0:{port}` (default port: 8080, configurable)
- Khong dung TLS (LAN-only, sau firewall cua quan)
- CORS: cho phep tat ca origins tu private IP ranges

## Security: LAN-Only Middleware

Chi chap nhan connections tu private IP ranges:
- `10.0.0.0/8`
- `172.16.0.0/12`
- `192.168.0.0/16`
- `127.0.0.0/8` (localhost)
- `::1` (IPv6 localhost)

Request tu public IP -> 403 Forbidden.

## REST API Endpoints

### Status
```
GET /api/status
Response: {
    "version": "0.1.0",
    "status": "running",
    "online": true,
    "device_count": 3,
    "active_orders": 5,
    "uptime_seconds": 3600
}
```

### Menu
```
GET /api/menu
Query: ?category=drink&active=true
Response: {
    "items": [
        {
            "id": "uuid",
            "name": "Pho Bo",
            "name_ja": "フォーボー",
            "category": "food",
            "price": 90000,
            "printer_group": "kitchen",
            "is_active": true
        }
    ]
}
```

### Orders
```
GET /api/orders
Query: ?status=open,preparing&table=A1
Response: {
    "orders": [...]
}

GET /api/orders/:id
Response: {
    "order": {
        "id": "uuid",
        "order_number": 42,
        "table_number": "A3",
        "status": "open",
        "items": [...],
        "total": 363000,
        "created_at": "2026-04-11T14:32:00Z"
    }
}

POST /api/orders
Body: {
    "table_number": "A3",
    "customer_count": 2,
    "items": [
        {"menu_item_id": "uuid", "quantity": 2, "notes": "khong hanh"},
        {"menu_item_id": "uuid", "quantity": 1}
    ]
}
Response: 201 Created
{
    "order": {...}
}

PUT /api/orders/:id
Body: {
    "status": "preparing"
}
-- hoac them items:
Body: {
    "add_items": [
        {"menu_item_id": "uuid", "quantity": 1}
    ]
}

POST /api/orders/:id/print
Body: {
    "type": "kitchen"  // kitchen, receipt, all
}
Response: {
    "print_jobs": [
        {"printer": "Kitchen Printer", "status": "sent"}
    ]
}
```

### Devices
```
GET /api/devices
Response: {
    "devices": [
        {
            "id": "uuid",
            "type": "kitchen_printer",
            "name": "May in bep",
            "status": "online",
            "connection_type": "network",
            "address": "192.168.1.100:9100"
        }
    ]
}
```

## WebSocket

### Connection
```
WS /ws
Query: ?subscribe=orders,devices
```

### Message Format
```json
{
    "type": "event_type",
    "payload": { ... },
    "timestamp": "2026-04-11T14:32:00Z"
}
```

### Server -> Client Events

| Event | Payload | Mo ta |
|-------|---------|-------|
| `order_created` | Full order object | Order moi duoc tao |
| `order_updated` | Order with changes | Order duoc cap nhat (status, items) |
| `order_item_status` | Item ID + new status | Trang thai item thay doi |
| `device_connected` | Device info | Thiet bi ket noi |
| `device_disconnected` | Device ID | Thiet bi mat ket noi |
| `print_completed` | Print job result | In xong |
| `print_failed` | Print job + error | In that bai |
| `sync_status` | online/offline/syncing | Trang thai sync thay doi |

### Client -> Server Messages

| Message | Payload | Mo ta |
|---------|---------|-------|
| `ping` | {} | Keep-alive |
| `subscribe` | {"channels": ["orders"]} | Dang ky nhan events |

### Hub Architecture

```go
type Hub struct {
    clients    map[*Client]bool
    broadcast  chan Message
    register   chan *Client
    unregister chan *Client
}

// Mot goroutine chay Hub.Run()
// Clients dang ky/huy dang ky qua channels
// Broadcast gui message toi tat ca clients da subscribe
```

## LAN Discovery

### mDNS Advertisement

ws-app tu dong quang ba tren LAN:
- Service: `_ws-app._tcp.local.`
- Port: 8080 (hoac configured port)
- TXT records:
  - `version=0.1.0`
  - `branch_id=xxx`
  - `store_name=Quan ABC`

### QR Code

Dashboard hien thi QR code chua URL cua Local Server:
```
http://192.168.1.50:8080
```
Nhan vien scan bang dien thoai/tablet de ket noi.

## KDS LAN Endpoints

KDS (Kitchen Display System) endpoints serve kitchen display screens on the LAN. All KDS endpoints require authentication via bearer device token (type="kds", obtained from pairing).

### GET /api/v1/kds/me

Returns the KDS device's identity resolved from auth_token_cache. No DB hit required — device context is already resolved by auth middleware.

```
Response 200:
{
  "data": {
    "id": "device-uuid",
    "type": "kds",
    "branch_id": "branch-uuid"
  }
}

Response 401: Missing/invalid token
Response 503: Device not paired (no cached identity)
```

### GET /api/v1/kds/orders

Returns branch-scoped active orders with embedded items. Reads from post-Sprint-4 legacy `orders` + `order_items` tables (migration 007). KDS staff can view all items being prepared.

Status filter: `open`, `dining`, `checkout`, `paying` (excludes closed/voided).
Limit: 200 orders max per response.

```
Response 200:
{
  "data": [
    {
      "id": "order-uuid",
      "order_code": "T5-001",
      "status": "dining",
      "opened_at": "2026-04-11T14:32:00Z",
      "note": "no onion",
      "guest_count": 2,
      "table_id": "table-uuid",
      "table_number": "T5",
      "items": [
        {
          "id": "item-uuid",
          "product_sku_id": "sku-uuid",
          "menu_item_name": "Margherita Pizza",
          "quantity": 2,
          "unit_price": 1200,
          "subtotal": 2400,
          "note": "extra cheese",
          "status": "preparing",
          "served_at": null,
          "voided_at": null,
          "printer_group": "kitchen"
        }
      ]
    }
  ],
  "meta": {
    "count": 5,
    "fetched_at": "2026-04-11T14:35:00Z"
  }
}

Response 503: orders table empty (cloud-fallback expected)
```

### PATCH /api/v1/kds/orders/{order}/items/{item}/status

Bump kitchen item status (preparing → ready → served). Idempotency-Key header is required for safe replay.

**Valid statuses**: `preparing`, `ready`, `served`

When status transitions to `served`, `served_at` timestamp is set.

```
Request:
PATCH /api/v1/kds/orders/order-uuid/items/item-uuid/status
Idempotency-Key: <uuid>
Authorization: Bearer <token>
Content-Type: application/json

{
  "status": "ready"
}

Response 200:
{
  "data": {
    "id": "item-uuid",
    "status": "ready",
    "served_at": null,
    "updated_at": "2026-04-11T14:36:00Z"
  }
}

Response 404: Order/item not found
Response 422: Invalid status or missing Idempotency-Key header
```

After the item status is bumped:
1. Local `order_items.status` is updated immediately.
2. WebSocket event `order_item.status_changed` is broadcast to all clients on the same branch (`source: "local"`).
3. Sync UP is enqueued to cloud (resource: `customer_order_item`, operation: `update_status`).

**409 Conflict handling**: If cloud returns 409 (e.g., item was voided concurrently), sync handler reverts local `order_items.status` to the previous value and broadcasts a corrective WS event (`source: "revert"`, `reason: "sync_conflict"`). No retry is attempted.

## WebSocket

### Connection and First-Message Auth Protocol

KDS and POS clients connect via WebSocket to `/ws` and must authenticate within 5 seconds by sending the first message with auth payload.

**Handshake sequence:**

1. Client opens WebSocket to `ws://<workstation-ip>:8080/ws`
2. Client sends within 5 seconds:
```json
{
  "type": "auth",
  "payload": {
    "token": "<bearer-device-token>"
  }
}
```
3. Server validates token via `CloudVerifier` (cached in `auth_token_cache` for performance)
4. **Success**: Server replies and binds client context:
```json
{
  "type": "auth_ok",
  "payload": {
    "device_id": "device-uuid",
    "branch_id": "branch-uuid"
  }
}
```
   After this, client receives branch-scoped events via `Hub.BroadcastEventScoped`.

5. **Failure**: Server closes connection with code:
   - `4401` — bad/missing/invalid token
   - `4408` — timeout (no auth message within 5s)

### Events

After auth, clients receive events matching their branch. Relevant events for KDS:

| Event | Payload | Source |
|-------|---------|--------|
| `order_item.status_changed` | `{order_id, item_id, previous_status, status, served_at, idempotency_key, occurred_at, source}` | `"local"` (KDS bump), `"revert"` (409 conflict), or `"pull_down"` (cloud sync detected change) |

## Tablet/Phone Web Client

Phase 2+ se co web client chay tren tablet/phone:
- Progressive Web App (PWA) hoac simple SPA
- Served boi Local Server (`GET /` -> frontend assets)
- Features: xem menu, dat order, xem trang thai order
- Real-time updates qua WebSocket
