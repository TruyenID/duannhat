---
title: Emitting Notifications
category: contributing
tags: [notifications, emitters, service, dispatch, polymorphic, audience, templates, plan-008, plan-012]
summary: Contract for wiring a new emitter to the notification platform — NotificationService::dispatch input shape, idempotency key discipline, audience-based recipient resolution with feature-flag fallback, silent-fail pattern, morph-map requirements, template + channel coordination.
related: [service, notifications]
---

# Emitting Notifications

This document is the checklist for adding a new domain event that should reach a user's inbox. Conceptual overview is in `docs/explanation/notifications.md`; HTTP surface is in `docs/reference/notifications-api.md`.

## When to emit

You emit a notification when a domain event must reach a human inbox beyond the immediate HTTP response. Examples:

- A state transition the subject's creator/approver/owner should know about (`recipe.approved`, `recipe.rejected`, `order.status_changed`)
- A background condition some role needs to act on (`stock.alert.low`, `stock.alert.out`)
- A broadcast an admin composes from the HQ UI — that path goes through the composer controller, not an emitter (see `docs/explanation/notifications.md` §Broadcast composer)

You do **not** emit for:

- Things already returned in the same HTTP response (`POST /products` returning the new product — caller already has the data)
- Audit trail without user-facing intent — use `AuditLog` (`logAudit(...)`) instead
- Transient UI toasts — handle on the frontend, not through the notification service

### `Log::error` alone does not satisfy "every error reaches a human" (#2694 / #2697)

Ruling from the project owner, 2026-08-13: **no error is allowed to be silent.**
A `Log::error` line — even a correctly `[tagged]` one that DevOps alerting can
match — is not on its own a path to a person. It is an *addition* to that path,
never a substitute.

This is not a hypothetical. `[inventory.stock_drift]` fired **69 times on
production on 12/08 and produced zero notifications**; the code carried a comment
saying ops reads the logs, and nobody did. It only surfaced because somebody went
grepping. `paypay_qr_notification_unbookable` fired twice the same day, same
result.

So when you add a new **fail-open** path — the shape where a subsystem failure is
deliberately swallowed so the money-bearing operation survives:

1. Keep the `Log::error` with its `[tag]` prefix. `MoneyFailOpenLogsAreAlertableTest`
   guards the level and the tag, and DevOps alerting matches on that prefix.
2. **Also** dispatch a notification, wrapped in its own silent try/catch so the
   alarm can never abort the path that raised it.
3. Decide the recipients for *that event* (see below). Do not infer them from role
   hierarchy.
4. Write a test in **both directions**: the failure fires a notification with a
   recipient count **greater than zero**, and the success path stays quiet. A test
   that only asserts "a notification row exists" passes with zero recipients —
   which is exactly the silent failure this rule exists to prevent.

Reference implementations: `App\Services\Order\StockDriftAlertService` (branch
scope, `shop-manager` + `org-admin`) and
`ProviderEventApplicator::notifyUnbookableQrNotification` (organization scope,
`org-admin`).

## The dispatch contract

Every emitter funnels through one method:

```php
app(\App\Services\Notification\NotificationService::class)->dispatch([
    'type' => 'recipe.approved',
    'template_key' => 'recipe.approved',          // defaults to `type` when omitted
    'params' => ['recipe_name' => $r->name, 'approver' => $u->name],
    'recipients' => $collection,                  // iterable<Notifiable>
    'actor' => $actor,                            // ?Model — null for system events
    'subject' => $recipe,                         // ?Model
    'organization_id' => $r->organization_id,     // tenancy boundary (required)
    'idempotency_key' => "recipe.approved:{$r->id}",
    'priority' => NotificationPriorityEnum::Normal, // optional override
]);
```

### Required fields

- `type` — dot-namespaced event name (same idiom as `AuditLog.action`). Stable and searchable via the admin audit filter.
- `recipients` — at least one `App\Contracts\Notifiable` model. Empty collection throws `NotificationException('recipients_empty')` → 422.
- `organization_id` — the local `organizations.id` (not `console_organization_id`). Tenancy boundary for every inbox query.

### Optional but recommended

- `idempotency_key` — **pass this for retry-safe events.** Without it, a queue retry that failed after the parent insert but before recipient fan-out would create a duplicate event. With it, the second dispatch short-circuits and returns the existing Notification.
- `actor` — a `Notifiable` model (User, Device). `null` means system/service event; do NOT invent a sentinel "system" User.
- `subject` — the entity the event is about (Recipe, CustomerOrder, StockAlert, …). Both `subject_type` and `subject_id` live-or-die together.

## Morph-map requirement

