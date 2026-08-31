# Plan 023 — Notes

> Discovery log + decision context + drift check for the notification completeness pass. Newest entries at the bottom of each section.

## Drift check (2026-05-15, plan creation)

Verified against `main` as of HEAD before plan creation:

- Plan-008 + plan-012 artefacts present per [DESIGN.md §What's already in place](DESIGN.md#whats-already-in-place). Spot-checked:
  - `php artisan db:show --table=notifications` → 15 columns including `aggregation_key`, `template_key`, `scheduled_for`, `audience_id`, `organization_id`. ✓
  - `backend/app/Services/Notification/NotificationService.php` present, signatures match plan-012 HANDOFF. ✓
  - `brands.reverb_app_id`, `reverb_app_key`, `reverb_app_secret` columns exist via plan-012 migration `2026_04_22_100000`. ✓
  - admin-web `/hq/[brandSlug]/notifications/audiences|templates|routing|compose` pages all present + i18n keys filled. ✓
- `git log --oneline --since="2026-04-23" -- backend/app/Services/Notification/ backend/app/Models/Notification*.php backend/app/Http/Controllers/Api/V1/HQ/Notification* schemas/Backend/Notification/` returns 0 commits since plan-012 merge. Foundation is stable.
- `composer require simshaun/recurr --dry-run` resolves cleanly with current PHP 8.4 + Laravel 13 constraints. Plan-safe to require.
- `pnpm view @playwright/test versions --json | tail -5` shows ≥ 1.50 available. Plan-safe.

**No drift detected. Plan proceeds.**

## Why one PR

Each milestone is sized to be a reviewable commit range, not a separate PR:

| Milestone | Why ride along | Why not a separate PR |
|---|---|---|
| M1 | 4 small tasks, mostly delete | Splitting would require feature-flag re-introduction shim — pointless for ~150 LOC delete |
| M2 | Browser infra needs to exist before M3–M7 add specs | Shipping Playwright config alone delivers zero user value |
| M3, M4, M5, M6, M7 | All depend on M2 being in place (browser specs) and on M1's flag-removal landed first | Sequencing in one PR matches the dependency chain; reviewers can read commit ranges per milestone |
| M8 | Pure additive on top of M7 — seed data + 2 jobs + 1 page. Splitting would leave M7 with no production-relevant rules and force a follow-up PR that touches the same files (seeder, console.php). | Bundled. If review fatigue hits, the cleanest split-point is between M7 and M8 (ship platform, then ship coverage). |

That said, the milestone boundary is a clean commit range. If review fatigue sets in, the alternative is splitting at M2/M3 boundary (ship M1+M2 first, M3–M7 next, M8 last). Discuss with the reviewer if the diff exceeds ~10k LOC.

## Decision context

### Decision 20 (Postmark over SES/Mailgun/SendGrid)

Considered each in turn. Reasoning notes:

- **SES**: cheapest by ~3× vs Postmark. But SNS-backed webhook routing introduces an extra hop + IAM policy work. We have no SNS infra in this project yet — first hop would be measurable extra work. Defer.
- **Mailgun**: clean webhook DX, but EU/US data residency split adds an env-var-driven URL flip that ages badly. Not worth for the volume.
- **SendGrid**: solid product, expensive at our scale, and the recent Twilio rebrand introduces account-management complexity that's overkill here.
- **Postmark**: clean HMAC signature scheme, single webhook URL per event type collapses to one route, dev sandbox has signed test webhooks, pricing scales linearly with our volume.

Open clean-room exit: `MailWebhookContract` + `MailWebhookManager` driver registry means adding SES or Mailgun later is a new class + a `MailWebhookManager::register('ses', ...)` call. No schema, API, or test refactor.

### Decision 21 (shop_admin role, not per-feature permission)

Audited the existing role surface:

- `brand_admin` is the only HQ-side role gating notification surfaces today. No `notifications.manage-*` permission rows exist.
- `shop_admin` mirrors that pattern for every other shop-scoped admin surface in the codebase (orders, materials, tables, settings).
- Per-feature permissions (`notifications.manage-audiences-shop`, etc.) would mean shipping 5 new permission rows for 5 surfaces with no current customer ask for fine-grained delegation. Premature.

If/when a customer wants "let a non-admin user manage only templates", re-open as a separate plan that introduces a permission resource for notifications. Schema-additive.

### Decision 22 (Playwright over Cypress)

- `@godxjp/ui` already uses Playwright for visual snapshots — single test toolchain is a clear win.
- Playwright runs parallel by default; Cypress requires `cypress-parallel`/dashboard. CI run time matters for PR turnaround.
- Cypress is no longer being actively championed by Anthropic peer projects (anecdotal); Playwright is the safer long-bet.
- Trace viewer for debugging is significantly better.

### Decision 23 (parallel-shadow rollout for Phase A emitters via NOTIFICATION_USE_RULES)

The "flip the flag" pattern from plan-012's `NOTIFICATION_USE_AUDIENCE` worked. Re-using that for plan-023's rule-engine cut-over for the 3 Phase A emitters gives:

- Reversibility: roll back instantly by flipping the flag if rule output drifts from hardcoded output
- Visibility: `notifications.rule-shadow-compare --since=14d` is a one-line check before flip
- Coverage: shadow mode logs every would-be firing, so we have audit trail even pre-flip

Cost: ~2 weeks of double-running emitters + rules in shadow. Cheap.

### Decision 24 (rule evaluator blocklist)

If a rule says "when a Notification is created, notify X", the rule fires → `NotificationService::dispatch` creates a Notification → the bridge fires the rule again → infinite loop.

Static blocklist is the cleanest fix:
- `Notification`, `NotificationRecipient`, `NotificationDelivery`, `NotificationRuleFiring`.
- Arch test enforces the blocklist literal so future model adds can't accidentally re-open the loophole.

Considered: dynamic loop detection (count recent firings, abort on cycle). Rejected — adds runtime state + correctness risk. Static blocklist is sufficient because the blocklisted models are infrastructure, not domain.

### Decision 25 (digest hourly, not daily, scheduler)

Users live in different timezones and want different delivery times. A daily scheduler at 00:00 UTC means somebody is getting their digest at 04:00 local time or 18:00 — inconvenient.

Hourly scheduler with per-user eligibility check (`delivery_time.hour == now()->hour` in user's tz) gives 1-hour resolution to any combination of (tz, delivery_time). Idempotency check via `last_sent_at` prevents double-send when daylight-savings transitions cause an hour to repeat.

Worker cost: trivial. The job is `SELECT WHERE eligibility` + per-user mail queue. 1000 users × 1 query/hour × 24 = 24 000 queries/day on the digest preference table, indexed.

### Decision 26 (inbox collapse opt-out via query param)

The bell + `/inbox` will use collapse by default. But a power user might want the flat list, and any FE that hasn't updated to the new resource shape must keep working.

`?collapse=false` is the explicit opt-out. Default is `true` so the new behaviour is the new normal.

### Decision 27 (recurring tick = 5 minutes)

Why not 1 minute?

- 1-min ticks mean every minute the worker reads ~all active schedules. With 1000 active schedules per brand × 10 brands = 10 000 rows. Cheap, but wasteful.
- The composer copy promises "your weekly send will fire between 09:00 and 09:04" — 5-minute resolution is acceptable for broadcast UX (vs transactional UX).
- `withoutOverlapping(15)` is the safety net if a tick takes longer than expected.
- `onOneServer()` prevents duplicate dispatches on a multi-worker deployment.

If a future use case needs 1-minute precision (e.g. SLA-driven alerts), open a follow-up plan to add a separate `everyMinute()` tick scoped to a "high-precision" schedule subset.

## Risk audit (refined post-DESIGN)

| Pre-DESIGN Risk | Status | Mitigation refined |
|---|---|---|
| Audience flip flips noise direction | Active, M1 T1.4 hard gate at 25% diff threshold | If staging diff exceeds threshold, halt M1, re-scope audience rule per emitter |
| Postmark webhook spoofing | Mitigated, T4.3 + T4.5 | HMAC + 5-min replay window + log-and-Sentry on every reject |
| Recurring clock-drift | Mitigated, T3.3 stores UTC + tz | All RRule re-computation reads tz at each tick |
| Digest cardinality blow-up | Mitigated, T5.5 caps sample at 50 | Email body includes "+N more" + filtered inbox link |
| Aggregation cross-brand merge | Mitigated, M5-arch + brand-scoped resolver | Arch test enforces brand boundary on collapse query |
| Rule evaluator infinite loop | Mitigated, static blocklist + arch test | `M7-arch: bridge blocklist enforced` |
| Shop admin escalation | Mitigated, Policy split + arch test | `M6-arch: shop controllers never trust brand-only auth` |
| Browser test flakiness | Mitigated, T2.2 deterministic Echo stub + `expect.poll` | No live WebSocket; tests assert against synchronous emit |

## Open follow-ups (post-plan-023)

Tracked here so a future plan can pick them up:

- **Delete hardcoded Phase A emitter code entirely.** After `NOTIFICATION_USE_RULES=true` has been on in prod for 1 release cycle with zero shadow-compare diffs, delete `StockAlertNotificationObserver` / `CustomerOrderNotificationObserver` / `RecipeService` dispatch calls (replaced by the seeded system rules). Removes ~150 LOC + simplifies the mental model.
- **Push provider integration.** `PushChannel` stub is the last remaining channel that isn't real. Pick FCM or APNs and wire it up. Out of scope here (mobile concern).
- **Cross-organization rule library.** Seeded system rules could be shared across orgs as templates. Out of scope.
- **Aggregation digest mode.** Plan-008 mentioned this; with M5 collapse + digest both shipped, a future plan can let users get "aggregated daily digest" (collapse semantics inside the digest body) for power-user inbox management.
- **Per-feature notification permissions.** Decision 21 left this open — re-open if a customer asks.
- **Real Reverb in CI.** Plan-012 left this open. A future plan can spin up Reverb in a containerised CI job for true end-to-end realtime.

## Glossary additions (cross-plan)

- **Effective channels** — defined in plan-012. Unchanged here.
- **Aggregation key** — non-unique string set by emitters used to group related notifications in the inbox (e.g. `stock.alert.low:warehouse:42`). Empty/null means "do not aggregate".
- **Shadow rule** — A `NotificationRule` row with metadata flag `is_phase_a_shadow=true` that runs in lockstep with the hardcoded Phase A emitter, logging firings without dispatching. Used to validate parity pre-flip. Removed after flag is on.
- **RRULE substring** — RFC-5545 RRULE without the surrounding `BEGIN:VEVENT`/`END:VEVENT` wrapper. We parse and store only the recurrence rule portion.
- **Freeze window** — 60-second pre-occurrence interval during which a scheduled broadcast cannot be cancelled (prevents racing the tick worker).
- **System rule** — A `NotificationRule` row with `is_system=true, organization_id=NULL` seeded by `SystemNotificationRuleSeeder`. Applies to every org via the bridge OR clause. Admins can disable per-brand (future) but not delete. The 18 M8-seeded rules are all system rules.
- **Custom event** — A non-Eloquent notification trigger dispatched via `Event::dispatch('custom.X', [new CustomNotificationEvent(...)])`. The `ModelEventToRuleBridge` listens to `custom.*` and routes through the same rule engine path as Eloquent events. Two scheduled detectors (device offline, coupon expiry) emit these.
- **Coverage catalogue** — Read-only page at `/hq/{brand}/notifications/coverage` showing which models have active notification rules and which don't. Drives M8's success criterion that every approval-workflow model is covered.
- **Approval-workflow model** — Any model class that uses the `HasApprovalWorkflow` trait (currently: Product, Menu, Recipe; Article-level review pending). Enforced to have ≥1 system rule via arch test `M8-arch-1`.

## M8 scope rationale (added 2026-05-15)

User explicitly requested expanding plan-023 to cover HQ + shop emitters that were left silent after M1–M7. M8 was added rather than a separate plan because:

1. M8 depends entirely on M7's rule engine — splitting them risks merge ordering pain
2. The seed data + 2 scheduled jobs is small (~16 tasks, no migrations)
3. Coverage page (T8.13) closes the loop on M7's "rules are admin-visible" promise

**Out of M8 explicitly:**

- Customer-facing notifications (FCM/APNs/Web Push, customer-web inbox) → separate follow-up plan. Push provider abstraction is the bulk of that work.
- TMS/workstation-app FE subscription wire-up → per-app plans (already in §Non-goals).
- Emitter coverage for features that don't exist yet (Export/Import, UserInvite, shift, cash reconciliation, login anomaly, recipe cost history, menu schedule expires_at) → wait for the underlying feature.

**Audit baseline (2026-05-15, Explore agent):** Product, Menu, StockTransaction, StockTransfer, Device, Brand, Coupon, CouponRedemption all exist with the columns M8 rules depend on. All registered in the morph map. Re-verified at T8.1 before seeding.

## M1 rollout diff (deferred — no staging snapshot available in this session)

T1.4 originally required running the audit command against a staging DB snapshot and pasting a 10-row summary here, with a hard gate at 25 % diff per (emitter, brand). **This session does not have access to a staging DB**, so the diff replay is deferred.

The audit command itself (T1.1) ships with 5 Pest scenarios verifying the CSV shape, the cap-N clip, the role-scoping, the emitter filter, and the read-only contract — those are green. When the team next has a staging snapshot, run:

```sh
docker compose exec app php artisan notifications:audit-rollout \
    --since=30d \
    --output=storage/app/plan-023/rollout-diff.csv
```

then paste the 10-row summary below. If any (emitter, brand) row's `diff_pct` exceeds ±25 %, file a blocker note + re-scope the audience rule for that emitter (open a follow-up task, do NOT amend T1.3 — that has already landed unconditionally; re-scope = a NEW commit narrowing the role/scope arguments to `Audience::byRole(...)->scopedTo(...)`).

```
emitter,brand,trigger_id,resolved_pre_flip,resolved_post_flip,diff_pct
…(to be filled in on next staging run)
```

## M1 corrections (2026-05-15)

Two deviations from the README scope, recorded so they don't become folklore:

### Recipe emitter has never had a flag branch

Plan-023 README §Scope and TASKS.md T1.3 both list `RecipeService::approve` + `RecipeService::reject` as call sites needing flag removal. **Reading the actual code (`backend/app/Services/Product/RecipeService.php::dispatchRecipeNotification`) shows there has never been a `config('notifications.use_audience')` branch there.** The Recipe emitter always dispatches to a single hardcoded recipient — the submitter (`User::find($recipe->created_by_id)`) — with silent no-op when the submitter can't be resolved. Plan-008 §"3 canonical emitters" lists Recipe under `Audience::user($recipe->submitted_by_user)`, which collapses to a 1-element collection — equivalent to the current literal `[$submitter]` array. There was nothing to refactor.

Consequence: T1.3 production changes touch only 3 files (`StockAlertNotificationObserver`, `CustomerOrderNotificationObserver`, `config/notifications.php`), not 4. T1.2 ~~`LegacyRecipientResolver`~~ (**ĐÃ XOÁ ở #2413**) shipped with `forStockAlert` (cap-50) + `forCustomerOrder` (cap-20) — no `forRecipe` factory.

### ~~LegacyRecipientResolver~~ moved out of `tests/` (**ĐÃ XOÁ ở #2413**)

TASKS.md T1.2 originally specified `Tests\Support\Notification\LegacyRecipientResolver`. **The audit command in T1.1 is production code (`app/Console/Commands/Notifications/`) and is its primary caller** — PSR-4 autoload-dev would hide the helper from production, so the command would fatal under `composer install --no-dev`. The helper moved to `App\Support\Notification\LegacyRecipientResolver`. The "this is the only place the cap-50 logic survives" intent is preserved — there is still exactly one file holding the cap-N logic.

## Pre-existing test regressions outside M1 scope

`tests/Feature/Notification/SystemNotificationTemplateSeederTest.php` has 2 failing scenarios expecting `count() === 5` but the seeder produces 7 rows. The seeder was extended in plan-012 (post-PR-#121 commits added two template keys) without updating the assertions. Plan-023 M1 does NOT touch the seeder; the regression is inherited drift. Open as a separate cleanup task — out of M1 scope.

## Implementation notes (running log during execution)

### 2026-05-15 — M1 implemented

- Branch: `feature/plan-023-notification-completeness` (off `dev`).
- Commits in M1:
  - `d0db8085` — `docs(plan-023): add notification platform completeness pass plan`
  - `4af2d5b5` — `feat(plan-023): T1.2 extract LegacyRecipientResolver test helper`
  - (T1.1) — `feat(plan-023): T1.1 notifications:audit-rollout console command`
  - (T1.3) — `feat(plan-023): T1.3 delete NOTIFICATION_USE_AUDIENCE flag + cap-N fallback`
  - (T1.4 — this commit) — `docs(plan-023): T1.4 record M1 corrections + deferred staging replay`
- Local Pest: M1-scope **19 / 19 green** (audit-rollout 5, EmitterAudience 4 + 3 grep, LegacyResolver 4, EmitterIntegration 3 post-fixture-update).
- Broader Notification filter: **221 green**, 2 pre-existing failures (template count drift — unrelated; see §"Pre-existing test regressions").
- Decisions taken inline: relocate ~~`LegacyRecipientResolver`~~ (**ĐÃ XOÁ ở #2413**) from `tests/Support` to `app/Support`; drop the Recipe leg from T1.2 / T1.3 because the actual codebase never had a flag branch there.
- Deferred: T1.4 staging replay (no DB snapshot available); picked up by ops when staging is provisioned.

## 2026-05-18 — M8 implementation complete

- Branch: `feature/plan-023-2-notification-coverage` (off `dev`)
- Commits in M8: 17 (branch init + T8.1–T8.16 + seeder-test fix)
- Pest M8 filter: **41 passed (232 assertions)** — all green
- `notifications:audit-coverage-precheck` exits 0 — all 18 rule field references resolve cleanly
- Schedule entries added: `notifications.device-offline.detect` (every 5 min) + `notifications.coupon.expiration-scan` (daily 08:00 Asia/Tokyo)
- Pre-existing test failures (53 on dev, now 52 after seeder-test fix): all outside M8 scope — BranchMenuSchedule, BrandCatalog, Customer auth, TemplateSnapshot, NotificationServerRendering, WarehouseCrud, etc. None introduced by M8.

### Key adaptations vs TASKS.md spec (field mismatches caught by T8.1 precheck)

- **Product/Menu**: TASKS.md said `approval_status` — actual field is `status` (ProductStatusEnum / MenuStatusEnum). Conditions use `status changed_to pending/approved/rejected`. Submitter field is `created_by_id` not `submitted_by_user_id`.
- **StockTransaction**: No `shop_id` or `requested_by_user_id`. Audience scoped via `warehouse_id → Warehouse.branch_id`. Submitter = `created_by_id`.
- **StockTransfer**: No `source_shop_id`/`destination_shop_id`. Uses `source_warehouse_id`/`destination_warehouse_id`; audience resolved via warehouse → branch.
- **Device**: No `shop_id` on Device directly — audience rules use brand-level scoping for device notifications.
- All 25 M8 templates seeded with ja/en/vi content. `SystemNotificationTemplateSeederTest` updated to expect 25 rows (was stale at 5 from original Phase-A plan).
