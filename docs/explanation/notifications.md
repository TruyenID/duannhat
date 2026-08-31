---
title: Notification Platform
category: explanation
tags: [notification, platform, morph, inbox, polymorphic, audience, templates, channels, realtime, broadcast, preferences, reverb, plan-008, plan-012]
summary: Architecture of the notification system — two-table normalized store, polymorphic actor/subject/recipient, audience rule engine, DB-backed templates with locale fallback, multi-channel delivery (in_app / realtime / email / push), per-brand Laravel Reverb, user preferences with quiet hours + urgent bypass, admin broadcast composer with scheduled dispatch.
related: [approval-workflow, order-domain, translatable-forms]
---

# Notification Platform

This document explains the notification layer. The foundation (two-table store, morph map, service dispatch, own-inbox + HQ audit) ships in plan-008. The dynamic extensions (audience engine, templates, multi-channel delivery, user preferences, per-brand realtime, admin broadcast composer) ship in plan-012 as additive layers on top of the same foundation — no existing columns or endpoints changed.

## Why two tables

One event typically fans out to multiple recipients with independent read/dismissed state. Two natural approaches:

| Approach | Trade-off |
|----------|-----------|
| Laravel default `notifications` single polymorphic table | Duplicates the full JSON payload N times per fan-out — storage waste, no post-send correction |
| Normalized 2-table (`notifications` + `notification_recipients`) | Content stored once, per-recipient state tracked independently |

We chose normalized. This is the same pattern Knock / MagicBell / Linear use. The downside (two inserts per dispatch) is negligible at restaurant-chain scale (fan-out caps at ~hundreds).

## Schema

```text
notifications                            notification_recipients
┌────────────────────────────┐          ┌─────────────────────────────┐
│ id (uuid, PK)              │          │ id (uuid, PK)               │
│ organization_id (FK)       │          │ notification_id (FK CASCADE)│
│ actor_type (morph alias?)  │          │ recipient_type (morph alias)│
│ actor_id (uuid?)           │          │ recipient_id (uuid)         │
│ type (dot.namespaced)      │◄─────────┤ seen_at (?timestamp)        │
│ template_key (string)      │          │ read_at (?timestamp)        │
│ params (json?)             │          │ dismissed_at (?timestamp)   │
│ subject_type (morph alias?)│          │ resolved_via (?trace)       │
│ subject_id (uuid?)         │          └─────────────────────────────┘
│ priority (enum: low/…/urg) │
│ aggregation_key (?string)  │
│ idempotency_key (?unique)  │
│ audience_id (?uuid)        │
│ scheduled_for (?timestamp) │       Phase D
│ is_dispatched (bool)       │       scheduled broadcast support
└────────────────────────────┘
```

Full column-level details in `schemas/Backend/Notification/Notification.yaml` + `NotificationRecipient.yaml`.

## Polymorphic via morph map

Three polymorphic edges share the same alias map, registered in `OmnifyServiceProvider::boot` via `Relation::enforceMorphMap`:

| Edge | Valid aliases |
|------|---------------|
| `notifications.actor` | `User`, `Device`, or `NULL` (system event) |
| `notifications.subject` | `Recipe`, `Product`, `CustomerOrder`, `StockAlert`, … |
| `notification_recipients.recipient` | `User`, `Device` |

Type columns store the **TitleCase alias** (`User`, `Device`) — not the class path. Renaming `App\Models\Recipe` to `App\Models\Catalog\Recipe` is a one-line morph-map update; existing rows stay valid.

`enforceMorphMap` (not the permissive `morphMap`) throws at write time if code tries to persist an unregistered model as a polymorphic target. This catches drift at the source instead of silently producing orphan rows.

### Notifiable recipients

`User` and `Device` both implement the `App\Contracts\Notifiable` marker interface and use the `App\Models\Concerns\ReceivesNotifications` trait. `NotificationService::dispatch` rejects any recipient that is NOT `Notifiable`, so passing a `Recipe` or a raw stdClass trips a 422 before any row is written.

