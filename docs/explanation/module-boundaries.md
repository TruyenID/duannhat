---
title: Module boundaries — the ownership ledger and the coupling measurement
category: explanation
tags: [architecture, modular-monolith, bounded-context, coupling, adr]
summary: The ownership ledger for the backend's nine bounded contexts, the coupling measured between them, and the Deptrac ratchet that keeps the number going down. Phase 0 measured 1,218 edges; six reclassification stages later it is 805 — and about three quarters of the original figure turned out not to be debt at all.
related: [api-as-boundary, branch-isolation]
---

# Module boundaries — the ownership ledger and the coupling measurement

This is **Phase 0** of [#962](https://github.com/godx-jp/godx-tempo/issues/962)
(moving Tempo to a modular monolith): measure the current state before moving a
single file. No module has been split out yet; `app/Modules` holds only the
kernel every future module registers through.

The epic asks for exactly that: *"The list above must be confirmed by dependency
mapping in Phase 0; do not treat that table as a reason to move files
mechanically."*

## The three things this document defines

| Thing | Where | Role |
|---|---|---|
| The ownership ledger | `backend/config/modules.php` | Every class belongs to exactly one module — **editing that file is editing the boundaries** |
| The measurement tool | [Deptrac](https://qossmic.github.io/deptrac/) — config generated from the ledger by `php artisan architecture:deptrac-config` | Reads the ledger, scans `app/`, reports each violation with file and line |
| The ratchet | `backend/deptrac-baseline.yaml` — one block per class | CI goes red when coupling increases |

## How it is measured

Edges are taken from `use App\…;` statements and inline `\App\…` references
across the 2,657 files under `backend/app`. That is the boundary at compile time:
a class cannot call another class without appearing in one of those two places.

**The blind spots, stated up front:** container calls (`app(Foo::class)` written
as a string), `resolve('...')`, string-based event names, and Eloquent relations
that point at another module's model through a table name. This means the numbers
below are a **lower bound** on the real coupling.

**A delivery surface is not a module.** Controllers under
`Api/V1/{Customer,HQ,Shop,Pos,Kiosk,Kds,Handy,Workstation,Tms}`, console commands
and MCP are tagged `surface:*`. They are adapters; edges from a surface into a
module are measured separately (fan-out) and do not count as boundary violations.

## The ownership ledger — nine bounded contexts

| Module | Classes | Ownership notes |
|---|---:|---|
| Payments | 499 | Money: order_payments, till/cashier shifts, gateways, invoices + 赤伝, VN e-invoice |
| Catalog | 494 | product/SKU/option/topping/category/menu/section/floating/recipe |
| Inventory | 280 | material, lot, stock, warehouse, production, disposal, recall, genealogy |
| Ordering | 185 | CustomerOrder + item + topping, table session, void reason |
| PlatformIntegration | 179 | SSO/user/role, device, printer, print job/template, file, audit log |
| Notifications | 140 | audience, rule, template, delivery, digest preference |
| CustomerEngagement | 136 | customer, review, post/tag |
| Organization | 118 | organization, brand, branch, zone, table, shop order setting |
| Pricing | 93 | tax type, coupon, promotion, order adjustment |

On top of that: 178 shared infrastructure classes (`App\Support`, `App\Casts`,
`App\Concerns`, middleware, Omnify enums…), 241 delivery-surface classes, and
**114 classes nobody owns** (mostly Http Request/Resource classes whose names do
not imply an aggregate, plus a few events and jobs).

The ledger does **not** permit orphan models: `unownedModels()` catches a model
that has a file but that no module declares, and `phantomModels()` catches the
reverse. Both are blocking tests — adding a new model without declaring its owner
turns CI red.

## Current measurement (2026-07-31)

```
2,657 classes · 1,218 cross-module edges · 59/72 pairs have an edge · 28 pairs depend on each other in both directions
```

**That figure did not survive contact with a better instrument.** Re-measured
with Deptrac and corrected across six reclassification stages, the same codebase
reports **805** — and roughly three quarters of the original number was never
debt:

| Correction | Edges removed |
|---|---|
| A layer may depend on itself | 697 |
| `User` recognised as an identity anchor, like `Organization`/`Brand`/`Branch` | 596 |
| Model-matching pattern made exact (`Customer` was swallowing `CustomerOrder`) | 685 |
| 12 services declared under the module they actually serve | 227 |
| The till/cash cluster declared under Payments | 114 |
| `AuditLog`, `UserWorkspaceAccess` recognised as cross-cutting | 71 |

Three of those six were bugs in the measurement configuration itself. One of
them invented a 496-edge cycle between Ordering and CustomerEngagement that had
already been written into the paydown plan as its largest task — the tool had
been emitting `is in more than one layer` warnings the whole time, ignored
because the gate was green.

**All nine modules sit inside ONE strongly connected component.** This is the
most important finding of Phase 0: today **no module can be extracted** without
cutting edges first — not even the one that looks most peripheral.

Simple cycles are not counted: with 59 of 72 pairs connected, the cycle count
explodes combinatorially (16,050 were measured) and says nothing. The SCC is
stable and answers the question that matters — *where do we cut*.

The SCC finding is from the Phase 0 instrument and has **not** been re-run since
the corrections above; treat "all nine in one component" as the last measured
answer, not as a current one. It is the reading that most deserves re-measuring,
because the corrections removed exactly the kind of phantom edge — a model
matched into two layers at once — that welds unrelated modules into one
component.

**Read this before refactoring to satisfy a coupling number: verify the number
first.** A wrong model of the system produces confident, expensive, wrong work.

The reduction above cost **no file moves at all** — see
[ADR 0001 § 1b](../decisions/0001-modular-monolith.md) on why `app/Modules` is a
destination for new modules rather than a migration target for the existing
nine.

### Who is depended on the most — Phase 0 instrument

Kept because the paragraph after it is still the right reading. The **counts are
superseded** by the table below; do not quote them as current.

| Module | Inbound edges | Outbound edges |
|---|---:|---:|
| Organization | **413** | 179 |
| PlatformIntegration | **273** | 88 |
| Catalog | 122 | 161 |
| Ordering | 106 | 155 |
| Payments | 106 | 174 |
| Pricing | 58 | 73 |
| CustomerEngagement | 57 | 157 |
| Inventory | 49 | 127 |
| Notifications | **34** | 104 |

`Organization` takes 413 inbound edges — half again as many as the runner-up. In
practice it **is a shared kernel**, not a peer module: `Branch`, `Brand` and
`Organization` are referenced by everything, because every query is scoped by
branch.

### Who is depended on the most — re-measured 2026-08-02

Deptrac, after the six corrections, on `dev`. `Organization`/`Brand`/`Branch` and
`User` are no longer in this table at all: the reading above was acted on and
they became `TenancyKernel`, so depending on them stopped being counted as debt.
That is 596 of the removed edges, and it is why the shape of the table changed
more than the totals did.

| Module | Inbound | Outbound |
|---|---:|---:|
| **Notifications** | **16** | **13** |
| PlatformIntegration | 44 | 25 |
| CustomerEngagement | 52 | 74 |
| Payments | 69 | 155 |
| Inventory | 75 | 56 |
| Pricing | 87 | 30 |
| Organization | 107 | 59 |
| Catalog | 116 | 62 |
| Ordering | **218** | **242** |

The heaviest pairs are now `Payments → Ordering` (119), `Ordering → Organization`
(74), `Ordering → Catalog` (52), `Ordering → Pricing` (48) and
`Ordering → CustomerEngagement` (42). Ordering sits on one end of every one of
them — the remaining debt is concentrated, not spread.

Reproduce it by stripping the `imports:` line from `deptrac.yaml` into a scratch
config (otherwise the baseline hides every violation) and running
`deptrac analyse --config-file=<scratch> --formatter=json`.

### The most entangled pairs

| Pair | Forward / reverse |
|---|---|
| Organization ↔ Payments | 41 / 98 |
| Organization ↔ PlatformIntegration | 34 / 57 |
| Catalog ↔ Organization | 59 / 31 |
| Notifications ↔ Organization | 59 / 16 |
| CustomerEngagement ↔ Ordering | 38 / 28 |
| Catalog ↔ PlatformIntegration | 56 / 8 |

### One service called directly by many surfaces

The epic notes that `CustomerOrderService` is called from many surfaces. Measured:
**32 classes are called directly by four or more surfaces**, led by

| Class | Surfaces |
|---|---:|
| `Models\CustomerOrder` | 9 |
| `Models\Device` | 8 |
| `Models\Branch` · `Models\Table` | 7 |
| `Services\Customer\CustomerOrderService` | 7 |
| `Services\Order\Contracts\OrderMutationFacade` | 7 |

## Three things the data says that the epic did not predict

**1. The easiest module to extract is Notifications, not CustomerEngagement/Post
or Catalog/Menu.** Notifications has only 34 inbound edges — the lowest — and
almost all of them are *event emission*, the direction that is easy to invert.
The epic proposed piloting `CustomerEngagement/Post` or the `Catalog/Menu read
path`; CustomerEngagement has 57 inbound edges and is bidirectionally entangled
with Ordering (38/28), and Catalog has 122.

**Decided, and the corrected numbers made the case stronger.** Re-measured,
Notifications has **16** inbound against 44 for the next-lowest module and 218
for Ordering — the gap widened from 1.7× to nearly 3×. Phase 2 pilots
Notifications (#1360): `GET /api/v1/me/notifications/summary` re-layered as
route → controller → use case → domain rule → repository port → resource, same
URL and payload, rollback by config switch.

**2. Ordering's write path currently lives in CustomerEngagement's namespace.**
`App\Services\Customer\CustomerOrderService` is the order-write service, and
seven surfaces call it directly. That is why the CustomerEngagement ↔ Ordering
pair is entangled in both directions. Relocating it is cheap and cleans up a
whole pair — but it touches the money path, so it deserves its own PR with
contract tests.

**3. The evidence in the epic is out of date.** The epic records "≈193 classes in
`app/Services` and 131 models". Today the reality is **721 files** under
`app/Services` and **176 models**. The epic was not wrong when it was written —
the cost is growing exactly as it predicted, only faster than the numbers
recorded there.

## The CI ratchet

`deptrac-baseline.yaml` pins the current measurement — one block per class —
and forces every change to go down
or stay flat:

| Gate | Goes red when |
|---|---|
| `deptrac analyse` | A class gains a cross-module dependency that is not in the baseline — including reaching into a module it never touched before |
| `DeptracConfigInSyncTest` | `deptrac.yaml` no longer matches `config/modules.php` (the YAML is generated, not a second opinion) |
| `ModuleKernelTest` | A module provider's `moduleName()` disagrees with the directory it lives in |
| `CanonicalPortsAreBindableTest` | A published port stops resolving from the container — or one designed as unimplemented quietly becomes bindable |

The baseline is **per class**, not one number per module pair. That is what makes
it safe to pay debt down in parallel: two pull requests fixing different classes
never edit the same line.

Two units get confused here, so state both: `deptrac analyse` reports **805
skipped violations** — one per violating *occurrence* — while
`deptrac-baseline.yaml` lists **316 entries**, one per unique *class → class*
pair. The file being three times shorter than the reported number is expected,
not a sign of a partial baseline. The single-number-per-pair file it replaced put 6 of 9
open pull requests into conflict on one day and turned `dev` red twice.

Paying debt down does **not** require lowering the baseline by hand — it only
requires not adding to it. Regenerating it after a real reduction keeps the file
honest, and the baseline only ever shrinks; raising it needs a reason in the
commit message.

This is **not** a "cross-module dependencies are forbidden" test — there are 805
of them today; forbidding them all would only force `--skip`, and a gate that is
switched off is no longer a gate.

```sh
cd backend
php -d memory_limit=-1 vendor/bin/deptrac analyse                    # the gate
php -d memory_limit=-1 vendor/bin/deptrac analyse --report-uncovered  # what no layer claims
php -d memory_limit=-1 vendor/bin/deptrac debug:unassigned            # classes owned by nobody
php -d memory_limit=-1 vendor/bin/deptrac analyse --formatter=baseline -o deptrac-baseline.yaml   # after cutting coupling
php artisan architecture:deptrac-config              # regenerate config from the ledger
```

## Ranh giới GHI theo aggregate — cổng thứ hai, khác Deptrac

Deptrac đo **ai gọi ai** (cạnh `use`). Nó không thấy **ai GHI vào bảng của ai** —
`DB::table('products')->update()` không tạo một cạnh `use` nào. Đó là cổng thứ
hai, độc lập, từ plan-047:

| Thứ | Ở đâu |
|---|---|
| Aggregate: model + bảng + **danh sách file được phép ghi** | `backend/config/domain-mutation-guard.php` (`aggregates.*.boundaries`) |
| Allowlist writer legacy | `backend/architecture/domain-mutation-writers.php` — **cố ý RỖNG ở gate 4** |
| Bộ quét | `php artisan architecture:domain-writers --json` → `{known, new, stale, errors}` |
| Rào | `tests/Arch/{DomainMutationProductMenuBoundaries,ZDomainMutationBaseline}Test.php` + `tests/Feature/Payment/Plan047AcceptanceDomainMutation*Test.php` |

**Một writer mới ngoài `boundaries` là ĐỎ ngay.** Đừng gỡ bằng cách thêm vào
allowlist — file đó rỗng có chủ đích; thêm vào là tháo chính cái cổng.

Ba đường hợp lệ khi một module cần ghi bảng của module khác, theo thứ tự ưu tiên:

1. gọi service/persistence sẵn có của module sở hữu bảng;
2. thêm một **cổng hẹp** (interface trong `Services/<Module>/Contracts/`) +
   hiện thực trong `Services/<Module>/Internal/`, rồi khai file hiện thực vào
   `boundaries`. Tiền lệ: `EloquentReviewedSkuDirectory` (#962),
   `EloquentProductTaxStamp` (#2346);
3. với việc **một lần, ngoài runtime** (migration catalog, lệnh bảo trì) thì
   dùng khuôn `TaxExemptBrandPersistence`: class không bind container, docblock
   nói rõ maintenance-only. **Đường runtime không được dùng lại nó.**

**Cổng chỉ quét `app/`** (`DomainMutationGuard` bỏ qua mọi path không bắt đầu
bằng `app/`). Nên cùng một câu lệnh ghi nằm trong `database/seeders/` thì vô
hình. Dời logic từ seeder vào `app/Services/` sẽ làm lộ ra vi phạm vốn đã có —
đó là cổng làm đúng việc, không phải regression của lần dời (#2346).

## The runtime kernel names the same modules

`App\Modules\Kernel\ModuleServiceProvider` is the whole contract: a module
declares its own wiring, and the kernel only knows how to ask it to register.

`ModuleRegistry` discovers providers **by convention over the same ownership
manifest Deptrac is generated from** — for each module in `config/modules.php`
it registers `App\Modules\<Name>\<Name>ModuleServiceProvider` if that class
exists. A module with no code yet is skipped, so adding one never touches the
kernel, and the registry throws at boot if a provider's `moduleName()` disagrees
with its directory.

Two registries naming the same thing differently is exactly how the previous
hand-rolled dependency map drifted out of step with reality. Here they cannot:
there is one list, and both the checker and the runtime read it.

## Mỗi PR khai module sở hữu

Every PR states which module it belongs to, in `.github/pull_request_template.md`.
One line, filled in by the author.

It is deliberately **not** enforced by CI. A bot cannot tell "Payments" from
"Pricing" for a given diff — only the author knows which context the change
belongs to, and a check that guesses would be trained around within a week. The
value is in the author having to answer at all: a change whose owning module is
genuinely unclear is usually a change that spans a boundary, and that is exactly
the moment worth noticing — before it becomes one more of the 805 edges.

A PR touching more than one module must state the **dependency direction**
(which module calls which) and why it could not be two PRs. Reverse-direction
dependencies and new cycles are review blockers under
[ADR 0001](../decisions/0001-modular-monolith.md).

Docs-only, CI-only or tooling PRs answer `none`.

## Phase 0 is complete

| Piece | Where |
|---|---|
| Ownership ledger | `backend/config/modules.php` |
| Measurement | Deptrac, config generated by `php artisan architecture:deptrac-config` |
| CI ratchet | `backend/deptrac-baseline.yaml`, run by `deptrac analyse` in the `arch-gate` job |
| Signed decision | [ADR 0001 — Modular monolith](../decisions/0001-modular-monolith.md) — dependency rule, event policy, transaction ownership, and the five conditions a module must meet before it may be extracted |
| Operational baseline | [architecture-baseline.md](../operations/architecture-baseline.md) — CI 47% red rate, 41% deploy-attempt failure rate, and the runtime signals that cannot be measured yet |
| Ownership declared per PR | `.github/pull_request_template.md` (see above) |

The baseline changes what Phase 1 should start with. The measured pain is
**delivery reliability**, not coupling latency — and ADR 0001 condition 5 makes
that explicit: a healthy CI and deploy pipeline is a precondition for extracting
anything, because splitting deployables multiplies deployments, and multiplying a
41% failure rate multiplies the failures too.
