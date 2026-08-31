---
title: Notifications API
category: reference
tags: [notifications, api, inbox, bell, audit, audiences, templates, routing, preferences, broadcast, reverb, plan-008, plan-012]
summary: Endpoint reference for the notification platform — own-inbox, HQ audit, audience CRUD + preview, template CRUD + render, channel-route matrix, user preferences, broadcast composer, and per-brand Reverb config + rotation. Covers filters, responses, authorization, and error codes.
related: [notifications]
---

# Notifications API

Reference for the notification HTTP surface shipped in plan-008 (own-inbox + HQ audit) plus plan-012 (audiences, templates, channel routes, preferences, broadcast composer, Reverb config). All routes require Sanctum authentication. Conceptual overview and rationale are in `docs/explanation/notifications.md`.

## Endpoint inventory

### Own inbox (`/api/v1/me/notifications/*`)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/me/notifications` | Paginated list of the authenticated user's notifications |
| GET | `/me/notifications/summary` | Unread count + priority breakdown for the bell badge |
| PATCH | `/me/notifications/{id}/seen` | Mark a single notification as seen |
| PATCH | `/me/notifications/{id}/read` | Mark a single notification as read (implies seen) |
| PATCH | `/me/notifications/{id}/dismissed` | Archive a single notification |
| POST | `/me/notifications/bulk-read` | Mark many / all unread as read |
| POST | `/me/notifications/bulk-dismiss` | Dismiss many / all / all-read |

### HQ audit (`/api/v1/hq/{brandSlug}/notifications/*`)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/hq/{brandSlug}/notifications` | Brand-scoped audit list |
| GET | `/hq/{brandSlug}/notifications/{id}` | Detail with full fan-out |
| GET | `/hq/{brandSlug}/notifications/types` | Distinct types in use, with counts |
| DELETE | `/hq/{brandSlug}/notifications/{id}` | Cancel a scheduled notification (plan-012) |

### HQ audiences (`/api/v1/hq/{brandSlug}/notifications/audiences/*`)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/hq/{brandSlug}/notifications/audiences` | List saved audiences for this brand |
| POST | `/hq/{brandSlug}/notifications/audiences` | Create |
| GET | `/hq/{brandSlug}/notifications/audiences/{id}` | Detail |
| PATCH | `/hq/{brandSlug}/notifications/audiences/{id}` | Update (rule, name, description) |
| DELETE | `/hq/{brandSlug}/notifications/audiences/{id}` | Delete (disallowed when `is_system=true`) |
| POST | `/hq/{brandSlug}/notifications/audiences/preview` | Resolve a rule to `{count, sample}` without saving (throttled 10/min) |

### HQ templates (`/api/v1/hq/{brandSlug}/notifications/templates/*`)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/hq/{brandSlug}/notifications/templates` | List templates visible to this brand |
| POST | `/hq/{brandSlug}/notifications/templates` | Create custom template |
| PATCH | `/hq/{brandSlug}/notifications/templates/{id}` | Update (content per locale, default_channels, params_schema) |
| DELETE | `/hq/{brandSlug}/notifications/templates/{id}` | Delete (disallowed when `is_system=true`) |
| POST | `/hq/{brandSlug}/notifications/templates/render` | Render inline content + params to `{ja, en, vi}` preview without saving |

### HQ channel routes (`/api/v1/hq/{brandSlug}/notifications/channel-routes/*`)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/hq/{brandSlug}/notifications/channel-routes` | Full routing matrix: per `type`, the default channels + priority overrides |
| PUT | `/hq/{brandSlug}/notifications/channel-routes` | Upsert the whole matrix atomically |
| DELETE | `/hq/{brandSlug}/notifications/channel-routes/{type}` | Remove a single type row (falls back to implicit defaults) |

### HQ broadcast composer

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/hq/{brandSlug}/notifications/broadcast` | Resolve audience + render template + dispatch (immediate or scheduled) |

### HQ brand Reverb settings

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/hq/{brandSlug}/settings/reverb/rotate` | Regenerate this brand's Reverb app key + secret (app_id preserved) |

### Own preferences (`/api/v1/me/notification-preferences/*`)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/me/notification-preferences` | Full preference payload: master_mute, quiet_hours, per-(type × channel) matrix |
| PUT | `/me/notification-preferences/master-mute` | Toggle master mute (urgent bypasses this) |
| PUT | `/me/notification-preferences/quiet-hours` | Set quiet window + timezone |
| PUT | `/me/notification-preferences/{type}/{channel}` | Upsert a single (type, channel) toggle |