The trait also adds `$user->notificationInbox()` and `$user->unreadNotifications()` — morph relations pointing at `notification_recipients`. These do NOT collide with Laravel's built-in `Illuminate\Notifications\Notifiable` trait because the method names are different; a `insteadof` conflict resolution on `User` handles the one overlapping `unreadNotifications` name.

## Service-only dispatch

`NotificationService::dispatch(array $input): Notification` is the single programmatic entry point. Emitters (StockAlertService, RecipeService, CustomerOrderService, admin broadcast composer later) call it; nothing else writes to the tables directly.

The input shape:

```php
[
    'type' => 'recipe.approved',                 // dot-namespaced event
    'template_key' => 'recipe.approved',         // FE i18n lookup (defaults to type)
    'params' => ['recipe_name' => ..., ...],     // template parameters
    'recipients' => [$user1, $user2, $device1],  // any Notifiable
    'actor' => $approver,                        // or null for system events
    'subject' => $recipe,                        // or null
    'organization_id' => $org->id,               // tenancy boundary
    'idempotency_key' => 'recipe.approved:'.$r->id,  // queue-retry dedup
    'priority' => NotificationPriorityEnum::Normal,  // optional override
]
```

Dispatch runs inside `DB::transaction()` — one `notifications` row + N `notification_recipients` rows are atomic. A failure after the parent insert rolls back the whole thing.

**Idempotency:** when `idempotency_key` is present, a second call with the same key returns the original Notification without writing new recipient rows. This is retry-safe for queue workers.

**Recipient dedup:** duplicate (class, id) pairs in the `recipients` collection collapse to one row at the service level before hitting the DB's unique index.

## Default priorities

`NotificationService::defaultPriorityFor(string $type): NotificationPriorityEnum` maps known types to priorities:

| Type | Priority |
|------|----------|
| `stock.alert.out` | `High` |
| `stock.alert.low` | `Normal` |
| `order.paid` / `order.status_changed` | `Normal` |
| `recipe.approved` / `recipe.rejected` | `Normal` |
| `system.critical` | `Urgent` |
| anything else | `Normal` (fallback) |

Emitters override per-event by passing `priority` in the dispatch input.

## Own-inbox API

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/me/notifications` | Paginated list; filter by status / type / priority / since |
| GET | `/api/v1/me/notifications/summary` | Unread count + priority breakdown + latest timestamp |
| PATCH | `/api/v1/me/notifications/{id}/seen` | Mark seen (idempotent) |
| PATCH | `/api/v1/me/notifications/{id}/read` | Mark read (implies seen; idempotent) |
| PATCH | `/api/v1/me/notifications/{id}/dismissed` | Archive |
| POST | `/api/v1/me/notifications/bulk-read` | Mark many as read (ids OR all_unread=true) |
| POST | `/api/v1/me/notifications/bulk-dismiss` | Dismiss many (ids OR all_read=true OR all=true) |

Row-level access is enforced via `NotificationRecipient::forRecipient($user)->firstOrFail()` — a caller who isn't a recipient of a notification gets **404** (existence-hiding), not 403. This prevents leaking the existence of notifications addressed to other users.

## HQ admin audit

Brand-scoped audit endpoints expose every notification in the brand's organizations — read-only:

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/hq/{brandSlug}/notifications` | Paginated audit list with filter bar |
| GET | `/api/v1/hq/{brandSlug}/notifications/{id}` | Detail with full fan-out + per-recipient state |
| GET | `/api/v1/hq/{brandSlug}/notifications/types` | Distinct types currently in use, with counts (filter dropdown) |

Admin authorization gate in Phase A is `user.console_organization_id === brand.console_organization_id`. Câu hỏi mở về việc siết theo vai đã được trả lời một phần ở #2456: cổng `viewNotificationCoverage` giờ hỏi `org-admin`. Nó từng hỏi `brand_admin` — một slug **chưa bao giờ tồn tại**, nên cổng đó từ chối mọi người kể từ ngày được viết.

## Tri-state per-recipient interaction

