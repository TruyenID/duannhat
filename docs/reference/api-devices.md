---
title: Devices Shared Infrastructure API
category: reference
tags: [device, auth, reverb, broadcasting]
summary: 3 shared endpoints that accept any active device type (kiosk, tms, workstation, kds) — neutral identity verify, Reverb config bootstrap, broadcast channel auth. Replaces the previous "use /kiosk/me hack to verify any device" pattern.
related: [device-management, api-kiosk, kds-domain]
---

# Devices Shared Infrastructure API

Reference doc for the 3 shared device infrastructure endpoints. All routes are mounted under `/api/v1/devices/*` and require **device token** authentication via `device.auth` middleware. These endpoints accept any active device type (kiosk, tms, workstation, kds) — not type-specific.

---

## Overview

Before phase 0 (plan-027), there was no neutral way for any device to verify its own identity + branch. Workaround: Kiosk was the only type with a `/kiosk/me` endpoint, so workstation and other device types had to call `/kiosk/me` with their token — a type mismatch that middleware could have rejected. This was the "hack to verify any device."

These 3 endpoints unblock:
1. **CloudVerifier in workstation-app** — workstation needs to confirm its pairing status and branch on startup (non-blocking health check).
2. **Reverb config bootstrap** — each device-paired Echo client (workstation, future KDS) needs per-brand Reverb credentials to connect to the correct broadcast cluster.
3. **Broadcast channel auth** — device-paired clients need to authenticate private Reverb channels (e.g. `private-device.{id}.notifications`); kiosk WebSocket migration (RFC for phase 1) will use this endpoint to subscribe.

---

## Endpoints

| Method | Path | Purpose | Auth |
|--------|------|---------|------|
| GET | `/devices/me` | Verify device identity + branch | device token |
| GET | `/devices/reverb-config` | Reverb cluster config + credentials | device token |
| POST | `/devices/broadcasting/auth` | Pusher-protocol channel auth | device token |

---

## GET `/api/v1/devices/me`

Neutral device identity verification. Accepts any active device type. Returns device info + branch (token stripped).

### Auth

```
Authorization: Bearer {device_token}
```

Middleware `device.auth` validates:
- Token is valid (not revoked, not expired)
- `device.status = active` (not `inactive` or `disabled`)
- Accepts any device type (no type check — type-agnostic)
- Updates `last_seen_at` heartbeat

### Response `200`

```json
{
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Workstation-Shibuya-1",
    "type": "workstation",
    "status": "active",
    "pairing_code": null,
    "pairing_expires_at": null,
    "paired_at": "2026-04-15T09:30:00+00:00",
    "last_seen_at": "2026-05-26T14:22:15+00:00",
    "device_info": {
      "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
      "os": "macOS 14.5",
      "model": "Mac Mini",
      "brand": "Apple",
      "app_version": "1.0.0"
    },
    "notes": null,
    "organization_id": "330e8400-e29b-41d4-a716-446655440005",
    "organization": {
      "id": "330e8400-e29b-41d4-a716-446655440005",
      "name": "Famgia Co., Ltd."
    },
    "branch_id": "660e8400-e29b-41d4-a716-446655440001",
    "branch": {
      "id": "660e8400-e29b-41d4-a716-446655440001",
      "name": "Shibuya"
    },
    "created_at": "2026-04-15T09:00:00+00:00",
    "updated_at": "2026-05-26T14:22:15+00:00",
    "deleted_at": null
  }
}
```

### Field notes

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Device primary key |
| `name` | string | Human-friendly device label (set during pairing) |
| `type` | string | One of `kiosk`, `tms`, `workstation`, `kds` |
| `status` | string | `active`, `inactive`, or `disabled` |
| `pairing_code` | string or null | 6-digit pairing code; cleared after successful pairing |
| `pairing_expires_at` | ISO 8601 or null | Expiration time for current pairing code; cleared after successful pairing |
| `paired_at` | ISO 8601 | When device was first paired |
| `last_seen_at` | ISO 8601 | Last time device called any protected endpoint (updated on each request) |
| `device_info` | object | Device metadata (captured at pairing) |
| `notes` | string or null | Optional notes (typically null) |
| `organization_id` | UUID | FK to organization (company/franchise) |
| `organization` | object | Organization detail (eager-loaded if relation loaded; otherwise only `organization_id` present) |
| `branch_id` | UUID | FK to branch (restaurant location) |
| `branch` | object | Branch detail (eager-loaded if relation loaded; otherwise only `branch_id` present) |
| `created_at` | ISO 8601 | Device creation timestamp |
| `updated_at` | ISO 8601 | Last update timestamp |
| `deleted_at` | ISO 8601 or null | Soft-delete timestamp; null if not deleted |

