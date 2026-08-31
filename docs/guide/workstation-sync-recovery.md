---
title: Workstation sync dead-letter & recovery
category: guide
tags: [workstation, sync, dead-letter, recovery, runbook, ops, plan-042]
summary: "What a dead-lettered sync row is on the workstation, the seven reasons one appears, where the operator sees them, and how to recover — Discard, Re-resolve, or Re-create-on-Cloud — without losing money."
related:
  - offline-order-evidence
  - cashier-shift-recovery
---

# Workstation sync dead-letter & recovery

The workstation is offline-first: every sale, payment, and shift action commits
to local SQLite (`~/.ws-app/ws-app.db`, per `workstation/CLAUDE.md` Key
Conventions) and is queued in the `sync_queue` table for push-UP to Cloud. This
runbook covers what happens when a queued row can **never** be pushed — the
dead-letter state introduced by plan-042 — and how an operator gets the data
(especially money) home safely.

All file references below are into `workstation/`, in-tree; line
numbers were verified against the main working tree on 2026-08-08.

## What "dead-lettered" means

A dead-lettered row is a `sync_queue` row moved to an explicit **terminal
state**: `dead_lettered_at` + a machine `dead_letter_reason` are stamped, the
row leaves the active queue and the pending count, stops retrying, and is
surfaced to the operator for a decision
(`internal/service/sync_service.go:799-811`, columns defined in
`internal/store/migrations/036_sync_queue_dead_letter.sql`).

