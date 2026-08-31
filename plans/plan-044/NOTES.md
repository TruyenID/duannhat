# Plan 044 — Notes

> Working log for [Order ↔ Till Session Attribution + Shift Carry-over Queue](README.md). Append-only. Newest entries on top.

---

## 2026-07-16 — R1-disposition audit found + fixed a REAL gap: the LAN POS NO_OPEN_SHIFT gate

While auditing why the old "Original tasks" (R1) checkboxes were unticked, I initially
mis-classified **T5.2** as "superseded." Re-reading DESIGN R2 (line 98 "R1 (kept)…POS gated
NO_OPEN_SHIFT", endpoints 8-9, Decision 6) showed the gate is a **kept R2 requirement**, and
T-R2.10 said "confirm NO_OPEN_SHIFT gate kept" — but it had **never been built** (R1 Phase 5
was pending; grep found no `NO_OPEN_SHIFT` anywhere in workstation).

Fixed on this branch (workstation commit): `hasInProgressShift()` predicate (status open|closing)
+ `writeNoOpenShift()` (409, byte-parity with Cloud `ResolveOpenTillSession`), gating
`handleLocalPosCreateOrder` + `handleLocalPosCreatePayment`. **Kiosk/customer stay ungated** —
they legitimately transact during the close→open gap and feed the gap-payment reconcile. This
is consistent with README R2 point 1 ("orders created in the gap stay NULL"): that refers to the
*ungated* self-service surfaces; the *POS* surface is gated. Tests: `TestHasInProgressShift`
(open/closing→pass, settled/none→block), POS order+payment 409 gate, pass-through with open/closing
shift. ~24 existing POS tests updated to seed an open shift (shared `seedOpenShift` helper).
Full handler+service suite green. pos-web already gates the POS surface via
`useTillCurrent.open_session` (redirect to /shift/open) + inherits Cloud's NO_OPEN_SHIFT handling,
so the LAN gate needs no pos-web change (pure parity backstop for the mid-session close race).

## 2026-07-16 — R2 IMPLEMENTATION COMPLETE (all tasks except the release push)

Every R2 task is implemented + tested across all three repos. Only **T-R2.18** (push
branches + bump umbrella submodule pointers) remains — that is the release step
(`/mcp__omnify__complete` or the manual bump ritual), intentionally left for after review.

**Commit tally (nothing pushed):**
- **Umbrella / backend** — 11 commits (backend 100%, tested; cash-flow guarantee proven).
- **workstation-app** branch `feature/plan-044-order-till-session-attribution` — 7 commits
  (T-R2.8/9 read handlers, T-R2.10 claim, D2.2/D2.4 sync + convergence, D2.1 retention,
  D2.5 order no-leak, docs). Full handler+service suite green, 0 regression.
- **pos-web** branch `feature/plan-044-order-till-session-attribution` — 4 commits
  (T-R2.12 panel, T-R2.13 close summary, T-R2.14 i18n ja/en/vi parity, T-R2.15 vitest).
  341/341 vitest green; `tsc -b` clean; new files lint-clean.

**Convergence design (the two-way sync the user required):**
- UP: workstation claim → `payment.attribute` op → endpoint D (idempotent, branch-guarded,
  never 422/dead-letter). Not-yet-synced claimed payment carries its `till_session_id` on
  the create (send-time read in `handlePaymentCreate`).
- Converge: attribute op retries until Cloud echoes the sent session id (Cloud-authoritative
  R6), then adopts Cloud's value onto the local mirror → both DBs byte-identical.
- Retention: `PullTillSessions` upserts `ON CONFLICT(id)` (no replace-all) → settled session
  (gap-window lower bound) survives the active-feed pull.
- Orders never carry `till_session_id` (no local column; Cloud stamps its own open session).

**Release step (T-R2.18) — run when ready:**
```sh
# push submodule feature branches
(cd workstation-app && git push -u origin feature/plan-044-order-till-session-attribution)
(cd pos-web && git push -u origin feature/plan-044-order-till-session-attribution)
# bump umbrella pointers (backend deploys BEFORE the workstation build that enqueues to endpoint D)
git add workstation-app pos-web && git commit -m "chore(plan-044): bump workstation-app + pos-web pointers"
```

---

## 2026-07-16 — Workstation-app: read handlers done (T-R2.8, T-R2.9)

Branch `feature/plan-044-order-till-session-attribution` off `dev` in the workstation-app
submodule (2 commits). Go build + full handler test suite green (no regressions).

- **T-R2.8** `GET /pos/till/gap-preview` (`handleLocalPosTillGapPreview`) — local NULL payments
  in the window `(prev terminal closed_at, now]`, `is_cash = payment_method=='cash'`. Caught +
  fixed: local payments column is **`order_id`** (not `customer_order_id`); local terminal marker
  is `closed_at` (set on settle AND abandon). 2 Go tests.
- **T-R2.9** `GET /pos/till/sessions/{id}/order-summary` — paid (distinct orders w/ payment in
  session) + unpaid_carry (orders not `closed`/`voided`, no payment). 1 Go test.
- **T-R2.D2.1 (retention)** — verified `PullTillSessions` uses `ON CONFLICT(id) DO UPDATE`
  (upsert-by-id, not delete-all), so locally-settled sessions are NOT wiped → `prev_end` is
  always resolvable. Largely satisfied by existing design (a targeted test can be added).

**REMAINING workstation (higher-risk — needs focused work):** T-R2.10 (local claim stamp at open +
enqueue sync), T-R2.D2.2 (**`payment.attribute` sync-UP op** — local→cloud remap +
`errDependencyNotReady` + queue integration; the riskiest piece, needs deep `sync_service.go`
reading), T-R2.D2.4 (sync-DOWN of till_session_id), T-R2.D2.5 (order sync-UP omit local id),
T-R2.D2.6 (convergence tests), T-R2.11 (`make test`). Then **pos-web** (Phase R2-E) + **docs/bumps**
(Phase R2-F). Backend endpoint D is already live to receive the sync.

## 2026-07-16 — BACKEND COMPLETE (checkpoint before submodules)

All backend R2 tasks done + green, committed on `feature/plan-044-order-till-session-attribution`
(9 commits off `dev`). Deployable independently (the plan's required first deploy).

- **Done:** T-R2.1, T-R2.2, T-R2.3 (revert + verify) · T-R2.4 gap-preview · T-R2.5 order-summary ·
  T-R2.6 claim in open() · T-R2.7 tests (incl. HTTP + cash-flow independence proof) ·
  T-R2.D2.3 endpoint D (workstation attribution accept).
- **Verified:** 132 Till + 198 Pos + 5 endpoint-D + 4 HTTP tests green; no regressions; cash-flow
  guarantee proven (reconcile byte-identical w/ vs w/o order attribution).
- **Endpoint D dormant until the workstation calls it** — safe to deploy now.

**REMAINING (separate submodule efforts, not started):**
- **workstation-app (Go)** — Phase R2-D (T-R2.8/9/10/11: local gap-preview/order-summary/claim
  handlers + gate) + Phase R2-D2 (T-R2.D2.1 retention, .2 `payment.attribute` sync-UP op + remap +
  dependency, .4 sync-DOWN, .5 order no-leak, .6 convergence tests). **The riskiest part** (offline
  money sync) — needs a focused session with the Go codebase loaded.
- **pos-web (React)** — Phase R2-E (T-R2.12 gap panel, .13 close summary, .14 i18n, .15 tests).
- **Phase R2-F** — docs (CLAUDE.md/cashier-shift-recovery/workstation CLAUDE.md), format, submodule bumps.
- Backend deploys BEFORE the workstation build (README dependency).

## 2026-07-16 — Execution: Phase R2-B + R2-C (backend endpoints + claim + tests)

- **T-R2.4** `GET /pos/till/gap-preview` — `TillSessionService::gapPreview()` +
  `previousTerminalSessionForTill()` helper + `TillController::gapPreview` + route.
  `is_cash = pm.code === 'cash'` (matches reconcile's drawer bucket, L1141). No
  per-payment `currency_code` column → currency from shop setting. Smoke-verified. Commit `…`.
- **T-R2.5** `GET /pos/till/sessions/{id}/order-summary` — paid (distinct orders w/
  succeeded payment in-session) + unpaid_carry (active orders, `whereNotExists` succeeded
  payment) + list. Smoke: 14 unpaid-carry. Commit `…`.
- **T-R2.6** claim in `open()` — `claimGapPayments()` inside the tx; stamps branch-owned,
  NULL, succeeded, in-window `(prev_end, opened_at]` claimed ids (cash included); audit
  `till_session.gap_claim`; response `gap_payments_claimed` (transient model attr, read-only).
  `OpenTillSessionRequest` +2 nullable fields. Commit `…`.
- **T-R2.7** tests — **suite un-red (132 Till green, 198 Pos green)**:
  - `CarryOverGapPaymentSweepTest` **repurposed** → R2 no-restamp + operator-claim (9 tests:
    cash+non-cash claim, foreign-branch skip, out-of-window skip, already-stamped skip,
    idempotent, audit). Reused its fixtures.
  - `OrderTillSessionAttributionTest` — 4 creation-stamp tests still valid; the 2 carry-over
    tests converted (L125 → "no carry-over on open"; L188 → the T_prev-abandoned bound now
    applies to the CLAIM window).
  - `ConfirmRestampShiftTest` — **left untouched**: it tests `confirm()` re-stamp (#552,
    KEPT payment behavior), NOT the queue. The plan's note to rewrite it was wrong.
  - **NEW `ReconcileOrderAttributionIndependenceTest`** — the cash-flow guarantee: reconcile()
    byte-identical with vs without `customer_orders.till_session_id` (payments untouched),
    expected_cash > 0. GREEN.
  - **DEFERRED (small):** `TillGapPreviewTest` + `TillOrderSummaryTest` HTTP Feature tests
    (auth + response shape). Service logic is smoke-verified + exercised via the claim tests;
    the HTTP layer mirrors `reconciliation`/`current` exactly. T-R2.7 marked `[~]`.

## 2026-07-16 — Execution started: Phase R2-A (backend revert) done

Branch `feature/plan-044-order-till-session-attribution` off `dev`. Backend test
baseline green natively (native pest, no docker needed). Issue #503 open, assigned @me.

- **T-R2.1** — removed `carryOverActiveWork()` + its `open()` call + the `till_session.carry_over`
  audit. Verified money-neutral: **122 open/close/reconcile tests still green**; the **11**
  carry-over-asserting tests now fail *by design* (rewritten in T-R2.7). Discovery: a **3rd**
  shipped test file `OrderTillSessionAttributionTest.php` (beyond the 2 the plan named) also
  asserts the removed behavior — added to T-R2.7's rewrite list. Kept `openSessionIdForBranch`
  (creation stamp), `inProgressSessionIdForBranch`, `resolveSyncedSessionId` (R6). Commit `f6978a48`.
- **T-R2.2** — no `RestampOrphanOrders` command / schedule exists → nothing to delete (README
  was correct). Verification-only.
- **T-R2.3** — payment attribution intact: `Workstation\PaymentController` L71/L147-166 accepts
  `till_session_id` via `resolveSyncedSessionId` (R6); `OrderPaymentService` stamps it at
  create/confirm/refund. The NULL-sync bug fix is kept. Verification-only.

`ACTIVE_ORDER_STATUSES` const is momentarily unreferenced after T-R2.1; it is used again by the
order-summary (T-R2.5) so it's left in place rather than churned.

**Next:** Phase R2-B (gap-preview + order-summary endpoints + claim in open) → Phase R2-C tests
(green, incl. the 3-file rewrite) → endpoint D (R2-D2.3). Then workstation-app, then pos-web.

## 2026-07-15 — R2 supplement: two-way attribution sync (the removed-convergence gap)

Owner flagged: "giữa backend và workstation nó phải luôn sync những dữ liệu này." Audit of
the R2 tasks found a **real omission** and it's now filled in detail.

**Root cause.** The original plan converged Cloud ⇄ workstation by having **each side run
its own carry-over re-stamp** (R7 — idempotent, converge via sync). **R2 removed the auto
re-stamp**, so that implicit convergence is GONE. The claim is now a **single manual write
on ONE side**, so its `till_session_id` change MUST be explicitly propagated — the old plan
never needed a "sync the specific UPDATE" path because both sides recomputed independently.

**What was missing (now added, Phase R2-D2 + DESIGN "R2 sync model"):**

1. **Workstation → Cloud propagation of the claim.** A gap payment is usually already synced
   UP (NULL) by claim time, so the claim is a *post-creation* attribution change the
   creation-time sync doesn't cover. Added: (a) send-time read on `payment.create` covers a
   claim before the payment syncs; (b) NEW `payment.attribute` op + endpoint D
   `POST /workstation/payments/{id}/attribution`, remap local→cloud, dependency-ordered on
   `till_session.open`+`payment.create` (`errDependencyNotReady`), R6 + idempotent + tolerant.
2. **Cloud → workstation mirror** of the attribution (payment sync-DOWN carries
   `till_session_id`, cloud→local map). Not cash-bearing (workstation reconcile = time-window)
   but prevents silent divergence.
3. **Previous-terminal-session retention** so `prev_end` (gap window) is computable locally —
   `PullTillSessions` `INSERT OR REPLACE` could wipe the settled row (agent flagged this).
4. **Order sync-UP omits `till_session_id`** (display-only) to avoid leaking a local id onto
   Cloud — since R2 dropped the order-id remap.
5. **Convergence test suite** (Go + Pest): a workstation claim → identical cloud
   `till_session_id` on both stores, both paths, idempotent — the explicit replacement for R7.

**Deploy note:** Cloud endpoint D ships before the workstation build that enqueues to it.

## 2026-07-15 — R2 correction: gap cash is HELD SEPARATELY (so it IS claimable)

Owner clarified the physical process, which **reverses the earlier Q2 answer**
("cash not claimable"):

> "Số tiền trả trong khi đóng ca-1 sẽ được **staff cầm/giữ riêng** và được **payment
> như bình thường**. Khi mở ca-2, màn hình hiển thị các order đã paid trong gap; nhân
> viên đối chiếu và confirm → những order đó được **gán vào ca-2**."

Implication for cash correctness: gap cash is **held aside**, so it is **NOT in ca-2's
opening float**. Therefore confirming a gap **cash** payment into ca-2 is **correct**
(the cash is added to the drawer as ca-2 `cash_sales`, counted once). The earlier
"double-count vs float" hazard only existed under the *other* process (cash dropped into
the shared drawer). So:

- **Cash gap payments are now CLAIMABLE** (cash + non-cash). Endpoint C no longer drops
  cash; adds `gap_cash_held_separately_ack` (audited).
- Worked math (float ¥10,000; gap cash ¥800 held aside; ca-2 sells ¥5,000):
  `opening_float=10,000`, `cash_sales=800+5,000=5,800`, `expected=15,800`,
  `counted=10,000+800+5,000=15,800` → **variance 0**. Only error mode: operator counts
  the ¥800 into the float AND confirms → false shortage −800.
- Guard shifts from "block cash claims" to **UI/process**: separate gap-cash callout +
  required "held-separately" ack + the reminder not to include gap cash in the float
  count. Server can't observe the physical count, so this is a **process guarantee** for
  cash (the order-queue removal remains a **code-proven** guarantee).

Files updated for the correction: README (banner pt 3, In-scope claim/UI bullets,
success criteria), DESIGN (Cash-flow guarantee §2/§3 + bottom line, R4/R8, endpoint C,
Screen 1, RD2/RD3, risks row), TESTS (claim section + cash-flow closure correct/error
paths + Browser cash-row), TASKS (T-R2.6/T-R2.10/T-R2.12).

## 2026-07-15 — Revision R2: drop the queue, manual gap reconciliation

**Owner pivot.** Replace the automatic order/payment carry-over queue with (1) no
order re-stamp at all, (2) close counts only paid orders (already true), (3) an
operator-confirmed manual reconciliation of gap payments at shift open.

**Owner decisions (AskUserQuestion 2026-07-15):**
1. Gap ghi-nhận = **operator confirms → system stamps ca-2** (not display-only, not auto).
2. Cash gap rows = **listed + flagged "already in opening float"**, not auto-attributed.
3. Close screen = **show paid / unpaid-carry counts**.

**R2 discovery — code evidence (2 Sonnet sub-agents, file:line verified):**

- **CASH-FLOW PROOF (the load-bearing finding).** `TillSessionService::reconcile()`
  (L961–1156) computes `expected_cash` (L1095–1098) purely from
  `order_payments.till_session_id = session.id` (payment sums L984–1003, cash tips
  L1025, revenue order-ids derived *from payments* L1038–1046) + `till_cash_events`.
  `cash_variance = counted_cash − expected_cash` (close L559–585).
  `customer_orders.till_session_id` is **read by NO reconcile/revenue query** — only
  written by `carryOverActiveWork()` (L229–236) + `CustomerOrderService::create()`
  (L457). `ShopTillTrackingService` dashboard/list/Z-report all aggregate by
  `order_payments.till_session_id`. **⇒ removing the order queue is provably money-neutral.**
- **Workstation** `reconcileSession` (`local_pos_till.go` L685–805, SQL L707–713) uses a
  **time-window** on `payments.created_at` between opened_at↔closed_at — also
  order-attribution-free. Close settles **locally** in SQLite (L456–603) then syncs UP.
- **`opening_float_amount`** = cashier's physical denomination count at open
  (`persistDenominationCounts`, L361–368/L1216). Gap cash physically in the drawer is
  counted here → reconciles at ca-2 close ⇒ **auto-re-stamping a cash gap payment into
  ca-2 sales would double-count vs float → false shortage.** Hence cash rows non-claimable.
- **Payments during `closing`** stamp ca-1 (`currentForBranch()`/`inProgress()`,
  `ResolveOpenTillSession` L34–42, `OrderPaymentService` L128–150 accepts open+closing) —
  unchanged. The "gap" that needs manual handling is the **true gap** (ca-1 terminal,
  ca-2 not open → payment `till_session_id = NULL`).
- **What plan-044 shipped in `open()`:** `carryOverActiveWork()` (L224–270) re-stamps (a)
  active `customer_orders` L229–236 and (b) `NULL` gap payments `created_at >= T_prev`
  L256–263 — both in the open() transaction. **R2 removes both.**
- **No existing endpoint** lists payments by time-range / `NULL` attribution → **new**
  `gap-preview` + `order-summary` endpoints required, on Cloud **and** workstation (gap
  payments are often local-only kiosk/customer LAN before sync UP).
- **pos-web** `open-page.tsx` insert point ~L315 (between info + count cards);
  `close-page.tsx` shows only cash aggregates, no order list (~L490 for the summary).
  `GET /pos/till/current` does **not** return the previous session — the gap-preview
  endpoint must resolve prev_end itself. i18n flat-dot `shift.*`, 3-locale parity.
- **plan-045 is unrelated** (tax rounding + refund lines) — this stays in plan-044.

**Kept:** payment creation-stamp (open/closing), workstation payment-sync NULL bug fix,
`NO_OPEN_SHIFT` LAN gate, `customer_orders.till_session_id` creation-stamp.
**Reverted/dropped:** order re-stamp, auto gap-payment sweep, hourly orphan command,
workstation order re-stamp + order sync-id remap.

## 2026-07-08 — Plan created

Scaffolded via `/mcp__omnify__plan`. Research mode: **user session as spec** (Phase 0.4
option c) — the entire discovery was performed live in the planning session (two Explore
sub-agents + main-session verification of every load-bearing file). `laravel-boost` MCP
was not available in the session; schema facts were verified by reading migrations/models
directly. TESTS scenarios were reviewed with the user in-session (12-item list presented
and iterated) before TASKS was written; formal TESTS.md sign-off requested again at the
approval gate.

User decisions captured (AskUserQuestion, 2026-07-08):
1. Session = spec (no re-research).
2. Proceed despite dirty git (plan files are independent).
3. **Full NO_OPEN_SHIFT parity gate** on workstation LAN POS (order + payment).
4. Carry-over traceability via **audit log**, no physical history table.

## 2026-07-08 — Discovery (session evidence, file:line)

### Cloud (Laravel backend)

- `till_session_id` exists ONLY on `order_payments` (`migrations/omnify/2000_02_14_000000_alter_order_payments_table.php:26`); `customer_orders` has no shift column (checked create + all alters).
- Stamping happens ONLY on Cloud POS payments: `ResolveOpenTillSession` middleware → `Shop/OrderPaymentController.php:125`. Middleware comment anticipates order stamping ("and any future order service").
- POS order creation gated (`pos.php:154`) but NOT stamped — `Shop\CustomerOrderController::store` (:170) reads only `shop_id`/`brand_id` attributes.
- Handy: `handy.php` no gate; `HandyController::createOrder` (:148) → shared service; no payment endpoints at all.
- Customer: both order routes ungated/unstamped; Stripe path has zero `till_session` references; `OrderPaymentService.php:233-236` comment confirms NULL by design on customer/kiosk/refund paths.
- **Single funnel**: `CustomerOrderService::create()` (:197 → `insertOrder` :261-332, `CustomerOrder::create` at :319, mass-assignment → `$fillable` risk) used by POS/Handy/customer-by-branch/Workstation. Exception: QR-table path uses `CustomerQrOrderService::createOrder` (`Customer/CustomerOrderController:381`).
- Kiosk (`kiosk.php`): NO order creation; payments only.
- `TillSession` scopes (`app/Models/TillSession.php:42-57`): `open()` = open only; `inProgress()` = open+closing. `currentForBranch()` (TillSessionService :82-94) uses `inProgress()`. One Till per branch (v1, :101-107). `Till.current_session_id` set in open() :183, cleared in close :375 / abandon :419 / forceAbandon :479 / expire :560.
- `CustomerOrderStatusEnum`: pending, awaiting_confirmation, confirmed, open, dining, checkout, paying (active) / closed, voided (terminal).
- Workstation sync UP endpoints: `OrderController::store` (:168-293, validates client_order_id/order_type/tables/..., no till field, Idempotency-Key cache); `PaymentController::store` (:54-149, `$createData` :116-126 **omits till_session_id → the NULL-attribution bug**).
- Scheduler pattern to copy: `ExpireStaleShifts.php` + `routes/console.php:117-121`.
- Omnify YAML: `OrderPayment.yaml:219-240` = tillSession association precedent (records plan-030 "Decision 2: stamp, not time-window"); `CustomerOrder.yaml` header documents lifecycle + BR rules; indexes at :48-62.

### Workstation (Go)

- Orders table (005_orders.sql) has NO till_session_id; highest hand-written migration = 037; repair.go column-map pattern at :200-222 (payments loop already includes till_session_id).
- Payments: column exists (026_payments_full_parity.sql:19); `domain.Payment.TillSessionID` (payment.go:53); insert supports it (local_kiosk.go:900-908); **never populated** — POS build :641-664, kiosk build ~:574-591.
- ALL local order creation funnels through `OrderEngine.Create` (order_service.go:297; INSERT :380-407). "init" paths only PATCH existing orders.
- Till sessions ARE local (routes.go:226-237: open/close/abandon/cash-events/draft handlers) — workstation-app/CLAUDE.md's "proxied to Cloud" table is outdated. Offline shift open mints a LOCAL id; `cloud_id` written back post-sync (sync_service.go:1258-1264).
- Till mirror: `PullTillSessions` (sync_pull_pos.go:1392, endpoint `/workstation/till-sessions/active`), INSERT OR REPLACE, ~5s tick. No realtime WS from Cloud.
- Sync UP: order.create (sync_service.go:1217 → POST /workstation/orders; payload enqueued with full orderShape snapshot at local_pos.go:337-377 / local_handy.go:330-356); payment.create (:1109; **existing order_id remap pattern** :1115-1137 with `errDependencyNotReady` :27-33). Queue priority: order.create FIRST (:501-518) → dependency wait needed for session ids.
- Sync DOWN orders: `cloudOrderPayload` (sync_pull.go:469-500) + `upsertOrder` (:754+) — unknown fields silently dropped → explicit mapping required.
- Local reconcile is time-window based (local_pos_till.go:680-709); abandon guard time-window at :617-626.
- No `NO_OPEN_SHIFT` string anywhere in workstation-app or pos-web → LAN POS fully ungated today.

### Design deltas vs the first in-session sketch

- Re-stamp trigger on workstation needs TWO paths (local open + PullTillSessions detection) because shifts open both locally and on Cloud.
- Send-time remap chosen over enqueue-time (payload snapshots at enqueue; cloud_id doesn't exist yet) and over cloud-side-only resolution (would mis-attribute orders/payments of an offline shift that closed before sync).
- `T_prev` bound added after realizing a blanket NULL-payment re-stamp would swallow months of legacy kiosk/customer payments.