Each recipient row has three timestamp columns — `seen_at`, `read_at`, `dismissed_at`. Only one may be set at a time by user action, and all are idempotent:

- **seen_at** = the row entered the user's viewport (bell dropdown opened, inbox rendered)
- **read_at** = the user explicitly interacted (clicked a row, opened detail). Setting `read_at` also sets `seen_at` if it is still null
- **dismissed_at** = the user archived the row. Dismissed rows disappear from the default `/me/notifications` response (use `include_dismissed=true` to include them)

`markSeen()` / `markRead()` / `markDismissed()` on the `NotificationRecipient` model all check "is the timestamp still null?" before writing — a second call preserves the first timestamp.

## Emitter integration

Three canonical emitters ship with the notification platform:

Emitters do **not** build an `Audience` themselves — they call
`NotificationDispatcher` (#1622), which is the only published port into the
notifications module. `Audience` stays an internal concept.

| Emitter | Trigger | Dispatch call |
|---------|---------|---------------|
| `StockAlertNotificationObserver` | `StockAlert::created` | `toRole(role: 'warehouse_manager', scopeKey: 'warehouse_id', …)` |
| `RecipeService::approve / ::reject` | Method end, after audit log | `toRecipients(...)` — the recipe creator, a single known user |
| `CustomerOrderNotificationObserver` | `CustomerOrder::updated` when `status` changes | `toRole(role: ['shop-manager', 'org-admin'], scopeKey: 'branch_id', …)` |

Đơn hàng hỏi **hai** vai (#2450). Đây không phải luật "cấp cao hơn nhận hết" —
áp thế thì `shop-staff` sẽ dội cho cả admin — mà là quyết định cho riêng sự kiện
này: Platform cấp vai theo `service_role`, nên ở một doanh nghiệp mà chủ quán là
người dùng duy nhất thì người đó mang vai **admin**, không phải shop-manager.

All three use silent `try/catch` around the dispatch call — a notification failure must never break the upstream mutation (creating the alert, approving the recipe, updating the order).

**Không còn cờ `notifications.use_audience`, và không còn cap-N fallback** — cả
hai bị gỡ ở plan-023 M1/T1.3. Đường audience chạy vô điều kiện.
Bản đông lạnh `LegacyRecipientResolver` và lệnh đối chiếu
`notifications:audit-rollout` **đã gỡ** (#2413): đo trên production 30 ngày cho
`stock_alert` **0 dòng lệch**, `customer_order` **217 dòng lệch giống hệt nhau**
— nguyên nhân duy nhất là resolver cũ dội cho hai admin gắn vào một chi nhánh có
**0 đơn**. Engine là bên đúng, nên instrument diff hết đối tượng để đo.

Cờ còn thật là `config('notifications.use_rules')` (env `NOTIFICATION_USE_RULES`,
mặc định `false`) — nó chọn giữa emitter cứng và **máy luật**, chuyện khác.

**Reserved types — no emitter yet:** `order.paid` and `system.critical` have priority-map entries + system templates but no code currently dispatches them. They exist as "slots" for future business hooks (payment capture flow, infra health checks).

## Audience engine (plan-012)

`NotificationAudience` rows store a reusable recipient rule set. Admins manage them in `/hq/{brandSlug}/notifications/audiences`; emitter code references them either by id (persisted rule) or via the fluent builder (`Audience::byRole(...)` / `byRolesInScope(...)`) which sinks an inline rule directly into `dispatch()`.

### Rule schema

The `rule` JSON column on every audience row:

```json
{
  "version": 1,
  "combinator": "or" | "and",
  "rules": [
    { "type": "role",   "role": "warehouse_manager", "scope": { "warehouse_id": "…" } },
    { "type": "user",   "user_ids": ["…"] },
    { "type": "shop",   "shop_ids": ["…"], "include_members": true },
    { "type": "brand",  "brand_id": "…", "include_all_members": true },
    { "type": "device", "device_types": ["tms","workstation"], "branch_id": "…" }
  ],
  "exclude": [ { "type": "user", "user_ids": ["…"] } ]
}
```

### Resolution

`AudienceResolverService::resolveWithTrace(rule, brand)` runs five sub-resolvers (`RoleResolver`, `UserResolver`, `ShopResolver`, `BrandResolver`, `DeviceResolver`), unions or intersects their outputs per `combinator`, then subtracts `exclude`. It returns a `Collection<Notifiable>` plus a trace map `"{morphClass}:{id}" → "role:warehouse_manager:warehouse_id:…"` that becomes the `resolved_via` column on every recipient row (so debugging noisy notifications shows exactly which rule matched).

Hard cap: **10 000 recipients per dispatch**. Exceeding it throws `NotificationException('audience_too_large', 422)` — the composer UI pre-checks via `POST /audiences/preview` and refuses to save a rule that resolves above the cap. Queue flood + SMTP throttling are the justifications.

### Fluent builder

For emitters that need per-instance scope, the rule is built inline instead of stored:

```php
Audience::byRole('warehouse_manager')->scopedToKey('warehouse_id', $alert->warehouse->id)
Audience::byRolesInScope(['shop-manager', 'org-admin'], 'branch_id', $branchId)   // #2450
Audience::user($recipe->creator)
Audience::shop($order->branch)->includeMembers()
```

`scopedTo(Model)` **đã gỡ** (#1568): chữ ký nhận Model buộc chính lớp này import
`Warehouse` và `Device`, sinh ra hai cạnh phụ thuộc RA khỏi Notifications chỉ vì
một phép dò `instanceof`. Caller nêu thẳng khoá thì cạnh đó biến mất.

`byRolesInScope()` nhận phạm vi **ngay trong factory** thay vì để caller nối
`scopedToKey()`, vì hàm đó cố ý chỉ gắn scope vào rule CUỐI — dựng
`byRole(a)->byRole(b)->scopedToKey(...)` sẽ để vai thứ nhất bắn cho toàn bộ tổ
chức (#2450).

Each static factory method maps to a `*Resolver` class — an arch test (`NotificationArchTest`) enforces that mapping so helper / resolver drift fails CI.

## Templates (plan-012)

`notification_templates` stores keyed templates with JSON content per locale. Schema:

```text
notification_templates
┌─────────────────────────────┐
│ id (uuid, PK)               │
│ organization_id (FK)        │
│ brand_id (?FK, nullable)    │
│ key (string, unique)        │
│ content (json) — per-locale │   { "ja": {"title": "…", "body": "…"},
│ default_channels (json)     │     "en": {…}, "vi": {…} }
│ params_schema (?json)       │
│ is_system (bool)            │
│ created_by_id (?FK)         │
└─────────────────────────────┘
```

### Rendering

`TemplateRenderer::render($template, $params, $locale): array{title, body}` substitutes `{{param}}` tokens via regex, then falls back down `user.locale → ja → en` when the requested locale is missing. Missing `{{key}}` produces an empty substitution + a `notification.template.missing_param` log warning (not a 500 — locale fallback and missing params are diagnostics, never user-facing errors).

`renderAll($template, $params)` returns every locale at once; the composer uses it for the live-preview panel.

### System vs custom templates

- **System templates** (`is_system=true`) — seeded by `SystemNotificationTemplateSeeder` for every emitter-bound key (`stock.alert.low`, `stock.alert.out`, `order.status_changed`, `recipe.approved`, `recipe.rejected`, `order.paid`, `system.critical`). Admins may edit the content + channels but may not delete them (the UI disables the Delete button).
- **Custom templates** — admins create for broadcast-only keys (`maintenance.scheduled`, `promo.welcome`, campaign-specific keys, etc.). Full CRUD.

Template lookup is soft — there is no FK from `notifications.template_key` to `notification_templates.key`. A deleted / renamed template falls back to the `template_key` literal with a warning log, rather than cascading a delete that nukes history.

## Channels (plan-012)

Four channels are declared in `NotificationChannelEnum`:

| Channel | Handler | Behaviour |
|---------|---------|-----------|
| `in_app` | `InAppChannel` | No external action; the inbox reads `notification_recipients` directly. Always `DeliveryResult::sent()`. |
| `realtime` | `RealtimeChannel` | Broadcasts the `NotificationReceived` event via `BrandAwareBroadcastManager::brand($brand)->broadcast(...)`. Brand with null `reverb_app_id` → skipped with log warning. |
| `email` | `EmailChannel` | Renders template in the user's locale, sends via `Mail::to($user->email)->send(new NotificationMail(...))`. Device recipients + users with no email address are silently skipped. |
| `push` | `PushChannel` | Stub in plan-012 — always `DeliveryResult::skipped('push channel not implemented yet')`. Placeholder for FCM/APNs. |

### Per-channel jobs + queues

Every delivery is its own queued job (`NotificationChannelJob`) on a channel-specific queue (`notifications-email`, `notifications-realtime`, `notifications-in_app`, `notifications-push`). One slow channel cannot starve another — an SMTP outage pauses email only.

Retry policy: 3 attempts, backoff `[10, 60, 300]` seconds. After the third failure the `notification_deliveries` row is marked `failed_at = now()` and the error is logged to `notification.delivery.failed`.

### Effective channels

`EffectiveChannelService::forBatch($recipients, $type, $priority)` computes, per recipient, the set of channels that actually fire. The intersection is:

```text
routes(org, type) ∩ preferences(user, type, channel).enabled
                  ∩ ¬quiet_hours(user, channel)        # for realtime + email
                  ∩ ¬master_mute(user)                  # all channels except in_app
```

with two bypass rules:

1. **Urgent bypass.** When `priority = urgent`, master_mute and quiet_hours are ignored. This exists for incident-class notifications — the user can suppress a noisy category via opt-out but cannot silence a critical alert via "mute all".
2. **Per-(type × channel) opt-out always wins.** Even for urgent priority, if the user explicitly disabled `stock.alert.low × email`, the email channel does not fire. Urgent bypass restores a defaulted channel, not a user-disabled one.

## User preferences (plan-012)

`notification_preferences` table — 1 row per `(user_id, type, channel)` with `enabled` boolean, plus 3 user-global rows for `master_mute`, `quiet_from`, `quiet_to`, `quiet_timezone`.

UI: `/me/settings/notifications` — master-mute toggle, quiet-hours range + timezone picker, matrix of per-(type × channel) toggles.

Quiet hours apply to `realtime` and `email` only — `in_app` always writes a row so the inbox is never silently dropped. A user who sleeps through a stock alert still sees it in the morning; the realtime push + email just don't fire until the window ends.

## Realtime (plan-012)

Per-brand Laravel Reverb isolation — each brand has its own app ID / key / secret so a leaked key for brand A cannot be used to subscribe to brand B's channels.

### Brand Reverb provisioning

`BrandReverbAppService` generates the creds on `Brand::created` via `BrandObserver`:

- `reverb_app_id` — format `brand-{uuid}`
- `reverb_app_key` — 40 random chars
- `reverb_app_secret` — 40 random chars, stored encrypted (Laravel cast `encrypted`). The encrypted ciphertext of a 40-char string exceeds 255 chars, so `brands.reverb_app_secret` is declared `TEXT` in `database/migrations/omnify/*_create_brands_table.php` — never `VARCHAR(255)`.
- `reverb_allowed_origins` — JSON array, default `["*"]`

Existing brands are ensured by `BrandBaselineProvisioner` — via `BaselineProvisioningSeeder` (wired into `DatabaseSeeder`) or `php artisan provisioning:reconcile` (#2320). An arch test asserts every `Brand` row has non-null `reverb_app_id` after the seeder runs (`tests/Feature/Notification/BrandReverbProvisioningTest.php`).

> **Không có `BackfillBrandReverbAppsJob`** — chưa bao giờ có, không phải bị gỡ.
> Đo 2026-08-07: `grep -rn BackfillBrandReverbApps backend/` chỉ hit Seeder +
> `DatabaseSeeder` + test; `git log -S BackfillBrandReverbAppsJob` chỉ trả về
> hai commit **docs** của plan-012, không commit code nào. Backfill là đường
> **seeder**, chạy chủ động; đừng đi tìm một queue job để dispatch (#2029).

### Rotation

`POST /hq/{brandSlug}/reverb/rotate` regenerates the key + secret (app_id is unchanged so cache entries remain valid). Clients currently connected disconnect on next heartbeat and reconnect with fresh creds after fetching `/me/reverb-config`.

### BrandAwareBroadcastManager

Laravel's native `BroadcastManager` reads config once at boot. For per-brand routing we wrap it: `BrandAwareBroadcastManager::brand($brand)->broadcast($event)` temporarily rewrites `config('broadcasting.connections.reverb.{app_id,key,secret}')` for the dispatch, then restores the original values. The event `NotificationReceived` implements `ShouldBroadcastNow + ShouldDispatchAfterCommit` — no queue hop, but guaranteed not to fire if the surrounding DB transaction rolls back.

### Client

`useNotificationRealtime()` in admin-web fetches `/api/v1/me/reverb-config?brand={slug}` (or `?shop=`, or no query for `/inbox`), initialises Laravel Echo with the Pusher driver pointing at Reverb's creds, and subscribes `user.{userId}.notifications`. On `NotificationReceived` it invalidates the TanStack Query cache keys; the bell badge updates in < 500 ms without a refetch. On WS disconnect > 10s it falls back to polling every 30s.

## Broadcast composer (plan-012)

Admin UI at `/hq/{brandSlug}/notifications/compose` — 3-step wizard:

1. **Audience** — pick a saved audience or build an inline rule. Live preview count via `POST /audiences/preview` (debounced 400 ms).
2. **Template** — pick a saved template or inline content per locale. Live rendered preview via `POST /templates/render`.
3. **Delivery** — channel switches, priority select, optional `scheduled_for` datetime.

On submit (`POST /hq/{brandSlug}/notifications/broadcast`):

- Audience is resolved (same resolver as emitters)
- Template rendered + saved as the notification's `params` (or template_key reference)
- `NotificationService::dispatch` runs — one parent `Notification` row, N `notification_recipients` rows, and (if immediate) `notification_deliveries` rows per recipient × effective channel
- Returns `{ id, type, scheduled_for }` — the admin is redirected to `/hq/{brandSlug}/notifications?detail={id}` which auto-opens the detail Sheet on the existing audit page (there is no standalone `[id]` route)

Authorization: `NotificationPolicy::compose($user, $brand)` — the same admin policy that gates templates + audiences + routing.

## Scheduled dispatch (plan-012)

When `scheduled_for` is in the future, dispatch writes the parent + recipient rows immediately (`is_dispatched = false`) but defers delivery insertion. A delayed `DispatchScheduledNotificationJob` fires at the target time, re-computes effective channels (so user preferences at send time apply, not at schedule time), inserts delivery rows, sets `is_dispatched = true`, and queues the per-channel jobs.

### Missed-schedule recovery

`ScheduledNotificationHealthCheckJob` runs hourly (registered in `routes/console.php`) and scans:

```sql
WHERE is_dispatched = false
  AND scheduled_for <= now() - interval '5 minutes'
```

Any overdue row gets re-queued with zero delay. The 5-minute grace window avoids a race with the delayed job that's about to fire. This is the safety net for queue flushes (manual `queue:flush`, Redis crashes, worker restarts).

### Cancellation

`DELETE /hq/{brandSlug}/notifications/{id}` hard-deletes a scheduled notification — cascades to recipients + deliveries. Only allowed before `scheduled_for - 60s` (60-second freeze window); after that, the delayed job may already be executing.

## Retention

The `Notification` model uses Laravel's `Prunable` trait. `php artisan model:prune` (nightly via scheduler) deletes old rows per priority:

- `low` → 7 days
- `normal` / `high` → 30 days
- `urgent` → 90 days

`notification_recipients` cascades on parent delete, so recipient rows follow the parent's retention automatically.

## Recurring schedules (plan-023 M3)

`scheduled_for` on the notifications table covers a single send-later occurrence. For "every Monday at 9am" or "first of every month" delivery, plan-023 adds `notification_schedules`:

- Each row carries an RFC-5545 RRULE substring (`FREQ=WEEKLY;BYDAY=MO,WE,FR;BYHOUR=9`), an IANA timezone, `starts_at` / `ends_at` window bounds, and an optional `occurrences_remaining` counter.
- `RecurringNotificationDispatcher::tick()` runs every 5 minutes via the scheduler entry `notifications.recurring.tick` (invoked through `notifications:tick-recurring-schedules` artisan command — the `withoutOverlapping` + `onOneServer` modifiers are command-only). Each due row materialises one `Notification` via the standard `NotificationService::dispatch` path + advances `next_occurrence_at` to the next RRULE date.
- Idempotency: each occurrence carries `idempotency_key = "schedule:{id}:occurrence:{iso8601}"`. A worker crash between materialise and advance can't double-send — the unique index on `notifications.idempotency_key` short-circuits the second insert.
- Lifecycle: `active` → tick processes → `completed` (RRULE exhausted, `occurrences_remaining=0`, or `ends_at` reached). `active ↔ paused` flips skip the tick. `cancelled` is terminal once admin invokes the canceller, but the **60-second freeze window** rejects cancel attempts when `next_occurrence_at - now() < 60s` so the tick worker can't race the cancel.
- 5-minute resolution is the contract surfaced to the composer copy ("your weekly send fires between 09:00 and 09:04"). If a future use case needs minute-precision, open a separate plan for a high-precision schedule subset.

Endpoints under `/api/v1/hq/{brand}/notifications/schedules` cover full CRUD + pause / resume / `preview-rrule` (next-N occurrences). See `docs/reference/notifications-api.md`.

## Email delivery hardening (plan-023 M4)

Pre-M4 `EmailChannel::send` called `Mail::to()` unconditionally. Bounced or complaining recipients silently kept receiving mail because no webhook ever updated the delivery status. Plan-023 M4 adds the inbound feedback loop.

### Provider-driver abstraction

`App\Contracts\Notification\MailWebhookContract` has three methods — `verifySignature`, `parseEvents`, `mapToDeliveryStatus`. Per-driver implementations encapsulate the per-provider hashing scheme so swapping providers is a `MailWebhookManager::register($name, $factory)` call + an env flip, not a schema or controller change.

Postmark ships pre-registered (Decision 20 in plan-023 DESIGN). SES / Mailgun / SendGrid become additive.

### Postmark driver

`PostmarkMailWebhookHandler`:

- HMAC-SHA1 signature verify via `POSTMARK_WEBHOOK_SECRET` (set in the Postmark dashboard → Server → Settings → Webhooks → Webhook validation token).
- 5-minute replay window enforced via the per-event timestamp (`DeliveredAt` / `BouncedAt` / `ReceivedAt` / `ChangedAt`).
- Maps `Delivery` → `delivered`, `Bounce` / `HardBounce` → `bounced`, `SpamComplaint` → `complained`, `SubscriptionChange` → `suppressed`. Unknown `RecordType` raises `InvalidArgumentException`; the webhook controller turns that into a 202 + `accepted: 0` so the provider stops retrying without us inventing a state.

### Webhook flow

```
Postmark POST /api/v1/webhooks/mail/postmark
                                ↓
                  MailWebhookController
                                ↓
   handler->verifySignature($request)            → 401 on signature/replay failure
                                ↓
   handler->parseEvents($request) → EmailEvent[] → 202 + accepted=0 on unknown type
                                ↓
   per event: ApplyEmailEventJob::dispatch($event)
                                ↓
   ApplyEmailEventJob (queue notifications-email-webhook):
     1. Find NotificationDelivery by provider_ref = messageId
     2. Update status + relevant timestamp column
     3. On Bounced / Complained / Suppressed → upsert
        NotificationEmailSuppression row (org, email, reason)
```

### Suppression list

`notification_email_suppressions` is the org-scoped blocklist. `EmailChannel::send` queries it before calling `Mail::to()` and returns `DeliveryResult::skipped(...)` when the recipient is on the list. Address comparison is case-insensitive (normalised lower-case at write).

Admin un-suppress (`DELETE /hq/{brand}/notifications/email-suppressions/{id}`) writes `un_suppressed_at` rather than deleting the row, so the audit trail of past blocks survives. The channel's pre-send query filters `WHERE un_suppressed_at IS NULL`, so flipping that column is enough to restore delivery.

Admin can also manually add a suppression (`reason='manual'`) to pre-block an address before any provider event fires.

## Production queue workers — the named queues MUST be listed (#2552)

Delivery — and three background jobs — run on **named** queues, not `default`:

| Queue | Job | Dispatched |
|---|---|---|
| `notifications-digest` | `SendDigestJob` | scheduled hourly (`routes/console.php`) — always fires |
| `notifications-email-webhook` | `ApplyEmailEventJob` | on an inbound Postmark bounce/webhook |
| `notifications-rule-evaluation` | `EvaluateRuleJob` | when a notification rule needs evaluating |
| `notifications-email`<br>`notifications-realtime`<br>`notifications-in_app`<br>`notifications-push` | `NotificationChannelJob` | **every delivery**, from `DispatchScheduledNotificationJob` |

The delivery family is **generated, not a fixed list**: `NotificationChannelJob`
builds its queue name as `'notifications-'.$channel` from `NotificationChannelEnum`
(`app/Jobs/NotificationChannelJob.php:78`), so **adding a channel adds a queue**.
A worker command written by hand goes stale the day someone adds one, and it goes
stale silently — the new channel's jobs simply sit there. Anyone touching that
enum has to touch the crontab in the same change.

Leaving the four delivery queues out is the worst of the set: they are not a
background nicety, they ARE the notification. Nothing is delivered — no email, no
push, no realtime, no in-app — while every rule evaluates happily and every
schedule fires on time.

A bare `php artisan queue:work` processes **only the `default` queue**, so all
three sit unprocessed. The failure is asymmetric and that is what hid it:
`notifications-digest` fires hourly so its jobs visibly pile up (42 stuck on
2026-08-12 before the fix), while the other two dispatch on demand — zero stuck
looks healthy right up until the first bounce or rule fire lands in a queue
nobody drains, and then email-health / rules break **silently**.

The production worker command therefore MUST enumerate every queue:

```
php artisan queue:work \
  --queue=default,notifications-email,notifications-realtime,notifications-in_app,notifications-push,notifications-email-webhook,notifications-rule-evaluation,notifications-digest \
  --max-time=55 --sleep=1 --tries=3 --timeout=90
```

Order is priority order — `default` (orders, payments) first; the notification
queues after it, digest last (it is a batch email, the least latency-sensitive).

**This list is the fragile part.** The worker lives only in the server crontab —
there is no Procfile, no supervisor config, no deploy-workflow step that owns it.
A server rebuild that reinstalls a plain `queue:work` silently drops all three
queues again. Whenever a new named queue is added (an `onQueue()` call on a job),
it MUST be appended to the worker `--queue` list, or its jobs never run.

## Related

- plan-008 (đã archive — xem git history) — Phase A foundation
- plan-012 (đã archive — xem git history) — audience engine, templates, channels, real-time
- `plans/plan-023/` — completeness pass (M3 recurring schedules, M4 email hardening, …)
- `docs/reference/notifications-api.md` — endpoint reference
- `docs/contributing/emitting-notifications.md` — how to wire a new emitter
- `schemas/Backend/Notification/` — Omnify YAML schemas
- `backend/app/Services/Notification/NotificationService.php` — service implementation
- `backend/tests/Feature/Me/NotificationControllerTest.php` — 19 feature scenarios