Every non-null model passed as `actor`, `subject`, or a `recipients` member must be registered in `Relation::enforceMorphMap` (in `OmnifyServiceProvider::boot`). Omnify-generated models register automatically via the regen. Pre-existing or hand-authored models need a manual map entry.

Unregistered → dispatch throws `NotificationException('unregistered_morph_type')`.

## Recipient types — Notifiable only

`recipients` must contain models that implement `App\Contracts\Notifiable`. Phase A ships `User` and `Device`; any other class triggers `NotificationException('not_notifiable')` before insert.

To make a new model notifiable:

```php
class Kiosk extends Model implements \App\Contracts\Notifiable
{
    use \App\Models\Concerns\ReceivesNotifications;
    // ...
}
```

Then register it in the morph map so the `recipient_type` column value resolves.

## Silent-fail wrap

Notification failures MUST NOT break the upstream mutation that triggered them. Wrap every dispatch in a silent try/catch:

```php
try {
    $service->dispatch([...]);
} catch (\Throwable $e) {
    \Log::warning('<my-emitter>: notification dispatch failed', [
        'subject_id' => $subject->id,
        'error' => $e->getMessage(),
    ]);
}
```

This mirrors the `AuditsActivity::logAudit` idiom — logging for observability, but never throwing through.

## Idempotency-key conventions

One stable key per (event, subject) pair. Examples:

| Event | Key |
|-------|-----|
| Stock alert created | `stock.alert.low:{alert.id}` |
| Recipe approved | `recipe.approved:{recipe.id}` |
| Order status changes | `order.status_changed:{order.id}:{new_status}` |
| Scheduled admin broadcast | `broadcast:{audience_id}:{scheduled_at}` |

Pick keys that are stable across queue retries — NOT timestamp- or UUID-based. A retry MUST produce the same key as the original attempt.

## Recipient resolution

Emitter **không tự dựng `Audience`**. Kể từ #1622, đường vào duy nhất là cổng
`NotificationDispatcher`; `Audience` là khái niệm nội bộ của module notifications
và emitter không được biết tới nó:

```php
$this->notifications->toRole(
    new NotificationRequest(type: 'stock.alert.low', /* … */),
    role: 'warehouse_manager',
    scopeKey: 'warehouse_id',
    scopeId: (string) $alert->warehouse->getKey(),
    brand: $brand,
);
```

Ba method của cổng:

| Method | Gửi tới |
|---|---|
| `toRole($request, string\|array $role, string $scopeKey, string $scopeId, Brand $brand)` | một hoặc NHIỀU vai trong cùng một phạm vi |
| `toRecipients($request, iterable $recipients)` | danh sách người nhận đã biết trước |
| `coversEmitter($modelAlias, $triggerEvent, $organizationId)` | hỏi máy luật đã thay thế emitter cứng này chưa |

`$scopeKey` hợp lệ: `warehouse_id` · `brand_id` · `organization_id` · `branch_id`
· `device_id`.

### Chọn vai — cạm bẫy đắt nhất ở tầng này

Từ vựng vai thật do `RoleTemplateMatrix::ROLES` định nghĩa, **toàn gạch ngang**:
`org-admin` · `org-manager` · `shop-manager` · `staff` · `shop-staff`. Trước khi
viết một slug vào emitter, kiểm nó có trong đó không.

