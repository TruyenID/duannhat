---
title: Notification rules (workflow rule builder)
category: explanation
tags: [notification, rule-engine, plan-023]
summary: "The admin-authored workflow rule engine layered on the notification platform, so a new notification type no longer needs a backend PR."
related: [notifications, emitting-notifications]
---

# Notification Rules (workflow rule-builder)

Plan-023 M7 adds an admin-authored workflow rule engine on top of the
existing notification platform. Pre-M7 every notification type was
born in code — adding "notify the warehouse manager when a recipe's
daily cost rises 20% week-over-week" required a backend PR. M7 lets
admins declare the rule in DB without touching code.

This doc covers the **mental model** + **operational rollout**. For
the data shape, see `schemas/Backend/Notification/NotificationRule.yaml`
+ `NotificationRuleFiring.yaml`. For the API surface, see
`docs/reference/notifications-api.md`.

## End-to-end picture

```
$recipe->update(['approval_status' => 'approved'])
   ↓ Eloquent fires `eloquent.updated: App\Models\Recipe`
   ↓
ModelEventToRuleBridge::handle
   ├─ Static BLOCKLIST check (Notification / Recipient / Delivery /
   │   RuleFiring — infinite-loop guard)
   ├─ morph alias resolve (try/catch ClassMorphViolation; unmapped
   │   classes skip silently — they could never appear in a rule
   │   anyway because the controller validates trigger_model_type
   │   against the morph map)
   ├─ Query active rules WHERE trigger_event='model.updated'
   │   AND trigger_model_type='Recipe' AND is_active=true
   └─ For each match: EvaluateRuleJob::dispatch
                        ↓ queue: notifications-rule-evaluation
   EvaluateRuleJob::handle
   ├─ Re-load rule + model fresh (defensive — could have been
   │   disabled / deleted between bridge enqueue and worker pick)
   ├─ Cooldown query — has the same (rule, model) fired within
   │   cooldown_minutes? If yes, log `outcome=skipped_cooldown` and return
   ├─ RuleEvaluatorService::evaluate(rule, model, $changes)
   │   └─ Pure-function — 13 ops + AND/OR short-circuit + per-leaf
   │      trace recording
   │
   ├─ Mismatch → log `outcome=skipped_condition` with trace, return
   ├─ Match + NOTIFICATION_USE_RULES=false (default) →
   │   log `outcome=shadow` with trace; DON'T dispatch (parallel-
   │   shadow rollout)
   └─ Match + flag=true → build params from action.param_template
      → NotificationService::dispatch → log `outcome=matched` with
      trace + notification_id; bump rule.fire_count / last_fired_at
```

## The condition DSL

A rule's `conditions` column is a JSON tree.

### Combinator nodes

```jsonc
{ "combinator": "and", "children": [ /* nodes */ ] }
{ "combinator": "or",  "children": [ /* nodes */ ] }
```

Empty `and` evaluates to `true`; empty `or` evaluates to `false`. AND
short-circuits on the first false child; OR short-circuits on the
first true child. Both record every evaluated child to the trace
(short-circuited siblings are left out, which keeps traces small on
rejected branches).

### Leaf nodes

```jsonc
{ "field": "approval_status", "op": "changed_to", "value": "approved" }
```

`field` supports dotted paths up to **3 levels deep**
(`branch.organization.tier`). Deeper paths force the evaluator to walk
relations that emitters rarely eager-load — capped by the validator.

### 13 supported ops

| Op | Semantics |
|---|---|
| `=` / `!=` | Permissive equality (PHP `==` so `1 == "1"`) + strict `===` short-circuit |
| `>` / `<` / `>=` / `<=` | Numeric comparison; non-numeric → false |
| `in` / `not_in` | Membership in a value array |
| `is_null` / `is_not_null` | Null check |
| `matches` | PCRE regex with backtrack cap. Literal substring if no delimiter; otherwise the pattern is used verbatim |
| `changed` | Field present in `$changes` map (model.updated only) |
| `changed_to` | `$changes[field][1] == value` |
| `changed_from` | `$changes[field][0] == value` |

`matches` runs with `pcre.backtrack_limit = 50_000` so a pathological
regex cannot lock the worker. Invalid regex returns false silently.

### Special fields

- `__changed.column` — true if `column` is present in the changes map,
  regardless of values. Equivalent to `op=changed` but addressable
  as a field path (useful for nested combinator gates).

## Lifecycle states

A `NotificationRule.is_active=false` row is **dormant** — the bridge
skips it on every event. Activating happens via the admin UI (deferred
T7.7 / T7.8) or directly in DB during the parallel-shadow rollout.

A `NotificationRuleFiring` row is written on **every** EvaluateRuleJob
invocation regardless of outcome:

| outcome | meaning |
|---|---|
| `matched` | Condition passed; notification dispatched (flag on) |
| `skipped_cooldown` | Same (rule, model) fired within `cooldown_minutes` |
| `skipped_condition` | Condition tree evaluated to false |
| `shadow` | Condition passed but `NOTIFICATION_USE_RULES=false` — dispatch suppressed |
| `error` | Exception inside EvaluateRuleJob; `error_message` captures the throwable |

The audit log is what powers the admin "why didn't my rule fire?"
debug surface. Trace JSON is per-leaf `{field, op, expected, actual, pass}`.

## Parallel-shadow rollout

