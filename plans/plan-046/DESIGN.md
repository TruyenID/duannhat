# Plan 046 — Design

> Design for [Shift handover reports + chain-of-shifts final close](README.md).

## Context

@see plan-030 DESIGN (đã xoá khỏi cây #2188 — git history) — the TillSession open→closing→settled state machine, `open()`/`close()`/`reconcile()` shape this extends.
@see plan-032 DESIGN (đã xoá khỏi cây #2188 — git history) — force-abandon/expire/manual-settle + status enum; the chain must interoperate with these terminal exits.
@see plan-038 DESIGN (đã archive — xem git history) — LAN print endpoints + `FormatShiftReport`/`handleLANPrintShiftReport`; the single-shift slip is reused, the chain slip mirrors this pattern.
@see plans/plan-043/DESIGN.md — per-rate tax snapshot + `RoundingMode`/`OrderTaxBreakdownAggregator`; the aggregate slip must sum per-rate tax from immutable snapshots.
@see plans/plan-044/DESIGN.md — gap reconciliation + `reconcile()` attribution by `order_payments.till_session_id`; the per-shift snapshot is captured from `reconcile()`.
@see docs/guide/cashier-shift-recovery.md — operator runbook the chain lifecycle is documented against.
@see workstation-app/CLAUDE.md — LAN print + proxy-vs-local till endpoint rules the new endpoints must follow.

## Approach

A **chain** is an ordered run of shifts on one till, linked by handovers and terminated by a final
close. We model it **without a new table** — three nullable columns + one JSON snapshot on
`till_sessions`, plus one new enum. The existing settle becomes the *final close*; a new *handover*
is a settle that keeps the chain open. `open()` decides whether it starts a new chain or continues
the running one by inspecting the till's most-recent terminal session. The aggregate report is
computed by **summing each chain session's immutable `settlement_snapshot`** (captured at settle
time from `reconcile()`), so it can never double-count nor drift when a later shift refunds an
earlier shift's order (research: Z ≠ Σ live-recomputed X).

## Architecture

```
Cashier (pos-web) ──"Bàn giao ca"──▶ POST /pos/till/sessions/{id}/handover
                                       │ settle (kind=handover) + snapshot + keep chain open
                                       ▼
                                     print single-shift slip (workstation /api/lan/print/shift-report, report_kind=handover)
                                       │
                                     open next shift (open() continues chain: chain_id same, seq+1, BLIND recount float)
                                       │  … repeat …
Cashier (pos-web) ──"Kết ca cuối"──▶ POST /pos/till/sessions/{id}/close  (kind=final, ends chain)
                                       ▼
                                     GET /pos/till/chains/{chainId}/summary  (Σ snapshots)
                                       ▼
                                     print aggregate slip (workstation /api/lan/print/chain-report)
                                       │
                                     next open() → NEW chain (chain_id fresh, seq=1)
```

Chain lifecycle (derived, not stored as a separate status):

```
[shift1 open] --handover--> [shift1 settled(handover)] --open(continue)--> [shift2 open] --final--> [shift2 settled(final)]  = chain CLOSED
     seq=1                        chain_id=C, seq=1                            chain_id=C, seq=2         next open = new chain C'
```

## Data model changes

**Omnify-owned** — edit `schemas/Backend/Till/TillSession.yaml` + add one enum, then `npm run omnify:gen`. No hand-written migration.

| Table | Owner | Change | YAML schema file |
|-------|-------|--------|------------------|
| `till_sessions` | Omnify | + `chain_id`, `chain_sequence`, `settlement_kind`, `settlement_snapshot` + indexes | `schemas/Backend/Till/TillSession.yaml` |
| enum | Omnify | new `TillSettlementKind` (`handover`, `final`) | `schemas/Shared/Enum/TillSettlementKind.yaml` |
| workstation `till_sessions` | manual SQLite | mirror the 4 columns | `workstation-app/internal/store/migrations/044_till_session_chain.sql` |

New fields (all nullable so existing rows/backfill are safe):
- `chain_id` (**`type: Uuid`** — P5-4, nullable — matches the file's `*_by_id` soft-FK convention, NOT `type: String`; **indexed** via `options.indexes` list-of-`- columns:`-objects `[chain_id, chain_sequence]` + `[branch_id, chain_id]` — P5-1) — groups a chain; the first shift generates it explicitly via `Str::uuid()->toString()` in `open()` (NOT auto-filled — `HasUuids` only populates the model PK, and `TillSessionService.php` has no `Str` import yet, so add `use Illuminate\Support\Str;`).
- `chain_sequence` (Integer, default 1) — 1-based position within the chain. **P5-5: Omnify renders `type: Integer` as a VARCHAR column** (repo-wide quirk — `review_up_count`, `expire_threshold_hours`). Arithmetic `prev+1` in PHP is fine, but any DB-side `ORDER BY chain_sequence` sorts lexically ('10' < '2') — so `chainSummary`/`buildChainReport` MUST sort int-wise (cast in PHP or `CAST(... AS UNSIGNED)`), see T2.4/T5.8.
- `settlement_kind` (EnumRef `TillSettlementKind`, nullable) — set at settle: `handover` keeps the chain open, `final` ends it. Null while open/abandoned/expired.
- `settlement_snapshot` (Json, nullable) — immutable settle-time snapshot. **TWO sources (GAP-1 fix):**
  cash / tenders / revenue / cash-variance from `TillSessionService::reconcile($session)`; **per-rate tax**
  from `OrderTaxBreakdownAggregator::forOrders($sessionOrderIds)` (plan-043) — **`reconcile()` only returns a
  single `revenue.tax` total, NOT the 8%/10% split**, so the per-rate `tax_breakdown` MUST come from the
  aggregator, or the chain slip can't print 8%対象/10%対象 (R4 / インボイས).
  `$sessionOrderIds` = distinct `order_payments.customer_order_id where till_session_id = session.id`.
  Shape: `{opening_float, cash{expected,counted,variance,sales,paid_in,paid_out}, tenders[], tax_breakdown[{rate,taxable,tax}], revenue{gross,net,tax,discount}, orders{paid_count,paid_total}}`. **Read by the aggregate; never recomputed.**
  **Codegen verify (pass-3):** confirm `omnify:gen` emits `settlement_snapshot` into `TillSessionBaseModel::$fillable` AND casts it as `array`/`json` (Omnify `type: Json` → Eloquent `array` cast). If it doesn't, the `$session->update([... 'settlement_snapshot' => [...] ...])` write and the `$session->settlement_snapshot` read will mis-serialize — a hard blocker to check right after T1.3.

## R-rules (invariants)

- **R1** A chain continues **only** via explicit `handover`. `open()` continues the running chain iff the till's most-recent terminal session has `settlement_kind = handover`; otherwise (`final`, `abandoned`, `expired`, or none) it starts a new chain. **Call the existing resolver** `TillSessionService::previousTerminalSessionForTill(Till)` (`TillSessionService.php:216`) — it is public + reusable. **CORRECTION (pass-3):** `open()` does **NOT** already call it; the two existing call sites (L264 `gapPreview`, L414 `claimGapPayments`) are the plan-044 gap machinery, and `open()` (L473-576) only reaches the resolver *indirectly* via `claimGapPayments()` (L554) which early-returns `if (empty($claimedIds)) return 0` (L408) — so on a normal open it is never reached. Chain continuation must **add a NEW direct call** `$prev = $this->previousTerminalSessionForTill($till)` inside `open()` (after `lockTill` L482, before the `TillSession::create` at L511). **P6-1 CORRECTION (pass-6): the resolver returns `array{session: TillSession, end: CarbonInterface}|null` (`:215-234`), NOT a model** — so access is `$prev !== null && $prev['session']->settlement_kind === 'handover'`, `$prev['session']->chain_id`, `(int) $prev['session']->chain_sequence + 1`. Using `$prev?->settlement_kind` (model-property/`?->` on an array) is a fatal TypeError. Workstation mirror: `handleLocalPosTillOpenSession` (`local_pos_till.go:215-355`) has **no prior-session read at all** — the `local_pos_till.go:865` query the plan cited is the **gap-preview handler**, not open. Extract the latest-terminal `closed_at` query into a shared local helper (a second copy already exists in `stampClaimedGapPayments`) and call it from the open handler; do NOT assume a seam is already there. **G2 fix (pass-4): make the resolver tie-break deterministic.** `previousTerminalSessionForTill` currently does `sortByDesc('end')->first()` (`TillSessionService.php:226-233`) with NO secondary sort — two terminal sessions on the same till sharing an identical `end` timestamp (a handover-settle + an abandon in the same tick, second-granularity collisions) would pick a non-deterministic "most recent", and **both R1 (continue-vs-new-chain) and R8 (block-vs-allow currency) depend on this single resolver**. Add a stable tiebreaker. **P6-4 CORRECTION (pass-6): the sort runs on a MAPPED collection of `['session'=>…, 'end'=>…]` arrays (`:225-233`), so `sortByDesc('chain_sequence')` sorts on a nonexistent top-level key (null → no-op).** Use closures: `->sortByDesc('end')->sortByDesc(fn ($r) => (int) $r['session']->chain_sequence)->sortByDesc(fn ($r) => $r['session']->id)`. Mirror the same deterministic ordering in the workstation local helper. (This only changes behaviour on exact ties, so it's safe for the plan-044 gap machinery that also calls the resolver.)
- **R2** `settlement_snapshot` is written **once**, inside the settle transaction, and never mutated — the aggregate is `Σ snapshots`, immune to later cross-shift void/refund.
- **R3** A handover shift's next opening float is a **blind re-count** by the incoming cashier (Decision 1); the prior shift's counted cash is NOT auto-carried. **CORRECTION (pass-3):** this needs **no new validation** — `opening_counts` is ALREADY unconditionally required at open (Cloud `OpenTillSessionRequest.php:31` = `required|array|min:1`; workstation `local_pos_till.go:221-224` rejects empty counts), and `open()` already carries nothing forward (L539-543 documents *"NO automatic carry-over"*). So R3 is satisfied **by construction**; the only work is a UI banner ("blind recount — count the drawer fresh") when `continues_chain=true`. Do NOT add a `continues_chain`-conditional `opening_counts` requirement — it would be a redundant no-op.
- **R4** Grand total lines (cash / per-rate tax / revenue) equal the yen-exact sum of the per-shift snapshot lines; per-rate tax is summed **per rate bucket** (8% with 8%, 10% with 10%), never merged. **P5-9: the grand total is `Σ(already-rounded per-shift figures)`, NOT a re-rounded whole-chain recompute** — each shift's tax was rounded once at its own settle (for its own 精算 slip / legal record), so the chain sums those frozen per-shift values. Both the workstation `FormatChainReport` and Cloud `chainSummary` sum the SAME stored per-shift snapshots, so they converge to the yen (a whole-chain recompute would drift from the per-shift slips and is wrong for per-shift accountability).
- **R5** Abandon / expire / force-abandon / **manual-settle (plan-032)** of a mid-chain shift ends the chain — **none** of these set `settlement_kind` (only `handover()` and `close()`=final do), so R1 makes the next open a new chain; the aggregate counts only shifts that have a `settlement_snapshot` (settled via handover/final). A manual-settled/abandoned shift keeps its own single-shift report but is never a chain-aggregate member. (Verified pass-3: there are **three** paths to `status=Settled` — `close()` L794, `settleFromWorkstation()` L947, `manualSettle()` L1346 — none but `close()`/`handover()` set `settlement_kind`. The only `close()` caller is `TillSessionController::close` :137. **`settleFromWorkstation()` (L866) is the Cloud sync-UP accept path** — see W1 below, it is where the workstation handover/close op lands, NOT `close()`.)
- **R6** Every shift shows BOTH actions (handover + final close). A shift-1 final close = a chain of one, aggregate == today's single 精算 slip.
- **R7 (snapshot convergence — GAP-4, REDESIGNED pass-3)** The workstation is **local-first** for the shift lifecycle (`handleLocalPosTillClose`/`Open` own it in SQLite, sync UP async). Both stacks write a snapshot: the **workstation** builds a *provisional* snapshot from its local time-window `reconcileSession` (+ local per-line per-rate tax) at offline settle — this drives the OFFLINE handover/chain print. On sync UP, **Cloud is authoritative**: it recomputes the snapshot from attribution-based `reconcile()` + `OrderTaxBreakdownAggregator` and stores THAT.
  **CORRECTION (pass-3): adoption is via the sync-UP RESPONSE, NOT `PullTillSessions`.** The original design said the workstation "adopts Cloud's snapshot on the next `PullTillSessions`" — that is **broken**: `PullTillSessions` reads `/api/v1/workstation/till-sessions/active`, and `TillController::activeSessions` (`TillController.php:84-91`) returns **only `open`/`closing`** rows. A settled handover/final session is **never** in the active feed, so its Cloud-recomputed snapshot would never sync DOWN and the workstation would keep its provisional snapshot forever. Instead, the Cloud handover/close accept **computes the authoritative `settlement_snapshot` SYNCHRONOUSLY inside the settle request and returns it (+ canonical `chain_id`/`chain_sequence`) in the 200 response body**, and the sync-engine handler (`handleTillSessionClose`/`handleTillSessionHandover`) **adopts it onto the local row from that same response**. **SIMPLIFIED (pass-7): adopt-if-present, ACK-regardless — NOT retry-until-applied.** The snapshot rides the SAME synchronous 2xx (Cloud has already settled + recomputed in that request), so there is nothing to *wait* for: if the response carries `settlement_snapshot`, overwrite the local provisional value and ACK; if it is absent (a NEW workstation talking to an OLD Cloud during a mis-ordered deploy), **just ACK and keep the local provisional** — do NOT return `errDependencyNotReady`. This is CORRECT because Cloud's chain summary / reconcile use Cloud's OWN authoritative snapshot server-side; the workstation's provisional only drives the LOCAL offline print, an acceptable degradation in the skew window. Rationale (pass-7): the queue's `errDependencyNotReady` path (`sync_service.go:632-639`) increments no counter → a "retry until snapshot present" loops forever with no bound, and the plain-retryable path (`:692-715`) head-of-line-blocks the whole cycle then auto-dead-letters at 20 (`isStuckTransient`) as poison — NEITHER gives "bounded retry then ACK". Adopt-if-present sidesteps all of it. `handlePaymentAttribute` (`:1377-1408`) remains the write-back *shape* precedent (read a field from `resp`, `UPDATE` a local row), but its unbounded `errDependencyNotReady` retry is NOT copied here — the snapshot is never "not yet ready" on a 2xx. No new pull feed, no new retry/counter plumbing.
  **G3 contract (pass-4) — two silent-failure traps to avoid:** (a) **`cloudPost` returns ONLY the inner `data` map** (`sync_service.go:2889-2893`), dropping every top-level key — so Cloud MUST nest the snapshot *inside* `data`: add it to `TillController::shape()` (`:445-464`, which is spread into `{"data": …}`), **NOT** as a sibling of `data`. A top-level `settlement_snapshot` is silently dropped and the write-back reads empty forever. (b) The snapshot must serialize as a **JSON object/map**, not a JSON *string* — so the Eloquent `array`/`json` cast from T1.3 is load-bearing here too, else the Go `resp["settlement_snapshot"].(map[string]any)` type-assert fails. Both the fresh-settle and the idempotent-replay return paths flow through the same `shape()` (`settleFromWorkstation` idempotent early-return `:878-880` → `findById` → `shape()`), so once the field is in `shape()` the frozen snapshot rides back on every re-drain (R2 upheld — recompute only on the first, not-yet-settled accept). Both stacks sum disjoint per-session data, so figures converge to the yen; any residual time-window-vs-attribution delta resolves in Cloud's favour once the op drains.
- **R8 (chain currency/rounding invariance — C1 fix, pass-3)** A handover **clears `Till.current_session_id`** (it is a settle), which opens a window where the config-change guard `branchHasOpenShift()` (`ShopOrderSettingsController.php:610`, joins `tills.current_session_id → till_sessions`) returns **false** — so an admin could flip `currency_code`/rounding between a handover and the next open, and the continued chain would stamp a *different* currency onto later shifts while the aggregate blindly Σ's them (yen + USD in one grand total). Under the old standalone-close model this was harmless (separate reports); the chain aggregate makes it a **money-correctness bug**. Fix: the guard must ALSO block while a chain is *awaiting continuation* — extend `branchHasOpenShift()` (or add a sibling `branchHasOpenChain()`) to return true when the till's most-recent terminal session has `settlement_kind = handover`. A chain is currency/rounding-invariant from its first open until its final close.

## API surface

### Endpoint inventory

| # | Method | Path | Purpose | Auth | Route file |
|---|--------|------|---------|------|------------|
| 1 | POST | `/api/v1/pos/till/sessions/{session}/handover` | Settle current shift as handover; keep chain open | SSO/pos-device + `X-Shop-Slug` | `backend/routes/api/pos.php` |
| 2 | POST | `/api/v1/pos/till/sessions/{session}/close` | **MODIFIED** — settle as final; end chain | SSO/pos-device + `X-Shop-Slug` | `backend/routes/api/pos.php` |
| 3 | GET | `/api/v1/pos/till/chains/{chainId}/summary` | Aggregate: per-shift blocks + grand total | SSO/pos-device + `X-Shop-Slug` | `backend/routes/api/pos.php` |
| 4 | POST | `/api/v1/pos/till/sessions` | **MODIFIED** — `open()` continues/starts a chain | SSO/pos-device + `X-Shop-Slug` | `backend/routes/api/pos.php` |
| 5 | POST | `/api/lan/print/shift-report` | **MODIFIED** — `report_kind: handover\|settlement` header label | Bearer (LAN) | `workstation-app internal/handler/routes.go` |
| 6 | POST | `/api/lan/print/chain-report` | **NEW** — aggregate chain slip | Bearer (LAN) | `workstation-app internal/handler/routes.go` |

### Endpoint detail

#### 1. POST `/pos/till/sessions/{session}/handover`
- **Auth:** `auth.sso_or_device` + `ResolvePosShop` + `TillSessionPolicy@handover` (cashier of own shop).
- **Request body:** same shape as `close` (the shift is settled) —

  | Field | Type | Required | Notes |
  |-------|------|----------|-------|
  | `closing_counts` | array | yes | denomination counts of the drawer at handover |
  | `tender_details` | array | yes | per-tender declared totals |
  | `closing_note` | string | no | |
  | `closing_cash_adjustment` | number | no | 端数 adjustment |

- **Success (`200`):** `{ data: { session: <settled TillSession, settlement_kind=handover>, chain_id, next_chain_sequence } }`
- **Errors:**

  | Status | Code | When |
  |--------|------|------|
  | 409 | `SHIFT_NOT_OPEN` | session not in `open`/`closing` (status guard `assertStatus`, `TillSessionService.php:739`) |
  | 409 | `PENDING_PAYMENTS_BLOCK_CLOSE` | live pending payments on the shift (`assertNoLivePendingPayments` L746) — **inherited from the close path; the pass-2 table omitted it** |
  | 422 | `VARIANCE_REASON_REQUIRED` | out-of-tolerance variance w/o reason (reuse close guard L783-790) |
  | 422 | — | unknown/negative tender in `tender_details` (`persistSettlementDetails` L768) |
  | 403 | — | not this shop's cashier |

- **Side effects:** `handover()` runs the SAME settle body as `close()` inside `DB::transaction`: it MUST lock the **session's own till** via `lockTillForSession()` (`TillSessionService.php:736/1661` — the multi-till bugfix; NOT the branch MAIN till) + `TillSession::lockForUpdate`; settle the session (kind=handover), write `settlement_snapshot` (two sources — see §Data model), keep `chain_id` open; **`$session->logAudit('till_session_handover', [...])`** (logAudit is a **model** method from the `AuditsActivity` trait — `$session->logAudit(...)`, NOT a service method; action names use underscores like `till_session_settled`); clear `Till.current_session_id` (guarded `if current === session->id`, L811). No events/broadcasts/jobs are dispatched (printing is a client concern). The client then prints the single-shift slip (endpoint 5, `report_kind=handover`) and navigates to open (endpoint 4 continues the chain).

#### 2. POST `/pos/till/sessions/{session}/close`  *(MODIFIED)*
- Same request as today. **Adds:** sets `settlement_kind = final`, writes `settlement_snapshot`, marks the chain closed (implicit — no continuation). `$session->logAudit('till_session_final_close', [...])` (model method, underscore action name).
- **Success (`200`):** `{ data: { session, chain_id, chain_summary_ready: true } }`.
- Client then GETs endpoint 3 and prints the chain slip (endpoint 6).

#### 3. GET `/pos/till/chains/{chainId}/summary`
- **Auth:** same; the chain must belong to the caller's branch.
- **Success (`200`):**
  ```json
  { "data": {
    "chain_id": "…", "branch_id": "…", "till_code": "MAIN",
    "opened_at": "<first shift opened_at>", "closed_at": "<final shift closed_at>",
    "shifts": [ { "session_code": "…", "chain_sequence": 1, "opener_name": "…",
                  "opened_at": "…", "closed_at": "…", "settlement_kind": "handover",
                  "cash": {…}, "tax_breakdown": [{"rate":8,"taxable":…,"tax":…},{"rate":10,…}],
                  "revenue": {"gross":…,"net":…,"tax":…} } ],
    "grand_total": { "cash": {…}, "tax_breakdown": [{"rate":8,…},{"rate":10,…}], "revenue": {…} }
  } }
  ```
- **Errors:** 404 unknown chain / cross-branch. Data source: `TillSession where chain_id = :chainId **and settlement_kind in ('handover','final')** order by chain_sequence` → each row's immutable `settlement_snapshot`; grand total = Σ per rate bucket (R4). **G1 fix (pass-4): the query MUST filter to settled chain members** (`settlement_kind in (handover,final)` — equivalently `whereNotNull('settlement_snapshot')`), and the Σ **must null-guard each block** (`$snapshot['cash'] ?? []`), because a row can carry a `chain_id` yet have a NULL `settlement_snapshot` (an abandoned/expired mid-chain shift under R5, a race, a data repair, or a codegen-cast miss) — an unguarded `$snapshot['cash'][...]` would null-deref. Branch scope mirrors `TillSessionController::ensureShopMatch()` (`:327-333`): assert every row's `branch_id === request shop_id` (or filter the query by `branch_id`, backed by the `[branch_id, chain_id]` index); `{chainId}` is a raw string (no model binding — query manually).

#### 4. POST `/pos/till/sessions`  *(MODIFIED — chain continuation)*
- Adds to `open()`: **a NEW direct call** `$prev = $this->previousTerminalSessionForTill($till)` (there is no existing call to reuse in `open()` — see R1 correction), placed after `lockTill` (L482) and before `TillSession::create` (L511). **The resolver returns `array{session, end}|null`, so access `$prev['session']->…` (P6-1), never `$prev?->…`.** If `$prev !== null && $prev['session']->settlement_kind === 'handover'` → `chain_id` = `$prev['session']->chain_id`, `chain_sequence` = `(int) $prev['session']->chain_sequence + 1`, response `continues_chain = true` (UI shows the blind-recount banner — R3, no new validation). Else → **fresh** `chain_id` via `Str::uuid()->toString()` (add `use Illuminate\Support\Str;` — the service has NO `Str` import today, and `HasUuids` only fills the PK, not `chain_id`), `chain_sequence = 1`, `continues_chain = false`. Set `chain_id`/`chain_sequence` **in the `TillSession::create([...])` array** (L511). No request-body change; response gains `{ chain_id, chain_sequence, continues_chain }`.

#### 5–6. Workstation print (see workstation-app section)
- 5 reuses `buildShiftReport(session_id)`; body gains `report_kind` → header label 引き継ぎ/精算.
- 6 `{ chain_id }` → `buildChainReport(chainId)` iterates the chain's local settled sessions, sums snapshots, → `FormatChainReport`.

## Workstation offline — local-first lifecycle + sync (GAP-2 + GAP-3)

> **Critical:** the workstation OWNS the shift lifecycle in SQLite — `POST /pos/till/sessions` →
> `handleLocalPosTillOpenSession` (`routes.go:253`), `.../{id}/close` → `handleLocalPosTillClose`
> (`routes.go:265`), `.../{id}/draft` → `handleLocalPosTillDraft` are served **locally**
> (*"Workstation owns the lifecycle in SQLite; sync UP fires async"*). Therefore handover, final-close,
> and chain-continuation MUST be implemented **locally** too, or offline handover/close breaks. This
> section is NOT optional. (File note: `PullTillSessions` is in `sync_pull_pos.go:1400`, not
> `sync_pull.go`.)

### Local endpoints (served on the workstation, mirror Cloud)
| Path | Local handler | Behaviour |
|------|---------------|-----------|
| `POST /pos/till/sessions/{id}/handover` **(NEW local)** | `handleLocalPosTillHandover` | settle current session locally (`status=settled`, `settlement_kind=handover`), build **local provisional** `settlement_snapshot` from `reconcileSession()` + local per-rate tax, clear `tills.current_session_id`, `enqueueShiftHandover` (sync UP). Mirrors `handleLocalPosTillClose`. |
| `POST /pos/till/sessions/{id}/close` **(MODIFIED local)** | `handleLocalPosTillClose` | add `settlement_kind=final` + build+store local snapshot; extend `enqueueShiftClose` payload with `settlement_kind`+`settlement_snapshot`+chain fields. |
| `POST /pos/till/sessions` **(MODIFIED local)** | `handleLocalPosTillOpenSession` | chain continuation (R1): read the local latest-terminal session; if `settlement_kind=handover` → same `chain_id`, `chain_sequence+1`; else new `chain_id`, seq 1. Blind recount (R3): opening float from the operator's counts, never auto-carried. |
| `POST /pos/till/chains/{chainId}/summary` | (Cloud-proxied OR local) | if offline, a local `buildChainReport`-style summary from local sessions' snapshots; online, proxy to Cloud. |

### Local snapshot builder
`buildLocalSettlementSnapshot(sessionID)` — cash/tenders/variance from the reusable `reconcileSession()`
(`local_pos_till.go:1015`). **CORRECTION (pass-3):** the per-rate tax buckets are **NOT** in a reusable
function — `FormatShiftReport` only *renders* `info.TaxBreakdown` (`print_shift_report.go:278-294`); the
actual per-rate computation (SQL `GROUP BY o.id, oi.tax_rate`, pro-rata discount, `GroupTaxForLegacy`
once-per-group, `math.Round`) lives **inline inside `buildShiftReport`** (`lan_shift_report.go:197-269`),
a `*Server` handler method. So **first extract that ~70-line block into a shared helper**
(e.g. `service.PerRateTaxBuckets(orders)` — the primitives `CurrencyStep`/`GroupTaxForLegacy` in
`service/pricing.go:141,147` are already exported), then call it from BOTH `buildShiftReport` and
`buildLocalSettlementSnapshot`. Same JSON shape as Cloud's snapshot. Provisional (R7).

### Sync-UP ops (workstation → Cloud)
| Op | Trigger | Cloud path | Payload adds |
|----|---------|-----------|--------------|
| `till_session.handover` **(NEW)** | `enqueueShiftHandover` | Cloud handover accept (below) | closing_counts, tender_details, counted_cash, cash_variance, `settlement_kind=handover`, `chain_id`, `chain_sequence`, `settlement_snapshot` |
| `till_session.close` **(EXTENDED)** | `enqueueShiftClose` (existing) | existing close accept | + `settlement_kind=final`, `chain_id`, `chain_sequence`, `settlement_snapshot` |
| `till_session.open` **(EXTENDED)** | `enqueueShiftOpen` (existing) | existing open accept | + `chain_id`, `chain_sequence` (so Cloud stores the same chain grouping) |

Register `till_session.handover` in the sync-engine op map (`sync_service.go` beside `till_session.close`
L192 — `e.handlers` is a `map[string]syncHandler`, so it's a one-line add via `RegisterHandler`);
`handleTillSessionClose` (L1463-1552, POSTs `/api/v1/workstation/till/sessions/{id}/close`) is the
template. **Each of `handleTillSessionClose`/`handleTillSessionHandover` must, on a 2xx, read the
authoritative `settlement_snapshot` from the response body and write it back onto the local row (R7
convergence)** — not fire-and-forget. The op only ACKs (drains) once the write-back succeeds.

### Cloud accept (receives the sync-UP)
- **CORRECTION (pass-3): the Cloud method that receives a workstation close/handover sync-UP is
  `TillSessionService::settleFromWorkstation()` (`TillSessionService.php:866`), NOT `close()`.** It is
  the third settle path (R5) and differs from the cashier `close()` in ways that matter here: it is
  **idempotent on an already-settled row** (returns the frozen session untouched, L878-880) and it
  **computes but does NOT hard-enforce `VARIANCE_REASON_REQUIRED`** (L936-940 — the workstation already
  gated the cashier locally). So the handover-via-workstation accept must be added to
  `settleFromWorkstation()` (branch on `settlement_kind`) or a close sibling — it will NOT inherit the
  cashier `close()` variance abort, which is correct for a replayed offline op.
- On receipt Cloud accepts `settlement_kind`/`chain_id`/`chain_sequence`/`settlement_snapshot`, stores
  `chain_id`/`chain_sequence`/`settlement_kind` **verbatim** (workstation-authoritative for grouping),
  then **recomputes the authoritative `settlement_snapshot`** (attribution `reconcile()` +
  `OrderTaxBreakdownAggregator`), **ignoring** the workstation's provisional snapshot for the stored
  value, and **returns the authoritative snapshot in the 200 response body** (see R7 — this is how the
  workstation adopts it).

### Sync DOWN + convergence (REDESIGNED pass-3)
- **Primary adoption = sync-UP response write-back (NOT a pull).** `PullTillSessions` pulls
  `/api/v1/workstation/till-sessions/active`, which returns **only `open`/`closing`** sessions
  (`TillController::activeSessions`, `TillController.php:84-91`) — a **settled** handover/final session
  is never in that feed, so it can never adopt its snapshot via pull. Instead, when the
  `till_session.handover`/`.close` op drains, the sync handler reads the authoritative
  `settlement_snapshot` from the Cloud **200 response body** and writes it back onto the local
  `till_sessions` row — **adopt-if-present, ACK-regardless (SIMPLIFIED pass-7): the snapshot is computed
  synchronously in that same 2xx, so no retry is needed; if it is absent (old-Cloud skew) the op still
  ACKs and keeps the local provisional** (see R7). This borrows the `payment.attribute`/endpoint-D
  write-back *shape*, but NOT its unbounded retry.
- `PullTillSessions` still learns the 4 chain columns for **active** rows via `ON CONFLICT(id) DO UPDATE`
  (`sync_pull_pos.go:1443-1460`) — but note it must be **extended to SELECT+upsert the new columns**
  (today it doesn't reference them). This covers a session opened on Cloud-direct then pulled while still
  open; the settled-snapshot adoption is the response write-back above. Neither path wipes a
  locally-settled row (plan-044 D2.1 retention).

## Screens

### Screen inventory

| # | Path | Type | Auth | Purpose |
|---|------|------|------|---------|
| 1 | `/shop/:shopSlug/shift/close` | MODIFIED | cashier | Rename to "Kết ca cuối"; add "Bàn giao ca" action + confirm |
| 2 | `/shop/:shopSlug/shift/open` | MODIFIED | cashier | Show chain position; blind recount when continuing a chain |

### Screen detail

#### 1. Kết ca / Bàn giao — `/shop/:shopSlug/shift/close` (MODIFIED)
- **Page file:** `pos-web/src/app/shift/close-page.tsx`.
- **Fetches:** `useTillCurrent`, `useReconciliation`, `useCloseOrderSummary` (existing) + new `useHandoverShift`, `useChainSummary`.
- **Components used:** `@godxjp/ui` Button, Card, Dialog (confirm), Badge, Alert — no new primitives.
- **Diff:** the primary settle button label → **"Kết ca cuối"** (final; prints chain slip). A secondary **"Bàn giao ca"** Button (handover; prints single-shift slip, then routes to `/shift/open`). Both use the same counted-cash form. A confirm Dialog states the consequence ("kết ca cuối sẽ chốt toàn bộ chuỗi N ca" vs "bàn giao sẽ mở ca kế tiếp"). Show a chain badge ("Ca N của chuỗi").
- **Empty/error/loading:** reuse existing reconcile states; on chain-summary fetch error, still allow the settle but toast that the aggregate print failed (non-blocking).

#### 2. Mở ca — `/shop/:shopSlug/shift/open` (MODIFIED)
- **Page file:** `pos-web/src/app/shift/open-page.tsx`.
- **Diff:** when `continues_chain` (the previous shift was handover), show a banner "Tiếp tục chuỗi — ca N" and REQUIRE a blind re-count (the denomination count is mandatory, no auto-carry — R3); the gap-reconcile panel (plan-044) still applies. When not continuing, unchanged (new chain, seq=1).

## Sitemap

No new routes — both screens exist. Navigation unchanged; only button labels/actions on `/shift/close` and a banner on `/shift/open`.

## Authorization matrix

### Roles involved

| Role key | Display | Source | Notes |
|----------|---------|--------|-------|
| cashier (pos user / pos device) | Thu ngân | SSO or paired pos device | performs handover + final close on own shop |
| manager | Quản lý | SSO | force-abandon/expire (plan-032) unchanged |

### Action × Role

| Action | cashier (own shop) | cashier (other shop) | manager |
|--------|--------------------|-----------------------|---------|
| handover | ✅ (scoped) | ❌ 403 | ✅ |
| final close | ✅ (scoped) | ❌ 403 | ✅ |
| view chain summary | ✅ (scoped) | ❌ 404 | ✅ |

### Policy ↔ gate cross-check

| Action | Backend gate | Frontend gate |
|--------|--------------|---------------|
| handover | `TillSessionPolicy@handover` + `ResolvePosShop` branch scope | button only on own open shift |
| final close | existing `close` policy | existing |
| chain summary | branch scope on `chain_id` | — |

## User journeys

### Journey 1 — Cashier hands over then a later cashier finalizes

**Persona:** morning cashier opens; evening cashier finalizes the day.

**Happy path:**
1. Morning cashier at `/shift/close`, counts drawer, taps **"Bàn giao ca"** → confirm dialog → settle. A single-shift slip prints (引き継ぎ header). App routes to `/shift/open` showing "Tiếp tục chuỗi — ca 2".
2. Evening cashier blind-counts the drawer, opens shift 2 (same chain).
3. At end of day the evening cashier taps **"Kết ca cuối"** → confirm ("chốt chuỗi 2 ca") → settle. The aggregate slip prints: block for shift 1, block for shift 2, then GRAND TOTAL.
4. Next open starts a fresh chain (ca 1).

**Alternate — chain of one:** a cashier opens and taps **"Kết ca cuối"** directly → aggregate slip == a single 精算 slip (R6).

**Edge/error:**
- Out-of-tolerance variance on handover → 422 `VARIANCE_REASON_REQUIRED`, must enter a reason (reuse close guard).
- Shift force-abandoned mid-chain (plan-032) → chain ends; next open = new chain; the final aggregate (if any later) counts only settled shifts (R5).
- Chain-summary/print failure → settle still succeeds; toast offers reprint.

### Cross-journey checklist
- [ ] Each step maps to an endpoint (1–6). ✅
- [ ] Error paths have 4xx cases in endpoint detail. ✅
- [ ] Both roles covered. ✅

## Field lifecycle

### TillSession

| Field | Added? | Default | Displayed | Editable | By roles | Validation | Omnify prop |
|-------|--------|---------|-----------|----------|----------|------------|-------------|
| `chain_id` | yes | null | open/close screen badge (derived) | no (system) | — | uuid | String nullable, indexed |
| `chain_sequence` | yes | 1 | "Ca N" badge | no | — | int ≥1 | Integer |
| `settlement_kind` | yes | null | report header | no (set at settle) | — | enum handover/final | EnumRef nullable |
| `settlement_snapshot` | yes | null | aggregate report | no (write-once) | — | json | Json nullable |

### Orphaned field audit
Existing till_session fields (opening_float_amount, counted/expected/variance, force_abandon*, etc.) are untouched; the snapshot COPIES the reconcile-derived values so a later edit to live orders can't change a settled shift's block.

## Key decisions

### Decision 1 — Blind re-count opening float on handover (chose)
- **Chose:** incoming cashier blind-counts the drawer for the next shift's opening float.
- **Rejected:** auto-carry prior shift's counted cash.
- **Why:** per-cashier variance isolation (research best practice); the user selected this.

### Decision 2 — No chain table; extend TillSession (chose)
- **Chose:** `chain_id` + `chain_sequence` + `settlement_kind` + `settlement_snapshot` on `till_sessions`; chain state is derived.
- **Rejected:** a `till_session_chains` entity.
- **Why:** chain open/close/opened_at/closed_at are all derivable from the member sessions; a table adds a second source of truth to keep consistent. Fewer moving parts, offline-simpler.

### Decision 3 — Aggregate = Σ immutable snapshots (chose)
- **Chose:** capture `reconcile()` into `settlement_snapshot` at settle; aggregate sums snapshots.
- **Rejected:** re-run `reconcile()` per shift at report time / one giant time-window query.
- **Why:** research — a later refund of an earlier shift's order would silently change a settled block; disjoint per-session snapshots sum without double-count and stay legally correct (インボイス per-rate).

### Decision 4 — Final close = repurposed `close` (chose)
- **Chose:** the existing settle becomes "final close" (`settlement_kind=final`); handover is the new action.
- **Why:** the user selected it; a chain-of-one final close == today's behaviour, so no regression for shops that never hand over.

## Alternatives considered
- A separate `till_session_chains` table (Decision 2 rejected).
- Auto-carry drawer float (Decision 1 rejected).
- Cloud-side aggregation query at print time (Decision 3 rejected).

## Risks & mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Double-count in aggregate | med | high (wrong money) | R2/R3 immutable disjoint snapshots + yen-exact Σ test |
| Concurrent handover/close on same till | low | high | reuse Till `lockForUpdate` in settle tx; second → 409 |
| Chain continuation picks wrong prior session | med | med | R1 keys off the till's most-recent terminal session only (a NEW direct `previousTerminalSessionForTill` call in `open()` + a new local query — NOT a pre-existing seam, see R1 correction); Go + Pest parity tests |
| **Chain spans two currencies/rounding modes (C1)** | med | **high (wrong-money aggregate)** | R8 — extend `branchHasOpenShift()` to also block config change while the till's latest terminal session is `settlement_kind=handover` (chain awaiting continuation); Pest test: handover → attempt currency flip → 409 |
| Workstation/Cloud aggregate diverge | med | med | R7 — Cloud recomputes authoritative snapshot on sync-UP + **returns it in the response body** for the workstation to write back (active-only pull can't carry settled sessions); cross-stack yen-exact test |
| **R7 adoption relies on a feed that excludes settled sessions** | (fixed) | high | resolved: adoption moved from `PullTillSessions` (active-only) to sync-UP response write-back (plan-044 pattern) |
| **Deploy skew: new workstation → old Cloud (P5-7)** | med (during rollout) | low (local print only) | Laravel `validate()` silently strips the chain keys + old `shape()` returns no snapshot. Fix (SIMPLIFIED pass-7): the write-back is **adopt-if-present, ACK-regardless** (T5.5) — an absent snapshot just leaves the local provisional in place and ACKs, so the queue NEVER wedges (no retry, no dead-letter). Cloud's own authoritative snapshot still drives the Cloud chain summary. Deploy backend-before-workstation (T8.3) shrinks the window to zero. Reverse direction (old WS → new Cloud) is safe (no `DisallowUnknownFields`, explicit columns). |
| **Long chain overflows thermal paper (P5-8)** | low | low | no ESC/POS pagination exists; chain per-shift blocks are CONDENSED (3–4 lines each), only the grand total is full (T5.8) |
| Backfill: existing settled sessions have null chain | high | low | treat null chain as a completed chain-of-one; open() starts fresh |
| **Failover chain break**: workstation does a local handover, then dies BEFORE the `till_session.handover` op syncs UP; pos-web fails over to Cloud-direct for the next open → Cloud can't see the un-synced handover → starts a NEW chain instead of continuing | low | low (a chain splits into two shorter chains; no money lost — each part still aggregates correctly, snapshots immutable) | accepted degradation, documented; R7 sync-UP fires async right after the local settle so the window is seconds; the split is a reporting-granularity loss, not a correctness/money bug. NOT worth a distributed-lock fix. |

## Open questions
- [ ] Abandon/expire mid-chain ends the chain (R5) — confirm with ops.
- [ ] Reprint policy (handover slip / chain report) — allowed count + audit.

## References
- X/Z report semantics (点検/精算), Air Regi three-tier — see NOTES Discovery.