`EloquentRoleAssignmentDirectory::withRole()` so `roles.slug` **chính xác**. Một
slug sai **không ném lỗi** — nó phân giải ra 0 người nhận và im lặng mãi mãi. Đã
xảy ra bốn lần: `shop_manager` (#2451), `brand_admin` · `branch_admin` ·
`org_owner` (#2456). Rào máy: `AudienceRoleSlugsExistTest`.

Hai điều nữa mà tầng directory hiểu, và bạn nên hiểu khi chọn phạm vi (#2460):

- Vai do Platform cấp mang tên `tempo-admin` / `tempo-manager`, không phải slug
  template — `RoleTemplateMatrix::equivalentSlugs()` khai triển giúp bạn.
- `branch_id IS NULL` nghĩa là **mọi chi nhánh** (`all_branches_access` của
  Platform), không phải "không chi nhánh nào".

Cần nhiều vai cho cùng một sự kiện thì truyền mảng — `toRole()` nhận
`string|array`. Đừng nối `byRole(a)->byRole(b)->scopedToKey(...)`: `scopedToKey()`
chỉ gắn phạm vi vào rule CUỐI, nên vai thứ nhất sẽ bắn cho toàn bộ tổ chức
(#2450).

### Không còn cờ `NOTIFICATION_USE_AUDIENCE`

Cờ đó và cap-N fallback **đã gỡ** ở plan-023 M1/T1.3 — đừng dựng lại mẫu
"ship sau cờ rồi lật". Bản đông lạnh `LegacyRecipientResolver` + lệnh
`notifications:audit-rollout` cũng đã gỡ ở #2413 — phép đo production cho thấy
mọi divergence còn lại là lỗi của resolver cũ (bỏ qua phạm vi chi nhánh), không
phải rủi ro của engine.

Cờ còn thật là `NOTIFICATION_USE_RULES`, và nó chọn giữa emitter cứng và máy
luật — chuyện khác.

### Hard cap

Audiences resolving to more than **10 000 recipients** throw `NotificationException('audience_too_large', 422)`. Emitters never need to worry about this — the resolver catches it before fan-out. But large-brand org data can surprise you; chọn phạm vi hẹp (`branch_id`) thay vì cấp brand.

## Priority policy

Known types get a default priority from `NotificationService::defaultPriorityFor(string $type)`. Override per-event when appropriate:

- One-off critical events → `NotificationPriorityEnum::Urgent`
- Low-signal informational → `NotificationPriorityEnum::Low` (shorter retention, bottom of inbox ordering)

**Urgent bypass semantics** (plan-012): `urgent` priority ignores user `master_mute` and quiet hours so incident-class alerts reach everyone. But it does NOT override a per-`(type × channel)` opt-out — a user who explicitly disabled `stock.alert × email` still does not get that email at urgent priority. The only way to silence urgent on a specific channel is the explicit opt-out.

To add a new type to the default map, edit the `DEFAULT_PRIORITIES` constant in `NotificationService`.

## Template coordination

Every `type` that emitters fire must have a matching row in `notification_templates` (plan-012 moved templates from frontend i18n into the DB). `TemplateRenderer::render()` looks them up by `template_key` (defaults to `type`) and falls back to the literal key string with a warning log if missing.

**One event, two copies — vary `template_key`, never `type` (#2754).** When the same event needs different wording in a sub-case (`till.unresolved_orders` reads differently once nothing is actually short), pass `NotificationRequest::templateKey` and leave `type` alone. `type` is what every *other* filter keys off — `NotificationService::DEFAULT_PRIORITIES`, user preferences, digests, watchers — and a new type slips past all of them **silently**, resolving to zero recipients with no error. That exact failure has cost us four times over role slugs (#2451/#2456). Omit `templateKey` and it defaults to `type`, so **no existing caller needed a change** — that is the point of the default, and it is why the field is optional rather than required. (#1568 measured seven call sites when it decided `template_key` should be inferred; the count has grown since and will keep growing, so don't pin a present-tense number here — `git grep -c "new NotificationRequest(" -- 'backend/app/**/*.php'` answers it.)

Checklist:

1. If the type is emitter-bound and new, add the row to `SystemNotificationTemplateSeeder` with `is_system=true` so admins can edit the content but not delete the template.
2. Supply `params_schema` (required + optional param keys) — the admin editor lists these as click-to-insert chips for template authors.
3. Supply `default_channels` — the plan-012 UI surfaces this when a new channel-route row is first created for the type, but rooting it in the template gives one canonical spec.

## Reserved type slots — no emitter yet

`order.paid` and `system.critical` have priority-map entries + system templates but no production emitter. Treat them as reserved: do not reuse these keys for unrelated events; wire emitters for them when the corresponding business logic lands (payment capture flow, infra health checks).

## Test requirements

Every new emitter adds at least these Pest scenarios (Feature tests):

1. The canonical transition dispatches ONE notification with expected `type`, `params`, `actor`, `subject`.
2. The recipient row(s) are attributed correctly — `recipient_type` / `recipient_id` match the resolved model.
3. Re-triggering the same event (e.g. idempotency key replay) does NOT duplicate rows.
4. A dispatch failure (mock the service to throw) does NOT break the upstream mutation.

See `backend/tests/Feature/Me/NotificationControllerTest.php` for reference scenarios.

## Checklist

- [ ] Service or observer wired to the domain transition
- [ ] Dispatch call passes all required fields (`type`, `recipients`, `organization_id`)
- [ ] `idempotency_key` is stable across retries (no timestamps / UUIDs per call)
- [ ] `actor` is `null` for system events (no sentinel User)
- [ ] `subject` models exist in the morph map
- [ ] Recipients implement `App\Contracts\Notifiable`
- [ ] Wrapped in silent `try/catch(Throwable)` with `Log::warning`
- [ ] Default priority set via `defaultPriorityFor` OR overridden explicitly
- [ ] `template_key` has a matching row in `notification_templates` (seeded via `SystemNotificationTemplateSeeder` for emitter-bound keys, with 3-locale `content` + `params_schema` + `default_channels`)
- [ ] Gửi qua cổng `NotificationDispatcher`, KHÔNG tự dựng `Audience` (#1622)
- [ ] Mọi slug vai truyền vào có mặt trong `RoleTemplateMatrix::ROLES` — slug sai không ném lỗi, nó phân giải ra 0 người nhận và im (#2451/#2456)
- [ ] Feature test covers the happy path + idempotency + silent-fail

## Migrating to a workflow rule (plan-023 M7)

Once the rule engine ships with `NOTIFICATION_USE_RULES=true`, a new
notification type is usually a **data change**, not code:

1. Author a `NotificationTemplate` for the new `template_key` (HQ
   templates page or seeder).
2. Author a `NotificationAudience` covering the recipient set (HQ
   audiences page).
3. Author a `NotificationRule`:
   - `trigger_event` = `model.created` / `model.updated` /
     `model.deleted` (or `custom.<name>` for non-Eloquent triggers).
   - `trigger_model_type` = morph alias of the watched model.
   - `conditions` = DSL tree gating the fire.
   - `action.template_key` + `action.audience_name` +
     `action.channels` + `action.priority`.
4. Activate `is_active=true`. The bridge picks it up on the next
   matching event.

Hardcoded emitters remain for the four canonical Phase A types
(stock alert / order status / recipe approved / recipe rejected)
until the parallel-shadow parity gate is met for two weeks — see
`docs/explanation/notification-rules.md` §Parallel-shadow rollout.

## Adding a new emitter via system rule (plan-023 M8)

For M8 domains (product / menu / stock / device / coupon / brand)
the correct path is a **system rule + system template**, not a new
hardcoded emitter. Follow these steps:

### 1. Add a system template

In `database/seeders/SystemNotificationTemplateSeeder.php`, append a
new entry to the `$templates` array:

```php
[
    'key'             => 'my_model.my_event',
    'name'            => 'My Model — my event',
    'default_channels'=> ['in_app', 'email'],
    'params_schema'   => ['id', 'name'],          // mustache vars
    'content'         => [
        'ja' => ['subject' => '…', 'body' => '…'],
        'en' => ['subject' => '…', 'body' => '…'],
        'vi' => ['subject' => '…', 'body' => '…'],
    ],
],
```

### 2. Add a system rule

In `SystemNotificationRuleSeeder::systemRules()`, append a new entry:

```php
[
    'name'                => 'System: my model my event',
    'description'         => 'One sentence covering who, what, when.',
    'trigger_event'       => 'model.updated',   // or model.created / custom.*
    'trigger_model_type'  => 'MyModel',          // morph alias — must be in morph map
    'conditions'          => ['field' => 'status', 'op' => 'changed_to', 'value' => 'my_value'],
    'action'              => [
        'template_key'   => 'my_model.my_event',
        'audience_name'  => 'All brand admins',
        'channels'       => ['in_app', 'email'],
        'priority'       => 'normal',
        'param_template' => ['id' => '$model.id', 'name' => '$model.name'],
    ],
    'priority'   => 'normal',
    'is_active'  => true,
],
```

For **custom events** (non-Eloquent triggers — device offline, coupon
expiry, etc.) set `trigger_event` to `custom.<your.event.name>` and
dispatch via:

```php
Event::dispatch('custom.my.event.name', [new CustomNotificationEvent(
    subject: $model,
    changes: [],
    context: ['key' => 'value'],   // extra data available via $context.key in param_template
)]);
```

### 3. Register the morph alias

If `MyModel` is not already in the morph map, add it to
`OmnifyServiceProvider` (generated) or to the manual section of
`AppServiceProvider::boot()`:

```php
Relation::morphMap(['MyModel' => MyModel::class]);
```

### 4. Seed and verify

```sh
php artisan db:seed --class=SystemNotificationRuleSeeder
php artisan db:seed --class=SystemNotificationTemplateSeeder
php artisan notifications:audit-coverage-precheck
```

The precheck command verifies morph-map registration and model field
presence. Exit 0 = ready for activation.

### 5. Write the M8 tests

| Test | What it covers |
|------|----------------|
| `M8-XX: rule is seeded` | `NotificationRule::where('name', '...')->exists()` |
| `M8-XX: template is seeded` | `NotificationTemplate::where('key', '...')->exists()` |
| Arch test (if new emitter class) | Source references `CustomNotificationEvent`; dispatch wrapped |

See `tests/Feature/Notification/SystemRuleSeederTest.php` for patterns.
