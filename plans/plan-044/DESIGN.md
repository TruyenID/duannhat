# Plan 044 — Design

> Design decisions, approach, and trade-offs for [Order ↔ Till Session Attribution + Shift Carry-over Queue](README.md).

## Context

@see docs/guide/cashier-shift-recovery.md — plan-032 exit doors (force-abandon / expire / manual-settle); the carry-over rule must treat all non-`open` terminal session states identically.
@see schemas/Backend/Product/OrderPayment.yaml — `tillSession` association block (lines 219–240) is the exact YAML syntax + comment style to mirror onto CustomerOrder; its header records plan-030 "Decision 2: stamp, not time-window", which this plan extends to orders.
@see schemas/Backend/Product/CustomerOrder.yaml — schema being extended; header documents lifecycle (`open → dining → checkout → paying → closed`, any→`voided`) that defines the "active" status set for the queue.
@see backend/app/Models/TillSession.php — `scopeOpen()` (open only) vs `scopeInProgress()` (open+closing) distinction (lines 42–57); order stamping uses `open()`, payment gating keeps `inProgress()`.
@see backend/app/Http/Middleware/ResolveOpenTillSession.php — existing plan-030 gate; its own comment ("and any future order service") anticipated this plan. NOT reused for order stamping because it resolves `inProgress`.
@see workstation-app/CLAUDE.md — hand-written migration ownership (versions 1..999), security middleware ring, and the (partially outdated) till endpoint table; routes.go:226–237 shows till sessions ARE served locally, which drives the offline id-remap design.
@see CLAUDE.md — umbrella § "Cashier shift workflow (plan-030/031/032)" + submodule bump ritual for workstation-app.

# ══════════════════════════════════════════════════════════════════
# Revision R2 — 2026-07-15 (PLAN OF RECORD)
# ══════════════════════════════════════════════════════════════════

> Pivot approved by owner: **drop the carry-over queue; reconcile gap payments
> manually at shift open.** Everything below this banner supersedes the "Original
> design (superseded)" sections further down, which are retained only for their
> discovery evidence and rationale.

## R2 Approach

Two axes remain (order ≠ payment), but only **one is cash-flow-bearing** and this
plan changes neither the math nor the drawer:

- **Order axis** — `customer_orders.till_session_id`. Stamped at creation for the
  `open` session (kept, R1). **No carry-over re-stamp.** Orders created in the gap
  stay `NULL`; unpaid orders remain `active` and are simply served in the next
  shift. This column is **display-only** — no reconcile or revenue query reads it.
- **Payment axis** — `order_payments.till_session_id`. Stamped at creation for the
  `inProgress` (open/closing) session (plan-030, kept) + the workstation-sync NULL
  bug fix (kept). Gap payments (`NULL`, no session) are **not auto re-stamped**;
  instead a **manual operator claim at shift open** attributes the chosen ones.

The "queue" (virtual set + atomic re-stamp) is gone. In its place: a **read**
(what happened in the gap) + an **operator-confirmed write** (claim into the new
shift), plus a close-screen **paid/unpaid-carry** summary.

## 🔒 Cash-flow guarantee (formal — this is the owner's non-negotiable)

**Claim: removing the order queue and the auto gap-payment re-stamp introduces
ZERO cash-flow risk.** Proof, by code:

1. **過不足 never reads order attribution.** Cloud `TillSessionService::reconcile()`
   (L961–1156) computes `expected_cash = opening_float + cash_sales + cash_tips +
   paid_in + loan_from_safe − paid_out − pickup_to_safe`, where every term is drawn
   from `order_payments.till_session_id = session.id` (L984–1003, 1025) or
   `till_cash_events.session_id`. `cash_variance = counted_cash − expected_cash`
   (L559–585). **`customer_orders.till_session_id` appears in NO reconcile or
   revenue query** (grep: only written by carry-over/creation, read by nothing).
   The workstation computes the same by **time-window** on `payments.created_at`
   (`reconcileSession`, `local_pos_till.go` L707–713) — also order-attribution-free.
   → A dedicated test asserts `reconcile()` is **byte-identical** with the order
   column set vs NULL. Dropping the order queue is provably money-neutral.

2. **Cash is never lost — the physical process decides how gap cash is counted.**
   Owner-specified process (2026-07-15): a gap payment is recorded like any other
   payment, and if it is **cash the staff physically holds it aside** (NOT dropped
   into the shared drawer). At ca-2 open the operator counts **the drawer only** into
   `opening_float` (the held gap cash is excluded), reviews the gap panel, **confirms
   the gap payments (cash included)** → they are attributed to ca-2 as `cash_sales`,
   and the held cash is then added to the drawer. Every coin is counted exactly once.
   Worked example (float ¥10,000; gap ¥800 cash held aside; ca-2 sells ¥5,000 cash):
   `opening_float = 10,000` · `cash_sales = 800 (gap) + 5,000 = 5,800` ·
   `expected = 10,000 + 5,800 = 15,800`; physical `counted_cash = 10,000 + 800 +
   5,000 = 15,800` → **variance = 0. ✅**

3. **The ONE error mode is double-counting the held gap cash** — i.e. the operator
   both counts the ¥800 into `opening_float` AND confirms it as a ca-2 sale. Then
   `expected = 10,800 + 5,800 = 16,600` vs `counted = 15,800` → **false shortage of
   ¥800**. Because the held cash is outside the system's view, this is prevented by
   **process + UI, not by blocking cash claims**: the panel shows the **gap-cash
   total separately** from the opening-float field, states *"tiền mặt gap do nhân
   viên giữ riêng — KHÔNG đếm vào quỹ đầu ca; xác nhận để cộng ¥X vào doanh thu ca
   này"*, and requires an **acknowledgment checkbox** ("gap cash is held separately,
   not in my drawer count") before cash rows can be confirmed. Non-cash gap payments
   touch no drawer, so confirming them only affects Cloud per-shift *revenue/tender*
   records — never 過不足. (The removed auto-R4 re-stamp had *no* such guard and would
   double-count silently whenever gap cash landed in the drawer — the reason it is
   dropped.)

