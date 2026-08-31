# Plan 023 — Design

## Context

Read [README.md](README.md) first for motivation and milestone breakdown. This document specifies the schema, services, contracts, decisions, and screen layouts. **Plan-008 DESIGN and plan-012 DESIGN remain the canonical architecture** — nothing in this plan changes their data-path semantics. Every addition here is additive: new tables, new columns, new endpoints, new screens, new jobs.

## What's already in place

Before touching files listed here, confirm:

| Artifact | Source plan | Verification |
|---|---|---|
| `notifications`, `notification_recipients` tables | 008 | `php artisan db:show --table=notifications` |
| `notification_audiences`, `notification_templates`, `notification_channel_routes`, `notification_preferences`, `notification_deliveries` | 012 | `db:show --table=…` for each |
| `brands.reverb_app_*` columns | 012 | `Schema::hasColumn('brands', 'reverb_app_id')` |
| `NotificationService::dispatch`, `AudienceResolverService`, `EffectiveChannelService`, `TemplateRenderer`, `BrandReverbAppService` | 012 | grep |
| 3 emitters (`StockAlertNotificationObserver`, `RecipeService`, `CustomerOrderNotificationObserver`) call `dispatch()` behind `NOTIFICATION_USE_AUDIENCE` flag | 012 | code review |
| HQ admin pages (audiences / templates / routing / compose / audit) | 012 | `/hq/[brandSlug]/notifications/...` routes |
| `<NotificationBell>`, `/inbox`, `useNotificationRealtime()` | 008 + 012 | grep |
| 1 602 passing Pest scenarios | 012 | `vendor/bin/pest --compact` |

If any of these is missing, STOP — the foundation has drifted. Re-run the drift check before proceeding.

## Approach summary by milestone

| Milestone | Strategy |
|---|---|
| **M1** | Delete-only. No new schemas. Remove a feature flag + dead-code path; lock in the audience resolution. |
| **M2** | Pure FE additive. Bring Playwright into the admin-web build, replace 9 stubs with real specs. |
| **M3** | One new schema + one new service + one tick job + composer step. Reuses existing `notifications` row as the materialised occurrence. |
| **M4** | One new schema (suppressions) + one new contract + one driver (Postmark) + webhook routes + admin page. Provider abstraction means SES/Mailgun additive later. |
| **M5** | One new schema (digest prefs) + one new service + one daily job + collapse logic in the existing inbox controller (query param, default on). |
| **M6** | Scope-aware variants of the 5 existing HQ controllers + 5 page mirrors + 2 column additions (`shop_id` on audience + template). Policy split. |
| **M7** | One new schema (rules) + one audit schema + one service + one event bridge + one job + 2 pages. Parallel-shadow flag for safe rollout. |
| **M8** | Seed-only + 2 scheduled jobs + custom-event extension to the M7 bridge + 1 read-only coverage page. Zero new schemas. Pure additive on top of M7. |

---

## M1 — Audience rollout finalisation

### Files touched

- `backend/config/notifications.php` — remove `'use_audience'` key
- `backend/.env.example` — remove `NOTIFICATION_USE_AUDIENCE` line
- `backend/app/Observers/StockAlertNotificationObserver.php` — remove `if ($flag)` branch, inline audience call
- `backend/app/Observers/CustomerOrderNotificationObserver.php` — same
- `backend/app/Services/Product/RecipeService.php` (recipe.approved / recipe.rejected dispatches) — same
- `backend/app/Services/Notification/NotificationService.php` — delete `legacyCapFifty…` helpers if any (search by grep first)
- `backend/app/Console/Commands/Notifications/AuditRolloutCommand.php` — **new**

### `AuditRolloutCommand`

```
php artisan notifications:audit-rollout {--brand= : audit a single brand} {--since=7d : recent window}
```

For each of the 3 Phase A emitters, replays the trigger condition against the last `--since` window and emits a CSV:

```
emitter,brand,trigger_id,resolved_pre_flip,resolved_post_flip,diff_pct
stock_alert,brand-acme,si-2026-05-11-warehouse-A,52,73,+40.4%
recipe.approved,brand-acme,re-2026-05-12-789,18,18,0.0%
…
```

Pre-flip = recipients the cap-50 fallback would have produced (replicated, since the prod code is being deleted in the same PR — we keep a `LegacyRecipientResolver` test-only class for replay).
Post-flip = `Audience::byRole(...)->scopedTo(...)->resolve()`.

The command is **read-only**. It does not dispatch notifications. It is a safety net for the diff in §Risks.

### Test expectations

- All Pest scenarios under "Audience resolver" in plan-012/TESTS.md continue to pass without the flag harness (no `Config::set('notifications.use_audience', false)` setups remain).
- New scenario: `M1: emitters unconditionally use Audience` greps the 3 emitter source files for the literal `use_audience` token and asserts zero occurrences.
- New scenario: `M1: AuditRolloutCommand reports diff` runs against a seeded fixture and asserts a known divergence count.

---

## M2 — Browser scenario unstub

### Tooling decision

**Decision 22 — Playwright over Cypress.** `@godxjp/ui` already includes Playwright snapshots; admin-web does not. We unify on Playwright because (a) `pnpm test:browser` already exists in the `@godxjp/ui` workspace and CI dev rules call it, (b) Playwright runs faster with parallel workers and supports tracing for flake debugging, (c) Cypress would add a second browser-test toolchain to learn.

### Files added

- `admin-web/playwright.config.ts`
- `admin-web/test/browser/notifications/` — directory with 9 test files (one per scenario)
- `admin-web/test/fixtures/{echo,session}.ts` — Echo stub + Sanctum session stub
- `.github/workflows/frontend-browser.yml` — CI workflow (depends on the existing `frontend-typecheck.yml` pattern)
- `admin-web/package.json` — `"test:browser": "playwright test"` script
- `pnpm-lock.yaml` — `@playwright/test` and `playwright-core` entries

### Echo stub fixture

```ts
// admin-web/test/fixtures/echo.ts
export function installEchoStub(page: Page) {
  return page.addInitScript(() => {
    (window as any).Echo = {
      private: (channel: string) => ({
        listen: (event: string, cb: any) => {
          (window as any).__echoListeners ??= {};
          (window as any).__echoListeners[`${channel}.${event}`] = cb;
          return this;
        },
      }),
    };
  });
}
export async function emitEcho(page: Page, channel: string, event: string, payload: unknown) {
  await page.evaluate(([c, e, p]) => {
    const key = `${c}.${e}`;
    (window as any).__echoListeners?.[key]?.(p);
  }, [channel, event, payload]);
}
```

Tests can synchronously emit a notification arrival without spinning Reverb.

### Scenarios ported (9)

From plan-012 TESTS.md §Browser:

1. Audience rule-builder DnD updates preview count
2. Audience detail Sheet opens via card click + View button
3. Template editor live render preview updates with params
4. Routing matrix toggle persists across reload
5. User preferences quiet-hours timepicker round-trips
6. Broadcast composer 3-step wizard advance / back / submit
7. Broadcast composer audience preview shows count + sample
8. Bell badge increments on Echo emit
9. Bell dropdown links to `/inbox`