> **Note on relations:** `organization` and `branch` are eager-loaded as nested objects when the relation is loaded via Eloquent; otherwise only the `_id` fields are present. `device_token` is NEVER exposed in this endpoint's response — it is stripped by `DeviceResource::toArray()` to prevent accidental token leakage via logs or screens.

### Errors

| Status | When |
|--------|------|
| 401 | Missing token header or token invalid (expired, revoked, non-existent) |
| 401 | Device status is not `active` |

---

## GET `/api/v1/devices/reverb-config`

Per-device Reverb cluster config and public credentials. Used by device-paired Echo clients (workstation, future KDS) to bootstrap the correct Reverb connection.

The endpoint traces: device → branch → console_brand_id → brand → `reverb_app_key`, then assembles the cluster hostname/port/scheme from environment or config.

### Auth

```
Authorization: Bearer {device_token}
```

Same `device.auth` middleware as `/me`.

### Response `200`

```json
{
  "data": {
    "app_key": "reverb-0a1b2c3d4e5f6g7h8i9j0k1l2m",
    "cluster": "mt1",
    "host": "localhost",
    "port": 8080,
    "scheme": "http"
  }
}
```

### Field notes

| Field | Type | Notes |
|-------|------|-------|
| `app_key` | string | Reverb public app key (from `Brand.reverb_app_key`) |
| `cluster` | string | Reverb cluster name (default `mt1`, can override via `REVERB_CLUSTER` env) |
| `host` | string | Reverb server hostname. Resolves from `REVERB_CLIENT_HOST` env, fallback to config, fallback to `localhost`. |
| `port` | int | Reverb server port. Resolves from `REVERB_CLIENT_PORT` env, fallback to config, fallback to `8080`. |
| `scheme` | string | Protocol scheme (`http` or `wss`). Resolves from `REVERB_CLIENT_SCHEME` env, fallback to config, fallback to `http`. |

**Environment resolution priority:**
1. `REVERB_CLIENT_HOST`, `REVERB_CLIENT_PORT`, `REVERB_CLIENT_SCHEME` env vars
2. `config/broadcasting.connections.reverb.options.*` config keys
3. Hardcoded fallback (`localhost`, `8080`, `http`)

This allows deployment-specific Reverb endpoints (e.g. production Reverb cluster at `wss://reverb.example.com:443`) without rebuilding the app.

### Errors

| Status | When |
|--------|------|
| 401 | Missing/invalid device token |
| 422 | Device has no associated branch |
| 422 | Device branch's `console_brand_id` does not map to any `Brand` row with `reverb_app_key` set |

---

## POST `/api/v1/devices/broadcasting/auth`

Pusher-protocol channel authorization for device-paired Reverb clients. Used by kiosk/workstation/KDS to subscribe to private broadcast channels (e.g. `private-device.{device_id}.notifications`).

This endpoint is the device-token equivalent of Laravel's default `/broadcasting/auth` (which is gated by `sso.auth` and only accepts SSO user tokens).

### Auth

```
Authorization: Bearer {device_token}
```

Same `device.auth` middleware.

### Request body