**Bottom line for the owner:** two tiers of assurance —
   - **Order-queue removal = code-proven zero cash risk** (§1) — reconcile never reads
     order attribution; a byte-identical test locks it.
   - **Gap-cash attribution = process-guaranteed correct** (§2) — the drawer balances
     when gap cash is held aside and counted once (as a confirmed ca-2 payment, not in
     the float). The system enforces attribution, validation, and the "hold separately"
     acknowledgment; the single residual risk (§3, operator double-counts) is contained
     by the UI, not left silent as the old auto-re-stamp did.

## R2 Business invariants

| # | Invariant |
|---|-----------|
| R1 (kept) | Orders stamp the branch's `open` session at creation; `closing`/none → `NULL`. POS gated (`NO_OPEN_SHIFT`), handy/customer/kiosk ungated. |
| R2 (**changed**) | **No order carry-over.** `carryOverActiveWork()` no longer updates `customer_orders`. Unpaid orders carry by remaining `active`. |
| R3 (kept) | Terminal orders (`closed`,`voided`) and settled sessions are immutable. |
| R4 (**changed**) | **No auto gap-payment re-stamp.** Gap `NULL` payments — **cash and non-cash alike** — are attributed to ca-2 only by an explicit operator claim at open. Cash gap payments are held aside by staff (not in the drawer) so confirming them is correct; the panel enforces the "held-separately" acknowledgment (§Cash-flow guarantee). |
| R5 (kept) | Payment attribution is independent of order attribution; a stamped payment keeps its session forever. |
| R6 (kept) | Cloud is authoritative on sync UP; a provided `till_session_id` kept only if it resolves to a same-branch session, else fallback/NULL — never 422. |
| R8 (**new**) | The claim is idempotent, branch-scoped, and gap-window-bounded. Claiming an already-stamped / terminal-session / foreign-branch / out-of-window id is a silent no-op, never an error (never 422-dead-letters the open call). |
| R9 (**new**) | Close never force-closes or voids unpaid `active` orders — they persist across the close→open boundary untouched. |

## R2 Data model

**No new columns, no migrations.** `customer_orders.till_session_id` and
`orders.till_session_id` already exist (shipped) — kept for the creation-time stamp
+ future order-level display. `order_payments.till_session_id` already exists. R2 is
pure behavior + read endpoints + one optional write field + UI.

## R2 API surface

**3 changes.** No new tables; one existing endpoint gains an optional field.

### Endpoint inventory (R2)

| # | Method | Path | Change | Auth | Layer |
|---|--------|------|--------|------|-------|
| A | GET | `/api/v1/pos/till/gap-preview` | **NEW** — previous session end + gap `NULL` payments (tagged cash/non-cash) | SSO + `X-Shop-Slug` (no open session required) | Cloud + workstation-local |
| B | GET | `/api/v1/pos/till/sessions/{id}/order-summary` | **NEW** — paid count + unpaid-carry count/list | SSO + `X-Shop-Slug` + session∈branch | Cloud + workstation-local |
| C | POST | `/api/v1/pos/till/sessions` (open) | **MODIFIED** — optional `claimed_gap_payment_ids[]` + `gap_cash_held_separately_ack`; stamps those (**cash + non-cash**, branch-owned, NULL, in-window) to the new session | SSO + `X-Shop-Slug` | Cloud + workstation-local |
| D | POST | `/api/v1/workstation/payments/{id}/attribution` | **NEW** — workstation→Cloud propagation of a **post-creation** `till_session_id` change (a claim done locally); R6-validated + idempotent, tolerant (never 422) | `device.auth:workstation` | Cloud (accept) ← workstation (sync op) |

### Endpoint detail

#### A. GET `/api/v1/pos/till/gap-preview`

- **Resolver:** most-recent **terminal** session of the branch's till (status
  `settled`/`abandoned`/`expired`) → `prev_end = max(closed_at, expired_at,
  abandoned_at)`. No prior session → `previous_session: null, payments: []` (mirror
  the plan-030 "no prior session → skip" bound; never sweep unbounded history).
- **Query:** `order_payments WHERE branch_id = :branch AND till_session_id IS NULL
  AND status IN (succeeded, refunded-of-succeeded) AND created_at > :prev_end`,
  joined to `payment_methods` for label + `type` (→ `is_cash = (type = 'cash')`),
  and to `customer_orders` for the order code. Workstation runs the equivalent over
  local SQLite (same `substr(created_at,1,19) > prev_end` window as `reconcileSession`).
- **Response 200:**

  ```json
  { "data": {
    "previous_session": { "id": "...", "session_code": "SHIFT-…", "ended_at": "2026-07-14T09:00:00Z" },
    "gap_window": { "from": "2026-07-14T09:00:00Z", "to": "2026-07-14T09:12:00Z" },
    "payments": [
      { "id": "...", "order_id": "...", "order_code": "#0007", "amount": 800,
        "currency_code": "JPY", "method_code": "paypay", "method_label": "PayPay",
        "is_cash": false, "created_at": "...", "customer_name": null }
    ],
    "totals": { "count": 1, "cash_amount": 0, "non_cash_amount": 800 }
  } }
  ```
- **Side effects:** none (read).

#### B. GET `/api/v1/pos/till/sessions/{id}/order-summary`

- **paid_orders_count** = `COUNT(DISTINCT customer_order_id)` from `order_payments`
  where `till_session_id = :id` and status succeeded (the orders whose money this
  shift holds). **unpaid_carry** = branch `customer_orders` in the active status set
  with no fully-covering succeeded payment. Response also returns the unpaid list
  (code, table, total, outstanding, status) for the expandable UI.