The M7 rollout pattern mirrors plan-012's audience flag flip (which
plan-023 M1 deleted). We don't blindly flip the flag to "rules drive
everything"; we run the rule engine in `shadow` mode for two weeks
first.

1. `php artisan db:seed --class=SystemNotificationRuleSeeder` —
   per brand, 4 rules ship inactive mirroring the Phase A emitters:
   - Stock alert (low / out)
   - Customer order status changed
   - Recipe approved
   - Recipe rejected
2. Admin (or migration) flips `is_active=true` on those rows.
3. With `NOTIFICATION_USE_RULES=false`, hardcoded emitters keep
   dispatching notifications normally AND the engine logs `shadow`
   firings in parallel.
4. `php artisan notifications:rule-shadow-compare --since=14d` emits
   a CSV diff per (emitter, rule, trigger_id). Exit 0 = parity.
5. After two consecutive clean windows, flip
   `NOTIFICATION_USE_RULES=true`. The hardcoded emitter short-circuits
   (T7.13, deferred until the parity gate is met) and the seeded
   rules take over.

The flag flip is the only deploy-level action — the rest is data.

## Infinite-loop protection

A rule whose action dispatches a `Notification` would normally trigger
itself: `Notification::created` → bridge → EvaluateRuleJob → dispatch
→ `Notification::created` → bridge → …

`ModelEventToRuleBridge::BLOCKLIST` is a static array of four classes
the bridge always skips:

- `App\Models\Notification`
- `App\Models\NotificationRecipient`
- `App\Models\NotificationDelivery`
- `App\Models\NotificationRuleFiring`

`tests/Arch/RuleBridgeBlocklistArchTest.php` enforces all four are
present. Don't widen this list without thinking through the loop
implications.

## When to graduate a rule to code

System rules cover the happy path for well-understood, stable business
events. Graduating a rule to a hardcoded emitter is appropriate when:

- **The trigger is not Eloquent** — scheduled scans, webhook callbacks,
  and external integrations produce no `model.created/updated/deleted`
  events. Use `custom.*` events wrapped in `CustomNotificationEvent`
  instead (see `DeviceOfflineDetectionJob`, `CouponExpirationScannerJob`).
  The bridge routes `custom.*` events through the rule engine just like
  Eloquent events.

- **The condition DSL is insufficient** — the evaluator handles simple
  field comparisons and `changed_to` / `changed` / `in` / `not_in`
  operators. If the fire condition requires joining multiple models,
  aggregation, or external API calls, wire a hardcoded observer or
  service call and document it in the `M7_RULES` const as a Phase A
  shadow mirror.

- **Performance budget** — a rule that fires on every `model.updated`
  for a high-write model (e.g. `CustomerOrder`) generates one
  `EvaluateRuleJob` per write. If the model receives > 100 updates/sec
  in production, prefer a targeted observer that short-circuits early
  (status guard at the top of `handle()`) over a generic rule.

- **Two-phase rollout needed** — add the rule with `is_active=false`,
  run the parallel-shadow parity check (`notifications:rule-shadow-compare`)
  for two weeks, then flip `is_active=true`. This is the recommended
  path for any rule that replaces an existing hardcoded emitter.

In all other cases (new business event on a low/medium-write model,
approval workflows, lifecycle notifications), a system rule is the
correct approach — it avoids a deploy, is auditable via the firings
log, and can be paused without redeployment.

## Coverage catalogue API (plan-023 M8 T8.13)

`GET /api/v1/hq/{brandSlug}/notifications/coverage` returns:

```json
{
  "system_rules": [{ "id", "name", "trigger_event", "trigger_model_type",
                     "is_active", "fire_count", "last_fired_at" }],
  "covered_models":   ["Product", "Menu", …],
  "uncovered_models": ["Coupon", …]
}
```

**covered_models** — distinct `trigger_model_type` values across active
rules for the brand (including global rules). A model appears here if at
least one rule is watching it.

**uncovered_models** — morph-map aliases not excluded by
`notifications.coverage.excluded_models` config (pivot tables,
notification-domain models, audit helpers) and not yet covered by any
active rule. This is the gap list — useful during onboarding new
notification domains.

Gate: `viewNotificationCoverage` — granted to `org-admin`.

Nó từng hỏi `brand_admin`, một slug **không có trong `RoleTemplateMatrix::ROLES`**,
nên cổng từ chối mọi người kể từ ngày được viết (#2456). Brand không có vai
riêng: mọi phép phân giải phạm vi brand đều đi qua tổ chức sở hữu brand.

To add a new exclusion (e.g. a new pivot table that should not appear as
uncovered), append to `config/notifications.php`:

```php
'coverage' => [
    'excluded_models' => [
        // existing entries …
        'MyPivotTable',
    ],
],
```

## Related

- `plans/plan-023/DESIGN.md` §M7
- `docs/reference/notifications-api.md` (when T7.5 CRUD ships)
- `schemas/Backend/Notification/NotificationRule.yaml`
- `schemas/Backend/Notification/NotificationRuleFiring.yaml`
- `backend/app/Services/Notification/RuleEvaluatorService.php`
- `backend/app/Listeners/Notification/ModelEventToRuleBridge.php`
- `backend/app/Jobs/Notification/EvaluateRuleJob.php`
- `backend/database/seeders/SystemNotificationRuleSeeder.php`
