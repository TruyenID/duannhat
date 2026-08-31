---
title: Observability across the TempoFast fleet
category: explanation
tags: [observability, sentry, audit-log, security, deployment, pci-dss]
summary: How error tracking + lifecycle audit is wired across the 4 production frontends + the Laravel backend. Deployment posture, privacy contracts, CSP wiring, DSN strategy.
related: [api-kiosk, kds-domain, api-as-boundary]
---

# Observability — design + deployment guide

Sprint A→C shipped error tracking (Sentry) + lifecycle audit (`POST /api/v1/kiosk/audit-logs`) across 4 production apps. This doc records WHY the wiring looks the way it does, so the next maintainer doesn't accidentally regress privacy posture or break CSP at deploy time.

## What landed where

| App | SDK | Init file | Env var | Surface |
|---|---|---|---|---|
| godx-kds | `@sentry/react` | `src/lib/sentry.ts` | `VITE_SENTRY_DSN` | Tablet kitchen display (PWA) |
| pos-web | `@sentry/react` | `src/lib/sentry.ts` | `VITE_SENTRY_DSN` | In-store POS terminal (browser) |
| workstation/frontend | `@sentry/react` | `src/lib/sentry.ts` | `VITE_SENTRY_DSN` | Wails webview (operator) |
| godx-kiosk | `@sentry/react-native` | `src/lib/sentry.ts` | `EXPO_PUBLIC_SENTRY_DSN` | Customer self-service kiosk (RN) |
| backend (Laravel) | — | n/a | n/a | Audit trail consumer + reader (TODO Sprint E) |

Plus: `POST /api/v1/kiosk/audit-logs` accepts structured lifecycle events from the kiosk so the cloud holds an audit trail even when the device crashes. See [api-kiosk.md](../reference/api-kiosk.md).

## Privacy posture

The same `sendDefaultPii: false` + Sentry Replay-disabled + DSN-gated init posture is shared across all four SDK init files. Three reasons it's the same shape everywhere:

1. **Replay records customer-facing UI**. Kiosk shows the cash-amount keypad, kds shows order detail with table number + dish names, pos-web renders customer.phone, workstation-FE shows full payment receipts. Sentry Replay would ship all of this off-device — privacy-incompatible with the operator-locked deployment model.
2. **`sendDefaultPii: false`** strips IPs and cookies from events. Sentry's defaults assume a consumer SaaS context — for a restaurant-fleet deployment those defaults leak operator identities.
3. **Sampling stays low** (`tracesSampleRate: 0.05` for kiosk + kds, `0.1` for pos-web + workstation-FE). The tablets run 24/7; full sampling burns the Sentry quota on what's mostly idle.

### Token + PII scrubbers

Each SDK has both `beforeBreadcrumb` (scrubs console messages) and `beforeSend` (scrubs the Sentry-internal exception/componentStack paths that breadcrumbs miss). The scrubber patterns are app-specific:

| App | Scrubbed patterns |
|---|---|
| godx-kds | `kds_device_token=...` (quoted + unquoted) + `Authorization: Bearer ...` |
| godx-kiosk | `device_token=...` (quoted + unquoted) + `Bearer ...` |
| pos-web | `Authorization: Bearer ...` + `token=...` cookie fragments + email addresses |
| workstation-FE | `Authorization: Bearer ...` |

**Phone numbers are intentionally NOT regex-scrubbed.** The false-positive rate against minified bundle digit chunks is too high. The pos-web pattern (which renders `customer.phone`) is to not surface phone numbers inside React components that could throw — tracked as a UI hardening follow-up, not a Sentry config.

## DSN strategy

