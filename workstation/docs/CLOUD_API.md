# Workstation Cloud API

> **This file is a pointer, not a specification.** The endpoint tables that used
> to live here were an early design sketch, and **none of the endpoints they
> described were ever implemented** — `/sync/pull`, `/sync/push`, `/sync/status`,
> `/menu/changes`, `/heartbeat`, `/config`, `/workstation/pair`. They were
> deleted on 2026-07-30 (#1323): a stale banner above a full spec still gets
> copied by anyone skimming.
>
> **Authoritative source of truth: `backend/routes/api/workstation.php`.**

## Where the truth lives

| Question | Read this |
|---|---|
| Which cloud endpoints exist? | `backend/routes/api/workstation.php` (~62 routes under `/api/v1/workstation`) |
| Request/response shape of one endpoint? | The OpenAPI attributes on the matching `App\Http\Controllers\Api\V1\Workstation\*Controller` |
| What this app pulls DOWN, and when? | `internal/service/sync_pull.go` |
| What this app pushes UP, and how it retries? | `internal/service/sync_service.go` |
| Cross-repo summary | umbrella `CLAUDE.md`, "Cloud API" section |

## Authentication

```
Authorization: Bearer {device_token}
```

Every `/api/v1/workstation/*` route sits behind `device.auth:workstation`, which
resolves the bearer token to a device row, requires `status = active` and
`type = workstation`, and puts `organization_id` / `branch_id` / `device_id` on
the request. The group also carries the named `workstation` rate limiter
(300 req/min keyed by **device id**, not client IP — several terminals behind
one branch NAT must not share a bucket).

## Pairing

Pairing is **not** a workstation-namespace route. Cloud exposes one public
endpoint shared by every device type:

```
POST /api/v1/devices/pair
```

The pairing code identifies the device row, and therefore its type, so there is
no `/api/v1/workstation/pair`. This app calls it from
`internal/handler/routes.go`, attaching the freshly rotated Ed25519 `public_key`
so offline-order signing works from the first boot (#1311), and refuses to keep
a token whose device type is not `workstation`.

## Sync shape — how it actually works

There is **no batch push envelope, no `/sync/pull`, no `/menu/changes`, no
`/heartbeat`, and no `/config`.**

- **Pull DOWN** — direct authenticated `GET`s, one per feed, on a tick. Since
  \#1175 the loop first issues one conditional
  `GET /api/v1/workstation/sync-manifest` and re-pulls only the feeds whose
  version moved (`304` when nothing changed). Some feeds are cross-namespace on
  purpose: tables and zones come from `/api/v1/tms/*`, which Cloud owns. The
  full matrix is in `sync_pull.go`.
- **Push UP** — one `POST` per queued entry, each with its own idempotency key,
  with retry and backoff owned by the queue in `sync_service.go`. Payments go to
  `/api/v1/workstation/payments`, or to the legacy `/api/v1/kiosk/payments` when
  a queue entry predates the `target` field.
- **Connectivity** is tracked locally; the workstation reports no heartbeat.