Each becomes one `.spec.ts` file under `admin-web/test/browser/notifications/`.

### CI gate

`.github/workflows/frontend-browser.yml`:

```yaml
on: pull_request
jobs:
  browser:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v3
      - run: pnpm install --frozen-lockfile
      - run: pnpm --filter admin-web exec playwright install --with-deps
      - run: pnpm --filter admin-web build
      - run: pnpm --filter admin-web exec playwright test
        env:
          NEXT_PUBLIC_API_URL: http://127.0.0.1:5400
```

Backend mocked via MSW for the FE-only contract; no Laravel boot required.

---

## M3 — Recurring broadcasts

### Schema — `notification_schedules`

```yaml
# schemas/Backend/Notification/NotificationSchedule.yaml
columns:
  organization_id: { type: foreignId, references: organizations.id, onDelete: cascade }
  brand_id:        { type: foreignId, references: brands.id, onDelete: cascade }
  template_key:    { type: string, length: 100 }
  audience_id:     { type: foreignId, references: notification_audiences.id, onDelete: restrict }
  channels:        { type: json }                      # array of NotificationChannel enum
  priority:        { type: string, length: 20 }
  params:          { type: json, nullable: true }
  rrule:           { type: string, length: 500 }       # RFC-5545 substring, no nested VCALENDAR
  timezone:        { type: string, length: 64 }        # IANA tz name
  starts_at:       { type: timestamp }
  ends_at:         { type: timestamp, nullable: true }
  occurrences_remaining: { type: integer, nullable: true }
  next_occurrence_at:    { type: timestamp, nullable: true }
  last_occurrence_at:    { type: timestamp, nullable: true }
  status:          { type: string, length: 20 }        # active | paused | completed | cancelled
  created_by_user_id: { type: foreignId, references: users.id, onDelete: setNull }
indexes:
  - [next_occurrence_at, status]                       # tick query
  - [brand_id, status]
```

### Service — `RecurringNotificationDispatcher`

```php
namespace App\Services\Notification;

class RecurringNotificationDispatcher
{
    public function __construct(
        private NotificationService $notifications,
        private NotificationScheduleRepository $repo,
        private Clock $clock,
    ) {}

    public function tick(): TickResult
    {
        return DB::transaction(function () {
            $due = $this->repo->dueSchedules($this->clock->now());      // SELECT ... FOR UPDATE SKIP LOCKED
            foreach ($due as $schedule) {
                $this->materialiseOccurrence($schedule);
                $this->advanceNextOccurrence($schedule);
            }
            return new TickResult(count: $due->count());
        });
    }

    private function materialiseOccurrence(NotificationSchedule $s): void
    {
        $this->notifications->dispatch(
            type: $s->template_key,
            recipients: Audience::fromRule($s->audience->rule),
            params: $s->params ?? [],
            priority: $s->priority,
            channels: $s->channels,
            idempotencyKey: sprintf('schedule:%d:occurrence:%s', $s->id, $s->next_occurrence_at->toIso8601String()),
        );
    }

    private function advanceNextOccurrence(NotificationSchedule $s): void
    {
        $rrule = new \Recurr\Rule($s->rrule, $s->starts_at, $s->ends_at, $s->timezone);
        $tx    = new \Recurr\Transformer\ArrayTransformer();
        $next  = collect($tx->transform($rrule))
            ->map->getStart()
            ->first(fn ($d) => $d->getTimestamp() > $this->clock->now()->getTimestamp());
        $s->last_occurrence_at = $s->next_occurrence_at;
        $s->next_occurrence_at = $next;
        if ($s->occurrences_remaining !== null) {
            $s->occurrences_remaining -= 1;
            if ($s->occurrences_remaining <= 0) {
                $s->status = 'completed';
                $s->next_occurrence_at = null;
            }
        }
        if ($next === null) {
            $s->status = 'completed';
        }
        $s->save();
    }
}
```

### Tick scheduling

`backend/routes/console.php`:

```php
Schedule::call(fn () => app(RecurringNotificationDispatcher::class)->tick())
    ->everyFiveMinutes()
    ->withoutOverlapping(15)        # 15-min stale lock
    ->onOneServer()                 # multi-worker safe
    ->name('notifications.recurring.tick');
```

Five-minute granularity is the resolution promise. A user setting "every Monday at 9:00" gets dispatched between 9:00 and 9:04. Documented in the composer copy.

### API endpoints

```
GET    /api/v1/hq/{brand}/notifications/schedules                # paginated list
POST   /api/v1/hq/{brand}/notifications/schedules                # create
GET    /api/v1/hq/{brand}/notifications/schedules/{id}           # detail (with next 5 occurrences preview)
PATCH  /api/v1/hq/{brand}/notifications/schedules/{id}           # edit (only future occurrences affected)
DELETE /api/v1/hq/{brand}/notifications/schedules/{id}           # cancel (status='cancelled')
POST   /api/v1/hq/{brand}/notifications/schedules/{id}/pause
POST   /api/v1/hq/{brand}/notifications/schedules/{id}/resume
POST   /api/v1/hq/{brand}/notifications/schedules/preview-rrule  # body: {rrule, timezone, starts_at} → next 5 occurrences (auth required, rate-limited 20/min)
```

### Composer wizard — "repeats" step

After step 3 (delivery), insert step 4 (recurrence):

```
[ ] One-time send (default)
[ ] Repeats
    ○ Daily at HH:MM            (rrule: FREQ=DAILY;BYHOUR=...)
    ○ Weekly on [M T W T F S S] at HH:MM   (rrule: FREQ=WEEKLY;BYDAY=...;BYHOUR=...)
    ○ Monthly on day N at HH:MM            (rrule: FREQ=MONTHLY;BYMONTHDAY=N;BYHOUR=...)
    ○ Custom RRULE
Timezone: [Asia/Tokyo ▾]
Ends: ○ Never  ○ After N occurrences  ○ On date YYYY-MM-DD

Next 5 occurrences (live preview):
  2026-05-19 09:00 JST
  2026-05-26 09:00 JST
  …
```

Live preview hits `POST /schedules/preview-rrule`.

---

## M4 — Email delivery hardening

### Decision 20 — Postmark default, contract-driven

- **Chose:** Postmark as the default mail provider, with a `MailWebhookContract` abstraction so SES / Mailgun / SendGrid can be added additively.
- **Why:** Postmark has the cleanest webhook DX (single endpoint per event type with HMAC-friendly basic-auth + signed token option), short integration path, dev sandbox available for `.env.example`. Open the abstraction in this same PR so future providers are not a refactor — they are a new driver class registered in a service-provider map.
- **Rejected:** SES (cheapest, but SNS webhook routing is heavyweight for our scale), Mailgun (good but the EU/US split adds config drift), SendGrid (good DX but pricier than Postmark for our volume).
- **Switching providers later:** add a new driver class implementing `MailWebhookContract`, register it in `MailWebhookManager`, point `MAIL_WEBHOOK_DRIVER` env. No schema change, no API change.