- **Read-only**; drives the close-screen summary. Workstation local parity.

#### C. POST `/api/v1/pos/till/sessions` — optional `claimed_gap_payment_ids[]`

- **Added request field:**

  | Field | Type | Required | Notes |
  |-------|------|----------|-------|
  | `claimed_gap_payment_ids` | uuid[] | no | Ids the operator ticked on the gap panel (**cash and non-cash**). After the session is created inside `open()`, each id is stamped `till_session_id = new` **iff** it is: branch-owned, currently `NULL`, and `created_at` in `(prev_end, opened_at]`. Invalid ids are **skipped, never 422** (R8). Cash ids are accepted (the held cash is added to the drawer as ca-2 `cash_sales`); the "held-separately" correctness is a UI/process guarantee, not a backend filter. |
  | `gap_cash_held_separately_ack` | bool | no | Required `true` by the client when the claim set contains any cash payment — records the operator's acknowledgment that the gap cash is held aside and NOT in the opening float (audit trail). Backend stores it in the audit entry; it does not gate the stamp (physical count is unobservable server-side). |

- **Success (unchanged 201) + new field** `data.gap_payments_claimed: <int>` (count applied).
- **Side effects:** the stamped payments now belong to ca-2 (Cloud reconcile + tender
  reports include them); **audit entry** `till_session.gap_claim` with `{ applied_ids,
  skipped_ids, total_amount, actor }`. All inside the existing `open()` transaction —
  a claim failure rolls back the open (shift never opens half-attributed).

## R2 sync model — attribution must ALWAYS converge (backend ⇄ workstation)

> **This is the load-bearing sync contract of R2.** The original plan let the two DBs
> converge by having *each side run its own carry-over re-stamp* (R7). R2 **removes that
> auto re-stamp**, so convergence is no longer implicit — the manual claim is a single
> operator action on ONE side and its `till_session_id` write MUST be propagated to the
> other. Without this, Cloud per-shift revenue and the workstation mirror silently diverge.

### Where the claim executes

- **Workstation (LAN shops — primary).** pos-web at a LAN shop calls `/pos/till/sessions`
  (open) on the **workstation** (served locally, `routes.go:226`). The claim stamps LOCAL
  `payments.till_session_id` → this side is the source of truth → **syncs UP** to Cloud.
- **Cloud (Cloud-only shops, or workstation-down fallback).** Cloud `open()` stamps
  `order_payments` directly; nothing to propagate. *(Caveat: if a LAN shop opens via the
  Cloud fallback while the workstation is down, Cloud cannot see local-only gap payments
  — documented limitation; the LAN is non-operational in that state anyway.)*

### (1) Workstation → Cloud (sync UP) — the critical path

A claimed gap payment was created earlier (during the gap) and, if the workstation was
online, **already synced UP to Cloud with `till_session_id = NULL`**. The claim UPDATE is
therefore a **post-creation attribution change** that the creation-time sync does NOT
cover. Two complementary mechanisms guarantee delivery:

- **Send-time read on `payment.create`** (reuse Decision 4): the creation payload reads
  `till_session_id` at **send time**, so a claim that lands *before* the payment has synced
  UP is carried by the creation op itself (local→cloud remap). Covers the
  offline-the-whole-gap case.