```json
{
  "channel_name": "private-device.550e8400-e29b-41d4-a716-446655440000.notifications",
  "socket_id": "12345.67890"
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `channel_name` | string | yes | Pusher-protocol channel name (may be prefixed with `private-`, `presence-`, or `private-encrypted-`). |
| `socket_id` | string | yes | Unique socket identifier (assigned by Reverb client on connect). Used to prevent echoing back to sender. |

### Channel authorization flow

The endpoint walks the registered channel authorization callbacks in `routes/channels.php`. Each callback pattern receives the authenticated Device as the first argument:

```php
// routes/channels.php
Broadcast::channel('device.{id}.notifications', function (Device $device, string $id) {
    return (string)$device->id === $id;  // only the device itself can subscribe
});
```

If the callback returns:
- **`true`** — channel subscription authorized. Endpoint signs and returns auth token.
- **`false`** — authorization denied. Endpoint returns `403 Forbidden`.
- **`null` / falsy** — channel pattern not matched. Walk continues to next pattern.

### Response `200`

```json
{
  "auth": "reverb-0a1b2c3d4e5f6g7h8i9j0k1l2m:8f7e6d5c4b3a2f1e0d9c8b7a6f5e4d3c2b1a0f9e8d7c6b5a4f3e2d1c0b9a8"
}
```

| Field | Type | Notes |
|-------|------|-------|
| `auth` | string | Pusher-protocol auth token in format `{app_key}:{signature}`. Signature is HMAC-SHA256 over `"{socket_id}:{channel_name}"` using the Reverb app secret. |

**Signature generation:**
```
string_to_sign = "{socket_id}:{channel_name}"
signature      = HMAC-SHA256(app_secret, string_to_sign)
auth           = "{app_key}:{signature}"
```

Reverb client validates the signature server-side before establishing subscription.

### Errors

| Status | When |
|--------|------|
| 401 | Missing/invalid device token |
| 403 | `channel_name` or `socket_id` missing/empty |
| 403 | Device not authorized for the requested channel (callback returned false) |
| 403 | No registered callback matches the channel pattern |
| 500 | Reverb app secret or app key is not configured |

---

## Cache and Replay Notes

All 3 endpoints are **idempotent and cache-friendly**:

- **`GET /me`** — no side effects beyond `last_seen_at` update. Safe to cache client-side for 60–300s or on app resume.
- **`GET /reverb-config`** — returns static config (changes only when brand config or env vars change). Safe to cache for the lifetime of the device session or until app restart.
- **`POST /broadcasting/auth`** — stateless sign operation. Safe to replay; same inputs always produce the same signature. Useful for retry logic without worrying about duplicate subscriptions.

No `Idempotency-Key` header is required (unlike payment endpoints) because the operations are inherently side-effect-free or deterministic.

---

## Files and Related Context

| File | Purpose |
|------|---------|
| `routes/api.php` | Route definitions for `/devices/{me, reverb-config, broadcasting/auth}` |
| `app/Http/Controllers/Api/V1/Device/MeController.php` | Neutral device verify |
| `app/Http/Controllers/Api/V1/Device/ReverbConfigController.php` | Reverb config lookup |
| `app/Http/Controllers/Api/V1/Device/BroadcastAuthController.php` | Pusher-protocol auth signing |
| `app/Http/Middleware/AuthenticateDevice.php` | `device.auth` middleware (validates token, loads device, checks status) |
| `app/Models/Device.php` | Device model |
| `app/Http/Resources/DeviceResource.php` | Resource (strips `device_token`) |
| `routes/channels.php` | Channel authorization callbacks (used by broadcasting/auth) |
| `config/broadcasting.php` | Reverb config (cluster, host, port, scheme) |

---

## Phase 1 — Kiosk WebSocket Migration (RFC)

Current kiosk payment status polling (`GET /kiosk/payments/{id}/status` every 3s) will be replaced with Reverb private-channel subscription:

1. Kiosk calls `POST /api/v1/devices/broadcasting/auth` → gets signed socket ID
2. Kiosk subscribes to `private-device.{device_id}.payments` via Reverb WebSocket
3. Backend emits a `PaymentConfirmed` event → Reverb broadcasts to channel
4. Kiosk receives event instantly (~100ms) instead of polling every 3s

This endpoint is infrastructure-ready now; kiosk implementation is pending phase 1 work.

> **`PaymentConfirmed` is a NAME THIS RFC PROPOSES, not an existing class.**
> Đo 2026-08-07: `backend/app/Events/` **không có** file nào tên đó (zero hit
> `grep -rn PaymentConfirmed backend/app backend/tests backend/routes`). Sự kiện
> broadcast có thật gần nhất là **`OrderPaymentRecorded`**
> (`app/Events/OrderPaymentRecorded.php`, `ShouldBroadcastNow`), dispatch từ
> `OrderPaymentService` — phase 1 có thể tái dùng nó thay vì thêm class mới.
> Ghi rõ vì bước 3 viết ở thì hiện tại giữa một RFC, đọc dễ tưởng đã có (#2029).