Before plan-042, these rows died invisibly: a permanent Cloud rejection burned
all 5 attempts (`max_attempts` default — `internal/store/migrations/007_sync.sql:28`)
and then sat silently at `attempts >= max_attempts`, while dependent child rows
(item bumps waiting on the order's `cloud_id`) looped forever. The migration
header of `036_sync_queue_dead_letter.sql` records that history.

Dead-lettered ≠ lost. The row and its payload stay on disk until an operator
resolves it; nothing is deleted.

## When it happens — the reasons

`dead_letter_reason` values, as written by the engine
(`internal/service/sync_service.go`):

| Reason | Trigger | Where |
|---|---|---|
| `cloud_404_order_gone` | Cloud answered **404** for the order the row addresses — the order no longer exists on Cloud (reseed, DR restore, admin delete). Dead-lettered immediately, no blind retries. | `classifyDataConflict`, `sync_service.go:126-131`; push branch `:729-747` |
| `cloud_422_entity_missing` | Cloud answered **422** whose body carries an entity-missing signature ("does not exist", "no query results", "is invalid"…) — a table/customer/SKU the row references is gone. Immediate dead-letter. | `sync_service.go:132-139` |
| `payment_orphan_order_gone` | Special case of the 404: the dead row is a **payment** whose order is gone — money the workstation took that Cloud never saw. Re-labeled so it sorts first in every surface. | `sync_service.go:742-744` |
| `parent_order_dead` | Cascade: the order's `order.create` is already dead-lettered, so its item/payment/other-op children can never succeed. Swept every 5s tick, before the backfill reconcilers, so they are not resurrected. | `reconcileDeadLetterCascade`, `sync_service.go:2326-2345`; tick order `:590-597` |
| `parent_session_dead` | Same cascade for a till family: `till_session.open` dead → its close/abandon/cash-event children follow. | `sync_service.go:2347-2363` |
| `stuck_transient` | Poison-row backstop (plan-042 TH.3): the row failed **retryably** (5xx/network) ≥ 20 consecutive times (`rlStuckTransientThreshold`, `sync_service.go:196-199`) *while Cloud demonstrably succeeded on other work* — so it is row-specific, not an outage, and must stop head-of-line-blocking. | `sync_service.go:764-768` |
| `max_attempts_exhausted` | Safety-net: a genuinely bad row (4xx no classifier caught) that burned all its attempts is dead-lettered instead of dying invisibly. | `deadLetterIfExhausted`, `sync_service.go:815-822` |

A genuine Cloud-wide outage does **not** dead-letter anything: retryable
failures during an outage stop the cycle without burning attempts, and the
backlog drains when connectivity returns (`sync_service.go:748-772`).

The Cloud half of the same plan tries to prevent these conflicts from arising
at all: entities an open order references refuse deletion with 409 — see
[Delete-guard 409 codes](../reference/api-delete-guards.md).

## Where to read it

- **Workstation UI**: a red failure banner links to the recovery page at
  `/sync-recovery` (`frontend/src/components/layout/sync-failure-banner.tsx:67`,
  route in `frontend/src/main.tsx:115`, page
  `frontend/src/pages/SyncRecovery.tsx`).
- **HTTP**: `GET /api/sync` on the workstation (port 8080 by default) returns
  `dead_letter_count`, `payment_orphan_count`, and up to 200 `dead_letters`
  items — payment orphans first, then newest — plus throttle state
  (`internal/handler/routes.go:1413-1454`; item shape
  `internal/service/sync_service.go:461-470`; ordering `:475-484`).
- Dead-lettered rows are **excluded** from `pending_count`; they only appear in
  the dead-letter fields (`sync_service.go:422-429`).

## How to recover

Three operator actions, all **loopback-only** (`localOnly` middleware rejects
non-loopback callers — `internal/handler/middleware.go:30-34`; registrations
`internal/handler/routes.go:89-91`). Every action is audit-logged.

### 1. Re-resolve — after fixing the Cloud side

`POST /api/sync/{id}/re-resolve` returns the row to the active queue with a
clean slate (attempts, transient counters, error cleared) and wakes the engine.
Use when you restored the missing Cloud entity (e.g. re-created the table, or
the DR restore completed). Idempotent-safe: if the data is still broken the row
simply re-dead-letters on the next attempt
(`internal/service/sync_service.go:990-1012`; handler `routes.go:1484-1504`).

### 2. Re-create the order on Cloud — for a gone order (and its money)

`POST /api/sync/orders/{orderId}/recover` handles the worst case, the payment
orphan. It first asks Cloud whether the order truly is gone — refusing with
`409 ORDER_STILL_EXISTS_ON_CLOUD` if not, `503 CLOUD_UNREACHABLE` on
uncertainty, never duplicating — then clears the dead `cloud_id`, re-enqueues
an idempotent `order.create`, and **re-activates** (not discards) the family's
dead rows so the locally-recorded payments still reach Cloud once the fresh
create mints a new `cloud_id`. Cloud upserts idempotently, so this never
double-charges (`internal/service/sync_service.go:1019-1077`; handler
`routes.go:1505-1524`; Cloud existence endpoint is branch-scoped —
`backend/app/Http/Controllers/Api/V1/Workstation/OrderController.php:536`).

### 3. Discard — reconciled by hand

`POST /api/sync/{id}/discard` marks the row resolved (`resolution =
'discarded'`): it stops counting toward the banner and never retries. Use only
after you have accounted for the row outside the system (e.g. the sale was
voided on paper, or the row is a duplicate of something already on Cloud).
Both discard and re-resolve answer `409 NOT_DEAD_LETTERED` if the row is not in
an unresolved dead-letter state (`internal/service/sync_service.go:975-988`;
handler `routes.go:1464-1482`).

Additionally `POST /api/sync/retry` resets attempts on **failed-but-not-dead**
rows (the pre-plan-042 backlog shape) — it does not touch dead-lettered rows
(`sync_service.go:554-566`).

### Priority rule

Work **payment orphans first** — every surface sorts them to the top because
they are money nobody can reconcile until resolved. For each one, prefer
Re-create-on-Cloud (path 2); Discard a payment only when the charge itself was
reversed or never really happened.

## What recovers automatically (don't do it by hand)

- **Dead-parent cascades** run on every 5s tick, so children of a dead order
  never need individual triage — resolve the parent and the family follows
  (`sync_service.go:590-597`).
- **Backfill reconcilers** re-enqueue local rows that have no live queue row
  (orders, items, payments, peripherals). They are gated by
  `shouldAutoRecover()` so a cross-branch re-pair never pushes branch-A data
  onto branch-B (`sync_service.go:598-604`, `:2552`).
- **Unpair with unsynced money is blocked, not lost** (plan-818): unpairing
  with unsynced data returns `409 unsynced_data_present` with counts; a forced
  unpair *keeps* the transaction tables on disk, and re-pairing to the same
  branch re-pushes them automatically — per `workstation/CLAUDE.md`
  ("Unpair guard + kept-data recovery"), with the guard verified in code at
  `internal/handler/routes.go:2010-2110`. The details of that flow (which
  tables are wiped vs kept, `sync_target` origin tracking) are **sourced from
  CLAUDE.md** and not independently re-verified here.

## Quick triage table

| Symptom | Likely reason | Action |
|---|---|---|
| Banner shows payment orphans | `payment_orphan_order_gone` | `POST /api/sync/orders/{orderId}/recover` |
| Dead letters after an admin deleted a table/customer/SKU | `cloud_422_entity_missing` | Restore the entity on Cloud, then Re-resolve; or Discard if the sale was handled otherwise |
| Dead letters after a Cloud reseed / DR restore | `cloud_404_order_gone` (+ cascades) | Recover each order on Cloud (path 2) |
| One row dead while everything else syncs | `stuck_transient` or `max_attempts_exhausted` | Read `last_error` in `GET /api/sync`; fix the cause, Re-resolve |
| Many `parent_*_dead` rows | Cascade from one dead parent | Resolve only the parent; children follow |