### Contract

```php
namespace App\Contracts\Notification;

interface MailWebhookContract
{
    /** Reject the request if signature is invalid or replayed. Throws \App\Exceptions\WebhookVerificationException on failure. */
    public function verifySignature(Request $request): void;

    /** Parse the provider-specific payload into our canonical event list. */
    public function parseEvents(Request $request): array; // [ EmailEvent, ... ]

    /** Map provider-specific event name to internal NotificationDeliveryStatus. */
    public function mapToDeliveryStatus(string $providerEvent): NotificationDeliveryStatus;
}
```

`EmailEvent` value object:

```php
final readonly class EmailEvent
{
    public function __construct(
        public string $messageId,        // provider's tracking id (matches notification_deliveries.provider_ref)
        public string $recipientEmail,
        public NotificationDeliveryStatus $status,
        public ?string $reason,          // bounce reason, complaint type
        public DateTimeImmutable $occurredAt,
        public array $raw,               // full payload for audit
    ) {}
}
```

### PostmarkMailWebhookHandler

```php
namespace App\Services\Notification\Webhooks;

class PostmarkMailWebhookHandler implements MailWebhookContract
{
    public function verifySignature(Request $req): void
    {
        $signature = $req->header('X-Postmark-Signature');                  # base64 HMAC-SHA1
        $expected  = base64_encode(hash_hmac('sha1', $req->getContent(), config('services.postmark.webhook_secret'), true));
        if (! hash_equals($expected, $signature ?? '')) {
            throw new WebhookVerificationException('signature_mismatch');
        }
        $timestamp = $req->json('DeliveredAt') ?? $req->json('BouncedAt') ?? $req->json('ReceivedAt');
        if (Carbon::parse($timestamp)->diffInMinutes(now()) > 5) {
            throw new WebhookVerificationException('replay');
        }
    }

    public function parseEvents(Request $req): array
    {
        $type = $req->json('RecordType');     # Delivery | Bounce | SpamComplaint | …
        return [new EmailEvent(
            messageId:      $req->json('MessageID'),
            recipientEmail: $req->json('Recipient') ?? $req->json('Email'),
            status:         $this->mapToDeliveryStatus($type),
            reason:         $req->json('Details'),
            occurredAt:     Carbon::parse($req->json('DeliveredAt') ?? $req->json('BouncedAt') ?? $req->json('ReceivedAt')),
            raw:            $req->all(),
        )];
    }

    public function mapToDeliveryStatus(string $event): NotificationDeliveryStatus
    {
        return match ($event) {
            'Delivery'                              => NotificationDeliveryStatus::Delivered,
            'Bounce', 'HardBounce'                  => NotificationDeliveryStatus::Bounced,
            'SpamComplaint'                         => NotificationDeliveryStatus::Complained,
            'SubscriptionChange'                    => NotificationDeliveryStatus::Suppressed,
            default                                 => throw new \InvalidArgumentException("Unknown Postmark event: {$event}"),
        };
    }
}
```

### Webhook route

```
POST /api/v1/webhooks/mail/{provider}      # public, signature-verified by provider handler
```

`backend/routes/api.php`:

```php
Route::post('webhooks/mail/{provider}', MailWebhookController::class)
    ->middleware(['throttle:120,1'])         # 120 req/min ceiling — far above Postmark expected volume
    ->name('webhooks.mail');
```

Controller dispatches `ApplyEmailEventJob::dispatch($event)` per event for async DB writes (keeps webhook 200-OK SLA tight).

### Suppression schema

```yaml
# schemas/Backend/Notification/NotificationEmailSuppression.yaml
columns:
  organization_id: { type: foreignId, references: organizations.id, onDelete: cascade }
  email:           { type: string, length: 254 }       # RFC 5321 max
  reason:          { type: string, length: 50 }        # hard_bounce | spam_complaint | manual
  source_provider: { type: string, length: 50 }
  suppressed_at:   { type: timestamp }
  un_suppressed_at:{ type: timestamp, nullable: true }
indexes:
  - [organization_id, email]                            # unique-ish lookup
unique:
  - [organization_id, email, reason]                    # one row per (org, email, reason)
```

`EmailChannel::send` now:

```php
public function send(Notification $n, NotificationRecipient $r): NotificationDelivery
{
    $email = $r->recipient->email;
    if ($this->suppressions->isSuppressed($n->organization_id, $email)) {
        return $this->markSkipped($n, $r, reason: 'suppressed');
    }
    // existing Mail::to() call …
}
```

### Admin page — `/hq/{brand}/notifications/email-health`

Three tabs:

1. **Deliverability** — 30-day rolling bounce % + complaint % per template; sparkline; "Investigate" CTA links to filtered delivery log.
2. **Suppression list** — paginated table of suppressed addresses with reason + suppressed_at + "Un-suppress" action (requires `notifications.manage-suppressions` permission).
3. **Deadletter** — deliveries stuck at `status='failed'` after 3 attempts; per-row "Retry" action re-queues with attempts reset.

### Decisions cascaded into env

```ini
MAIL_WEBHOOK_DRIVER=postmark
POSTMARK_WEBHOOK_SECRET=replace-me
POSTMARK_TOKEN=replace-me           # send-side, already used by Laravel's mail config
```

---

## M5 — Aggregation + daily digest

### Inbox collapse

`GET /me/notifications?collapse=aggregation_key` (default `true` via query default). Resource shape:

```jsonc
{
  "data": [
    {
      "id": "agg:stock.alert.low:warehouse-42",            // synthetic id for collapsed rows
      "is_collapsed": true,
      "aggregation_key": "stock.alert.low:warehouse-42",
      "type": "stock.alert.low",
      "count": 14,
      "latest": { /* NotificationResource shape of the most recent in the bucket */ },
      "sample": [ /* up to 3 most-recent NotificationResource items */ ],
      "first_at": "2026-05-15T08:11:00Z",
      "last_at":  "2026-05-15T11:42:00Z"
    },
    {
      "id": "nt-9921",
      "is_collapsed": false,
      "aggregation_key": null,
      /* normal NotificationResource shape */
    }
  ]
}
```

Pass `?collapse=false` for the flat list (back-compat).

### Service — `InboxCollapseService`

```php
class InboxCollapseService
{
    public function collapseFor(User $user, InboxFilters $f): Collection
    {
        $query = NotificationRecipient::query()
            ->forRecipient($user)
            ->joinNotifications()
            ->filter($f);

        // Two-pass approach:
        // 1. Buckets keyed by aggregation_key (NULL → itself, not collapsed)
        // 2. Per bucket: COUNT + first_at + last_at + sample of latest 3
        return $query->get()
            ->groupBy(fn ($r) => $r->notification->aggregation_key ?? "single:{$r->notification->id}")
            ->map(fn ($bucket) => $this->buildCollapsedView($bucket));
    }
}
```

