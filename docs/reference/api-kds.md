---
title: KDS API
category: reference
tags: [kds, kitchen, orders, broadcasting, realtime, rfc-7807]
summary: Kitchen Display System endpoints — gen-2 operation-oriented commands (mark-preparing/ready/served, revert, bump-all) under /api/v1/kds/*. Returns derived fields server-side (aging_minutes, priority, allowed_transitions). RFC 7807 error catalog with 8 KDS_E0xx codes. Reverb broadcasts OrderItemStatusChanged with idempotency_key for self-echo dedup.
related: [api-devices, api-orders, kds-domain, api-as-boundary]
---

# KDS API

All routes are mounted under `/api/v1/kds/*` and require **device token** authentication (`device.auth:kds` middleware). KDS devices are rate-limited to 120 requests per minute. Per plan-028, the API is **operation-oriented**: each mutation endpoint is a verb (`mark-preparing`, not `PATCH status`), enforces business rules server-side, returns derived fields the client used to compute, and emits RFC 7807 errors with stable codes.

The gen-1 `PATCH /kds/orders/{}/items/{}/status` endpoint stays live with `Deprecation: true` + `Sunset` header for the 2-sprint transition window; new clients MUST use gen-2.

---

## Endpoints at a glance

| Method | Path | Generation | Purpose |
|--------|------|------------|---------|
| GET | `/kds/me` | — | KDS device info + branch + capabilities |
| GET | `/kds/orders` | — | Active orders + aggregate meta (derived fields) |
| POST | `/kds/orders/{order}/items/{item}/mark-preparing` | gen-2 | Advance pending → preparing |
| POST | `/kds/orders/{order}/items/{item}/mark-ready` | gen-2 | Advance preparing → ready |
| POST | `/kds/orders/{order}/items/{item}/mark-served` | gen-2 | Advance ready → served (30s anti-misclick) |
| POST | `/kds/orders/{order}/items/{item}/revert` | gen-2 | Body `{to}` — backward transition |
| POST | `/kds/orders/{order}/bump-all` | gen-2 | Body `{scope}` — bulk advance |
| PATCH | `/kds/orders/{order}/items/{item}/status` | gen-1 deprecated | Sunset 2026-07-12; internally redispatches to gen-2 |

All gen-2 operation endpoints require **`Idempotency-Key`** (HTTP header) — replay returns the cached body, never double-applies the mutation.

---

## GET `/api/v1/kds/me`

Device identity verification + branch context. The token's device must be `type=kds`, status `active`, and not revoked; the middleware updates `last_seen_at`.

### Response 200

`KdsDeviceResource` — hides DB-internal columns (`organization_id`, `console_*_id`, `created_by_id`, `device_token`, `pairing_code*`, raw `branch_id`):

```json
{
  "data": {
    "id": "uuid",
    "type": "kds",
    "name": "Kitchen Tablet 1",
    "status": "active",
    "branch": {
      "id": "uuid",
      "name": "Shibuya Main",
      "code": "SHB-01"
    },
    "paired_at": "2026-05-01T08:00:00Z",
    "last_seen_at": "2026-05-28T03:12:44Z",
    "capabilities": {
      "supports_offline": true,
      "supports_wake_lock": true,
      "supports_audio": true
    }
  }
}
```

---

## GET `/api/v1/kds/orders`

Active orders for the device's branch, scoped to non-final statuses. The list response carries an **aggregate meta block** so the dashboard header can show "{active_count} active · 🔥 {late_count} late" without client-side derivation.

### Query parameters

| Name | Type | Default | Notes |
|------|------|---------|-------|
| `limit` | int | 200 | Max 500 |

### Response 200

```json
{
  "data": [KdsOrderResource, …],
  "meta": KdsAggregateMeta
}
```

#### `KdsOrderResource`

Server-derived fields are authoritative; clients SHOULD NOT recompute aging or priority.

| Field | Type | Source |
|-------|------|--------|
| `id` | uuid | DB |
| `order_code` | string | DB |
| `order_type` | string | DB |
| `guest_count` | int? | DB |
| `note` | string? | DB |
| `opened_at` | ISO8601 | DB |
| `aging_minutes` | int | `now() - opened_at`, clamped >=0 |
| `is_late` | bool | `aging_minutes > 10` |
| `priority` | enum | `normal` (<5min), `warning` (5–9min), `critical` (>=10min) |
| `pending_items_count` | int | filtered, voided excluded |
| `preparing_items_count` | int | — |
| `ready_items_count` | int | — |
| `oldest_pending_age_minutes` | int | max aging of pending items |
| `can_bump_all` | bool | false if order finalized OR no items in pending/preparing |
| `table` | object? | `{ id, code, zone }` or `null` for takeaway |
| `items[]` | `KdsItemResource[]` | voided items filtered out |

#### `KdsItemResource`

| Field | Type | Source |
|-------|------|--------|
| `id` | uuid | DB |
| `menu_item_name` | string | DB |
| `quantity` | int | DB |
| `status` | enum | `pending\|preparing\|ready\|served\|voided` |
| `note` | string? | DB |
| `aging_minutes` | int | `now() - created_at` |
| `time_in_current_status_seconds` | int | COALESCE per status — see §"Aging anchors" |
| `is_blocked_by_toppings` | bool | true when status=preparing AND at least one topping is not ready |
| `allowed_transitions` | string[] | operation names — `["mark-preparing"]`, `["mark-ready", "revert"]`, etc. |
| `started_preparing_at` | ISO8601? | set by mark-preparing (COALESCE first-write-wins) |
| `ready_at` | ISO8601? | set by mark-ready |
| `served_at` | ISO8601? | set by mark-served |
| `toppings[]` | array | `[{name, quantity, status}]` |

##### Aging anchors (`time_in_current_status_seconds`)

Status-specific COALESCE chain. Workstation mirrors this formula exactly so LAN and Cloud display the same values.

```
preparing → started_preparing_at ?? created_at
ready     → ready_at ?? started_preparing_at ?? created_at
served    → served_at ?? ready_at ?? created_at
pending   → created_at
voided    → 0 (status terminal, no anchor)
```

#### `KdsAggregateMeta`

```json
{
  "active_count": 12,
  "late_count": 3,
  "critical_count": 1,
  "avg_age_minutes": 6.4,
  "oldest_pending_item_age_minutes": 18,
  "items_by_status": {"pending": 8, "preparing": 14, "ready": 5, "served": 22},
  "fetched_at": "2026-05-28T03:12:44Z"
}
```

---

## Operation endpoints (gen-2)

All operation endpoints share the same auth, idempotency, business-rule, and broadcast contract. They differ only in target status / revert direction / batch scope.

### Common requirements

- Header `Idempotency-Key: <string>` (required, 422 if missing)
- Cache TTL: 86400s — replay returns the cached 200 body
- Branch ownership enforced (KDS_E006 on mismatch)
- Order finalization enforced (KDS_E001 on voided/closed)
- Per-item throttle: 1 op / 3s / item-device pair (KDS_E005)

### Response shape (single-item ops)

All single-item endpoints respond `200 {"data": KdsItemResource}` on success.

---

### POST `/kds/orders/{order}/items/{item}/mark-preparing`

Advance an item from `pending` → `preparing`. Sets `started_preparing_at` on first transition; idempotency replay returns the original timestamp.

Errors: `KDS_E001` (order finalized), `KDS_E005` (throttled), `KDS_E006` (branch), `KDS_E007` (item).

### POST `/kds/orders/{order}/items/{item}/mark-ready`

Advance `preparing` → `ready`. Toppings parent dependency: if the item has toppings still in pending/preparing, returns `KDS_E004`.

Errors: `KDS_E001`, `KDS_E004`, `KDS_E005`, `KDS_E006`, `KDS_E007`.

### POST `/kds/orders/{order}/items/{item}/mark-served`

Advance `ready` → `served`. Anti-misclick: cloud rejects with `KDS_E003` if `ready_at` is less than 30s ago (kitchen physical reality — a cook can't go from "just plated" to "delivered to customer" in under half a minute).

Errors: `KDS_E001`, `KDS_E002` (item not ready), `KDS_E003`, `KDS_E005`, `KDS_E006`, `KDS_E007`.

### POST `/kds/orders/{order}/items/{item}/revert`

Backward transition. Request body required:

```json
{ "to": "pending" | "preparing" }
```

- `preparing` → revert to `pending` is allowed
- `ready` → revert to `preparing` is allowed
- `served` is terminal — cloud returns `KDS_E002`

Workstation accepts `to` body but currently derives the previous state internally (one-step-back). Tracked in plan-028 follow-ups; both ends still reject revert from served.

Errors: `KDS_E002`, `KDS_E005`, `KDS_E006`, `KDS_E007`.

### POST `/kds/orders/{order}/bump-all`

Bulk advance every item in scope:

```json
{ "scope": "pending" | "preparing" }
```

- `scope=pending` advances every `pending` item to `preparing`
- `scope=preparing` advances every `preparing` item to `ready`

Response is the whole `KdsOrderResource` (refreshed), not a single item.

**Per-item event broadcast.** Cloud dispatches one `OrderItemStatusChanged` event per affected item; each event's `idempotency_key` is derived as `` `${batchKey}:${itemId}` `` from the batch's `Idempotency-Key` header. The godx-kds `useBumpAll` hook pre-records that exact format for self-echo dedup, and the backend test `BumpAllTest::"bump-all broadcasts OrderItemStatusChanged with idempotency_key …"` locks the contract — touch both sides if the format changes.

Errors: `KDS_E001`, `KDS_E006`. Returns 200 with `bumped_count: 0` if no items in scope.

---

## Error catalog (RFC 7807)

All gen-2 endpoints return errors in RFC 7807 shape:

```json
{
  "type": "https://godx-tempo.dev/errors/{slug}",
  "title": "Order is finalized",
  "status": 409,
  "code": "KDS_E001",
  "detail": "Đơn này đã đóng (status=cancelled), không thể bump item.",
  "context": {
    "order_id": "uuid",
    "order_status": "cancelled",
    "finalized_at": "2026-05-28T03:00:00Z"
  },
  "remediation": "Refresh dashboard để load lại trạng thái."
}
```

The single-source catalog lives at `backend/config/kds-errors.php`.

| Code | HTTP | Title | When |
|------|------|-------|------|
| `KDS_E001` | 409 | Order finalized | Mutation against an order in `voided`/`closed`/`refunded` |
| `KDS_E002` | 409 | Invalid status transition | mark-served when not ready, revert from served, etc. |
| `KDS_E003` | 409 | Anti-misclick guard | mark-served less than 30s after mark-ready |
| `KDS_E004` | 409 | Toppings parent not ready | mark-ready while child toppings are still preparing |
| `KDS_E005` | 429 | Throttle exceeded | More than 1 op / 3s / item-device pair |
| `KDS_E006` | 403 | Branch mismatch | Device targets an order in a different branch |
| `KDS_E007` | 404 | Item not found | Item already void/deleted |
| `KDS_E008` | 200 | Idempotency replay | Informational — header on cached replay responses |

### Workstation LAN deviation

Workstation handlers emit a simpler envelope `{"message": "..."}` (per plan-028 DESIGN §6, LAN simplicity). The FE `ApiError` class parses both shapes; the codes-to-UX mapping in `error-toast.ts` falls back to a generic error toast for LAN envelopes that lack a `code` field.

---

## Realtime: Reverb broadcast + self-echo dedup

Each gen-2 mutation dispatches an event after the transaction commits:

- Channel: `private-branch.{branch_id}.kds-events`
- Event name: `order_item.status_changed`
- Payload:

```json
{
  "order_id": "uuid",
  "item_id": "uuid",
  "previous_status": "preparing",
  "status": "ready",
  "served_at": null,
  "voided_at": null,
  "idempotency_key": "the-uuid-the-caller-sent-OR-{batchKey}:{itemId}",
  "occurred_at": "2026-05-28T03:12:44Z"
}
```

**Self-echo dedup.** The originating client pre-records its `idempotency_key` in a short-lived (30s) map before firing the request. When the WS event arrives, RealtimeProvider compares against the map and skips `invalidateQueries` if it matches — the optimistic update already reflects the change. Foreign-device events (unknown key, or missing key) trigger a refetch.

For `bump-all`, the client pre-records `` `${batchKey}:${itemId}` `` for each item it expects to bump (read from QueryClient cache at mutation time). Each per-item broadcast is then dedup'd individually.

---

## Rate limiting

- Per-device: 120 req/min (Laravel throttle middleware)
- Per-item-per-device: 1 op / 3s (KDS_E005)

Throttle errors are silent on the FE (no toast) — they only trigger on anti-double-tap noise, which would be confusing to surface.

---

## Files & context

- Cloud controller: `backend/app/Http/Controllers/Api/V1/Kds/KdsController.php`
- Error catalog: `backend/config/kds-errors.php`
- Business rules: `backend/app/Services/Kds/KdsBusinessRules.php`
- Aggregate meta: `backend/app/Services/Kds/KdsAggregateMeta.php`
- Workstation LAN mirror: `workstation/internal/handler/local_kds_ops.go`
- Workstation OpenAPI: `workstation/api/openapi-workstation.yaml`
- FE consumer: `app/kds/src/services/kds/operations.ts` + 5 mutation hooks
- Design rationale: [`docs/explanation/kds-domain.md`](../explanation/kds-domain.md), [`docs/explanation/api-as-boundary.md`](../explanation/api-as-boundary.md)
- Plan: plan-028 `DESIGN.md` (đã xoá khỏi cây #2188 — git history)