DSNs are public client identifiers (Sentry's documented pattern) — bundled at build time, NOT a server secret. Recommendation:

- **One DSN per environment tier** (dev / staging / prod), shared across the four apps. Use Sentry's "Source" tag to separate per-app drilldown.
- **NOT one DSN per restaurant** for the kiosk/workstation surface — that would explode the Sentry org's project count beyond practical UI navigation.
- **Each app's `.env.example`** documents its own DSN env var. CI wires the actual value via secrets-manager.

Setting `VITE_SENTRY_DSN` / `EXPO_PUBLIC_SENTRY_DSN` to a real value is the ONLY thing required to turn Sentry on. Init is a silent no-op when unset, so dev + unwired deploys ship clean.

## CSP compatibility

**Important deployment gotcha**: each frontend's CSP `connect-src` must include `https://*.sentry.io https://*.ingest.sentry.io` (already in this commit), otherwise Sentry's ingest POST is blocked at the meta-CSP layer and events fail silently. Specifically:

- `app/kds/index.html` — `connect-src` includes `https:` wildcard ✓
- `web/pos/index.html` — same ✓
- `workstation/frontend/index.html` — explicitly lists `https://*.sentry.io https://*.ingest.sentry.io` (no broad `https:` because the Wails-served bundle is loopback-only otherwise) ✓
- `godx-kiosk` — RN, no DOM CSP, ignores this concern

If you tighten CSP later, **DON'T remove the Sentry origins**. The frontend will throw silent CSP-violation errors and Sentry will stay empty — the issue surfaces only as "we haven't seen any errors lately, must be working".

## Audit-log endpoint (PCI-DSS Req 10.2)

The kiosk-side flow: payment lifecycle events flow through `src/lib/audit-log.ts::recordAudit()` which POSTs to `/api/v1/kiosk/audit-logs`. See [api-kiosk.md](../reference/api-kiosk.md) for the wire format.

Helpers wired (see `use-payment.ts` + `error-boundary.tsx`):

- `auditPaymentInitiated` — before `POST /payments`
- `auditPaymentSubmitted` — after cloud responds
- `auditPaymentConfirmed` — terminal AUTH success
- `auditPaymentFailed` — terminal decline / confirm failure
- `auditCrash` — React ErrorBoundary catches uncaught error

All fire-and-forget — they never block the payment flow even if the cloud audit endpoint is unreachable. Sentry captures the cloud-side network failure via `reportError("audit-log-post", err)` so ops still has the diagnostic signal.

### What's NOT in audit-log

Sentry handles **runtime errors** (uncaught exceptions, render crashes). The cloud audit-log is for **lifecycle events** (deterministic state transitions). Don't conflate:

- Crash report → Sentry (with stack trace, breadcrumbs)
- Crash audit row → cloud audit-log (so reconciliation knows the kiosk crashed mid-payment)
- BOTH fire for a crash — the audit row is the durable evidence, Sentry is the triage tool.

## Deployment readiness checklist

Before flipping `*_SENTRY_DSN` in any prod environment:

1. ✅ Each app's `.env.example` has DSN + release placeholders.
2. ✅ Each frontend's CSP `connect-src` allows Sentry origins.
3. ✅ Per-app `beforeBreadcrumb` + `beforeSend` scrubbers cover the auth-token + PII patterns the app actually renders.
4. ✅ ErrorBoundary in every app wires `captureException` (godx-kds + pos-web + workstation-FE + godx-kiosk).
5. ✅ Backend `/api/v1/kiosk/audit-logs` endpoint live with morph-map whitelist + device-keyed throttle + PCI deny-list.
6. ⏳ **TODO before prod cutover**: per-environment DSN provisioned in Sentry + wired into CI build secrets. (Not a code change — ops setup.)
7. ⏳ **TODO follow-up**: source-map upload via `@sentry/vite-plugin` so prod stack traces are readable (minified-only otherwise).

## Deferred / open items

Tracked in the two in-tree gap ledgers, [`workstation/docs/INTEGRATION_GAPS.md`](../../workstation/docs/INTEGRATION_GAPS.md) and [`app/kds/docs/INTEGRATION_GAPS.md`](../../app/kds/docs/INTEGRATION_GAPS.md):

- **PCI Req 10.3** (audit log read protection): No HQ admin read endpoint for `audit_logs` yet. Backend has the rows but no UI exposes them.
- **PCI Req 10.5** (timely review): No Sentry alerter for `payment.crash` events. Alert rule needs to be configured in Sentry dashboard after cutover.
- ~~**PCI Req 10.6** (retention)~~ — **closed by #2555.** See [Audit-log retention](#audit-log-retention) below.
- **Source-map upload**: All 4 apps minify in prod; without source-map upload Sentry stack traces are unreadable. `@sentry/vite-plugin` per repo + per-CI auth-token.
- **Kiosk audit retry queue**: Network failure during the lifecycle = audit event permanently lost. Could queue in AsyncStorage + drain on reconnect — acceptable for v1, tracked for v2.

## Audit-log retention

`audit_logs` had no prune or archival job at all until #2555, so it grew for the
lifetime of the deployment. That was two problems wearing one hat: a PCI finding,
and the reason #2554 removed the caller `ip` — personal data may not accumulate
without a horizon.

### The number: 400 days

**PCI DSS v4.0 Req 10.5.1** — *"Retain audit log history for at least 12 months,
with at least the most recent three months immediately available for analysis."*

That is the floor the ruling is built on, not a preference:

- **12 months** is the requirement. The default is **400 days** — twelve months
  plus about five weeks. The margin buys two things: a dispute opened on the last
  day of a period still finds its evidence while the case is being worked, and a
  scheduler outage of a few weeks cannot push the effective window below the
  floor before anyone notices.
- **≥3 months immediately available** is satisfied by construction — nothing is
  archived to cold storage, everything inside the window stays queryable in the
  live table.
- The window is tunable **upward only**: `AUDIT_LOG_RETENTION_DAYS` raises it (a
  contract or jurisdiction wanting seven years just sets it), and `audit:prune`
  **aborts without deleting anything** when the effective window is below
  `audit.pci_floor_days` (365). A mistyped env var fails loudly instead of
  quietly destroying evidence.

Config and the full reasoning: `backend/config/audit.php`.

### The sweep

`php artisan audit:prune` deletes rows whose `created_at` is older than the
cutoff. Scheduled nightly at 02:50 on `app.operations_timezone` in
`backend/routes/console.php` (`audit.prune`) — clear of the other overnight table
walkers at 03:20 / 03:40 / 04:10.

| Flag | Effect |
|---|---|
| `--dry-run` | Counts eligible rows and prints; deletes nothing |
| `--measure` | `COUNT(*)` of the whole table before and after (opt-in — it is a full scan) |
| `--days=N` | Override the window for one run (still refused below the floor) |
| `--chunk=N` · `--max-rows=N` · `--max-seconds=N` | Override the batching bounds |

The off-peak hour is margin, **not** the safety mechanism. The command fetches
primary keys in chunks, deletes by key, pauses between batches, and stops at
whichever of `max-rows` / `max-seconds` comes first — so a run that landed at
14:30 on a busy Saturday would be a few hundred keyed deletes and then a clean
exit, never an unbounded `DELETE` holding locks across a table that has only ever
grown. Hitting a bound is a normal outcome, reported as `stopped: max-rows`; the
next run recomputes its own cutoff, so a months-old backlog drains over several
nights.

Each run emits `[audit.prune] run` at INFO with `deleted`, `batches`,
`stopped_by`, `remaining_eligible` and `duration_seconds` — that is the
before/after measurement, per run, without anyone having to remember to take one.

**The cutoff compares `created_at` in UTC, and that is correct here.** It is not
a #1091 violation: a storage horizon is elapsed time, not a business date. No
branch's timezone changes how long a log row must be kept, so there is nothing
for `BusinessClock::forBranch()` to resolve.

### Known cost: no index on `created_at`

`audit_logs` is indexed on `(auditable_type, auditable_id)` and `action` only, so
the cutoff scan is not index-backed. The chunk bounds are what keep that
affordable — each round trip reads a bounded number of rows and the run stops on
a clock budget. An index on `created_at` would make the scan cheap and is the
obvious follow-up, but it is a migration on the Omnify-generated table and was
left out of #2555 deliberately rather than smuggled in.

## Cross-references

- [api-kiosk.md](../reference/api-kiosk.md) — wire format of `/audit-logs`
- [kds-domain.md](kds-domain.md) — KDS RFC 7807 error UX (separate from Sentry)
- [api-as-boundary.md](api-as-boundary.md) — error-envelope policy across kiosk + KDS + workstation
- `app/kds/src/lib/sentry.ts` — kds init reference
- `app/kiosk/src/lib/sentry.ts` — RN init reference
- `web/pos/src/lib/sentry.ts` — POS init reference
- `workstation/frontend/src/lib/sentry.ts` — Wails init reference
- `backend/app/Http/Controllers/Api/V1/Kiosk/KioskController.php::auditLog` — endpoint handler
