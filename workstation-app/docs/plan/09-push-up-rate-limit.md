# Plan 09 — Push-UP to Cloud under rate limit (429 storm)

Status: **PLAN** (not implemented). The local-status half is already fixed
(`fix(order): pull must not reopen a locally-closed order`). This document
covers the push-to-Cloud half, to be implemented separately.

## Problem (observed on a live workstation)

`GET /api/sync` on a running WS:

- `status: online`, `pending_count: 111`, `failed_count: 0`
- All 79 local payments had `cloud_id` empty → **none reached Cloud**.
- Head of `sync_queue`: `payment.create` → `cloud 429: "Too Many Attempts"`
  (Laravel `ThrottleRequestsException`), `attempts: 0`.

### Root cause

The workstation makes **too many requests/min** against Cloud's throttle:

1. **Push** (`SyncEngine.processQueue`): up to 50 items / 5 s ≈ **600/min**.
2. **Pull** (`SyncPuller`): a fast loop + a slow loop, each hitting several
   endpoints every tick (zones, tables, menu, branch, customer-orders, lots, …).

Push + pull together (“lượng request gấp đôi”) blow past Cloud's per-IP limit
(Laravel default ~60/min/route-group), so Cloud answers **429**. Because the
**pull keeps firing and never backs off**, Cloud stays throttled, so the single
`payment.create` POST is *always* 429'd → the backlog (111) never drains.

Two secondary faults made it worse before the partial revert:
- 429 was treated like any retryable error → it **burned `attempts` to
  max_attempts (5)** → payments permanently `failed`.
- `processQueue` stops the whole cycle on the first retryable error →
  **head-of-line blocking** of everything behind a stuck item.

## Goal / invariants

- Local is source of truth and updates instantly (already true).
- Push-UP must **eventually** reach Cloud without tripping the throttle.
- A transient 429 must **never** permanently fail a payment/order.
- WS↔Cloud total request rate stays under Cloud's limit.

## Approach (3 layers, do in order)

### A. Shared throttle backoff (smallest, no Cloud change) ⭐
A single backoff gate shared by **push and pull** (e.g. a `settings` row
`cloud_throttled_until`, or one in-memory object injected into both
`SyncEngine` + `SyncPuller`).

- On a 429 from **either** `cloudPost` (push) or `cloudGet` (pull): set the
  gate to `now + Retry-After` (default 30 s, clamp 5 s–2 m).
- `processLoop` and the puller loops **skip** their tick while the gate is hot.
- A 429 must **not** increment `attempts` (it is "slow down", not "bad
  request") — record `last_error`, stop the cycle, retry after backoff.

Effect: when Cloud throttles, *both* paths pause together; the rate window
resets; the backlog drains in chunks under the limit.

### B. Reduce baseline pull volume
- Increase the slow-loop interval (and/or fast-loop) when idle.
- Consolidate the per-tick GETs into one bundled endpoint where Cloud supports
  it (there is already a TODO note about a bundled feed in `sync_pull.go`).
- Skip pulls that aren't needed (e.g. menu/branch change rarely → longer cadence).

### C. Batch push (real scale fix — needs Cloud) 
Gather up to N same-type rows from `sync_queue` into **one** POST.

- **Cloud**: add batch endpoints accepting an array, e.g.
  `POST /api/v1/kiosk/payments/batch` `{ "payments": [ {...}, ... ] }`
  returning per-item results `[{ idempotency_key, id, status }]` so partial
  failures are addressable. Same for `order.*` if needed.
- **WS**: in `processQueue`, group queued items by `(entity_type, operation,
  target, bearer)`, POST the batch, then mark each row synced / errored from
  the per-item result. Respect ordering deps (order.create before its
  payments) — batch within a dependency tier only.

Effect: push drops from N requests → ⌈N/batch⌉.

## Also fix regardless of layer
- `processQueue` head-of-line blocking: a per-item retryable failure should not
  stall **unrelated** items. Either continue to independent items, or only stop
  the cycle for a genuine Cloud-wide signal (429/5xx via the shared gate).
- `RetryFailed` (`POST /api/sync/retry`) should be run once after deploying A to
  un-fail the payments whose `attempts` were burned by 429 before the fix.

## Verify
- Pay many orders quickly → `pending_count` trends to 0; `failed_count` stays 0.
- No `cloud 429` loop in logs; payments get `cloud_id`; Cloud shows orders paid.
- Pull keeps replicas fresh without 429.

## Notes / data captured
- Live DB had **312 orders / only 92 distinct `order_code`** → heavy duplicate
  order codes from accumulated test sessions. Not part of this plan, but worth a
  separate look at the order-code generator / test-data reset.
- Orders store `total_amount = 0` for sync-down rows; reads recompute via
  `NormalizedTotals`. Fine for display, but confirm nothing close-/sync-side
  depends on the stored column being non-zero.