Brand boundary preserved implicitly because `forRecipient($user)` already scopes to recipient rows the user is part of, which are FK-scoped to organisations/brands.

### Digest schema

```yaml
# schemas/Backend/Notification/NotificationDigestPreference.yaml
columns:
  user_id:           { type: foreignId, references: users.id, onDelete: cascade, unique: true }
  cadence:           { type: string, length: 10 }       # off | daily | weekly
  delivery_time:     { type: time }                     # HH:MM in user's tz
  timezone:          { type: string, length: 64 }
  weekday:           { type: tinyInteger, nullable: true } # for weekly, 0–6 (Sun=0)
  include_priorities:{ type: json }                     # array, default ['urgent','high','normal','low']
  last_sent_at:      { type: timestamp, nullable: true }
```

### Service — `DigestBuilderService`

```php
class DigestBuilderService
{
    public function buildFor(User $user, DateRange $window): ?DigestPayload
    {
        $rows = NotificationRecipient::query()
            ->forRecipient($user)
            ->whereBetween('created_at', [$window->start, $window->end])
            ->whereIn('notifications.priority', $user->digestPreference?->include_priorities ?? [])
            ->joinNotifications()
            ->get();
        if ($rows->isEmpty()) return null;
        return new DigestPayload(
            user: $user,
            window: $window,
            countsByType:     $rows->groupBy('notification.type')->map->count(),
            countsByPriority: $rows->groupBy('notification.priority')->map->count(),
            sample: $rows->sortByDesc('created_at')->take(50),   // capped — see Risks
        );
    }
}
```

### Hourly job

```php
class SendDigestJob implements ShouldQueue
{
    public function handle(DigestBuilderService $b, UserRepository $users)
    {
        $now = now();
        $eligible = $users->dueForDigest($now);  // queries NotificationDigestPreference for users whose delivery_time matches now()->hour() in their tz AND last_sent_at < today (or this week for weekly)
        foreach ($eligible as $user) {
            $payload = $b->buildFor($user, DateRange::since($user->digestPreference->last_sent_at ?? $now->subDay()));
            if ($payload === null) continue;
            Mail::to($user->email)->queue(new DigestMail($payload));
            $user->digestPreference->update(['last_sent_at' => $now]);
        }
    }
}

# scheduled hourly
Schedule::job(new SendDigestJob)->hourly()->name('notifications.digest.send');
```

### Mail template

`backend/resources/views/emails/notification-digest.blade.php` — Markdown component. Uses `TemplateRenderer` to render each notification's `title` (no body — keep digest scannable). Subject: `"Digest — {$brand_name} — {$date}"`.

### `/me/settings/notifications` extension

New "Digest" section after the existing quiet-hours block:

```
Daily / weekly summary
  Send me a summary email of recent notifications.
  Cadence: ○ Off  ● Daily  ○ Weekly [Monday ▾]
  Time:    [08:00] [Asia/Tokyo ▾]
  Include priorities: [✓ Urgent] [✓ High] [✓ Normal] [☐ Low]
```

---

## M6 — Shop-level admin surface

### Decision 21 — Permission model

- **Chose:** `shop_admin` role authorises all shop-level notification surfaces. Mirrors the existing `brand_admin` for HQ.
- **Why:** Reuses the role infrastructure already in place. No new permission resource needed. Aligns with how all other shop-scoped admin pages (orders, materials, tables) gate on `shop_admin`. Per-feature permissions (`notifications.manage-audiences-shop`, etc.) would let us be finer-grained later but add 5 rows to a permission table for zero current need — defer.
- **Rejected:** Dedicated permissions `notifications.manage-audiences-shop`, `notifications.manage-templates-shop`, etc. Over-engineered for current scope. Re-open if a customer asks for delegating only-template-management to a non-admin role.

### Schema additions

```yaml
# delta on NotificationAudience.yaml
columns:
  shop_id: { type: foreignId, references: shops.id, onDelete: cascade, nullable: true, after: brand_id }

# delta on NotificationTemplate.yaml
columns:
  shop_id: { type: foreignId, references: shops.id, onDelete: cascade, nullable: true, after: brand_id }
```

### Resolver behaviour

`AudienceResolverService`:

- When the dispatch is brand-scoped (org event), only `shop_id IS NULL OR shop_id IN (brands_shops)` audiences are eligible (already true via FK).
- When the dispatch is shop-scoped (shop event), the resolver auto-restricts the resulting recipient set to `User`s who hold a membership in that shop. Implemented by intersecting the resolved set with `ShopMembership::query()->where('shop_id', $shop->id)->pluck('user_id')`.

`TemplateRenderer::resolveTemplate(string $key, Brand $brand, ?Shop $shop): NotificationTemplate`:

```
1. notification_templates WHERE key=$key AND shop_id=$shop?->id      (if shop given)
2. notification_templates WHERE key=$key AND shop_id IS NULL AND brand_id=$brand->id
3. notification_templates WHERE key=$key AND brand_id IS NULL AND shop_id IS NULL AND is_system=true
4. throw NotificationTemplateNotFoundException
```

Cached in-request via `Container::scoped()` to avoid 3-query waterfall per notification on bulk inbox fetches.

### API routes

Mirror HQ under `/api/v1/shops/{shopSlug}/notifications/*` with the same shape. Controller classes:

- `ShopNotificationAudienceController`
- `ShopNotificationTemplateController`
- `ShopNotificationChannelRouteController`
- `ShopNotificationBroadcastController`
- `ShopNotificationAdminController`           # audit list

All extend a shared `ShopNotificationControllerConcern` that resolves `$shop = Shop::whereSlug(...)->firstOrFail()` from route params and runs `$this->authorize('manageX', $shop)` on every method.

### Policy split

`ShopNotificationPolicy`:

```php
public function manageAudiences(User $u, Shop $s): bool {
    return $u->hasShopRole($s, 'shop_admin');
}
// + manageTemplates / manageRouting / compose / viewAudit
```

`NotificationPolicy` (HQ) stays untouched. Each policy resolves on the route prefix (`/shops/*` vs `/hq/*`), never on a request-body scope claim — eliminates the escalation risk from §Risks.

### FE routes & pages

```
admin-web/src/app/shop/[shopSlug]/notifications/
  page.tsx                          # audit
  audiences/page.tsx
  audiences/[id]/page.tsx
  templates/page.tsx
  templates/[id]/page.tsx
  routing/page.tsx
  compose/page.tsx
```

Each FE page imports a shared component from `admin-web/src/components/notifications/admin/` parametrized on `scope: 'hq' | 'shop'`. The scope flag controls:

- Audience picker filter (`scope=shop` hides brand-only audiences)
- "Save" payload (`scope=shop` includes `shop_id`)
- Cancel-redirect URL prefix

No duplication of the rule-builder, no copy-paste of the template editor.

---

## M7 — Workflow rule-builder

### Schema — `notification_rules`

