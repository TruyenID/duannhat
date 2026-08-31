---
plan: 046
title: Shift handover reports + chain-of-shifts final close (引き継ぎ / 精算)
slug: shift-handover-chain-reports
issue: 884
status: shipped
branch: feature/plan-046-shift-handover-chain-reports
created: 2026-07-17
updated: 2026-08-05
landed_via: >-
  merged to dev (feature branch deleted); see the plan's tracking issue
  for how it closed. TASKS.md checkboxes are NOT the completion signal here —
  several plans shipped by a different route than the ladder they describe (#1802).
---

# Plan 046 — Shift handover reports + chain-of-shifts final close

> Add a **handover (bàn giao ca)** action alongside the existing settle flow so a cashier can end
> the current shift, print a **single-shift** settlement-style report, and immediately open the
> next shift in the **same chain** (blind-recount opening float). The existing settle becomes the
> **final close (kết ca cuối)**: it ends the whole chain and prints an **aggregate report** covering
> every shift from the first to now (per-shift blocks + grand total). After a final close, the next
> open starts a **brand-new chain**.

## Status

- **Current:** `draft`
- **Created:** 2026-07-17
- **Owner:** _(assign)_

## Motivation

Restaurants run multiple cashiers per business day on one drawer. Today each `till_session` is a
standalone open→settle cycle: closing prints one 精算 (Z) slip and there is **no way to hand the
drawer to the next cashier mid-day while keeping a running total**. Operators asked for the classic
POS **X-report (点検 / handover, non-resetting) vs Z-report (精算 / final, resetting)** split, plus a
day-level roll-up that combines every shift. This plan adds that: per-shift handover slips during
the day, and one aggregate slip at final close — with per-cashier cash accountability (blind count)
and per-rate consumption-tax lines on every slip (軽減税率 / インボイス, plan-043).

## In scope

- **Chain model on `TillSession`** (no new table): `chain_id`, `chain_sequence`, `settlement_kind`
  (`handover` | `final`), `settlement_snapshot` (immutable reconcile JSON captured at settle).
- **Handover action** — `POST /pos/till/sessions/{session}/handover`: settle current shift as
  `handover`, snapshot its totals, keep the chain open; the next `open` auto-continues the chain with
  a **blind re-count** opening float and `chain_sequence + 1`.
- **Final close = repurposed settle** — the existing `close` now sets `settlement_kind = final`,
  ends the chain, and drives the aggregate report. Behaviour for a chain of ONE shift is identical
  to today.
- **Aggregate chain report** — `GET /pos/till/chains/{chainId}/summary` returns a per-shift block
  for every shift in the chain (cash / per-rate tax / revenue) **plus a grand-total block**, summed
  from the immutable per-shift `settlement_snapshot` (never re-derived — avoids double-count + tax drift).
- **Workstation ESC/POS** — reuse `/api/lan/print/shift-report` for the single-shift handover slip
  (add a `report_kind` header label 引き継ぎ vs 精算); new `/api/lan/print/chain-report` +
  `FormatChainReport` for the aggregate.
- **pos-web** — rename the close flow to "kết ca cuối" (final), add a "bàn giao ca" action; the open
  flow shows chain position + blind re-count when continuing a chain. Both buttons visible on every
  shift (a shift-1 final close = chain of one).
- **Offline-first** — workstation mirrors the new fields (SQLite migration + sync DOWN), computes the
  aggregate locally by summing each chain session's snapshot.

## Out of scope

- Cash-drawer physical hardware handover / dual-custody signatures.
- Manager approval workflow for handover (handover is cashier self-service; force-abandon/expire from
  plan-032 still apply).
- A Cloud-side PDF of the chain report (thermal ESC/POS only, matching plan-038).
- Cross-branch / multi-till chains (a chain is scoped to one till at one branch).
- Editing/re-opening a settled shift or a closed chain (immutable, per Z-report semantics).

## Success criteria

- [ ] Pressing "bàn giao ca" on an open shift settles it (`settlement_kind = handover`), prints a
      single-shift slip, and the next open continues the same `chain_id` with `chain_sequence + 1`
      and a blind-recount opening float.
- [ ] Pressing "kết ca cuối" settles the shift (`settlement_kind = final`), prints an aggregate slip
      listing every shift of the chain + a correct grand total, and the next open starts a new chain
      (`chain_sequence = 1`, fresh `chain_id`).
- [ ] Grand total == Σ per-shift snapshots for cash, per-rate tax, and revenue — asserted to the yen;
      a later void/refund does NOT retro-change an already-settled shift's block (snapshot immutable).
- [ ] Chain of one shift (final-close without any handover) prints an aggregate identical to today's
      single 精算 slip.
- [ ] Workstation offline handover → final-close produces the same figures as Cloud after sync.

## Dependencies

- plan-030 (till_session state machine), plan-032 (force-abandon/expire/manual-settle), plan-038
  (LAN print + `FormatShiftReport`), plan-043 (per-rate tax snapshot + rounding), plan-044 (gap
  reconciliation + attribution) — all shipped/inherited.
- `@godxjp/ui` for any pos-web additions.

## Open questions

- [ ] Abandon/expire of a mid-chain shift: confirm it ends the chain (next open = new chain) and the
      final aggregate only counts settled shifts — proposed default in DESIGN, needs sign-off.
- [ ] Reprint policy for a handover slip / chain report after the fact (allowed N times? audit each?).

## Files in this plan

- [DESIGN.md](DESIGN.md) — approach, chain model, endpoints, screens, decisions
- [NOTES.md](NOTES.md) — research (X/Z + project), decisions, blockers

## Related

- Domain: X-report vs Z-report (点検 / 精算), Air Regi three-tier (点検 / 精算 / 売上報告).
- plan-044 `docs/guide/cashier-shift-recovery.md`.
