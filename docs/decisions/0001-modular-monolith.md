---
title: "ADR 0001 — Modular monolith, and the test a module must pass to leave it"
category: contributing
tags: [architecture, adr, modular-monolith, bounded-context, events, transactions]
summary: Tempo stays one deployable with enforced internal module boundaries. Records the dependency rule, the event policy, transaction ownership, and the five conditions that must ALL hold before any module is extracted into its own service.
related: [module-boundaries, architecture-baseline, api-as-boundary]
---

# ADR 0001 — Modular monolith, and the test a module must pass to leave it

- **Status**: Accepted — **amended 2026-08-03** (adds §1c)
- **Date**: 2026-08-01
- **Ratified by**: repo owner, 2026-08-03. The 2026-08-01 record was drafted and
  self-marked `Accepted` by an agent session with no human sign-off; §1c below
  exists because a rule invented under that gap reached production code.
- **Context issue**: [#962](https://github.com/godx-jp/godx-tempo/issues/962) (epic), [#1358](https://github.com/godx-jp/godx-tempo/issues/1358) (this record)
- **Supersedes**: —

## Context

`backend/` is one Laravel application of ~2,657 classes. Phase 0 of #962 measured
what is actually there before moving any file
([module-boundaries.md](../explanation/module-boundaries.md)):

- nine bounded contexts declared in `backend/config/modules.php`;
- **1,218 cross-module edges**, and every one of the nine modules sits in **a
  single strongly-connected component** — i.e. today there is no module you could
  lift out without dragging the other eight;
- and that 1,218 is a **lower bound**: container calls by string, `resolve()`,
  string event names and table-name Eloquent relations are invisible to the
  measurement.

The team is small, the product runs in two countries off one database, and the
operational baseline ([architecture-baseline.md](../operations/architecture-baseline.md))
shows the current pain is **delivery reliability** — a 41% deploy-attempt failure
rate and a 47% red rate on CI — not throughput or scaling limits.

The decision that needed recording is not "should we modularize" (#962 already
answered yes). It is **what the boundaries mean in code**, and **what would have
to be true before anyone splits a service out** — because "modular monolith"
without that second half drifts into microservices one plausible-sounding
exception at a time.

## Decision

### 1. One deployable, one database

Tempo remains a single Laravel application against a single database for the
duration of #962. Modules are enforced *inside* the process. No module gets its
own service, its own schema, or its own release train in this epic.

This is the "monolith first" position: a distributed system buys independent
scaling and independent deployment at the price of network failure modes,
eventual consistency, and distributed debugging. We have no measurement saying we
need what it buys, and a 41% deploy failure rate says we cannot yet afford what
it costs.

### 1b. Ownership is declared, not relocated

A module is defined by what `config/modules.php` says it owns, not by which
directory its files sit in. `app/Modules/` is a **destination for new modules**,
not a migration target for the existing nine. Moving an existing module there
must be justified against a measured benefit for that module — it is not assumed
to be the shape of the work.

This was decided against evidence rather than taste. Cross-module dependencies
fell from 3,193 to 805 (−75%) without a single file being moved: every step was
a change to declared ownership, verified by regenerating the Deptrac
configuration and re-measuring. Three of the six steps turned out to be bugs in
the measurement itself — one had invented a 496-edge cycle between Ordering and
CustomerEngagement that was already written into the paydown plan as its largest
task.

The physical-relocation plan would have reached the same 805 through thousands
of file moves, every import rewritten, every test path touched, and no way to
bisect a regression. The corollary is a rule worth stating on its own:

> Before refactoring to satisfy a metric, verify the metric.

A coupling count is a model of the system; a wrong model produces confident,
expensive, wrong work.

### 1c. Laravel is the base. No plain-PHP domain layer.

Business logic lives in `app/Services/<Domain>/`. Eloquent is the query tool at
every layer. There is **no** `app/Modules/<X>/{Domain,Application,Infrastructure}`
tree, no framework-independent core, no DDD tactical layering.

`app/Modules/` remains, with one narrowed job: **a home for published contracts**
(`Notifications\Contracts\NotificationDispatcher` and friends). It is not a
destination for business logic — that is §1b applied to code shape rather than
file location.

The rule this supersedes — *"`Domain` does not depend on Laravel, HTTP, Eloquent"* —
never appeared in this ADR. It was written into the #962 issue body on 2026-07-22
by an agent session, was never ratified, and produced exactly one artifact before
being caught: the `InboxSummary` pilot (#1360).

That pilot is the measurement, and it is why this clause is stated rather than
assumed:

| | |
|---|---:|
| `NotificationController::summary` (before) | **7 lines** |
| `app/Modules/Notifications/` (after) | **510 lines across 10 files** |
| response | byte-identical |
| queries | **identical Eloquent** — `EloquentInboxSummaryReader`'s own docblock says *"deliberately IDENTICAL … same three statements, same joins, same scopes"* |

The pre-pilot code was already correct Laravel: a thin controller delegating to a
service. The layering replaced a correct pattern with one ~70× larger and bought
nothing measurable — not a query, not a bug, not a test that could not already be
written with Pest and `RefreshDatabase`.

The two textbook arguments for a framework-free core both fail here on their own
terms: test speed is already served by Pest against ~2,657 classes, and framework
portability insures against leaving Laravel — which nothing here plans to do, and
which Omnify (a generator that emits Laravel models) forecloses anyway.

**Consequence for §2:** the boundary between modules sits **between services**, not
between a service and Eloquent. Service A asks module B through B's published
contract; inside that contract, B queries with ordinary Eloquent against the one
shared database. Code calling code is what crosses a boundary — never a table
calling a table.

### 2. Dependency rule — acyclic, and only through a published API

- A module may depend on **shared infrastructure** (`App\Support`, `App\Casts`,
  `App\Concerns`, framework, Omnify enums). Shared infrastructure depends on **no
  module**.
- A module may depend on another module **only through that module's published
  API** — a small, named surface owned by the module. Never another module's
  Eloquent model, never its internal services, never its tables.
- **Delivery surfaces** (controllers under `Api/V1/*`, console commands, MCP)
  depend on modules. **No module ever depends on a surface.** A surface is an
  adapter, not a module — this is already how `config/modules.php` tags them.
- **No cycles between modules.** Two modules that need each other are either one
  module or are missing a third that both depend on.

Today's code violates this everywhere — that is the point of the ratchet in
`deptrac-baseline.yaml`: the number goes down, never up. The rule describes
the target; the ratchet is the only thing allowed to enforce it, so that
enforcement never blocks unrelated work.

### 3. Event policy

- Cross-module **queries** (I need to read something) go through the published
  API, synchronously.
- Cross-module **notifications** (something happened, others may care) are domain
  events. The emitting module owns the event; it is part of that module's public
  contract and changing its shape is a breaking change.
- Event payloads carry **identifiers and immutable facts**, never Eloquent models
  and never mutable objects. A listener that needs more re-reads through the
  owning module's API.
- **No listener may assume it runs inside the emitter's transaction.** See §4.
- An event is not a way to dodge the dependency rule: emitting an event whose
  only possible listener is one specific module is a direct call wearing a
  costume — make it a call.

### 4. Transaction ownership

- **One transaction per use case**, owned by the module that started the use
  case. It commits or rolls back as a unit.
- A cross-module side effect **runs after commit** — queued listeners with
  `afterCommit`, never inside the initiating transaction. The repo already works
  this way in its most delicate places: the `catalog_revisions` observer flushes
  marks once per transaction **on COMMIT**, and `TransmitVnEinvoiceJob` is
  dispatched `afterCommit`. Both exist because the alternative produced work
  queued for rows that a rollback then removed.
- **No nested cross-module transactions**, and no module opens a transaction
  inside another module's.
- Money keeps its stronger existing rule, which this ADR does not relax:
  per-domain sub-ledgers, immutable per-line snapshots, and reversal-by-new-row —
  see [money-ledger-architecture.md](../explanation/money-ledger-architecture.md).

### 5. The extraction test — ALL five, measured, not predicted

A module may be extracted into its own deployable only when every one of these
holds. Four out of five is a "not yet", not a judgement call:

1. **Boundary is already clean.** Zero incoming cross-module edges except through
   its published API, held for at least one full release — proven by
   `deptrac analyse`, not by reading the code and feeling good.
2. **There is a measured reason.** A scaling, availability, or isolation
   requirement the monolith demonstrably fails to meet, with the number from
   [architecture-baseline.md](../operations/architecture-baseline.md) that shows
   the failure. "It would be cleaner" and "it will not scale later" are not
   reasons.
3. **The data separates.** No cross-service join and no cross-service transaction
   on any hot path. If splitting the module means a distributed transaction over
   money, the answer is no — and it stays no.
4. **Someone owns it end to end**, including its on-call. A service with no owner
   is a monolith with extra network hops.
5. **Delivery is healthy first.** Deploy-attempt failure rate below 10% and CI red
   rate below 10%, sustained. Splitting a deployable multiplies deployments; doing
   that while ~2 in 5 deploy attempts fail multiplies the failures too.

Recording this now, while nothing is extractable, is deliberate: the criteria are
worth more written *before* there is a specific module someone wants to move.

## Consequences

- Cross-module calls have to get worse-looking before they get better: the
  published-API rule makes some current one-liners into two steps. That is the
  cost being paid on purpose.
- The ratchet can only measure what static `use` statements show. String-based
  container calls and table-name relations stay invisible, so a falling edge count
  is **necessary, not sufficient** evidence of decoupling.
- `afterCommit` for cross-module effects means a listener can observe a world that
  moved on since the event. Listeners must be idempotent — which they must be for
  retries anyway.
- Condition 5 ties architecture work to delivery health, so the first
  architecture-motivated work is not moving files: it is making CI and deploy
  trustworthy.
- Declared ownership means the directory tree no longer tells you which module a
  class belongs to — `config/modules.php` does. That file is therefore
  load-bearing, and both the boundary checker and the runtime module registry
  are generated from it so the two cannot disagree.

## Alternatives considered

**Microservices now.** Rejected: a single SCC containing all nine modules means
there is no seam to cut along today. Cutting anyway produces distributed
transactions over payments — condition 3 exists to make that a hard no.

**Package-per-module with separate composer packages.** Rejected for this epic:
it adds release mechanics to a team that is currently failing to release
reliably, and enforces nothing that `deptrac analyse` does not already
enforce inside one repository.

**Leave it as is.** Rejected by #962: with all nine modules mutually reachable,
every change has unbounded blast radius, and the measurement exists precisely
because nobody could say how bad it was.
