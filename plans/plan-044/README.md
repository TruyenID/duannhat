---
plan: 044
issue: 503
title: Order ↔ Till Session Attribution + Shift Carry-over Queue
slug: order-till-session-attribution
status: shipped
branch: "feature/plan-044-order-till-session-attribution"
created: 2026-07-08
updated: 2026-08-05
landed_via: >-
  merged to dev (feature branch deleted); see the plan's tracking issue
  for how it closed. TASKS.md checkboxes are NOT the completion signal here —
  several plans shipped by a different route than the ladder they describe (#1802).
---

# Plan 044 — Order ↔ Till Session Attribution + Shift Carry-over Queue

> Stamp every order-creation surface (POS cloud/LAN, Handy cloud/LAN, Customer QR/branch, Workstation sync) with the currently-open cashier shift (`till_session_id`), carry unpaid + gap orders to the next shift atomically when it opens (the "queue" from the whiteboard design), and fix the existing bug where payments synced UP from the workstation lose their shift attribution on Cloud.

## Status

- **Current:** `implementing` — **R2 implementation COMPLETE** across backend + workstation-app + pos-web (all tested); only the release push + submodule-pointer bump (T-R2.18) remains. The "Shipped reality (2026-07-11)" note below is the pre-R2 (R1) snapshot; R2 reverted the carry-over queue. Note: the LAN POS `NO_OPEN_SHIFT` gate (R1 T5.2), never built in R1, was found + implemented during the R1-disposition audit (2026-07-16).
- **Created:** 2026-07-08
- **Owner:** p-duyanh@famgia.com

> **Shipped reality (2026-07-11).** Backend is live: `customer_orders` carries a
> `till_session_id` column (Omnify migration + model fillable), orders are
> stamped at creation, and carry-over re-stamp runs **inside**
> `TillSessionService::open()` (`openSessionIdForBranch()` +
> `carryOverActiveWork()` — re-stamps active orders whose `till_session_id`
> is null or `!= new`, plus gap payments). Regression-covered by
> `ConfirmRestampShiftTest`. The workstation `Payment` domain has the
> `TillSessionID` field (`internal/domain/payment.go`).
>
> **Still open (not built):** the hourly `tills:restamp-orphan-orders` safety-net
> command (no such command in `backend/app/Console`), and the full workstation
> Phase 5–6 (`restampActiveOrders`, the LAN `NO_OPEN_SHIFT` gate, send-time
> session-id remap on sync UP). Read the phase list below with Phases 1–4 as
> shipped and Phases 5–7 as pending.

## 🔄 Revision R2 — 2026-07-15 (pivot: drop the queue, manual gap reconciliation)

Direction change approved by the owner. Discovery (R2, see NOTES) **proved by
code** that the drawer reconciliation (`cash_variance = counted_cash −
expected_cash`) is computed **solely** from `order_payments.till_session_id`
(Cloud `TillSessionService::reconcile()` L984–1098) or a **time-window** on the
workstation (`reconcileSession`, `local_pos_till.go`) — and that
`customer_orders.till_session_id` is **never read by any reconcile/revenue query**.
Therefore the **order carry-over queue is cash-flow-irrelevant and is dropped.**

New model:

1. **Drop the order re-stamp queue.** `carryOverActiveWork()` stops re-stamping
   active `customer_orders`; the workstation order re-stamp + the hourly
   `tills:restamp-orphan-orders` safety net are removed. Orders created in the gap
   stay `NULL`; **unpaid orders simply remain `active` and are served in the next
   shift** — no attribution chase. **Guaranteed zero cash-flow impact.**
2. **Close counts only paid orders** — already true (reconcile is payment-driven;
   unpaid orders have no payment → contribute nothing). The close screen gains a
   visible **"N paid · M unpaid-carry"** summary.
3. **Gap payments → operator-confirmed manual reconciliation, not auto re-stamp.**
   The auto gap-payment re-stamp (old R4 in `open()`) is removed. At shift open a
   **NEW pos-web panel** lists payments taken during the previous close-gap; the
   operator reconciles them against a paper note (and the physically-held cash) and
   **ticks which to attribute to the new shift** → `open()` stamps only those.
   **Gap cash is held aside by staff** (not dropped into the drawer), so it is **also
   claimable** — confirming it adds it to ca-2's cash sales and the held cash goes into
   the drawer. To avoid the one error mode (counting the held cash into the opening float
   AND confirming it → double count), the panel shows the **gap-cash total separately**
   and requires a **"held-separately" acknowledgment** before cash rows can be confirmed.

Approved decisions: gap ghi-nhận = operator-confirm (2026-07-15); **gap cash is held
separately by staff → recorded as a normal payment and attributed to ca-2 on confirm**
(2026-07-15 clarification — supersedes the earlier "cash not claimable" answer); close
screen = show paid / unpaid-carry counts.

**Kept from the original plan:** payment stamping at creation for `open`/`closing`
sessions (plan-030), the workstation→Cloud payment-sync NULL bug fix, the
`NO_OPEN_SHIFT` LAN parity gate, and `customer_orders.till_session_id` stamping
*at creation* (harmless, retained for future order-level display).

**Superseded (do not build / revert if shipped):** auto gap-payment re-stamp,
workstation order re-stamp, hourly orphan-restamp command, the "virtual FIFO queue"
framing. The **In/Out/Success sections below are rewritten to R2**; the original
auto-queue rationale is preserved in DESIGN "Original design (superseded)".

## Motivation

Today `till_session_id` exists **only** on `order_payments` (plan-030), and is stamped
**only** on the direct-to-cloud POS payment path. Verified gaps (this session, file:line
evidence in [NOTES.md](NOTES.md)):

1. **Orders are never attributed to a shift** — no `till_session_id` column on
   `customer_orders` (Cloud) or `orders` (workstation SQLite). POS order creation is
   *gated* by an open shift (`409 NO_OPEN_SHIFT`) but the created order is not *stamped*.
2. **Handy / Customer order creation** is neither gated nor stamped, on both Cloud and
   workstation LAN paths.
3. **Bug:** payments recorded on the workstation (POS LAN + kiosk) sync UP with
   `till_session_id = NULL`, so Cloud per-shift reports
   (`ShopTillTrackingService`, `TillSessionService::reconcile`) silently exclude them.
   Local shift reports use a time-window fallback, so Cloud and workstation disagree.
4. **No carry-over semantics** for the window between shift close and next shift open:
   orders created in that gap (handy/customer keep working) belong to nothing, forever.

The whiteboard design (2026-07-08, photo) defines the target behavior: paid orders lock
to their shift; unpaid orders at close + orders created during the gap form a queue;
when the next shift opens, the whole queue is re-stamped to the new shift; payments
arriving during the gap are accepted and attributed to the next shift.

## In scope (R2 — current plan of record)

- **Remove the active-order re-stamp** from `TillSessionService::carryOverActiveWork()`
  (Cloud); do NOT port it to the workstation; delete the hourly
  `tills:restamp-orphan-orders` command if it was shipped. Keep the
  `customer_orders.till_session_id` column + its **creation-time** stamp (open-only, R1).
- **Remove the automatic gap-payment re-stamp** (old R4) from `open()`.
- **Keep** payment attribution at creation for `open`/`closing` sessions (plan-030) +
  the workstation→Cloud payment-sync NULL bug fix (send-time local→cloud session-id
  remap + `errDependencyNotReady` + R6 tolerant accept on `POST /workstation/payments`).
- **NEW read endpoint — gap-payment preview** for the shift-open screen: the previous
  settled/abandoned session's `closed_at`/`abandoned_at` + a list of the branch's
  `till_session_id IS NULL` payments in the gap window `(prev_end, now]`, each row
  tagged `is_cash` (method type) + order code + amount + method + created_at.
- **NEW write path — operator-confirmed claim.** `POST /pos/till/sessions` (open) gains
  optional `claimed_gap_payment_ids[]` + `gap_cash_held_separately_ack`; after the session
  is created, `open()` stamps **only those ids** to the new session (validated:
  branch-owned, currently NULL, inside the gap window). **Cash IS claimable** (held aside
  by staff, not in the opening float); the ack is recorded in the audit. Idempotent + audit-logged.
- **NEW read endpoint — close order summary**: paid-order count (distinct orders with a
  succeeded payment in the session) + unpaid-carry count (active orders not fully paid)
  + the unpaid-carry order list, for the close screen.
- **NEW pos-web UI**: (a) shift-open **gap-reconciliation panel** (per-row claim checkbox
  for cash & non-cash; a separate **gap-cash callout** + required **"held-separately" ack**
  gating cash confirmation); (b) shift-close **paid/unpaid-carry summary**. `@godxjp/ui`;
  ja/en/vi i18n; no admin-web.
- **Workstation Go parity** for the two read endpoints + the claim, served from local
  SQLite — gap payments may be **local-only** (kiosk/customer LAN) before they sync UP,
  so a Cloud-only query would miss them.
- **Two-way attribution sync — must ALWAYS converge.** R2 removed the auto re-stamp that
  used to converge the two DBs, so the claim (a single manual write) must be propagated:
  the workstation syncs it UP via a NEW `payment.attribute` op → `POST
  /workstation/payments/{id}/attribution` (local→cloud remap, dependency-ordered on
  `till_session.open`+`payment.create`, R6-validated, idempotent, tolerant); Cloud
  attribution mirrors DOWN into local `payments.till_session_id`; the workstation retains
  the previous terminal session so the gap window is always computable; order sync-UP omits
  the display-only local session id to avoid leakage.
- **`NO_OPEN_SHIFT` LAN parity gate** on workstation POS (order + payment) — kept.

## Out of scope (R2)

- **No automatic order OR payment carry-over queue** — dropped entirely (the pivot).
- **No hourly `tills:restamp-orphan-orders`** — obsolete without the order queue.
- **No physical queue table** (unchanged).
- **No change to the drawer-reconciliation math** — Cloud stays payment-attribution,
  workstation stays time-window; **cash reconciles via the physical open/close counts**.
  This plan adds attribution *records*, it does not touch how 過不足 is computed.
- **No auto-attribution of cash gap payments** — their cash is already captured by the
  opening-float count; the panel only *displays* them (flagged) to avoid double-counting.
- **No backfill** of historical NULL orders/payments; **no multi-till-per-branch**.
- **No force-close/void of unpaid orders at shift close** — they stay `active` and carry
  by simply remaining open (assert this invariant, don't add code for it).

## Success criteria (R2)

- [ ] **Cash-flow-independence proof:** a Pest test asserts `TillSessionService::reconcile()`
      output (expected_cash, cash_variance, per-method sums) is byte-identical whether or
      not `customer_orders.till_session_id` is set — removing the order queue changes no money.
- [ ] Unpaid active orders survive a close→open cycle unchanged (never voided/force-closed)
      and are payable in the next shift.
- [ ] Close screen shows correct **paid** vs **unpaid-carry** counts; the money
      reconciliation is unchanged (paid-only, payment-driven).
- [ ] Shift-open gap panel lists **every** branch payment taken in the gap window
      (Cloud + workstation-local), cash rows flagged; nothing silently omitted.
- [ ] Operator-confirmed claim stamps the ticked gap payments (**cash + non-cash**) to the
      new session; confirming any cash row requires the "held-separately" ack; unticked rows
      stay `NULL`; cash is never AUTO-attributed (no auto re-stamp).
- [ ] Cash-flow closure: with gap cash held aside (not in the opening float) and confirmed,
      the next shift's `cash_variance = 0`; if the operator mistakenly counts it into the
      float AND confirms, the panel's ack + separate callout is the guard (documented risk).
- [ ] Claim is idempotent and branch-scoped; a foreign / settled-session / out-of-window
      payment id is rejected (validation), never 422-dead-letters the open() call.
- [ ] Workstation→Cloud payment-sync NULL bug fix regression test still green.
- [ ] **Convergence:** a payment claimed on the workstation ends with the identical (cloud)
      `till_session_id` on Cloud after a full sync cycle — both when it was already synced and
      when it wasn't — idempotent + branch-scoped; local & Cloud never silently diverge (the
      explicit replacement for the removed auto-converge).
- [ ] The workstation retains the previous terminal session so `gap-preview` computes the
      window after a `PullTillSessions` cycle; order sync-UP never leaks a local session id.
- [ ] All R2 TESTS.md scenarios green: `php artisan test --compact`, `make test`.

## Dependencies

- plan-030/031/032 cashier-shift machinery (TillSession state machine, scheduler,
  recovery doc) — extended, not modified.
- Omnify codegen (`npm run omnify:gen` from umbrella root) for the CustomerOrder schema
  change.
- Workstation submodule release flow (migration 038 ships with the next
  workstation-app build; umbrella bump ritual applies).
- Deploy ordering: **backend first** (nullable column, tolerant accept rules), then
  workstation build.

## Open questions

- (resolved 2026-07-08) POS LAN gate parity → **full gate, order + payment**.
- (resolved 2026-07-08) Carry-over traceability → **audit log only**.
- Multi-till-per-branch: if v2 ever allows several tills per branch, the branch-scoped
  resolver and re-stamp need a till-selection rule. Recorded as assumption, not blocking.

## Files in this plan

- [DESIGN.md](DESIGN.md) — approach, decisions, alternatives
- [NOTES.md](NOTES.md) — working log, discovery evidence (file:line)

## Related

- `CLAUDE.md` § Cashier shift workflow (plan-030/031/032)
- `docs/guide/cashier-shift-recovery.md` — plan-032 exit doors the re-stamp must respect
- `schemas/Backend/Product/OrderPayment.yaml` — `tillSession` association precedent
- Whiteboard photo (2026-07-08) — FIFO queue carry-over design, transcribed in DESIGN.md