```yaml
# schemas/Backend/Notification/NotificationRule.yaml
columns:
  organization_id: { type: foreignId, references: organizations.id, onDelete: cascade }
  brand_id:        { type: foreignId, references: brands.id, onDelete: cascade, nullable: true }
  shop_id:         { type: foreignId, references: shops.id, onDelete: cascade, nullable: true }
  name:            { type: string, length: 200 }
  description:     { type: text, nullable: true }
  trigger_event:   { type: string, length: 100 }    # model.created | model.updated | model.deleted | custom.{name}
  trigger_model_type: { type: string, length: 100, nullable: true }  # morph alias e.g. "recipe", null for custom events
  conditions:      { type: json }                   # DSL tree
  action:          { type: json }                   # { template_key, audience_rule, priority, channels }
  cooldown_minutes:{ type: integer, default: 0 }    # min minutes between consecutive fires per (rule, model_id)
  is_active:       { type: boolean, default: false }# rules ship inactive — admin enables explicitly
  last_fired_at:   { type: timestamp, nullable: true }
  fire_count:      { type: integer, default: 0 }
  created_by_user_id: { type: foreignId, references: users.id, onDelete: setNull }
indexes:
  - [trigger_event, is_active]                      # bridge lookup
  - [brand_id, is_active]
  - [shop_id, is_active]

# schemas/Backend/Notification/NotificationRuleFiring.yaml — audit log
columns:
  rule_id:         { type: foreignId, references: notification_rules.id, onDelete: cascade }
  notification_id: { type: foreignId, references: notifications.id, onDelete: setNull, nullable: true }
  model_type:      { type: string, length: 100, nullable: true }
  model_id:        { type: unsignedBigInteger, nullable: true }
  fired_at:        { type: timestamp }
  outcome:         { type: string, length: 20 }    # matched | skipped_cooldown | skipped_condition | error
  evaluation_trace:{ type: json, nullable: true }   # per-condition pass/fail for debugging
  error_message:   { type: text, nullable: true }
indexes:
  - [rule_id, fired_at]
  - [model_type, model_id, fired_at]
```

### Condition DSL

```jsonc
// node
{ "combinator": "and", "children": [ /* node or leaf */ ] }
// leaf
{ "field": "total_amount", "op": ">", "value": 10000 }
// leaf with change semantics — only valid in trigger_event=model.updated
{ "field": "status", "op": "changed_to", "value": "approved" }
// leaf with relation traversal
{ "field": "branch.organization.tier", "op": "in", "value": ["enterprise"] }
```

Supported ops:

| Op | Semantics |
|---|---|
| `=`, `!=` | Strict equality (PHP `===`/`!==`) |
| `>`, `<`, `>=`, `<=` | Numeric / date comparison |
| `in`, `not_in` | Array membership |
| `is_null`, `is_not_null` | Null check |
| `matches` | PCRE regex match (server-side timeout 50ms via `preg_match` cap) |
| `changed` | `$changes[$field]` exists (only on `model.updated`) |
| `changed_to` | `$changes[$field]['new'] === value` |
| `changed_from` | `$changes[$field]['old'] === value` |

### Evaluator

```php
class RuleEvaluatorService
{
    public function evaluate(NotificationRule $rule, Model $model, array $changes = []): EvaluationResult
    {
        $trace = [];
        $matched = $this->evaluateNode($rule->conditions, $model, $changes, $trace);
        return new EvaluationResult($matched, $trace);
    }

    private function evaluateNode(array $node, Model $m, array $ch, array &$trace): bool
    {
        if (isset($node['combinator'])) {
            $childResults = array_map(fn ($c) => $this->evaluateNode($c, $m, $ch, $trace), $node['children']);
            return $node['combinator'] === 'and' ? !in_array(false, $childResults, true) : in_array(true, $childResults, true);
        }
        $actual = $this->resolveField($node['field'], $m, $ch);
        $ok = $this->compareOp($node['op'], $actual, $node['value'] ?? null);
        $trace[] = ['field' => $node['field'], 'op' => $node['op'], 'expected' => $node['value'] ?? null, 'actual' => $actual, 'pass' => $ok];
        return $ok;
    }
}
```

Pure. Idempotent. No DB writes. All side effects deferred to caller.

### Event bridge

`ModelEventToRuleBridge` registered in `AppServiceProvider::boot`:

```php
Event::listen('eloquent.created: *', function ($eventName, array $payload) { … });
Event::listen('eloquent.updated: *', function ($eventName, array $payload) { … });
Event::listen('eloquent.deleted: *', function ($eventName, array $payload) { … });
```

For each:

1. Resolve `morphAlias` from the model class.
2. **Blocklist**: if model class ∈ `[Notification, NotificationRecipient, NotificationDelivery, NotificationRuleFiring]` → return early. Prevents infinite loops.
3. Find matching active `NotificationRule`s where `trigger_event = "model.{verb}"` AND `trigger_model_type = $morphAlias` AND `is_active = true`.
4. For each match: `EvaluateRuleJob::dispatch($rule, $model->getKey(), $model->getMorphClass(), $changes)`.

### EvaluateRuleJob

```php
class EvaluateRuleJob implements ShouldQueue
{
    public function handle(RuleEvaluatorService $eval, NotificationService $notify)
    {
        $rule = NotificationRule::find($this->ruleId);
        if (! $rule?->is_active) return;
        $model = ($this->modelClass)::find($this->modelId);
        if (! $model) return;                                    # model deleted between event + job — skip silently
        if ($this->isInCooldown($rule, $model)) return $this->logFiring($rule, $model, 'skipped_cooldown');
        $result = $eval->evaluate($rule, $model, $this->changes);
        if (! $result->matched) return $this->logFiring($rule, $model, 'skipped_condition', $result->trace);
        $params  = $this->buildParams($rule, $model);
        $n = $notify->dispatch(
            type:          $rule->action['template_key'],
            recipients:    Audience::fromRule($rule->action['audience_rule']),
            params:        $params,
            priority:      $rule->action['priority'],
            channels:      $rule->action['channels'],
            actor:         $rule,                                  # morphs to NotificationRule for audit
        );
        $rule->increment('fire_count');
        $rule->update(['last_fired_at' => now()]);
        $this->logFiring($rule, $model, 'matched', $result->trace, $n->id);
    }
}
```

### Parallel-shadow rollout for Phase A emitters

System rules seeded by `SystemNotificationRuleSeeder` for the 3 hardcoded Phase A emitters, marked `is_active=false` until parity is confirmed.

Behind `NOTIFICATION_USE_RULES=false` (default), the seeded rules run in **shadow mode**: rule fires, builds the notification payload, **but does not dispatch** — instead writes a row to `notification_rule_firings` with `outcome='shadow'` and the would-be notification id (computed but rolled back). Comparison report:

```
php artisan notifications:rule-shadow-compare --since=7d
```

emits a CSV of (hardcoded_emitter_fired, rule_shadow_fired, match?) per Phase A event. When parity holds for 1 week, flip `NOTIFICATION_USE_RULES=true` and the hardcoded emitters short-circuit (no-op).