- **NEW `payment.attribute` sync op** → **endpoint D** `POST /workstation/payments/{id}/attribution`
  `{ "till_session_id": "<cloud id>" }`. For a payment ALREADY on Cloud (synced NULL), the
  local claim enqueues this op. The session id is remapped **local→cloud**; the op depends
  on BOTH `till_session.open` (the new session's `cloud_id` must exist) AND the payment's
  own `payment.create` (the payment must exist on Cloud) — resolved via
  `errDependencyNotReady` (retry one pass until ready; no deadlock — `till_session.open`
  depends on nothing). Cloud applies it under **R6** (kept only if it resolves to a
  same-branch session, else dropped → NULL; **tolerant, never 422**) and **idempotently**
  (re-applying the same value is a no-op). Audit `till_session.gap_claim_sync`.

### (2) Cloud → Workstation (sync DOWN) — consistency mirror

The workstation's own close report reconciles by **time-window**, so it does **not** depend
on `payments.till_session_id`. But to keep the local mirror correct (multi-terminal, a
Cloud-side correction, future local reports), the payment **sync-DOWN carries
`till_session_id` mapped cloud→local** (`id == local id` OR matched via `cloud_id`;
unresolvable → NULL). Lower-risk (not cash-bearing) but included so the two stores never
silently diverge. If no payment sync-DOWN channel exists today, add a minimal one keyed on
the claimed payment ids (or piggyback the existing pull).

### Order attribution (display-only) sync policy

`customer_orders.till_session_id` is stamped at creation (open-only) but **read by no
reconcile**. To avoid leaking a meaningless **local** session id onto Cloud, the workstation
order sync-UP **omits `till_session_id`** and lets Cloud stamp its own `open` session at
create (R6 fallback). No order-id remap, no order sync-DOWN mapping (both dropped with the queue).

### Previous-session retention (gap-window lower bound)

The gap window's lower bound `prev_end` = the branch till's most recent **terminal** session
end. The workstation settles sessions locally so it HAS this row — but `PullTillSessions`
(`sync_pull_pos.go`) does `INSERT OR REPLACE` and pulls only `open`/`closing` sessions,
which can **WIPE the settled row**. **Guard:** the workstation must retain the last terminal
session (or persist a `last_terminal_session_end` per till) so `prev_end` is always
resolvable locally. Pinned by a Go test: settle → run a PullTillSessions cycle → assert
`prev_end` still resolves.

### Convergence guarantee (test-enforced)

A payment claimed on the workstation and its reflection on Cloud MUST reach an **identical**
`till_session_id`, in both directions, idempotently — pinned by the cross-stack scenarios in
TESTS "R2 — Backend ⇄ Workstation attribution sync".

## R2 Screens (pos-web — NEW)

### Screen 1 — Shift-open gap-reconciliation panel

- **File:** `pos-web/src/app/shift/open-page.tsx` — a new `<Card>` inserted **between**
  the session-info card and the denomination-count card (~L315). New colocated
  component `open-page/components/gap-reconcile-panel.tsx` + hook `useGapPreview(shopSlug)`.
- **Fetches:** A (`/pos/till/gap-preview`) on mount; hidden entirely when `payments` empty.
- **Layout:** header "Thanh toán trong lúc kết ca trước" + window subtext; a list where
  **every** row (cash and non-cash) has a **checkbox** (default checked) — order code ·
  amount · method badge · time. **Cash rows carry a distinct badge** "tiền mặt — giữ
  riêng". A **separate "gap-cash total" callout** shows `¥X tiền mặt gap` with the
  instruction *"nhân viên giữ riêng — KHÔNG đếm số này vào quỹ đầu ca bên dưới; xác nhận
  để cộng vào doanh thu ca này"*. When any cash row is checked, a **required
  acknowledgment checkbox** appears — *"Tôi xác nhận tiền mặt gap được giữ riêng, không
  nằm trong quỹ đầu ca"* — and the open submit is blocked until it's ticked. Footer
  tallies "X khoản (¥…) ghi vào ca này" split cash / non-cash. A muted hint reminds the
  operator to đối chiếu với phiếu giấy.
- **Components:** `Card`, `Checkbox`, `Badge`, `Alert` (cash instruction) — all `@godxjp/ui`.
- **State → submit:** checked ids → `claimed_gap_payment_ids`, plus
  `gap_cash_held_separately_ack` (true when the ack is ticked), appended to the existing
  `OpenShiftPayload`. Client-side guard: cannot submit with cash rows checked but ack unticked.
- **Empty/loading/error:** empty → panel omitted; loading → skeleton row; error →
  inline `Alert` + retry, **never blocks** opening the shift (best-effort read).

### Screen 2 — Shift-close paid/unpaid-carry summary

- **File:** `pos-web/src/app/shift/close-page.tsx` — a summary block added to the
  session-info card (~L490). Hook `useCloseOrderSummary(shopSlug, sessionId)`.
- **Fetches:** B (`/pos/till/sessions/{id}/order-summary`).
- **Layout:** two stat chips — "N order đã thanh toán (đã tính)" · "M order chưa TT →
  chuyển ca sau", the latter expandable into a compact list (table/code · outstanding).
  Read-only; the money reconciliation UI is unchanged.
- **States:** loading skeleton; error → chips hidden (non-blocking, close must proceed).

## R2 Field lifecycle

| Field | Change | Written by | Read by |
|-------|--------|-----------|---------|
| `customer_orders.till_session_id` | behavior only — **creation stamp kept, carry-over removed** | `CustomerOrderService::create` (open-only) | order-level display only (never reconcile) |
| `order_payments.till_session_id` | behavior — creation stamp kept; gap attribution now via **claim** | creation (plan-030) + `open()` claim (C) + workstation sync | reconcile, per-shift revenue/tender reports |

## R2 Key decisions

- **RD1 — Drop the order queue.** Proven cash-flow-irrelevant (guarantee §1). Removes
  the biggest chunk of surface (workstation re-stamp + hourly command + sync remap of
  order session ids) at zero money risk.
- **RD2 — Manual operator-confirm gap claim (cash + non-cash), not auto.** Matches the
  real operator process (owner, 2026-07-15): staff **holds gap cash aside**, records it
  as a normal payment, and at open reconciles the physical held cash + paper note against
  the panel, then confirms → attribute to ca-2. Auto re-stamp is dropped because it had
  no "held-separately" guard and would silently double-count any gap cash that landed in
  the drawer.
- **RD3 — Gap cash IS claimable, under a held-separately process + UI acknowledgment.**
  Chose the owner's process — staff hold gap cash aside → attribute the confirmed payment
  (incl. cash) to ca-2 — over the earlier "count cash into float, don't attribute" model.
  The server cannot observe the physical count's composition, so correctness of the "not
  in float" step is a **UI/process guarantee** (separate gap-cash callout + required ack
  checkbox), not a backend filter. The reconcile math is exact when the process is
  followed (guarantee §2); the only residual is operator double-counting (§3), UI-contained.
- **RD4 — Claim folded into `open()` (atomic).** One operator action = open + attribute;
  same transaction, rolls back together.
- **RD5 — Workstation serves A/B/C locally.** Gap payments are frequently local-only
  (kiosk/customer LAN) before sync UP; a Cloud-only query would under-report the panel.
- **RD6 — Keep payment-sync bug fix + creation-time stamping.** These are correct
  attribution, orthogonal to the queue; the workstation-payment-NULL bug stays fixed.

## R2 Risks & mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Operator double-counts held gap cash (counts it into `opening_float` AND confirms it as a ca-2 sale) | low | false shortage = gap cash amount | UI: gap-cash total shown **separately** from the float field + **required "held-separately" ack checkbox** + hint; audit records the ack. The removed auto-R4 had no guard at all and double-counted silently. |
| Operator forgets to claim a non-cash gap payment | medium | that payment excluded from Cloud per-shift revenue (daily total + cash still correct) | reappears on the panel every open until claimed or terminal; low money impact; paper process |
| Gap payment arrives between preview-load and open-submit | medium | not claimed this round | idempotent — shown on next open (R8) |
| `is_cash` mis-derived (wrong method type) | low | a cash row becomes claimable → double count | derive `is_cash` from `payment_methods.type` server-side; unit test both branches |
| Workstation (time-window) vs Cloud (attribution) disagree on claimed gap payments | medium | per-shift **revenue** reports differ (cash reconciles on both) | documented + accepted: cash is physical-count-driven both sides; Cloud attribution is the record for claimed revenue |
| Reverting shipped `carryOverActiveWork` breaks `ConfirmRestampShiftTest` / `CarryOverGapPaymentSweepTest` | high | red suite | rewrite those tests to the R2 behavior (no auto re-stamp; claim-based) — TASKS T-R2.7 |