### Own Reverb config

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/me/reverb-config?brand={slug}` | Resolve Reverb app_key + host + port + scheme for the given brand |
| GET | `/me/reverb-config?shop={slug}` | Same, resolved via the shop's brand |
| GET | `/me/reverb-config` | No-brand fallback — bootstrap creds (dev) or null per-field (prod without brand context) |

Swagger JSON: `/api/auth/documentation` (own-inbox + preferences) and `/api/hq/documentation` (HQ audit + audiences + templates + routes + broadcast + Reverb rotate) — regenerated by `php artisan l5-swagger:generate --all`.

## Detail — own inbox

### `GET /me/notifications`

**Query parameters**

| Field | Type | Notes |
|-------|------|-------|
| `status` | `unread` \| `read` \| `all` | Default `all` |
| `type` | string | Filter by notification type |
| `priority` | `low` \| `normal` \| `high` \| `urgent` | |
| `since` | ISO 8601 datetime | `created_at >=` |
| `per_page` | integer | Default 25, max 100 |
| `page` | integer | Default 1 |
| `include_dismissed` | boolean | Default false — dismissed rows hidden |

**Success response (200)**

```json
{
  "data": [
    {
      "id": "019daa4f-...",
      "type": "recipe.approved",
      "template_key": "recipe.approved",
      "params": { "recipe_name": "Gyoza", "approver": "Tanaka" },
      "priority": "normal",
      "actor": { "type": "User", "id": "019daa...", "display_name": "Tanaka" },
      "subject": { "type": "Recipe", "id": "019daa...", "display_name": "Gyoza" },
      "aggregation_key": null,
      "created_at": "2026-04-20T10:12:00+00:00",
      "recipient": {
        "seen_at": null,
        "read_at": null,
        "dismissed_at": null
      }
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 1 }
}
```

`actor` and `subject` are `null` when the polymorphic pair is both-null (system events, rows without a subject).

**Error responses**

| Status | Code | When |
|--------|------|------|
| 401 | `unauthenticated` | No Sanctum token |
| 422 | `validation_error` | Invalid `status` / `priority` enum, `per_page` > 100 |

### `GET /me/notifications/summary`

**Success response (200)**

```json
{
  "data": {
    "unread_count": 7,
    "priority_breakdown": { "low": 1, "normal": 4, "high": 2, "urgent": 0 },
    "latest_created_at": "2026-04-20T10:12:00+00:00"
  }
}
```

### `PATCH /me/notifications/{id}/seen` · `/read` · `/dismissed`

**Success response:** 204 No Content.

**Idempotency:** the second call with the same id is a no-op — the first timestamp is preserved. `read` also sets `seen_at` when it is still null (read implies seen).

**Error responses**

| Status | Code | When |
|--------|------|------|
| 404 | `not_found` | Either the notification does not exist OR the caller is not a recipient of it (existence-hidden) |
| 401 | `unauthenticated` | |

### `POST /me/notifications/bulk-read`

**Request body**

| Field | Type | Notes |
|-------|------|-------|
| `ids` | string[] (UUIDs) | EITHER this OR `all_unread` |
| `all_unread` | boolean | EITHER this OR `ids` |

Exactly one of the two must be supplied — both unset OR both set → 422.

**Success response (200)**

```json
{ "data": { "marked_count": 12 } }
```

**Error responses**

| Status | Code | When |
|--------|------|------|
| 422 | `bulk_operation_mismatch` | Neither ids nor all_unread, OR both |
| 422 | `bulk_id_not_for_caller` | One of the ids is a notification the caller is not a recipient of |

### `POST /me/notifications/bulk-dismiss`

**Request body**

| Field | Type | Notes |
|-------|------|-------|
| `ids` | string[] (UUIDs) | Three-way exclusive with `all_read` and `all` |
| `all_read` | boolean | Dismiss everything already read |
| `all` | boolean | Dismiss everything (including unread) |

Exactly one of the three must be truthy.

**Success response:**

```json
{ "data": { "dismissed_count": 5 } }
```

## Detail — HQ audit

### `GET /hq/{brandSlug}/notifications`

**Authorization:** caller's `console_organization_id` must match the brand's (Phase A gate; tighter role gate pending).

**Query parameters**

| Field | Type | Notes |
|-------|------|-------|
| `type` | string \| string[] | Single or multi-value; accepts `?type[]=recipe.approved&type[]=recipe.rejected` |
| `actor_type` | `User` \| `Device` \| `null` | `null` literal filters system events |
| `actor_id` | UUID | Requires `actor_type` |
| `recipient_type` | `User` \| `Device` | Requires `recipient_id` |
| `recipient_id` | UUID | Requires `recipient_type` |
| `priority` | enum | |
| `from` / `to` | ISO 8601 datetime | `created_at` range |
| `organization_id` | UUID | Narrow within brand's orgs |
| `search` | string | LIKE on type + params JSON |
| `sort` | string | Default `-created_at` (desc); prefix with `-` for descending |
| `per_page` | integer | Max 100 |

**Success response (200)**

```json
{
  "data": [
    {
      "id": "...",
      "type": "stock.alert.low",
      "template_key": "stock.alert.low",
      "params": {...},
      "priority": "normal",
      "actor": null,
      "subject": { "type": "StockAlert", "id": "...", "display_name": null },
      "organization": { "id": "...", "name": "ACME HQ" },
      "aggregation_key": null,
      "created_at": "...",
      "recipients_summary": {
        "total": 5,
        "seen": 3,
        "read": 2,
        "dismissed": 0
      }
    }
  ],
  "meta": {...}
}
```

**Error responses**

| Status | Code | When |
|--------|------|------|
| 401 | `unauthenticated` | |
| 403 | `forbidden` | Caller is SSO but not an admin of this brand |
| 422 | `validation_error` | Invalid filter (unknown priority, malformed date, etc) |

### `GET /hq/{brandSlug}/notifications/{id}`

Full recipients fan-out. 404 if the notification does not belong to this brand's orgs (not 403 — avoids cross-brand existence leak).

### `GET /hq/{brandSlug}/notifications/types`

```json
{
  "data": [
    { "type": "recipe.approved", "count": 142, "last_at": "2026-04-20T10:12:00+00:00" },
    { "type": "stock.alert.low", "count": 88,  "last_at": "..." }
  ]
}
```

Ordered by `count DESC`.

## Detail — audiences

### `POST /hq/{brandSlug}/notifications/audiences/preview`

**Request body**

```json
{
  "rule": {
    "version": 1,
    "combinator": "or",
    "rules": [
      { "type": "role", "role": "warehouse_manager", "scope": {"warehouse_id": "…"} }
    ],
    "exclude": []
  }
}
```

**Success response (200)**

```json
{
  "data": {
    "count": 12,
    "sample": [
      { "type": "User", "id": "019da…", "display_name": "Tanaka" },
      "…up to 10 entries"
    ]
  }
}
```

**Error responses**

| Status | Code | When |
|--------|------|------|
| 422 | `audience_too_large` | Rule resolves above the 10 000-recipient hard cap |
| 422 | `validation_error` | Unknown rule type, missing required scope for role/shop/device resolvers |
| 429 | `throttled` | More than 10 previews in the last minute for this caller |

### Audience CRUD

Standard `index / store / show / update / destroy` shape. `rule` is the same JSON schema as the preview endpoint above. System audiences (`is_system=true`) are **read-only**: both PATCH and DELETE return **403** `system audience rows are read-only` (`NotificationAudienceAdminController`, plain `abort_if` — no machine-readable code).

> **Không phải 422 `cannot_delete_system`.** Mã đó **chưa bao giờ tồn tại** — đo
> 2026-08-07: `grep -rn cannot_delete_system backend/` → zero hit, và `git log -S`
> không có commit nào từng thêm rồi xoá nó. Bảo vệ thật là `abort_if(..., 403)`,
> áp cho **cả update lẫn destroy** (doc cũ chỉ nói DELETE). Client nào bắt 422
> theo bảng này sẽ không bao giờ khớp (#2029).

## Detail — templates

### `POST /hq/{brandSlug}/notifications/templates/render`

**Request body**

```json
{
  "content": {
    "ja": { "title": "在庫警告", "body": "{{item_name}} の在庫不足" },
    "en": { "title": "Stock alert", "body": "Low stock: {{item_name}}" },
    "vi": { "title": "Cảnh báo kho", "body": "Thiếu: {{item_name}}" }
  },
  "params": { "item_name": "Tuna" }
}
```

**Success response (200)**

```json
{
  "data": {
    "ja": { "title": "在庫警告", "body": "Tuna の在庫不足" },
    "en": { "title": "Stock alert", "body": "Low stock: Tuna" },
    "vi": { "title": "Cảnh báo kho", "body": "Thiếu: Tuna" }
  }
}
```

Unknown `{{param}}` tokens render as empty strings — the missing key is logged on the server side but does not fail the response.

### Template CRUD

Standard `index / store / update / destroy` shape. System templates (`is_system=true`) are **read-only**: both PATCH and DELETE return **403** `system template rows are read-only` (`NotificationTemplateAdminController`) — **not** 422 `cannot_delete_system`, which never existed (see the audience section above). `default_channels` is a JSON array of `NotificationChannel` enum values; `params_schema` follows `{ required: string[], optional: string[] }`.

Shop-scoped variants (`ShopNotificationAudienceController` / `ShopNotificationTemplateController`) cannot hit that guard at all — they force `is_system = false` on every row they create.

## Detail — channel routes

### `PUT /hq/{brandSlug}/notifications/channel-routes`

**Request body**

```json
{
  "routes": [
    {
      "type": "stock.alert.low",
      "channels": ["in_app", "email"],
      "priority_overrides": { "urgent": ["in_app", "realtime", "email", "push"] }
    },
    { "type": "order.status_changed", "channels": ["in_app", "realtime"] }
  ]
}
```

Rows with empty `channels` are rejected (use `DELETE /channel-routes/{type}` to intentionally remove a row). Upsert is atomic — the whole matrix replaces the current state.

**Success response (200)** returns the persisted matrix in the same shape.

## Detail — broadcast composer

### `POST /hq/{brandSlug}/notifications/broadcast`

**Request body**

```json
{
  "audience_id": "019da…",
  "template_id": "019da…",
  "channels": ["in_app", "realtime", "email"],
  "priority": "normal",
  "scheduled_for": null,
  "params": { "maintenance_window": "2026-05-01 02:00 JST" }
}
```

- `audience_id` OR `audience_inline` (JSON rule) is required. Same for `template_id` OR `template_inline` (`{ key, content }`).
- `scheduled_for` is an ISO 8601 datetime; when in the future the broadcast is deferred, when `null` it fires immediately.
- `params` populates `{{token}}` substitutions in the template body.

**Success response (201)**

```json
{
  "data": {
    "id": "019da…",
    "type": "maintenance.scheduled",
    "scheduled_for": "2026-05-01T02:00:00+09:00"
  }
}
```

The admin UI redirects to `/hq/{brandSlug}/notifications?detail={id}` on receipt — there is no standalone `[id]` page.

**Error responses**

| Status | Code | When |
|--------|------|------|
| 422 | `audience_empty` | Resolved audience is zero recipients (avoid silent no-op) |
| 422 | `audience_too_large` | > 10 000 recipients |
| 422 | `validation_error` | Missing required pair (audience + template), bad channel enum, bad priority |
| 403 | `forbidden` | Caller lacks `NotificationPolicy::compose($user, $brand)` |

### `DELETE /hq/{brandSlug}/notifications/{id}` — cancel scheduled

Hard-deletes a scheduled notification — cascades to recipients + deliveries. **Only allowed when `scheduled_for > now() + 60s`** (60-second freeze window before the delayed job may already be executing).

**Error responses**

| Status | Code | When |
|--------|------|------|
| 422 | `within_freeze_window` | `scheduled_for` is within 60s of now |
| 422 | `not_scheduled` | Notification is not in a cancellable state (already dispatched) |
| 404 | `not_found` | Notification does not exist OR does not belong to this brand |

## Detail — own preferences

### `GET /me/notification-preferences`

**Success response (200)**

```json
{
  "data": {
    "master_mute": false,
    "quiet_hours": { "from": "22:00", "to": "07:00", "timezone": "Asia/Tokyo" },
    "matrix": [
      { "type": "stock.alert.low", "channels": { "in_app": true, "realtime": true, "email": false, "push": false } },
      { "type": "recipe.approved", "channels": { "in_app": true, "realtime": false, "email": true, "push": false } }
    ]
  }
}
```

### `PUT /me/notification-preferences/{type}/{channel}`

**Request body**

```json
{ "enabled": false }
```

Idempotent. `{type}` matches the pattern `[a-zA-Z0-9._-]+` (route constraint); `{channel}` must be one of `in_app | realtime | email | push`.

## Detail — Reverb config

> **A 200 here does not mean realtime works.** Production runs
> `BROADCAST_CONNECTION=log`, so every `broadcast()` lands in the log file and no
> event ever reaches a socket; the creds this endpoint hands out (and even
> provisions on the fly when the brand has none) point at a server that does not
> exist. What that costs, and what enabling it would take, is in
> [Cloud realtime state](../guide/realtime-broadcast-state.md) (#2565).

### `GET /me/reverb-config`

**Success response (200)**

```json
{
  "data": {
    "app_key": "brand-019da…-key",
    "scheme": "http",
    "host": "127.0.0.1",
    "port": 8080,
    "cluster": "mt1"
  }
}
```

When the caller has no brand context (and no `?brand=` or `?shop=` in the query) and no bootstrap creds are configured, every field is `null` — the client treats this as "realtime unavailable, poll every 30s".

### `POST /hq/{brandSlug}/settings/reverb/rotate`

**Success response (200)**

```json
{
  "data": {
    "reverb_app_id": "brand-019da…",
    "reverb_app_key": "brand-019da…-key-new",
    "reverb_app_secret": "rotated-once-—-never-returned-again"
  }
}
```

`reverb_app_secret` is returned exactly once in the rotation response; subsequent calls do not expose it. Clients connected with the old creds disconnect at next heartbeat and reconnect after re-fetching `/me/reverb-config`.

## Recurring schedules (plan-023 M3)

### `GET /api/v1/hq/{brandSlug}/notifications/schedules`

Paginated list of recurring `NotificationSchedule` rows scoped to the brand.

Query params: `status` (one of `active`/`paused`/`completed`/`cancelled`), `per_page` (default 25, max 100).

### `POST /api/v1/hq/{brandSlug}/notifications/schedules`

Body:

```json
{
  "template_key": "stock.alert.low",
  "audience_id": "<uuid>",
  "channels": ["in_app", "email"],
  "priority": "normal",
  "params": {"warehouse_name": "Tokyo HQ"},
  "rrule": "FREQ=WEEKLY;BYDAY=MO,WE,FR;BYHOUR=9;BYMINUTE=0",
  "timezone": "Asia/Tokyo",
  "starts_at": "2026-05-18T09:00:00+09:00",
  "ends_at": null,
  "occurrences_remaining": null
}
```

`brand_id` is pinned from the URL — never accepted in the body. Invalid RRULE / unknown IANA timezone → 422 with field-level errors.

### `GET /api/v1/hq/{brandSlug}/notifications/schedules/{id}`

Detail row — includes `next_5_occurrences: string[]` (ISO-8601 timestamps) computed via Recurr.

### `PATCH /api/v1/hq/{brandSlug}/notifications/schedules/{id}`

Partial update. If `rrule` / `timezone` / `starts_at` changes, `next_occurrence_at` is recomputed automatically. Terminal schedules (`completed` / `cancelled`) reject mutation with 422.

### `DELETE /api/v1/hq/{brandSlug}/notifications/schedules/{id}`

Cancel (writes `status='cancelled'` + nulls `next_occurrence_at`). Rejects with **422 `within_freeze_window`** if `next_occurrence_at - now() < 60s`.

### `POST /api/v1/hq/{brandSlug}/notifications/schedules/{id}/pause`

Active → paused. Tick worker stops materialising.

### `POST /api/v1/hq/{brandSlug}/notifications/schedules/{id}/resume`

Paused → active. `next_occurrence_at` is recomputed from `now()`.

### `POST /api/v1/hq/{brandSlug}/notifications/schedules/preview-rrule`

Composer step 4 live-preview. Body:

```json
{
  "rrule": "FREQ=WEEKLY;BYDAY=MO,WE,FR;BYHOUR=9",
  "timezone": "Asia/Tokyo",
  "starts_at": "2026-05-18T09:00:00+09:00",
  "count": 5
}
```

Response: `{ "data": { "occurrences": ["2026-05-18T09:00:00+09:00", …] } }`. Throttled `notifications-admin-reads` (120/min/org).

## Email delivery hardening (plan-023 M4)

### `POST /api/v1/webhooks/mail/{provider}` *(public, signature-verified)*

Inbound provider webhook. **Auth is the provider HMAC signature** — no Sanctum.

`{provider}` currently must be `postmark`. Unknown provider → 404 `webhook_unknown_provider`.

Header: `X-Postmark-Signature` (base64 HMAC-SHA1 of the raw body using `POSTMARK_WEBHOOK_SECRET`). Missing → 401 `webhook_signature_missing`. Mismatch → 401 `webhook_signature_mismatch`. Per-event timestamp > 5 min old → 401 `webhook_replay_window_exceeded`.

Success: **202** with `{accepted: N}` — `N` is the count of events queued for async processing. Unknown `RecordType` returns `accepted: 0` (not a rejection — the provider stops retrying without us inventing a delivery state).

Throttle: 120/min global.

### `GET /api/v1/hq/{brandSlug}/notifications/email-suppressions`

Paginated suppression list scoped to the brand's organizations.

Query params: `reason` (one of `hard_bounce`/`spam_complaint`/`subscription_change`/`manual`), `active_only` (boolean, default `true` — hides rows with `un_suppressed_at` set), `per_page` (default 25, max 100).

### `POST /api/v1/hq/{brandSlug}/notifications/email-suppressions`

Manually pre-block an address. Body:

```json
{ "email": "blocked@example.com", "reason": "manual" }
```

Email is normalised lower-case at write. `reason` defaults to `manual`. Returns 201 with the created row. Idempotent on `(org, email, reason)` — re-posting the same combo refreshes `suppressed_at`.

### `DELETE /api/v1/hq/{brandSlug}/notifications/email-suppressions/{id}`

"Un-suppress" — writes `un_suppressed_at` rather than deleting the row, preserving audit trail. EmailChannel filters by `un_suppressed_at IS NULL`, so the address can receive mail again starting on the next send. Returns 204.

## Notes

- **Morph aliases are TitleCase** (`User`, `Device`, `Recipe`, `CustomerOrder`, `StockAlert`) — not class paths and not snake_case. Enforced via `Relation::enforceMorphMap` in `OmnifyServiceProvider::boot`.
- **Idempotency key** on the dispatch side is internal to the service (see `docs/contributing/emitting-notifications.md`) — not exposed as an API parameter. Recurring schedules use `schedule:{id}:occurrence:{iso8601}`.
- **Rate limits:** audience preview throttled at 10/min per caller; HQ admin mutations at `notifications-admin-mutations` (60/min/org); reads at `notifications-admin-reads` (120/min/org); mail webhook at 120/min global. Email delivery uses the dedicated `notifications-email` queue so SMTP throttling isolates there.
- **Plan-023 M1:** `NOTIFICATION_USE_AUDIENCE` env var + cap-N fallback path were **removed**. The 3 Phase A emitters now dispatch unconditionally through `NotificationDispatcher` (#1622) — they no longer construct an `Audience` themselves, and `scopedTo(Model)` is gone (#1568, replaced by `scopedToKey(key, id)`). The `notifications:audit-rollout` console command and the frozen `LegacyRecipientResolver` it diffed against were removed in #2413, once production showed every remaining divergence was the old resolver ignoring branch scope.
  ⚠️ Lệnh đó in `resolved_pre` / `resolved_post` = **hai đường phân giải** (legacy resolver vs engine Audience), KHÔNG phải "đã gửi cho ai". `diff_pct = -100` nghĩa là engine mới không tìm ra ai, không phải "không ai được thông báo".

## Workflow rule engine (plan-023 M7)

### `GET /api/v1/hq/{brandSlug}/notifications/rules/field-options?model={alias}`

Introspect an Eloquent model for the rule editor FieldPicker. Returns
the columns + relations admins can target in a rule condition.

Whitelist: `{alias}` MUST be present in `Relation::morphMap()`.
Unmapped → 404 with `error: "model_unmapped"`.

**Success response (200)**

```json
{
  "data": {
    "model": "Recipe",
    "fields": [
      {
        "name": "approval_status",
        "type": "string",
        "ops_supported": ["=", "!=", "is_null", "is_not_null",
                           "changed", "changed_to", "changed_from",
                           "matches", "in", "not_in"]
      }
    ],
    "relations": [
      {"name": "brand", "target_alias": "Brand", "type": "belongsTo"}
    ]
  }
}
```

Throttle: `notifications-admin-reads` (120/min/org).

### Console — `notifications:rule-shadow-compare`

```sh
php artisan notifications:rule-shadow-compare --since=14d --output=storage/app/shadow-parity.csv
```

Diffs hardcoded Phase A emitter output against shadow firings per
(emitter, rule, trigger_id). Exit code 0 means parity; non-zero means
drift detected (do NOT flip `NOTIFICATION_USE_RULES`).

CSV columns: `emitter, rule_id, trigger_id, hardcoded_count, shadow_count, match, drift_reason`.

### Other rule endpoints (CRUD + dry-run)

Deferred to a separate PR — plan-023 M7 T7.5. The
schema + evaluator + bridge + job + seeder already ship in this
plan; the admin-facing CRUD ride along once the FE rule editor
lands together.