### Rule editor UI

`/hq/{brand}/notifications/rules` → list. `/hq/{brand}/notifications/rules/[id]` → editor:

```
Trigger
  Event: [model.updated ▾]
  Model: [Recipe ▾]

When (conditions — visual tree builder, drag to nest)
  └─ AND
      ├─ status                    [=]      [approved]
      └─ submitted_by_user.role    [in]     [chef, sous_chef]

Then
  Template:  [recipe.approved ▾]
  Audience:  [audience picker — re-uses M6 component]
  Priority:  [normal ▾]
  Channels:  [✓ in_app] [✓ realtime] [☐ email] [☐ push]
  Cooldown:  [0] minutes between fires for the same model row

Dry run
  ▶ Run against last 7 days
    → 23 model rows matched. Sample:
       - Recipe #1241 (2026-05-12 08:14)
       - Recipe #1239 (2026-05-11 18:02)
       - …

[Save inactive] [Save & activate]
```

Shop view at `/shop/{shopSlug}/notifications/rules` lists org-level + brand-level rules **read-only**, plus shop-level rules with full CRUD.

---

---

## M8 — Emitter coverage seed (HQ + shop)

### Goal

Close the implicit coverage gap left after M1–M7: the platform is feature-complete but only 5 emitters wire it (stock alert, customer order, recipe approval, expiry alert, recall). M8 lights up the rest by **seeding system rules** through M7's `SystemNotificationRuleSeeder` rather than writing new observer classes. Non-Eloquent triggers (device-offline-by-timeout, coupon-expired-by-clock) emit `custom.*` events to the same bridge.

This milestone deliberately avoids new schemas, new services beyond two scheduled jobs, and any FE beyond a read-only coverage catalogue. Adding a future emitter becomes: seed a rule + seed a template — no code.

### Audit baseline (recorded 2026-05-15)

Re-verified by the Explore agent before this DESIGN was finalised. Implementations exist for: Product, Menu, StockTransaction, StockTransfer, Device, Brand, Coupon, CouponRedemption, Recipe (already wired), CustomerOrder (already wired), StockAlert (already wired), MaterialLot expiry (already wired), Recall (already wired). All registered in the morph map (`OmnifyServiceProvider::enforceMorphMap` or `AppServiceProvider::morphMap`).

Not implemented (excluded from M8 — see Non-goals in README): Export/Import jobs, UserInvite/membership, shift assignment, cash reconciliation, login anomaly tracking, recipe cost history, menu schedule `expires_at`.

### Decision 28 — Seed-not-code for emitter coverage

- **Chose:** All new emitter coverage in M8 lands as `SystemNotificationRuleSeeder` rows, not as new observer classes. Custom-event detectors (device offline, coupon expiry) are the only new PHP code paths — and they dispatch a single `Event::dispatch('custom.X', payload)` line, deferring all routing logic to the rule engine.
- **Why:** Reusing M7 means coverage is admin-visible (rules list page), admin-editable (disable a noisy rule without a deploy), and admin-extensible (clone-and-modify pattern). Writing a `ProductApprovalObserver` would put coverage back in code, defeating M7's purpose.
- **Rejected:** Per-domain observer classes (defeats M7), generic `HasNotificationOnStatusChange` trait on models (couples domain model to notification concern, harder to disable per-brand).
- **Switching later:** A seeded rule can be promoted to a dedicated observer if performance demands it (e.g., if rule evaluation per event becomes a hot path). Migration path: write the observer, set `notification_rules.is_active=false` for that key, ship. Documented in `docs/explanation/notification-rules.md` §"When to graduate a rule to code".

### Decision 29 — Custom-event payload via `CustomNotificationEvent` value object

- **Chose:** Every `Event::dispatch('custom.X', ...)` must pass a single `CustomNotificationEvent` value object. The bridge type-hints the listener on this class. String events keep the rule-trigger surface flat (matches DSL `trigger_event` directly) but the payload stays type-safe.
- **Why:** A free-form array payload (option rejected: `Event::dispatch('custom.X', [...])`) would drift silently across emitters; the rule no-fires and nobody notices. Value object catches mismatches at construction time.
- **Implementation:**
  ```php
  final readonly class CustomNotificationEvent
  {
      public function __construct(
          public string $eventName,            // 'custom.device.offline.detected'
          public Model $subject,               // the model the event is about
          public array $changes = [],          // synthetic changes payload for rule evaluator (e.g., ['last_seen_at' => ['old' => ..., 'new' => ...]])
          public array $context = [],          // free-form, non-evaluator data the template can render
          public ?Carbon $occurredAt = null,
      ) {}
  }
  ```
- **Bridge listener:**
  ```php
  Event::listen('custom.*', function (string $eventName, array $payload) {
      $event = $payload[0] ?? null;
      if (! $event instanceof CustomNotificationEvent) {
          Log::warning('notification.custom-event.bad-shape', ['event' => $eventName, 'payload' => $payload]);
          return;
      }
      $this->dispatchMatchingRules($event);
  });
  ```
- **Arch enforcement:** T8.15 arch test asserts every `Event::dispatch('custom.` literal in `backend/app/` is followed by a `CustomNotificationEvent::class` constructor in the same call.

### Decision 30 — Cooldown for scheduled detectors via firings table

- **Chose:** Re-use `notification_rule_firings.aggregation_key` (introduced in M7) as the idempotency key for scheduled detectors. No new tracking table.
- **How:** `DeviceOfflineDetectionJob` queries `NotificationRuleFiring::where('aggregation_key', "device.offline:device:{$device->id}")->where('fired_at', '>', now()->subMinutes(16))->exists()` before dispatching. If true → skip. The 16-min lookback ensures the every-5-min tick doesn't double-fire (5+5+5 < 16).
- **Why:** Avoids a new dedupe table; firings already have the index `[rule_id, fired_at]` and `[model_type, model_id, fired_at]` which serves this query. Cooldown is per-aggregation-key not per-rule globally, so two different devices going offline at the same minute each fire correctly.
- **Coupon scanner:** uses aggregation key `coupon:{id}:expiring` and `coupon:{id}:expired` — same lookback pattern but 24 h.

### Seeded rules

The 16+2 rules (16 distinct keys; entries 14, 16, 17 are `custom.*`-triggered) are codified in `database/seeders/Notification/SystemNotificationRuleSeeder.php` as PHP array literals — kept in one file so the catalogue page (T8.13) can read it for "expected vs actual":

```php
private function rules(): array
{
    return [
        // ─── HQ approval workflows ─────────────────────────────────────────
        [
            'key' => 'product.submitted_for_approval',
            'trigger_event' => 'model.updated',
            'trigger_model_type' => 'product',
            'conditions' => [
                'combinator' => 'and',
                'children' => [
                    ['field' => 'approval_status', 'op' => 'changed_to', 'value' => 'pending'],
                ],
            ],
            'action' => [
                'template_key' => 'product.submitted_for_approval',
                'audience_rule' => ['type' => 'role_scoped', 'role' => 'brand_admin', 'scope' => 'brand'],
                'priority' => 'normal',
                'channels' => ['in_app', 'realtime', 'email'],
                'aggregation_key_pattern' => 'product.submitted:brand:{brand_id}',
            ],
            'is_active' => true,
            'is_system' => true,
        ],
        // … 17 more entries, same shape …
    ];
}
```

