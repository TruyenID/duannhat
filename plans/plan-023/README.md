---
plan: 023
title: Notification platform — completeness pass
slug: notification-completeness
status: shipped
branch: feature/plan-023-2-notification-coverage
created: 2026-05-15
updated: 2026-08-05
depends_on: [008, 012]
spec: ../plan-012/DESIGN.md
pr:
landed_via: >-
  merged to dev (feature branch deleted). TASKS.md checkboxes are NOT the
  completion signal — plan-027 sits at 0/250 while godx-kds is a live shipping app
  (#1818). Verified by: no feature branch remains, plus a closed tracker or the
  plan's subject being present in the tree.
---

# Plan 023 — Notification platform completeness pass

> Close every notification-platform debt left open by plan-008 and plan-012, **excluding** anything mobile (tms-app) or workstation-app, and excluding SMS (Decision 17 plan-008 stays in force). After this plan, the admin-web notification surface is feature-complete for HQ + shop, email is production-grade with bounce tracking, scheduled broadcasts support recurring schedules, the inbox collapses noisy aggregates and ships a daily digest, the Phase A emitter rollout flag is removed, the workflow rule-builder lets admins declare new emitters without code, and browser tests stop being skipped stubs.

## Motivation

Plan-008 (PR #90, 2026-04-20) shipped the foundation. Plan-012 (PR #121, 2026-04-23) shipped audiences, templates, channels, preferences, per-brand Reverb, and an immediate / single-shot scheduled broadcast composer. Together they put a usable notification platform in production for HQ users.

The platform still has eight known gaps, all called out in plan-012 README §Non-goals + §Known limitations and in plan-008 HANDOFF §Known limitations (cả hai đã archive — xem git history):

1. **Audience engine is dark-launched.** `NOTIFICATION_USE_AUDIENCE=false` is the default in `.env.example`. Prod is still resolving recipients via the cap-50 fallback inside the three Phase A emitters. The audience engine works in test, but ops never flipped the flag in staging or prod. Until flipped, plan-012's M1 effort delivers no production value for those three emitters, and noise complaints predicted in plan-012 §Motivation are still landing.
2. **Email delivery is fire-and-forget.** `EmailChannel` calls `Mail::to()` and marks `notification_deliveries.status='sent'`. Nothing watches for bounces or complaints. SMTP failures roll back through Laravel's queue retries but successful-from-Postmark-then-bounced-at-recipient mail never updates the delivery row — `status='sent'` forever even when the inbox never received it.
3. **Scheduled broadcasts are single-shot.** `notifications.scheduled_for` accepts one timestamp. There is no UI or schema for "every Monday at 9am" or "first day of every month". The composer punts to "send later (once)" only.
4. **`aggregation_key` is a column with no behavior.** The schema persists it (plan-012 M3) and emitters fill it (e.g., `stock.alert.low:warehouse:{id}`), but the inbox lists every notification individually. A warehouse with 30 low-stock materials sends 30 bell-pings to every warehouse manager.
5. **No daily digest.** Users who set `master_mute=true` or who opt out of `email` for a type get zero visibility into accumulated events. There's no opt-in "summarise yesterday's notifications and email me at 8am".
6. **Shop-level admin surface is missing.** `/hq/[brandSlug]/notifications/*` exists for HQ users (audiences, templates, routing, compose, audit). Shop managers cannot CRUD shop-scoped audiences, override template content per shop, or compose a broadcast scoped to their own shop. Today they file a ticket and a brand admin does it for them.
7. **All emitters are code-driven.** Every notification type is born in a service method or observer. Adding "notify the warehouse manager when a recipe's daily cost rises more than 20% vs last week" requires a backend PR. There is no UI for an admin to author a model-event-triggered rule.
8. **Browser tests are skipped stubs.** Plan-012 §Test results lists "9 Browser scenarios written as skipped stubs". They run under no CI gate. Behaviour is also Feature-test-covered, but the wired-up FE surface (rule builder DnD, composer wizard step transitions, realtime bell update) has zero automated coverage at the browser layer.

The longer these gaps stay open, the more we are paying interest on a feature that's only half-delivered. This plan closes all eight in one batch.

## Scope

Eight milestones, one PR. Each milestone maps to a gap above (M8 closes the implicit gap 9 — coverage). M1 → M2 → M3 → M4 → M5 → M6 → M7 → M8 (sequential — M8 depends on M7 because it seeds rules into the engine M7 introduces; M3/M4 unblock recurring + email paths that some M8 rules emit through).

| Milestone | Gap | Tasks | Approx scope |
|-----------|-----|-------|--------------|
| **M1 — Audience rollout finalisation** | 1 | 4 tasks | flip flag, delete fallback, recipient-diff suite, remove `NOTIFICATION_USE_AUDIENCE` env var |
| **M2 — Browser scenario unstub** | 8 | 5 tasks | Wire admin-web Playwright runner, port 9 stubs to active tests, gate CI |
| **M3 — Recurring broadcasts** | 3 | 9 tasks | `notification_schedules` schema, RRULE field, composer "repeats" step, scheduler tick job, edit/cancel UX, occurrence audit |
| **M4 — Email delivery hardening** | 2 | 10 tasks | Postmark driver, provider abstraction (`MailWebhookContract`), bounce/complaint webhook routes, signature verify, `notification_deliveries.status` reconciler, suppression list, retry-deadletter UI |
| **M5 — Aggregation + daily digest** | 4 & 5 | 11 tasks | Inbox collapse by `aggregation_key`, "+N more" expansion, `NotificationDigestPreference`, `DigestBuilderService`, daily / weekly digest job, Markdown digest email template, opt-in flow in `/me/settings/notifications` |
| **M6 — Shop-level admin surface** | 6 | 12 tasks | `/shop/[shopSlug]/notifications/{audiences,templates,routing,compose,audit}`, `notifications.template.*` overrides, scoped resolver behaviour, ShopNotificationPolicy, sidebar entry, FE service layer, browser smoke |
| **M7 — Workflow rule-builder** | 7 | 16 tasks | `notification_rules` schema, condition DSL + evaluator, model-event hook (`ObservedDomainEvent`), HQ + shop rule-builder UI, dry-run preview, rule audit log, system rule seeder |
| **M8 — Emitter coverage seed (HQ + shop)** | 9 | 16 tasks | Seed ~16 system rules + templates for product/menu approval, stock-transaction lifecycle, stock-transfer lifecycle, device pairing/offline, coupon redemption/expiry, brand status; 2 scheduled detectors (device offline, coupon expiry) emitting `custom.*` events; coverage catalogue page; arch test enforcing approval-workflow coverage |

**Total: 83 tasks, 1 PR, 8 milestones reviewable as commit ranges.**

A second-pass count (M1 4 + M2 5 + M3 9 + M4 10 + M5 11 + M6 12 + M7 16 + M8 16) = **83 tasks**.

### Gap 9 — Emitter coverage (added)

Plan-008 + plan-012 + plan-023 M1–M7 deliver the platform; only **5 emitters** ship notifications today (stock alert, customer order status, recipe approval, material expiry, recall). Every other admin-facing state change is silent:

- **HQ:** product approval workflow, menu approval workflow, brand status transitions (active/suspended), device pairing lifecycle, device offline detection, coupon validity transitions
- **Shop:** stock-transaction approval workflow (draft → pending → approved → completed), stock-transfer in-transit / received, coupon redemption, coupon expiring-soon

Admins file tickets to ask "did X happen?" because there's no inbox event. M7 made it *possible* to fix this without code — M8 actually does it by seeding the rules + templates + scheduled detectors. Non-Eloquent triggers (device-offline-by-timeout, coupon-expired-by-clock) emit `custom.*` events that the M7 bridge already listens for (T7.4 extension).

## Non-goals

- **SMS channel.** Plan-008 Decision 17 stays. No `sms` enum case, no `SmsChannel` class, no Twilio binding. Re-opening this is a separate plan.
- **Real push delivery (FCM / APNs).** `PushChannel` remains a stub returning `status='skipped'`. Mobile push is a mobile concern and the user has explicitly excluded it.
- **tms-app and workstation-app realtime subscription.** Backend channels `device.{id}.notifications` exist and remain tested. FE subscription wire-up for each device app is owned by per-app plans (excluded per user instruction).
- **Push provider integration.** Same reason as above.
- **Cross-organization rule sharing.** M7 rules are organization-scoped. No marketplace, no template library, no copy-rule-from-org-A-to-org-B.
- **End-to-end Reverb in CI.** Plan-012 already documented this gap; live Reverb is still not spun up under CI. We add browser tests for the Reverb-consumer hooks via Echo client mocks, not via a real WebSocket server.
- **Migration of existing in-app notifications into a digest summary.** The first digest job runs on day-N+1 against day-N notifications. Backfill of historical inbox into digests is not a goal.
- **i18n for digest email body.** The email **subject** localizes; the body localizes via the same `TemplateRenderer` used elsewhere. We do NOT add a new `digest_email` translation surface — it composes from the existing per-notification `title` + `body`.
- **M8 does NOT cover the following features (they remain silent or out-of-scope):**
  - **Customer-facing notifications.** `CustomerOrderNotificationObserver` notifies shop staff only. No FCM/APNs/Web Push to the customer device, no order-status push to the customer-web app, no customer-side email confirmation/receipt. Customer push is deferred to a dedicated follow-up plan (push provider abstraction, customer auth model, opt-in flow).
  - **TMS app + workstation-app FE wire-up.** Backend `device.{id}.notifications` channel already exists; subscription wire-up is per-app and lives in those repos.
  - **Export / Import job notifications.** No Export/Import models exist in the codebase (confirmed 2026-05-15 audit). Re-open when the feature ships.
  - **User invitation / membership lifecycle notifications.** No UserInvite, BrandMembership, ShopMembership models exist. Out of scope until those ship.
  - **Shop staff / shift / clock-in notifications.** No shift feature exists.
  - **Day-close cash reconciliation notifications.** No cashup feature exists; `OrderClosingService::close` is per-order and already covered by `CustomerOrder.status` rule.
  - **Login anomaly / auth audit alerts.** No login_attempts table. Add when audit infra ships.
  - **Recipe cost spike (>20% week-over-week).** Mentioned in M7 README as a DSL example, but requires Recipe cost versioning history table — not in current schema. Defer.
  - **Category / Topping group lifecycle.** No approval workflow on Category; ToppingGroupItem changes are cascade-only. Admins who care can author rules via M7 UI.
  - **Menu schedule expiry notification.** plan-022 ships menu schedule with `start_time` / `end_time` (recurring weekly) but NO `expires_at`. Re-open if that column is added later.

## Success criteria

### M1 — Audience rollout finalisation

- `config('notifications.use_audience')` removed from `backend/config/notifications.php`
- `NOTIFICATION_USE_AUDIENCE` removed from `.env.example` and from CI env
- `StockAlertNotificationObserver`, `RecipeService::approve/reject`, `CustomerOrderNotificationObserver` all call `Audience::byRole(...)->scopedTo(...)` unconditionally (no `if($flag)` branch)
- Old cap-50 helpers in `NotificationService` removed (search for `take(50)` returns no hits in `backend/app/Services/Notification/`)
- `RecipientDiffAuditCommand` (`php artisan notifications:audit-rollout`) reports zero divergences between pre-flag and post-flag recipient sets across the 3 emitters, run on a staging snapshot
- Pest scenario `M1: emitters unconditionally use Audience` is green

### M2 — Browser scenario unstub

- `admin-web/playwright.config.ts` exists and CI invokes `pnpm test:browser`
- All 9 previously-skipped browser scenarios from plan-012 TESTS.md run in CI (not `test.skip`, not `test.fixme`)
- Each runs in under 30 s wall time, against a Playwright fixture with a seeded brand + a Sanctum-auth user + a stubbed Reverb endpoint
- GitHub Actions workflow `frontend-browser.yml` is green on PR

> **Sửa đổi 2026-08-18 — hai gạch đầu dòng trên KHÔNG còn đúng, và đã không đúng
> từ lâu.**
>
> `frontend-browser.yml` bị xoá ở `802c8494b` ("tách 3 web app ra repo riêng —
> in-tree → submodule"). #2306 gộp ba app **về lại** monorepo nhưng không ai
> khôi phục workflow. Đo 2026-08-18: `git grep -lni playwright .github/` trả về
> **rỗng**, trong khi `web/admin/test/browser/` có **16 file spec**.
>
> Nghĩa là suốt quãng đó tài liệu này khẳng định một cổng đang canh, mà cổng ấy
> không tồn tại — tệ hơn không có tài liệu, vì nó trả lời "có" cho câu hỏi "chỗ
> này đã được canh chưa".
>
> Đã khôi phục **nửa rẻ**: rào arch của bộ browser (quét tĩnh, 241ms, không mở
> trình duyệt) nay chạy trong job `web/admin` của `web-apps.yml`. Chạy 15 spec
> thật cần chromium + `pnpm dev` + backend trên runner — phần đó chưa làm, và
> **đừng đọc dòng trên như thể nó đã xong**.
- Arch test `ensures no notification browser test is skipped` is green

### M3 — Recurring broadcasts

- `notification_schedules` table exists with columns `notification_id` (nullable for templates of recurrence), `rrule` (String 500, RFC 5545 RRULE substring), `timezone` (String 64), `next_occurrence_at` (Timestamp), `last_occurrence_at` (Timestamp, nullable), `occurrences_remaining` (Integer, nullable), `is_active` (Boolean), `status` (Enum: `active`, `paused`, `completed`, `cancelled`)
- Composer UI "repeats" step lets admin pick: no-repeat (default, single-shot), daily, weekly (with weekday picker), monthly (day-of-month picker), custom (RRULE textarea with live "next 5 occurrences" preview)
- `RecurringNotificationDispatcher` invoked by `Schedule::job(...)->everyFiveMinutes()` scans schedules whose `next_occurrence_at <= now()`, materialises a new `Notification` row per occurrence with a fresh idempotency key, updates `next_occurrence_at` via `RRule::nextOccurrence($timezone)`
- `/hq/{brand}/notifications/schedules` lists active + paused + completed schedules with pause/resume/cancel actions
- Pest scenarios under "M3: recurring schedule" all green: every weekday at 9am dispatches Mon–Fri but skips weekend; daylight-savings transition handled correctly (asia/tokyo no-op, america/los_angeles spring-forward); pausing prevents next occurrence; cancelling marks status and stops occurrences

### M4 — Email delivery hardening

- `MailWebhookContract` exists in `backend/app/Contracts/Notification/` with methods `verifySignature(Request)`, `parseEvents(Request)`, `mapToDeliveryStatus(string event)`
- `PostmarkMailWebhookHandler` ships as the default (Decision 20)
- Webhook routes mounted: `POST /api/v1/webhooks/mail/{provider}` (public, signature-verified)
- Signature verification rejects unsigned + replayed requests (timestamp drift > 5 minutes)
- `notification_deliveries.status` transitions on webhook: `sent → delivered`, `sent → bounced`, `sent → complained`, `sent → suppressed`
- `notification_email_suppressions` table tracks suppressed addresses with reason + suppressed_at + (nullable) un-suppressed_at; `EmailChannel::send` checks suppression list before calling `Mail::to()`
- HQ admin page `/hq/{brand}/notifications/email-health` shows last-30d bounce/complaint rate per template, suppression list with un-suppress action
- Retry deadletter — failed deliveries after 3 attempts surface in the same admin page with replay action
- Pest scenarios "M4: email delivery hardening" green: signature verify, bounce status update, complaint sets suppression, suppression blocks send, un-suppress unblocks

### M5 — Aggregation + daily digest

- `GET /me/notifications` accepts `?collapse=aggregation_key` (default `true`) — collapsed rows show `{aggregation_count, latest, sample[≤3]}`
- Bell + `/inbox` display "X new low-stock alerts (warehouse A)" as one collapsed line; expanding shows the individual notifications
- `notification_digest_preferences` table with columns `user_id` (FK), `cadence` (Enum: `off`, `daily`, `weekly`), `delivery_time` (Time, e.g. 08:00), `timezone` (String 64), `last_sent_at` (Timestamp, nullable), `include_priorities` (JSON array, default all)
- `/me/settings/notifications` adds a "Digest" section with cadence + time + priorities multi-select
- `DigestBuilderService::buildFor(User, Date)` returns a structured payload grouped by `aggregation_key` then `type`, with counts + latest title + body
- `SendDigestJob` invoked by `Schedule::job(...)->hourly()` (granular enough to honour any user-selected hour without polling every minute)
- Markdown digest email template renders via `TemplateRenderer` (existing) with the structured payload; subject "Daily digest — {brand_name} — {date}"
- Pest scenarios under "M5: aggregation + digest" green: collapse returns correct shape, cadence=off skips, opted-out priorities excluded, timezone respected, idempotent (running twice for same day sends one email)

### M6 — Shop-level admin surface

- `/shop/[shopSlug]/notifications/audiences`, `/templates`, `/routing`, `/compose`, plus a shop-scoped audit list, mirror the HQ surface with shop scoping
- `ShopNotificationPolicy` authorizes `shop_admin` role (Decision 21) for all five surfaces
- `NotificationAudience.brand_id` extended with optional `shop_id` (FK, CASCADE) — when set, audience is shop-scoped; resolution restricted to users with a shop membership in that shop
- `NotificationTemplate` per-shop override pattern: shop admins create templates with `shop_id` set; resolver picks shop-scoped first, falls back to brand-scoped, falls back to system
- Shop-scoped broadcast composer cannot select brand-level audiences (audience picker filters by `shop_id IN (current_shop, null)` when scope=shop)
- Sidebar entry "Notifications" added under shop sidebar in `admin-web/src/app/shop/[shopSlug]/layout.tsx`
- Pest scenarios "M6: shop-level admin" green: shop_admin can CRUD shop audiences but not brand audiences; template fallback order; cross-shop access denied (404 existence-hide)

### M7 — Workflow rule-builder

- `notification_rules` table with `organization_id`, `brand_id` (nullable), `shop_id` (nullable), `name`, `description`, `trigger_event` (String — `model.created` / `model.updated` / `model.deleted` / `custom.{name}`), `trigger_model_type` (String, morph alias), `conditions` (JSON DSL), `action` (JSON: `{template_key, audience_rule, priority, channels}`), `is_active`, `last_fired_at`, `fire_count`
- Condition DSL is a JSON tree with leaf `{field, op, value}` and node `{combinator: and|or, children}`. Ops: `=`, `!=`, `>`, `<`, `>=`, `<=`, `in`, `not_in`, `changed`, `changed_to`, `changed_from`, `is_null`, `is_not_null`, `matches` (regex). `field` supports dotted paths into the model attributes + relations
- `RuleEvaluatorService::evaluate(NotificationRule, Model, array $changes)` returns bool deterministically and is pure
- `ModelEventToRuleBridge` registered in `AppServiceProvider::boot` listens to `eloquent.created.*`, `eloquent.updated.*`, `eloquent.deleted.*` for every model in the org's morph map; on match, queues `EvaluateRuleJob`
- `EvaluateRuleJob` re-loads the model fresh, evaluates against `conditions`, on true calls `NotificationService::dispatch(...)` with the action config
- HQ rule-builder page `/hq/{brand}/notifications/rules` lists rules + create / edit / clone / disable
- Shop rule-builder at `/shop/{shopSlug}/notifications/rules` shows org-level + brand-level rules (read-only) + shop-level rules (full CRUD)
- Rule editor UI is a tree visual builder using shadcn `Collapsible` + per-leaf field-selector / op-selector / value-input; live "dry-run" preview shows last 5 model rows that would have matched
- `notification_rule_firings` audit log table — every fire writes a row with `rule_id`, `notification_id`, `model_type`, `model_id`, `fired_at`, `evaluation_trace` (JSON for debugging)
- System-seeded rules `SystemNotificationRuleSeeder` migrates the 3 hardcoded Phase A emitters (stock alert, recipe approval, customer order status) into DB rules behind a feature flag (`NOTIFICATION_USE_RULES=false` initially, parallel-shadow mode to confirm parity)
- Pest scenarios "M7: workflow rule builder" green: condition evaluator covers every op + nesting, dry-run preview returns correct sample, parallel-shadow asserts hardcoded emitter output equals rule-engine output for the 3 Phase A types

### M8 — Emitter coverage seed (HQ + shop)

- `SystemNotificationRuleSeeder` (introduced in M7) is extended idempotently to seed the following **16 system rules** alongside the 3 Phase A shadow rules. All seeded rules are `is_active=true` (production-ready) and `is_system=true` (admins cannot delete, can only disable). Templates land in `system_notification_templates` table with `is_system=true` so M6 shop-override still applies cleanly.

  | # | Rule key | Trigger | Audience | Priority | Channels | Aggregation key |
  |---|---|---|---|---|---|---|
  | 1 | `product.submitted_for_approval` | `Product.updated` AND `approval_status changed_to pending` | brand_admin scoped to brand | normal | in_app, realtime, email | `product.submitted:brand:{brand_id}` |
  | 2 | `product.approved` | `Product.updated` AND `approval_status changed_to approved` | `submitted_by_user_id` (single user) + brand_admin | normal | in_app, realtime | none (rare) |
  | 3 | `product.rejected` | `Product.updated` AND `approval_status changed_to rejected` | `submitted_by_user_id` (single user) | high | in_app, realtime, email | none |
  | 4 | `menu.submitted_for_approval` | `Menu.updated` AND `approval_status changed_to pending` | brand_admin scoped to brand | normal | in_app, realtime, email | `menu.submitted:brand:{brand_id}` |
  | 5 | `menu.approved` | `Menu.updated` AND `approval_status changed_to approved` | `submitted_by_user_id` + brand_admin | normal | in_app, realtime | none |
  | 6 | `menu.rejected` | `Menu.updated` AND `approval_status changed_to rejected` | `submitted_by_user_id` | high | in_app, realtime, email | none |
  | 7 | `stock_transaction.submitted` | `StockTransaction.updated` AND `status changed_to pending` | shop warehouse_manager + shop_admin scoped to `shop_id` | normal | in_app, realtime | `stock_txn.submitted:shop:{shop_id}` |
  | 8 | `stock_transaction.approved` | `StockTransaction.updated` AND `status changed_to approved` | `requested_by_user_id` + shop warehouse_manager | normal | in_app, realtime | none |
  | 9 | `stock_transaction.rejected` | `StockTransaction.updated` AND `status changed_to rejected` | `requested_by_user_id` | high | in_app, realtime, email | none |
  | 10 | `stock_transfer.in_transit` | `StockTransfer.updated` AND `status changed_to in_transit` | dest-shop warehouse_manager scoped to `destination_shop_id` | normal | in_app, realtime | `stock_transfer.in_transit:shop:{destination_shop_id}` |
  | 11 | `stock_transfer.received` | `StockTransfer.updated` AND `status changed_to received` | source-shop warehouse_manager scoped to `source_shop_id` | normal | in_app, realtime | none |
  | 12 | `device.paired` | `Device.updated` AND `status changed_to active` AND `paired_at changed` | brand_admin + shop_admin scoped to device's `shop_id` (if any) | normal | in_app, realtime | none |
  | 13 | `device.unpaired` | `Device.updated` AND `status changed_to pending_activation` AND `paired_at changed_from not_null` | brand_admin + shop_admin scoped to device's `shop_id` | high | in_app, realtime, email | none |
  | 14 | `device.offline` | `custom.device.offline.detected` | brand_admin + shop_admin scoped to device's `shop_id` | high | in_app, realtime, email | `device.offline:device:{device_id}` (cooldown 60 min) |
  | 15 | `coupon.redeemed` | `CouponRedemption.created` | shop_admin scoped to redemption's `shop_id` | low | in_app | `coupon.redeemed:coupon:{coupon_id}:shop:{shop_id}` |
  | 16 | `coupon.expiring_soon` | `custom.coupon.expiring` | brand_admin scoped to coupon's `brand_id` | normal | in_app, email | `coupon.expiring:brand:{brand_id}` |
  | 17 | `coupon.expired` | `custom.coupon.expired` | brand_admin scoped to coupon's `brand_id` | low | in_app | `coupon.expired:brand:{brand_id}` |
  | 18 | `brand.status_changed` | `Brand.updated` AND `status changed` | organization owners | high | in_app, realtime, email | none |

  (Table lists 18 entries because items 14 & 16 & 17 are the three `custom.*`-triggered rules. T8.1 narrative counts them as one bundle each, so the section header still reads "16 system rules"; the table breakdown is the implementation truth.)

- `DeviceOfflineDetectionJob` (`Schedule::job(...)->everyFiveMinutes()`) scans `Device::where('status', 'active')->where('last_seen_at', '<', now()->subMinutes(config('notifications.device_offline_threshold_minutes', 15)))` and dispatches `custom.device.offline.detected` event per device, **once per device per cooldown window** (16-min cooldown so detection at minute 0 doesn't re-fire at minute 5). Tracked via `notification_rule_firings.aggregation_key` lookup, not a new table.
- `CouponExpirationScannerJob` (`Schedule::job(...)->dailyAt('08:00')->timezone('Asia/Tokyo')`) runs the two sweeps in order:
  - Coupons with `valid_until` in next 72 h → `custom.coupon.expiring` (idempotent via `notification_rule_firings` lookup keyed on `coupon:{id}:expiring`)
  - Coupons whose `valid_until` crossed `< now()` since last run AND haven't fired `coupon.expired` → `custom.coupon.expired` (idempotent via firings)
- `ModelEventToRuleBridge` (T7.4) extended to listen on `custom.*` events in addition to Eloquent `created/updated/deleted` — payload is `{model_type, model_id, custom_data}`. Bridge looks up active rules where `trigger_event` matches the literal custom string. Documented in M7 + repeated in M8 task list.
- HQ admin page `/hq/{brand}/notifications/coverage` (read-only): catalogue of all seeded system rules + which models are covered + last-fired-at + rule status. Empty-state row for any model in the org's morph map that has zero active rule → "No notification configured" with CTA → `/hq/{brand}/notifications/rules/new?model=X`.
- New templates seeded in `notification_templates` with `is_system=true, brand_id=null, shop_id=null` for all 18 rule keys above (subject + body in ja/en/vi via `TemplateRenderer`).
- Pest scenarios under "M8: emitter coverage" green: each rule fires when its trigger condition holds and no-fires when it doesn't; `DeviceOfflineDetectionJob` idempotency (running twice produces one notification); `CouponExpirationScannerJob` idempotency across multiple days; coverage catalogue endpoint returns the right counts.
- Arch test `every approval-workflow model has at least one active system rule` greps every model class that uses `HasApprovalWorkflow` trait and asserts each appears as `trigger_model_type` in at least one seeded rule.

## Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| **Audience flip flips noise direction** | M1 removes the cap-50 fallback. Some prod brands might have role-membership data that resolves to *more* recipients than the cap-50 union (e.g., 200 warehouse_managers across shops, while cap-50 was sampling 50 random users from the org). | M1 T1.3 runs the `RecipientDiffAuditCommand` against a prod snapshot before the flag flip is committed. If diff > 25% in either direction for any emitter on any brand, M1 T1.2 (the flag delete) is reverted and the audience rule for that role is re-scoped. Documented runbook in `docs/contributing/emitting-notifications.md`. |
| **Postmark webhook spoofing** | An attacker sends a forged webhook claiming bounces for arbitrary email addresses → false suppression → real users stop receiving mail. | T4.3 verifies HMAC signature using `POSTMARK_WEBHOOK_SECRET`. T4.5 enforces timestamp anti-replay (5-minute window). T4.6 logs every rejected webhook with `Log::warning('notification.webhook.rejected', [...])` for Sentry. |
| **Recurring schedule clock-drift** | Worker server timezone ≠ schedule timezone → next occurrence drifts by an hour every DST transition. | T3.3 stores `next_occurrence_at` as UTC and the original `timezone` field for re-computation; every tick reads timezone and recomputes via `php-rrule` library. Tests cover spring-forward and fall-back in tz-aware fixtures. |
| **Digest cardinality blow-up** | A user with 5 000 unread notifications gets a 5 000-line digest email that hits SMTP body limits. | T5.5 caps digest body at top 50 notifications per priority bucket with "...and N more" + deep link to filtered `/inbox?since=`. Subject still summarises total counts. |
| **Aggregation key collisions across brands** | Two brands both have a `warehouse_manager` role and a warehouse with the same generated UUID prefix — collapse merges across brand boundaries. | T5.1 includes `organization_id` + `brand_id` in the collapse key implicitly (resolver scopes to the requesting user's brand). Arch test: `aggregation collapse never merges across brand` asserts brand boundary on collapse query. |
| **Rule evaluator infinite-loop** | Rule "when notification created, notify X" → fires on its own emission → infinite loop. | T7.4 `ModelEventToRuleBridge` ignores Eloquent events on `Notification` + `NotificationRecipient` + `NotificationDelivery` models. Static blocklist. Documented in DESIGN. Arch test enforces the blocklist. |
| **Shop admin escalates to brand scope** | Bug in policy lets `shop_admin` of shop A CRUD brand-level audiences. | T6.4 `ShopNotificationPolicy` defaults deny + explicit allow; `HQ/NotificationAudienceAdminController` distinguishes brand-route vs shop-route via route prefix, never trusts request body for scope. Pest scenario covers cross-scope attempts. |
| **Browser test flakiness** | Reverb-mocked browser scenarios race the Echo client's connection handshake. | T2.2 wraps every realtime scenario in a `expect.poll()` with 5-second timeout + a deterministic Echo stub (factory in `admin-web/test/fixtures/echo.ts`) that emits synchronously, no WS dance. |
| **M8 rule storm on backfill** | First seeder run on a brand with thousands of historical `approval_status` rows could mass-fire rules. | M7 bridge fires on Eloquent **events**, not on historical rows — backfill is a non-event. Seeder asserts no model `touch()` happens during seeding. Pest scenario `M8: seeder does not fire historical rules` covers this. |
| **Scheduled detector double-fire across multi-worker** | `DeviceOfflineDetectionJob` runs every 5 min — if two workers pick it up simultaneously, the same device produces two notifications. | Reuse the same `withoutOverlapping(15)->onOneServer()` pattern as M3 (T3.x). Idempotency also enforced at the firings layer via `aggregation_key` cooldown lookup — if cooldown row exists within window, bridge skips dispatch. |
| **Custom event payload drift** | `custom.device.offline.detected` is a string event with a hand-shaped payload; if a new emitter passes the wrong shape, the rule silently no-fires (no compile-time check). | T8.3 defines `CustomNotificationEvent` value object — every custom emit must construct it. Type-hinted in the bridge listener. Arch test `every custom dispatcher uses CustomNotificationEvent` enforces. |
| **Approval-workflow audience misroute** | `submitted_by_user_id` audience rule depends on the model column existing on Product / Menu / Recipe. If a model lacks it, the rule no-fires silently. | T8.6 / T8.7 / T8.8 each gate on the M7 audience-rule validator (`AudienceRuleValidator::validateFieldPathExists`) — seeder run fails loudly if the column isn't there. Verified against current schema before commit. |
| **Coverage catalogue lies** | The `/notifications/coverage` page lists "no notification configured" rows based on the morph map; a model could be in the morph map but irrelevant (e.g., pivot tables). Page would prompt admins to configure rules for noise. | T8.13 maintains an opt-out list `coverage.excluded_models` in `config/notifications.php` (pivots, audit log, notification-domain models themselves). Default-populated by the seeder. |

## Dependencies

- **plan-008** and **plan-012** merged to `main`. ✓ Done (PR #90, PR #121).
- **Postmark account.** Bootstrap test creds in `.env.example` resolve to Postmark's developer sandbox by default; prod creds injected via secret-manager. Decision 20 in DESIGN locks Postmark as the default; M4 ships a `MailWebhookContract` abstraction so swapping to SES/Mailgun/SendGrid is additive — see DESIGN §Decision 20.
- **`php-rrule`** Composer package (for M3 RRULE parsing): `composer require simshaun/recurr`.
- **`@playwright/test`** pnpm package (for M2): `pnpm add -D @playwright/test`.
- **No DB rollback required for M1, M2** — those are FE / config / code only.
- **6 new migrations** for M3 / M4 / M5 / M7 — see DESIGN.
- **M8 adds zero new migrations.** Only seed data + 2 scheduled console entries + 1 admin page. Uses `notification_rules`, `notification_rule_firings`, `notification_templates` introduced in M7 / M6 / M4.
- **M8 requires every approval-workflow model to be in the morph map.** Audit (per Explore agent on 2026-05-15): all relevant models (Product, Menu, StockTransaction, StockTransfer, Device, Brand, Coupon, CouponRedemption) are registered via `OmnifyServiceProvider::enforceMorphMap` and `AppServiceProvider::morphMap`. Re-verify in T8.1 before seeding.

## Follow-ups (intentionally out of this plan)

- Mobile / workstation push providers + FE subscription (per-app plans).
- Customer-facing notification stack: push provider abstraction + FCM/APNs/Web Push drivers, `Customer` recipient morph, customer-web FE inbox/toast, opt-in flow, transactional email (order confirm, receipt PDF, pickup ready).
- SMS channel re-opening (separate plan if Decision 17 is revisited).
- Cross-organization rule library / marketplace.
- A/B variants on broadcast composer.
- Bounce / complaint webhook receivers for SES, Mailgun, SendGrid (only Postmark ships in this plan; the contract supports the others).
- Rule-engine perf optimisation (current design queues an `EvaluateRuleJob` per matching model event — fine up to ~50 rules × ~1000 events/min; sharding deferred).
- Emitter coverage for features that ship later (User invite, Export/Import jobs, shift assignment, day-close reconciliation, recipe cost history). M8 leaves clean extension points: add a new system rule via `SystemNotificationRuleSeeder` + an entry on the coverage catalogue page — no code beyond seed + template.

## Links

- Plan-008 (foundation): plan-008 README (đã archive — xem git history)
- Plan-012 (broadcast platform): plan-012 README (đã archive — xem git history)
- This plan: [DESIGN.md](DESIGN.md) · `TASKS.md` · `TESTS.md` · [NOTES.md](NOTES.md)
- Postmark webhook docs: https://postmarkapp.com/developer/webhooks/webhooks-overview
- RFC 5545 RRULE: https://datatracker.ietf.org/doc/html/rfc5545#section-3.8.5.3
- simshaun/recurr (PHP RRULE): https://github.com/simshaun/recurr