## R2 — what to remove from the SHIPPED backend

- `TillSessionService::carryOverActiveWork()` — delete the `customer_orders` UPDATE
  (order re-stamp) **and** the `order_payments` gap-sweep UPDATE (old R4). Keep
  `openSessionIdForBranch()` (creation stamp). The method may be removed entirely if
  `open()` no longer calls it.
- Delete `tills:restamp-orphan-orders` command + its schedule **if** it was shipped
  (README says not built — verify).
- Do **not** build workstation `restampActiveOrders`, order-id sync remap, or the
  order sync-DOWN session mapping (all belonged to the order queue). Keep the **payment**
  creation-time remap + accept (bug fix) **and ADD** the new `payment.attribute` op +
  endpoint D + payment sync-DOWN (see "R2 sync model" / Phase R2-D2) — the claim's
  propagation is the explicit replacement for the removed auto-convergence.

---

## Approach (ORIGINAL — superseded by Revision R2 above; retained for rationale + discovery)

Attribution moves to **two independent axes**: an order belongs to the shift that *serves*
it (mutable until the order closes, via carry-over), while a payment belongs to the shift
whose *drawer received the money* (immutable once stamped — plan-030, unchanged). We add a
nullable `till_session_id` FK to `customer_orders` (Omnify) and to workstation `orders`
(SQLite), stamp it at every creation point using an **`open`-only** resolver, and implement
the whiteboard "FIFO queue" as a **virtual queue**: the set of *active* orders of a branch.
Enqueue is a no-op (an unpaid order at shift close, or an order created in the gap, already
satisfies the filter); dequeue is one atomic `UPDATE` inside `TillSessionService::open()`
(Cloud) and a mirrored idempotent `restampActiveOrders()` on the workstation. Convergence
between the two databases relies on three rules: identical re-stamp predicates (R2/R7),
Cloud-authoritative accept rules on sync UP (R6), and send-time local→cloud session-id
remap reusing the existing `errDependencyNotReady` dependency machinery.

The same wiring fixes a verified production bug: payments recorded on the workstation
(POS LAN, kiosk) currently reach Cloud with `till_session_id = NULL` and are silently
excluded from per-shift reports.

## Whiteboard design (transcribed, 2026-07-08 photo)

```
Ca 1: 20 orders, 15 paid, 5 unpaid ──── close: report 15 paid
                     │ (5 unpaid keep old id, temporarily)
                     ▼
              ┌─────────────┐   during close-gap: handy + customer
              │ QUEUE (FIFO)│ ◄── keep creating orders (no shift);
              └─────────────┘     payments still accepted, attributed
                     │            to the NEXT shift
                     ▼
Ca 2 opens ──► update new shift id onto every order in the queue
```

## Business invariants (R1–R7)