The seeder is **idempotent** — keyed by `(organization_id NULL, key)` it uses `updateOrCreate` so re-runs against an existing DB do not duplicate. System rules have `organization_id = NULL` and `is_system = true`; they apply to every org via a JOIN in the bridge resolution step (the bridge ORs `WHERE organization_id = ?` with `OR is_system = true`).

### Audience rule shorthand for M8

M7 defined `audience_rule` as JSON. M8 standardises three reusable shapes referenced by the seeded rules:

| Shape | Resolves to |
|---|---|
| `{type: 'role_scoped', role: 'X', scope: 'brand'}` | Users with role X within the model's `brand_id` |
| `{type: 'role_scoped', role: 'X', scope: 'shop', shop_field: 'shop_id'}` | Users with role X within the model's `shop_id` (column name configurable for cases like `destination_shop_id`) |
| `{type: 'model_user', field: 'submitted_by_user_id'}` | Single user referenced by FK on the model |
| Composite (multiple audiences): `{type: 'union', members: [..., ...]}` | Union of resolved sets, deduplicated |

Resolution lives in `AudienceRuleResolver::resolve(array $rule, Model $model): Collection<User>`. Implemented as a small `match` over `$rule['type']`. New shapes can be added without schema change.

### `DeviceOfflineDetectionJob`

```php
namespace App\Jobs\Notification;

class DeviceOfflineDetectionJob implements ShouldQueue
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly NotificationRuleFiringRepository $firings,
    ) {}

    public function handle(): void
    {
        $threshold = config('notifications.device_offline_threshold_minutes', 15);
        $cooldown  = config('notifications.device_offline_cooldown_minutes', 60);

        $cutoff = now()->subMinutes($threshold);
        $devices = Device::query()
            ->where('status', DeviceStatus::Active)
            ->where('last_seen_at', '<', $cutoff)
            ->cursor();

        foreach ($devices as $device) {
            $aggKey = "device.offline:device:{$device->id}";
            $recentlyFired = NotificationRuleFiring::query()
                ->where('aggregation_key', $aggKey)
                ->where('fired_at', '>', now()->subMinutes($cooldown))
                ->exists();
            if ($recentlyFired) continue;

            Event::dispatch('custom.device.offline.detected', [
                new CustomNotificationEvent(
                    eventName: 'custom.device.offline.detected',
                    subject:   $device,
                    changes:   ['last_seen_at' => ['old' => $device->last_seen_at, 'new' => null]],
                    context:   ['minutes_offline' => now()->diffInMinutes($device->last_seen_at)],
                )
            ]);
        }
    }
}
```

Scheduled in `backend/routes/console.php`:

```php
Schedule::job(DeviceOfflineDetectionJob::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->onOneServer()
    ->name('notifications.device-offline.detect');
```

### `CouponExpirationScannerJob`

```php
class CouponExpirationScannerJob implements ShouldQueue
{
    public function handle(): void
    {
        $now = now();

        // Pass 1: expiring within next 72h
        Coupon::query()
            ->whereBetween('valid_until', [$now, $now->copy()->addHours(72)])
            ->cursor()
            ->each(function (Coupon $coupon) {
                if ($this->alreadyFired("coupon:{$coupon->id}:expiring", hours: 24)) return;
                Event::dispatch('custom.coupon.expiring', [
                    new CustomNotificationEvent(
                        eventName: 'custom.coupon.expiring',
                        subject: $coupon,
                        context: ['hours_remaining' => $coupon->valid_until->diffInHours(now())],
                    )
                ]);
            });

        // Pass 2: already expired since last sweep
        Coupon::query()
            ->where('valid_until', '<', $now)
            ->where('valid_until', '>', $now->copy()->subHours(28))   // window slightly > daily cadence
            ->cursor()
            ->each(function (Coupon $coupon) {
                if ($this->alreadyFired("coupon:{$coupon->id}:expired", hours: 168)) return;
                Event::dispatch('custom.coupon.expired', [
                    new CustomNotificationEvent(
                        eventName: 'custom.coupon.expired',
                        subject: $coupon,
                    )
                ]);
            });
    }

    private function alreadyFired(string $aggregationKey, int $hours): bool { /* … */ }
}
```

Scheduled daily at 08:00 Asia/Tokyo (configurable via `config('notifications.coupon_scan_time')`):

```php
Schedule::job(CouponExpirationScannerJob::class)
    ->dailyAt(config('notifications.coupon_scan_time', '08:00'))
    ->timezone('Asia/Tokyo')
    ->onOneServer()
    ->name('notifications.coupon.expiration-scan');
```

### Coverage catalogue page — `/hq/{brand}/notifications/coverage`

Read-only (no CRUD). Three tables:

1. **Active system rules** — list of all 18 seeded rule keys + their `last_fired_at` + 30-day fire count, sorted by trigger event. Click a row → `/hq/{brand}/notifications/rules/{id}` (read-only detail; cannot edit system rules but can disable per-brand via `NotificationRuleOverride` — out of scope, mention as follow-up).
2. **Models with notification coverage** — every model in the morph map that has at least one active rule (system or custom), with rule count. Green tick.
3. **Models without notification coverage** — every model in the morph map MINUS the opt-out list (`config('notifications.coverage.excluded_models')`, populated by T8.13) MINUS models with active rules. Red row with CTA "Configure a rule" → `/hq/{brand}/notifications/rules/new?model=X`.

Backend: `GET /api/v1/hq/{brand}/notifications/coverage` returns the three sets. Auth: `notifications.viewCoverage` ability, granted to `brand_admin`.

### Template seed

`SystemNotificationTemplateSeeder` (extends the M4 / M6 template seeder pattern) seeds 18 templates with `is_system=true, brand_id=NULL, shop_id=NULL`:

```php
[
    'key' => 'product.submitted_for_approval',
    'subjects' => [
        'ja' => '【承認依頼】商品「{{ product.name }}」',
        'en' => 'Approval requested: product "{{ product.name }}"',
        'vi' => 'Yêu cầu phê duyệt: sản phẩm "{{ product.name }}"',
    ],
    'bodies' => [
        'ja' => '{{ user.name }} さんが商品「{{ product.name }}」の承認を依頼しています。',
        'en' => '{{ user.name }} has submitted product "{{ product.name }}" for approval.',
        'vi' => '{{ user.name }} đã gửi sản phẩm "{{ product.name }}" để duyệt.',
    ],
    'priority' => 'normal',
    'is_system' => true,
],
// … 17 more …
```

Shop-scoped templates (rules with `shop_id` in audience scope) follow the M6 fallback resolver — shop admins can override per-shop via `notification_templates.shop_id`.

### File map for M8

