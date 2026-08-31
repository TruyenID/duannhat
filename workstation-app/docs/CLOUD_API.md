# Workstation Cloud API

> ⚠️ **STALE — early design sketch.** The `/sync/pull`, `/sync/push`,
> `/sync/status`, `/menu/changes`, `/heartbeat`, `/config` endpoints below
> were never implemented. Use this doc only as historical context.
>
> Authoritative current endpoints: `backend/routes/api/workstation.php` +
> the umbrella `CLAUDE.md` "Cloud API" section. The real Sync DOWN lives
> in `internal/service/sync_pull.go` (direct GET against `/menu`, `/branch`,
> `/lots`, plus `/api/v1/tms/zones` + `/tms/tables` on a 60s tick). Sync UP
> flows via per-entry POSTs in `internal/service/sync_service.go` —
> `/workstation/orders`, `/workstation/payments` (PR #289), or legacy
> `/kiosk/payments` when `target` field is absent.

## Overview

ws-app (workstation) giao tiep voi Laravel cloud backend qua REST API tai prefix `/api/v1/workstation/`.
Authentication bang `device_token` (Bearer), nhan duoc tu device pairing flow.

## Authentication

```
Authorization: Bearer {device_token}
```

Middleware `device.auth:workstation` tren Laravel:
- Doc Bearer token -> lookup device by `device_token`
- Validate `status = active` va `type = workstation`
- Set request context: `organization_id`, `branch_id`, `device_id`

## Endpoints

### Pairing (public, no auth)

| Method | Path | Mo ta |
|--------|------|-------|
| POST | `/api/v1/workstation/pair` | Pair device bang pairing code, nhan device_token |

Request:
```json
{
  "pairing_code": "XK9F2A",
  "device_info": { "hostname": "ws-01", "os": "darwin", "arch": "arm64", "app_version": "1.0.0" }
}
```

Response (200):
```json
{
  "device_token": "64-char-token",
  "device": { "id": "uuid", "name": "Shibuya WS 1", "type": "workstation", "branch_id": "uuid" }
}
```

### Sync

| Method | Path | Mo ta |
|--------|------|-------|
| GET | `/api/v1/workstation/sync/pull?since={ISO8601}` | Pull changes tu cloud (menu, tables, branch info). Bo `since` de full sync. |
| POST | `/api/v1/workstation/sync/push` | Batch push local changes (orders, payments) len cloud |
| GET | `/api/v1/workstation/sync/status` | Sync health check + server time |

### Menu

| Method | Path | Mo ta |
|--------|------|-------|
| GET | `/api/v1/workstation/menu` | Full menu cho branch |
| GET | `/api/v1/workstation/menu/changes?since={ISO8601}` | Incremental menu changes |

### Orders

| Method | Path | Mo ta |
|--------|------|-------|
| POST | `/api/v1/workstation/orders` | Tao order tren cloud |
| PUT | `/api/v1/workstation/orders/{id}/status` | Update order status |
| POST | `/api/v1/workstation/orders/{id}/payment` | Record payment |
| GET | `/api/v1/workstation/orders?date={YYYY-MM-DD}` | Orders theo ngay (reconciliation) |

### Branch

| Method | Path | Mo ta |
|--------|------|-------|
| GET | `/api/v1/workstation/branch` | Branch info (name, address, tax, brand) |
| GET | `/api/v1/workstation/branch/staff` | Staff list (future) |

### Device

| Method | Path | Mo ta |
|--------|------|-------|
| POST | `/api/v1/workstation/heartbeat` | Device telemetry (version, memory, uptime) |
| GET | `/api/v1/workstation/config` | Device config from cloud (tax, receipt text, features) |

## Data Flow

### Initial Setup
```
Admin tao device (web) -> pairing_code (6 digit, 15 min)
ws-app nhap code -> POST /api/v1/workstation/pair -> nhan device_token
ws-app -> GET /api/v1/workstation/sync/pull (full) -> luu vao SQLite
Ready.
```

### Normal Operation
```
Tablet -> ws-app /api/orders -> SQLite + sync_queue
Sync engine (5s) -> POST /workstation/sync/push -> mark synced
```

### Offline -> Online
```
Network drop -> orders van luu local
Reconnect -> GET sync/pull?since=last_pull_at -> update local
          -> POST sync/push (batch) -> push queued items
```

### Menu Update
```
Admin update menu (web) -> cloud DB updated
ws-app poll (30s) -> GET menu/changes?since= -> update local menu
```

## Sync Push Format

```json
{
  "operations": [
    {
      "idempotency_key": "uuid",
      "entity_type": "order",
      "entity_id": "local-uuid",
      "operation": "create",
      "payload": { ... },
      "created_at": "ISO8601"
    }
  ]
}
```

Response (partial success):
```json
{
  "data": {
    "server_time": "ISO8601",
    "results": [
      { "idempotency_key": "uuid", "status": "ok", "cloud_id": "uuid" },
      { "idempotency_key": "uuid", "status": "error", "error_code": "...", "error_message": "..." }
    ]
  }
}
```

## Error Handling

| HTTP | Error Code | ws-app Behavior |
|------|-----------|-----------------|
| 401 | UNAUTHENTICATED | Stop sync, show pairing screen |
| 409 | CONFLICT | Mark operation as failed, log for review |
| 422 | Validation | Mark operation as permanently failed |
| 429 | TOO_MANY_REQUESTS | Backoff, respect Retry-After |
| 500 | SERVER_ERROR | Retry with exponential backoff (max 5) |
| 503 | SERVICE_UNAVAILABLE | Queue locally, retry when online |

## Idempotency

- Moi operation co `idempotency_key` (UUID)
- Cloud luu key 72h
- Duplicate key -> tra ve response goc (khong tao duplicate)
- ws-app safe retry khi network fail

## Conflict Resolution

- **Menu**: Cloud luon thang (read-only local)
- **Orders**: ws-app la writer duy nhat, conflict hiem
- **Duplicate order**: Idempotency key chong duplicate
- **Status conflict**: 409 -> pull latest state tu cloud