| # | Invariant |
|---|-----------|
| R1 | Every created order is stamped with the branch's session in status **`open`** (never `closing`); none open → `NULL`. Handy/customer/kiosk surfaces are never gated; POS surfaces keep (Cloud) / gain (LAN) the `NO_OPEN_SHIFT` gate. |
| R2 | Queue = active orders of the branch (`pending, awaiting_confirmation, confirmed, open, dining, checkout, paying`). Shift open re-stamps the whole set to the new session in one atomic UPDATE inside the open() transaction. |
| R3 | Terminal orders (`closed`, `voided`) are never re-stamped. Settled sessions are immutable. |
| R4 | Payments keep plan-030 rules. Gap payments (`NULL`) are re-stamped at shift open **only if** `created_at >= T_prev` (end of the till's most recent prior session). No prior session → skip (never swallow historical NULL rows). |
| R5 | A stamped payment keeps its session forever, even when its order carries over. Order attribution and payment attribution are independent axes. |
| R6 | Cloud is authoritative on sync UP: a provided `till_session_id` is kept only if it resolves to an existing session **of the same branch**; otherwise fall back to the branch's current `open` session (orders) / `inProgress` session (payments), else `NULL`. |
| R7 | All re-stamps are idempotent; Cloud and workstation each run their own and converge through sync. |

## Architecture

```
                 CLOUD (Laravel)                          WORKSTATION (Go)
┌──────────────────────────────────────────┐   ┌────────────────────────────────────┐
│ create order (POS/Handy/Customer/WS-sync)│   │ create order (POS/Handy LAN)       │
│   └► CustomerOrderService::insertOrder   │   │   └► OrderEngine.Create            │
│        └► stamp: openSessionIdForBranch  │   │        └► stamp: local open session│
│   └► CustomerQrOrderService (QR path)    │   │ create payment (POS/kiosk LAN)     │
│                                          │   │   └► stamp TillSessionID (fix)     │
│ TillSessionService::open()               │   │ local shift open ──┐               │
│   ├► create session, lock till           │   │ PullTillSessions ──┼► restampActive│
│   ├► UPDATE active orders → new id (R2)  │   │  (newly-open)      │  Orders (R2)  │
│   ├► UPDATE gap payments ≥ T_prev (R4)   │   │                    └► gap payments │
│   └► audit log (counts + ids)            │   │ sync UP: remap local→cloud session │
│                                          │   │  id (errDependencyNotReady)        │
│ accept rules R6 on /workstation/orders,  │◄──┤ sync DOWN: map cloud→local session │
│  /workstation/payments                   │   │  id into orders mirror             │
│ hourly tills:restamp-orphan-orders       │   │ NEW: NO_OPEN_SHIFT gate on LAN POS │
└──────────────────────────────────────────┘   └────────────────────────────────────┘
```

## Data model changes

| Table | Owner | Change | YAML schema file (if Omnify) |
|-------|-------|--------|------------------------------|
| `customer_orders` (MySQL) | **Omnify** | Add nullable uuid FK `till_session_id` → `till_sessions`, `onDelete: SET NULL`, index `[till_session_id]` + `[branch_id, till_session_id]` | `schemas/Backend/Product/CustomerOrder.yaml` — new `tillSession` Association (ManyToOne, nullable), mirroring `OrderPayment.yaml:219-240`, with header + per-property intent comments per convention #5 |
| `orders` (workstation SQLite) | manual (workstation-owned, versions 1..999) | `ALTER TABLE orders ADD COLUMN till_session_id TEXT;` + partial index | `internal/store/migrations/038_orders_till_session_id.sql` (highest today: 037) + `repair.go` column-map entry (pattern at `repair.go:200-222`) |
| `payments` (workstation SQLite) | none | Column already exists (`026_payments_full_parity.sql:19`, insert support `local_kiosk.go:900-908`) — it is simply never populated today. No schema change. | — |

Generated fallout of the Omnify change (regen, never hand-edit): `CustomerOrderBaseModel`
gains `$fillable` entry + `belongsTo(TillSession)`; `TillSessionBaseModel` gains
`hasMany(CustomerOrder)`; `CustomerOrderResourceBase` exposes `till_session_id`;
store/update request bases gain `nullable|uuid|exists` rules.

## API surface

**No new HTTP endpoints.** This feature changes the behavior/contract of existing
endpoints and adds one artisan command. No frontend changes — backend + workstation Go
only.

### Endpoint inventory (modified)

| # | Method | Path | Change | Auth | Route file |
|---|--------|------|--------|------|------------|
| 1 | POST | `/api/v1/pos/orders` (Cloud) | order stamped; during `closing` → stamped NULL (gate still passes) | SSO + `X-Shop-Slug` + `ResolveOpenTillSession` | `backend/routes/api/pos.php:154` |
| 2 | POST | `/api/v1/handy/orders` | order stamped (open-only resolver); still ungated | `device.auth:handy,pos` | `backend/routes/api/handy.php:22` |
| 3 | POST | `/api/v1/customer/tables/{qrToken}/orders` | order stamped via `CustomerQrOrderService` | public | `backend/routes/api/customer.php:80` |
| 4 | POST | `/api/v1/customer/branches/{branchSlug}/orders` | order stamped via `CustomerOrderService` | public | `backend/routes/api/customer.php:69` |
| 5 | POST | `/api/v1/workstation/orders` | accepts optional `till_session_id` (R6 accept rule) | `device.auth:workstation` | `backend/routes/api/workstation.php` |
| 6 | POST | `/api/v1/workstation/payments` | accepts optional `till_session_id` (R6) — **bug fix** | `device.auth:workstation` | `backend/routes/api/workstation.php:48` |
| 7 | POST | `/api/v1/pos/till/sessions` + `/api/v1/workstation/till/sessions` | side effect: carry-over re-stamp (R2/R4) + audit entry | SSO / device token | `pos.php:179` / `workstation.php:94` |
| 8 | POST | `/api/v1/pos/orders` (workstation LAN) | **NEW 409 `NO_OPEN_SHIFT` gate** + local stamp | posAuth (LAN ring) | `workstation-app internal/handler/routes.go:269` |
| 9 | POST | `/api/v1/pos/orders/{id}/payments` (workstation LAN) | **NEW 409 gate** (inProgress parity) + `TillSessionID` stamp | posAuth | `routes.go:299` |
| 10 | POST | `/api/v1/kiosk/payments` (workstation LAN) | `TillSessionID` stamp only (no gate) | kiosk token + rate limit | `routes.go:134` |
| 11 | GET | `/api/v1/workstation/orders` (+ single) | response gains `till_session_id` (resource regen) → consumed by sync DOWN | `device.auth:workstation` | `workstation.php` |
| 12 | CLI | `php artisan tills:restamp-orphan-orders [--dry-run]` | **NEW** hourly safety net | scheduler (`onOneServer`) | `backend/routes/console.php` |

### Endpoint detail (diffs only)

#### 5. POST `/api/v1/workstation/orders` — new optional field

- **Request body (added):**

  | Field | Type | Required | Notes |
  |-------|------|----------|-------|
  | `till_session_id` | uuid | no | Cloud session id (workstation remaps local→cloud before sending). R6: kept only if session exists AND `branch_id` matches device branch; otherwise ignored → fallback resolver. Invalid value is **tolerated, never 422** (sync must not dead-letter on attribution). |

- **Success/side effects:** unchanged 201; created order carries resolved `till_session_id` (may be NULL).

#### 6. POST `/api/v1/workstation/payments` — new optional field (bug fix)

- **Request body (added):** same field/table as #5; fallback resolver uses `inProgress` (payment semantics, plan-030 parity).
- **Side effects:** `OrderPaymentService::create()` receives `till_session_id` in `$createData` (today it is absent → always NULL, `PaymentController.php:116-126`). New soft validation in the service: provided id must belong to the same branch, else dropped to fallback.

#### 7. Shift open — new side effects (both Cloud entry points funnel into `TillSessionService::open()`)

- Inside the existing `open()` transaction, after `Till.current_session_id` is set:
  1. `UPDATE customer_orders SET till_session_id = :new WHERE branch_id = :branch AND status IN (<active set>) AND (till_session_id IS NULL OR till_session_id <> :new)`
  2. Resolve `T_prev` = max(`closed_at`,`expired_at`) of the till's prior sessions; if found: `UPDATE order_payments SET till_session_id = :new WHERE branch_id = :branch AND till_session_id IS NULL AND created_at >= :t_prev`
  3. Audit entry: `till_session.carry_over` with `orders_carried`, `payments_carried`, id lists.
- **Error behavior unchanged** — re-stamp failures roll back the whole open() (shift does not open half-attributed).

#### 8–9. Workstation LAN POS gate (NEW)

- **Gate predicate:** local `till_sessions` row for the branch with status IN (`open`,`closing`) — exact parity with Cloud's `currentForBranch()`/`inProgress()`.
- **Error response (parity with Cloud middleware):**

  | Status | Code | When |
  |--------|------|------|
  | 409 | `NO_OPEN_SHIFT` | no in-progress session; body `{"message": "No cashier shift is currently open on this till.", "code": "NO_OPEN_SHIFT"}` |

- **Stamping:** orders stamp `open`-only (R1); payments stamp the in-progress session id (plan-030 parity).

#### 12. `tills:restamp-orphan-orders` (NEW command)

- Hourly, `withoutOverlapping(5)`, `onOneServer`, heartbeat cache (pattern: `ExpireStaleShifts.php` + `routes/console.php:117-121`).
- For each branch whose till has an `open` session: stamp active orders with `till_session_id IS NULL` to that session. Closes the race where an order commits concurrently with `open()`'s re-stamp scan. `--dry-run` lists candidates.

## Screens

**No frontend changes — backend API + workstation Go only.** `till_session_id` is exposed
on generated resources for future close-report widgets (explicitly out of scope, README).

## Sitemap

Skipped — API-only feature.

## Authorization matrix

### Roles / principals involved

| Principal | Source | Notes |
|-----------|--------|-------|
| POS cashier (SSO) | `auth:sanctum` + `X-Shop-Slug` (`ResolvePosShop`) | branch-scoped |
| Handy device | `device.auth:handy,pos` token | branch fixed at pairing |
| Customer (anonymous) | public routes, qr_token/branch slug as capability | |
| Workstation device | `device.auth:workstation` token | branch fixed at pairing |
| Kiosk device (LAN) | workstation LAN token ring | |
| Scheduler/system | artisan | no HTTP surface |

### Action × principal

| Action | POS cashier | Handy | Customer | Workstation | Kiosk | System |
|--------|------------|-------|----------|-------------|-------|--------|
| Create order (stamped) | ✅ (gated: shift required, Cloud + LAN) | ✅ (ungated) | ✅ (ungated) | ✅ (sync UP, R6) | ❌ (no order creation) | — |
| Record payment (stamped) | ✅ (gated) | ❌ (no endpoint) | ✅ (Stripe, ungated → gap rules) | ✅ (sync UP, R6) | ✅ (ungated) | — |
| Open shift → triggers re-stamp | ✅ | ❌ | ❌ | ✅ (sync UP `till_session.open`) | ❌ | — |
| Re-stamp orphans | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (hourly) |
| Write `till_session_id` directly via API | ❌ | ❌ | ❌ | ✅ (advisory only — R6 validates) | ❌ | — |

### Policy ↔ gate cross-check

| Action | Backend gate | LAN gate |
|--------|--------------|----------|
| POS create order w/o shift | `ResolveOpenTillSession` middleware (409) — existing | **NEW** local predicate in `handleLocalPosCreateOrder` |
| POS create payment w/o shift | same middleware (409) — existing | **NEW** local predicate in `handleLocalPosCreatePayment` |
| Cross-branch session stamping | R6 branch check in `Workstation\{Order,Payment}Controller` + `OrderPaymentService` | n/a (single-branch device) |

Role-switch checklist: n/a (no UI).

## User journeys

Skipped — API-only feature (no human-facing flow changes; handy/customer flows keep
identical request/response behavior except the invisible stamped field).

## Field lifecycle

### CustomerOrder (Cloud)

| Field | Added? | Default | Displayed on screens | Editable on screens | Editable by roles | Validation | Omnify prop |
|-------|--------|---------|----------------------|---------------------|-------------------|------------|-------------|
| `till_session_id` | ✅ NEW | `NULL` | none (resource-exposed only) | none — system-managed | system only (workstation sync value is advisory, R6-validated) | `nullable`, `uuid`, `exists:till_sessions,id`, same-branch (service-level) | Association ManyToOne → TillSession, nullable, onDelete SET NULL |

### orders (workstation SQLite)

| Field | Added? | Default | Written by | Read by |
|-------|--------|---------|-----------|---------|
| `till_session_id` | ✅ NEW (mig 038) | `NULL` | `OrderEngine.Create`, `restampActiveOrders`, sync DOWN upsert | sync UP payload builder, local reconcile, future local reports |

### payments (workstation SQLite)

| Field | Added? | Change |
|-------|--------|--------|
| `till_session_id` | already exists | now **populated** at creation (POS `local_pos.go:641`, kiosk `local_kiosk.go:574`) and carried in sync UP payloads |

### Orphaned field audit

No other fields on the affected schemas change. `client_order_id`, `qr_token`, money
caches etc. untouched. Cross-check: the new field appears in no form; the only write
paths are the service/engine internals listed above — consistent with "not editable on
screens".

## Key decisions

### Decision 1 — Order stamping resolver = `open`-only (payments keep `inProgress`)
- **Chose:** dedicated `TillSessionService::openSessionIdForBranch()` using `scopeOpen()`.
- **Rejected:** reusing `ResolveOpenTillSession` middleware attributes (resolves `inProgress` → would stamp orders onto a `closing` session, violating the whiteboard: orders created during kết-ca belong to the *next* shift).
- **Why:** the whiteboard explicitly routes close-gap orders into the queue; drawer semantics for payments during `closing` are unchanged plan-030 behavior.

### Decision 2 — Virtual queue, not a table
- **Chose:** queue = predicate over `customer_orders` / `orders`; dequeue = one atomic UPDATE in `open()`.
- **Rejected:** physical `order_shift_queue` table.
- **Why:** dual-write hazards (void/pay during gap must "remove from queue" — free with a predicate), an extra entity to sync SQLite↔MySQL, crash-recovery complexity. FIFO ordering is irrelevant since the whole queue re-stamps at once. Traceability handled by audit log (user decision 2026-07-08).

### Decision 3 — `T_prev` bound on gap-payment re-stamp
- **Chose:** only NULL payments with `created_at >= end of previous session`; skip when the till has no prior session.
- **Rejected:** blanket "re-stamp all NULL payments of the branch".
- **Why:** every pre-plan-030 / kiosk / customer payment in history is NULL — blanket update would inject months of revenue into the first shift opened after deploy.

### Decision 4 — Send-time local→cloud session-id remap with `errDependencyNotReady`
- **Chose:** in `handleOrderCreate`/`handlePaymentCreate` (sync_service.go), translate the stamped local session id → `till_sessions.cloud_id`; row exists without `cloud_id` → return `errDependencyNotReady` (retry after `till_session.open` syncs); no local row → value already a cloud id, pass through.
- **Rejected:** (a) sending the local id raw (meaningless on Cloud — offline-opened sessions mint a different cloud id); (b) enqueue-time translation (payload snapshots at enqueue; cloud_id typically doesn't exist yet); (c) omitting the field and letting Cloud always resolve (mis-attributes orders/payments that lived entirely inside an offline shift which has since closed).
- **Why:** exact reuse of the proven `order_id` remap pattern (`sync_service.go:1115-1137`); no deadlock (`till_session.open` depends on nothing); queue priority "order.create first" (`sync_service.go:501-518`) is compatible — a not-ready order waits one pass while the session op completes.

### Decision 5 — Cloud-authoritative tolerant accept (R6), never 422 on attribution
- **Chose:** invalid/foreign `till_session_id` on sync UP is dropped → fallback resolver; request still succeeds.
- **Rejected:** hard-422 on unknown session id.
- **Why:** attribution must never dead-letter a money-bearing sync item; R2's next-open re-stamp self-heals active orders anyway.

### Decision 6 — Full NO_OPEN_SHIFT parity gate on workstation LAN POS
- **Chose:** gate both order + payment creation locally (user decision 2026-07-08).
- **Rejected:** payment-only gate; no gate.
- **Why:** identical operator behavior on/offline; shifts open offline via local handlers (`routes.go:226`) so the gate cannot brick a store. Handy/customer/kiosk LAN endpoints stay ungated by design.

### Decision 7 — Dual re-stamp triggers on workstation
- **Chose:** shared `restampActiveOrders()` called from (i) `handleLocalPosTillOpenSession` and (ii) `PullTillSessions` upon detecting a session newly in `open`.
- **Rejected:** relying on pull-DOWN of re-stamped orders from Cloud alone.
- **Why:** local-only orders (not yet synced UP) exist only in SQLite — nobody else can re-stamp them; and offline-opened shifts must carry over without Cloud. Idempotence (R7) makes double-triggering harmless.

## Alternatives considered

- **Time-window attribution everywhere** (no column on orders): rejected — plan-030
  already chose stamping for payments ("Decision 2" in OrderPayment.yaml); windows break
  exactly in the gap/carry-over cases this plan exists to solve.
- **Stamp orders immutably at creation, attribute reports via payments only**: rejected —
  contradicts the whiteboard requirement that carried orders *belong* to the new shift.
- **Physical queue table**: see Decision 2.

## Risks & mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| `$fillable` silently drops the new key | high (if done by hand) | attribution never persists | Omnify YAML → regen only; Pest asserts persisted value (T-level test) |
| Orders stamped onto `closing` session | medium | close-gap orders wrongly counted in old shift | `open`-only resolver (Decision 1) + dedicated scenario |
| Historical NULL payments swallowed at first open after deploy | high without bound | corrupt first shift report | `T_prev` bound + skip-first-session (Decision 3) + scenario |
| Offline-opened session id meaningless on Cloud | certain without remap | orders/payments attributed to nothing | Decision 4 remap + dependency retry + Go tests |
| `order.create` processed before `till_session.open` | medium | same as above | `errDependencyNotReady` wait (Decision 4) |
| Race: order created concurrently with `open()` re-stamp | low | order stuck NULL until next open | hourly `tills:restamp-orphan-orders` |
| Cloud/workstation re-stamp divergence | medium | reports disagree | identical predicates (R2), idempotence (R7), Cloud-authoritative R6; parity tests both sides |
| Local shift report diverges from Cloud | current reality | operator distrust | reconcile switches to stamped-id with legacy time-window fallback |
| Cross-branch stamping via forged sync payload | low | wrong branch revenue | R6 branch check at every accept point + scenario |
| Re-stamp UPDATE bloats `open()` transaction | low | slow shift open | active-orders-per-branch is small (tens); `[branch_id, status]` index exists; single UPDATE statement |
| Workstation DB pre-038 after app update | medium | SQL error on new column | `repair.go` column map (existing pattern) + Go migration test |

## Out of canonical pattern

None. Schema via Omnify YAML; business logic in editable `CustomerOrderService` /
`TillSessionService` siblings; workstation SQLite migrations are workstation-owned by
project convention (CLAUDE.md), not Omnify-owned.

## Open questions

- [ ] Multi-till-per-branch (v2): re-stamp + resolver assume one till/branch. Revisit when Till model grows.
- [ ] Should close reports later display `orders_carried` counts? (out of scope; audit log already records them — future plan can surface.)

## References

- Whiteboard photo 2026-07-08 (user) — transcription above
- `backend/app/Services/Pos/TillSessionService.php` — open/close/abandon/expire/manual-settle (plan-030/032)
- `workstation-app/internal/service/sync_service.go` — queue priorities, remap + `errDependencyNotReady` patterns
- Session discovery evidence: [NOTES.md](NOTES.md)