```
backend/
  app/
    Events/Notification/
      CustomNotificationEvent.php                   # NEW (Decision 29)
    Jobs/Notification/
      DeviceOfflineDetectionJob.php                 # NEW
      CouponExpirationScannerJob.php                # NEW
    Services/Notification/
      AudienceRuleResolver.php                      # NEW (shorthand resolver)
      ModelEventToRuleBridge.php                    # EDIT — extend with custom.* listener
  config/
    notifications.php                               # EDIT — add device_offline_*, coupon_scan_time, coverage.excluded_models
  database/seeders/Notification/
    SystemNotificationRuleSeeder.php                # EDIT — extend with 16 new keys
    SystemNotificationTemplateSeeder.php            # EDIT — extend with 18 new template entries
  routes/
    console.php                                     # EDIT — 2 new Schedule::job entries
    api.php                                         # EDIT — add /hq/{brand}/notifications/coverage route
  app/Http/Controllers/Api/V1/HQ/
    NotificationCoverageController.php              # NEW (read-only catalogue)
admin-web/
  src/app/hq/[brandSlug]/notifications/
    coverage/page.tsx                               # NEW
  src/services/notifications/
    coverage.service.ts                             # NEW (React Query hook)
docs/
  contributing/emitting-notifications.md            # EDIT — add §"Adding a new emitter via system rule"
  explanation/notification-rules.md                 # EDIT — add §"When to graduate a rule to code"
```

### Why no migrations

M8 reuses every schema introduced earlier:

- `notification_rules` (M7) — new rows only
- `notification_rule_firings` (M7) — cooldown lookup
- `notification_templates` (plan-012 + M6 shop scope) — new system rows
- `notification_audiences` — referenced indirectly via `audience_rule` JSON, no FK
- `notifications` / `notification_recipients` / `notification_deliveries` (plan-008) — runtime targets

The audit confirmed every model needed by the 16 rules already exists with the required columns. Re-verification command in T8.1: `php artisan notifications:audit-coverage-precheck` (one-shot dev tool, deletable after merge).

## New env vars

```ini
# M3
# (none)

# M4
MAIL_WEBHOOK_DRIVER=postmark
POSTMARK_WEBHOOK_SECRET=replace-me

# M5
NOTIFICATION_DIGEST_DAILY_DEFAULT_TIME=08:00       # used by seeder for new users

# M7
NOTIFICATION_USE_RULES=false                       # parallel-shadow during M7 rollout

# M8
NOTIFICATION_DEVICE_OFFLINE_THRESHOLD_MINUTES=15   # last_seen_at older than this → considered offline
NOTIFICATION_DEVICE_OFFLINE_COOLDOWN_MINUTES=60    # min minutes between consecutive device-offline notifications for the same device
NOTIFICATION_COUPON_SCAN_TIME=08:00                # daily coupon expiry scan time (Asia/Tokyo by default)
```

Removed in M1:

```ini
NOTIFICATION_USE_AUDIENCE=false                    # deleted
```

## Config additions (`backend/config/notifications.php`)

```php
return [
    // … existing keys …

    // M8
    'device_offline_threshold_minutes' => env('NOTIFICATION_DEVICE_OFFLINE_THRESHOLD_MINUTES', 15),
    'device_offline_cooldown_minutes'  => env('NOTIFICATION_DEVICE_OFFLINE_COOLDOWN_MINUTES', 60),
    'coupon_scan_time'                 => env('NOTIFICATION_COUPON_SCAN_TIME', '08:00'),
    'coverage' => [
        // models in the morph map to exclude from the "missing coverage" list on the catalogue page
        'excluded_models' => [
            'audit_log',
            'notification', 'notification_recipient', 'notification_delivery', 'notification_rule_firing',
            'topping_group_item_sku',   // cascade-only pivot
            'role_user_pivot',          // pivot
        ],
    ],
];
```

## New migrations

1. `2026_05_16_000001_create_notification_schedules_table.php` (M3)
2. `2026_05_16_000002_create_notification_email_suppressions_table.php` (M4)
3. `2026_05_16_000003_create_notification_digest_preferences_table.php` (M5)
4. `2026_05_16_000004_add_shop_id_to_notification_audiences_table.php` (M6)
5. `2026_05_16_000005_add_shop_id_to_notification_templates_table.php` (M6)
6. `2026_05_16_000006_create_notification_rules_table.php` (M7)
7. `2026_05_16_000007_create_notification_rule_firings_table.php` (M7)

**M8 adds zero migrations** — seed data + jobs + console schedule + 1 read-only HTTP route + 1 admin page only.

All omnify-generated — schemas land in `schemas/Backend/Notification/`.

## Decisions cheat-sheet (this plan)

| # | Decision | Where |
|---|---|---|
| 20 | Postmark default + `MailWebhookContract` abstraction | M4 |
| 21 | Shop notification surface gated on `shop_admin` role (no per-feature permission split) | M6 |
| 22 | Playwright as the admin-web browser-test runner (not Cypress) | M2 |
| 23 | Rule engine parallel-shadow rollout for Phase A emitters via `NOTIFICATION_USE_RULES` | M7 |
| 24 | Rule evaluator blocklist of notification-domain models to prevent infinite loops | M7 |
| 25 | Digest cadence granularity = hourly job (not daily) so users can pick any hour in their tz | M5 |
| 26 | Inbox collapse opt-out via `?collapse=false` query param (default on) | M5 |
| 27 | Recurring tick = every 5 minutes, with `withoutOverlapping(15)` + `onOneServer()` | M3 |
| 28 | Emitter coverage shipped as seeded system rules, not new observer classes | M8 |
| 29 | Custom-event payload via `CustomNotificationEvent` value object (not free-form array) | M8 |
| 30 | Scheduled-detector cooldown via `notification_rule_firings.aggregation_key` lookup (no new dedupe table) | M8 |

## Open questions

- **Digest weekly cadence: which weekday is the default?** Lean: Monday for `weekly`. Confirm during M5 T5.7.
- **Rule editor preview window** — last 7 days hardcoded vs admin-configurable? Lean: hardcoded 7 days for v1; add a window picker only if user feedback asks.
- **Suppression un-suppress audit trail** — write to `audit_logs` table or to a notification-specific table? Lean: `audit_logs` (reuses existing) — confirm during M4 T4.8.
- **Cross-plan deletion semantics on `notification_rules.shop_id`** — cascade-on-shop-delete is already in the FK; what about brand delete? Lean: cascade on both. Spec'd in schema above.

## References

- Plan-008 DESIGN (canonical): plan-008 DESIGN (đã archive — xem git history)
- Plan-012 DESIGN: plan-012 DESIGN (đã archive — xem git history)
- RFC 5545 RRULE: https://datatracker.ietf.org/doc/html/rfc5545
- Recurr library: https://github.com/simshaun/recurr
- Postmark webhooks: https://postmarkapp.com/developer/webhooks/webhooks-overview
- Playwright config reference: https://playwright.dev/docs/test-configuration
