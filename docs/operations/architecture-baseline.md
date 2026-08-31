---
title: Operational baseline — the numbers #962 will be judged against
category: operations
tags: [architecture, baseline, dora, sre, ci, deployment, observability]
summary: Measured delivery and coupling numbers taken 2026-08-01, before any module is moved, plus the three signals that cannot be measured yet and the instrumentation each one needs.
related: [module-boundaries, 0001-modular-monolith, observability]
---

# Operational baseline — the numbers #962 will be judged against

Phase 0 of [#962](https://github.com/godx-jp/godx-tempo/issues/962) requires a
baseline taken **before** any module moves. Without it, the end of the epic can
only claim the code "looks cleaner"; with it, the claim is checkable.

Framing is the industry standard pair: **DORA** for delivery (deployment
frequency, lead time, change failure rate, restore time) and the **four golden
signals** for runtime (latency, traffic, errors, saturation). Both are used
as-is; a bespoke metric set would be one more thing to argue about.

**Measured 2026-08-01.** Every number below is reproducible with the command
under it — re-run them at the end of the epic, not new ones.

## Delivery — measured

### CI: backend test suite

```sh
gh run list --workflow=backend-tests.yml --limit 30 \
  --json conclusion,createdAt,updatedAt
```

| Metric | Value |
|---|---|
| Duration, successful runs (n=18) | min **563s** · median **647s** · p95 **954s** · max **980s** |
| Red rate, last 60 runs | **21 failed / 45 concluded ≈ 47%** (plus 13 cancelled) |

A ~11-minute median is livable. A **47% red rate is not**, and it is the single
most important number on this page: when CI is red half the time, a red run stops
carrying information, and the team learns to read failure as noise. Every later
architectural safeguard — the coupling ratchet included — is enforced by a suite
whose signal is currently half noise.

### Deployment to production

```sh
gh run list --workflow=deploy-xserver.yml --limit 60 \
  --json conclusion,createdAt,updatedAt
```

| Metric | Value |
|---|---|
| Window observed | 2026-07-05 → 2026-07-24 (32 runs) |
| Change failure rate (deploy attempts) | **13 failed / 32 ≈ 41%** |
| Deployment frequency | ~1.6 attempts/day, ~1.0 successes/day **inside the window** |
| Duration, successful runs (n=19) | min **25s** · median **27s** · max **47s** |
| Last successful deploy | **2026-07-24** — 8 days before this baseline |

Two cautions so these are not read as better than they are:

- The 27s median is the **workflow**, not the change reaching users. The job
  ships over SSH to XServer; it does not measure warm-up, migration, or cache
  state. Treat it as "how long the pipe takes", not lead time.
- Deployment frequency is **tag-driven** (`on: push: tags: v*.*.*`), so it
  measures how often someone cuts a tag, not how often work is ready. The 8-day
  gap since the last deploy is the more honest signal: work has been merging to
  `dev` and not shipping.

### Lead time for change

**Not measured.** Deploys are tag-triggered and tags do not record which commits
they carry, so commit→production cannot be reconstructed from CI history alone.
Cheapest fix: have the deploy workflow record the deployed SHA and timestamp
(a GitHub Deployment, or one line appended to a log), after which lead time is a
query rather than an archaeology project.

## Coupling — measured

```sh
cd backend && php -d memory_limit=-1 vendor/bin/deptrac analyse
```

| Metric | Value |
|---|---|
| Cross-module edges | **1,218** (lower bound — see below) |
| Strongly-connected components | **1**, containing **all nine** modules |
| Classes owned | 2,657 scanned · 114 unassigned |

The SCC is the finding, not the edge count: with all nine modules mutually
reachable, no module can be reasoned about — or extracted — in isolation. The
edge count is a lower bound because the measurement reads `use` statements only,
so container calls written as strings, `resolve()`, string event names, and
Eloquent relations that point through a table name are all invisible.

Target for the end of the epic: **zero cycles**, and the edge count monotonically
falling — enforced continuously by `deptrac analyse` in the `arch-gate` CI job, not checked once
at the end.

## Runtime — NOT measured, and what each needs

The four golden signals cannot be baselined today because the backend has **no
runtime instrumentation**: [observability.md](../explanation/observability.md)
shows Sentry wired into the four frontends and the backend row reading `n/a`. So
these are stated as gaps, with the specific instrumentation each needs — an
honest gap is worth more than a plausible number:

| Signal | Status | What would produce it |
|---|---|---|
| **Errors** (backend error rate) | none | Laravel Sentry SDK (or any APM) — same privacy posture as the frontends: `sendDefaultPii: false`, low `tracesSampleRate` |
| **Latency** (p95 API) | none | Request-duration middleware exporting per-route timings; APM traces give it for free |
| **Traffic** | partial | Web-server logs exist but are not aggregated; falls out of the same APM |
| **Saturation** | none | Host CPU/memory/disk on the XServer box + DB connection count |
| **Queue failure rate** | none | `failed_jobs` is written but nothing reads it; a periodic count by queue + job class is enough to start |
| **Restore time (MTTR)** | none | Requires incident records; there is no incident log yet |

**None of these blocks Phase 1.** They block the *closing argument* of the epic:
without error rate and p95 latency, "the modular monolith did not make the system
slower or less reliable" is unprovable in either direction. Standing them up is
cheapest now, while the system is still one deployable and one APM covers
everything.

## How to re-measure

Re-run the three commands above and compare against this page. If a number moved
because the *measurement* changed rather than the system, say so in the same
edit — a baseline that gets quietly re-based measures nothing.
