# Plan 046 — Notes

> Working log for [Shift handover + chain-of-shifts final close](README.md). Append-only, newest on top.

---

## 2026-07-17 — Implementation COMPLETE (all 8 phases, 36 tasks)

Branch `feature/plan-046-shift-handover-chain-reports` (from `dev`). All 36 tasks green.

- **Phase 1 schema** — chain columns + `TillSettlementKind` enum; `omnify:gen`; migration verified on
  docker MySQL (snapshot casts to `array`); workstation SQLite `044` + repair + unique-prefix guard.
- **Phase 2 service** — `settleShift(kind)` shared body (close=final / handover), `buildSettlementSnapshot`
  (two-source), `open()` chain continuation + deterministic resolver tiebreak, `chainSummary` (Σ, G1 filter,
  int-sort), `settleFromWorkstation` accept, R8 `branchHasOpenChain` guard.
- **Phase 3** — `HandoverTillSessionRequest` + policy.
- **Phase 4** — controller `handover()`/`chainSummary()` + routes + hand-rolled resource exposes chain fields.
- **Phase 5 workstation Go** — `settleLocalShift`, local open chain (P7-A write+read), `PerRateTaxBuckets`
  extract, provisional snapshot, sync-UP ops (handover op + adopt-if-present write-back), sync-DOWN chain
  cols, `report_kind`, `FormatChainReport` (condensed), routes. Cloud `openSession`/`shape` accept chain.
- **Phase 6 pos-web** — handover/final buttons + confirm + chain badge, chain banner, hooks/service,
  `printChainReport`, i18n ja/en/vi (parity).
- **Phase 7 tests** — 10 backend feature tests (8 chain + 2 R8), 3 Go chain-report tests, till regression
  (~348 green); `TestMigrations_UniquePrefixes` + `TestTillSessionChainColumns`.
- **Phase 8** — runbook + CLAUDE.md docs; pint/gofmt/tsc clean; submodule bumps.

**Code review (fresh agent):** found 1 CRITICAL — the R7 snapshot write-back double-unwrapped
(`resp["data"][…]` when `cloudPost` already returns the inner data map) so the authoritative snapshot was
never adopted. FIXED (read `resp["settlement_snapshot"]` directly). Also fixed a RED chain-of-one test
(label-count collision) and added the missing R8 currency tests + the "Ca N" badge. Re-review: all resolved.

Commits: ~22 on the umbrella (backend in-tree + submodule bumps for workstation-app + pos-web). Ready for
`/mcp__omnify__complete` (push + PR). NOT pushed.

## 2026-07-17 — Implementation: Phase 1 complete (T1.1–T1.4)

Branch `feature/plan-046-shift-handover-chain-reports` from `dev` (has plan-044 merged, PR #883).
Issue #884 flipped planning → executing.

- **T1.1/T1.2** schema + enum: `omnify:diff` → exactly 7 changes (4 props, 2 indexes, 1 enum), no errors.
- **T1.3** `omnify:gen`: migration `2000_03_02_..alter_till_sessions`, `TillSessionBaseModel` fillable +
  **`settlement_snapshot` cast `'array'`** (pass-3 blocker CONFIRMED resolved by codegen), `settlement_kind`
  cast `TillSettlementKindEnum`, `TillSettlementKindEnum.php`, workstation Go enum `till_settlement_kind.go`.
  Migration applied on docker MySQL → 4 chain columns present. `chain_sequence` renders VARCHAR (P5-5 confirmed).
- **T1.4** workstation SQLite `044_till_session_chain.sql` + repair heal + `TestMigrations_UniquePrefixes`
  (P5-2, 040 allowlisted) + `TestTillSessionChainColumns`. `go build` + `go test ./internal/store` green.

Commits: scaffold + T1.1..T1.4 on umbrella (6), + 2 in workstation-app submodule (Go enum, migration),
pointer bumped once. admin-web TS types left uncommitted (incidental fallout; admin-web has pre-existing drift).

**Research for Phase 2 (so next turn starts hot):**
- `reconcile($session)` returns: `revenue{gross,net,tax,discount,currency_code}`,
  `cash{opening_float,cash_sales,cash_tips,paid_in,paid_out,loan_from_safe,pickup_to_safe,expected_cash}`,
  `tenders[]`, `category_expected{}`. (`TillSessionService.php:1585`.) Sibling `revenueSnapshotColumns($recon)`
  at :1611 already extracts the 4 settled_* figures — the snapshot's revenue block mirrors it.
- `OrderTaxBreakdownAggregator::forOrders($orderIds)` (`:18`) → `{net, tax, gross, by_rate:[{rate,taxable,tax}]}`,
  empty-safe (`by_rate: []` for zero orders). Instantiate `(new OrderTaxBreakdownAggregator)` (no ctor deps),
  matching `DashboardService.php:171`. `$sessionOrderIds = OrderPayment::where('till_session_id',$session->id)->distinct()->pluck('customer_order_id')`.
- **T2.5 signature decision**: build it alongside T2.1/T2.2 (the settle path computes counted_cash/cash_variance
  from closing_counts BEFORE the settle UPDATE, so buildSettlementSnapshot needs those passed in, not read off
  the not-yet-updated `$session`). Coupled settle unit = T2.1 handover + T2.2 close + T2.5 snapshot.

## 2026-07-17 — Gap audit PASS 7 (deep end-to-end trace of Phase 5 workstation Go; 2 HIGH + 4 MED/LOW, and a SIMPLER correct R7)

Pass-7 stopped sweeping the whole repo and instead traced the single most complex, offline-critical,
least-verified phase — Phase 5 workstation Go — end to end (local settle/open path + the sync engine).
2 sub-agents read the real Go line by line. It caught the biggest "will-it-actually-build" gaps yet and,
notably, **replaced my own P5-7 fix with a simpler correct one**.

**HIGH:**
- **P7-A local open never WRITES the chain columns, and no SELECT READS them. CLOSED.** T1.4 adds the
  SQLite columns, but the open INSERT (`local_pos_till.go:292-303`, 12 cols) doesn't write `chain_id`/
  `chain_sequence`, and every row reader (`loadSession:1373`, `buildShiftReport` head `:115`,
  `reconcileSession:1019`, `sessionStatusAndCurrency:1443`) uses explicit column lists that omit them.
  Result: local chain never forms. An implementer copying `handleLocalPosTillClose` would miss this.
  T5.3 now explicitly extends the INSERT + the four SELECTs; T5.1/T5.2 UPDATE the settle columns; +test.
- **P7-1 my P5-7 bounded-retry has NO feasible mechanism — REPLACED with adopt-if-present, ACK-regardless.**
  The `syncHandler(ctx,entityID,payload)` signature gets no `attempts`; `errDependencyNotReady` climbs no
  counter (unbounded loop); a plain retryable error head-of-line-blocks then auto-dead-letters at 20 as
  poison. NONE gives "bounded retry then ACK". The correct + simpler design: Cloud computes the snapshot
  SYNCHRONOUSLY in the settle response, so there's nothing to wait for — **on 2xx, adopt the snapshot if
  present, ACK regardless; if absent (old-Cloud skew), keep the local provisional and ACK** (Cloud's own
  authoritative snapshot drives the Cloud chain summary; provisional only drives local offline print).
  No retry, no counter plumbing, no dead-letter, no wedge. DESIGN R7 + risk table + T5.5 + TESTS rewritten.
  (This also downgrades the deploy-skew risk from high/head-of-line to low/local-print-only.)

**MEDIUM:**
- **P7-C no Cloud `handover` route** — `workstation.php:107-114` has only close/abandon. `handleTillSessionHandover`
  POSTs to the SAME `/close` with `settlement_kind=handover`; T2.6 branches `settleFromWorkstation`. T5.5.
- **P7-D handover must mirror the FULL close handler** — the WS-5 pending-deps guard (`errDependencyNotReady`
  until payments/cash-events drain, `:1472-1481`) + the WS-3 send-time drawer manifest (`:1491-1548`), not
  just the snapshot write-back. Omitting → offline handover races its payments → Cloud 503. T5.5 + T1/GoInt test.
- **P7-B enqueue payloads are built from request+computed data, not the session row** — chain fields must be
  threaded as new args (or a `SELECT` added) at `enqueueShiftClose`/`enqueueShiftOpen` (`:1225/1270`). T5.2/T5.3/T5.5.

**LOW:**
- **P7-3 T5.6 upsert is a POSITIONAL 5-place edit** (struct + INSERT cols + VALUES + ON CONFLICT set + `stmt.Exec`
  arg order) — a positional mismatch silently writes the wrong column; also the Cloud `activeSessions.shape()`
  must add the fields. T5.6 + scan-order test.

**Verified GOOD (lots):** T5.1 handover mirrors close (full step list); `reconcileSession` reusable; P5-6
`PerRateTaxBuckets(q rowQueryer, …)` signature exactly right; op-map one-line register; `cloudPost` returns the
inner `data` map; ACK truly gated on the handler's returned error (`:734-737`); op ordering FIFO by `created_at`
(handover before open; open carries chain fields from LOCAL state → out-of-order safe); session id verbatim
local↔cloud (no manifest mapping); `uuid` imported; denomination/tender rows have no unique-constraint hazard.

Tasks 36 (edits, no new task); tests 73 → 75. R7 simplified (net LESS complexity than pass-3..5 left it).

## 2026-07-17 — Gap audit PASS 6 (OA/Swagger + test-infra + type-flow + resource; 3 HIGH incl. TWO of my own prior fixes being buggy)

Pass-6 targeted the last unaudited convention/infra surfaces: the l5-swagger OA requirement, the Pest
chain-building helpers, the TS type flow, and the Resource serialization. It caught the most
implementation-fatal issues yet — including **two of my own earlier fixes that were wrong** (a good sign
the passes keep earning their cost). 2 sub-agents + user decision on OA.

**HIGH:**
- **P6-1 [was a self-inflicted crash] resolver returns an ARRAY, not a model.** `previousTerminalSessionForTill`
  returns `array{session: TillSession, end: CarbonInterface}|null` (`:215-234`). My pass-3 fix wrote
  `$prev?->settlement_kind` / `$prev->chain_id` everywhere (R1, T2.3, DESIGN §4) — model access on an array =
  fatal TypeError. Fixed to `$prev['session']->…` throughout.
- **P6-2 [HIGH] hand-rolled `TillSessionResource` never exposes the chain columns.** The POS resource
  (`app/Http/Resources/Pos/TillSessionResource.php:29-102`) is a hand-rolled whitelist (docblock: "not
  extending TillSessionResourceBase") — chain fields appear in NO response unless added. **My pass-5 NOTES
  "Resource auto-include — Verified GOOD" was WRONG** (that was the generated base; pos uses the hand-rolled
  one). Fixed: new **T4.3** adds `chain_id`/`chain_sequence`/`settlement_kind` to the resource; corrected the
  false NOTES claim + T1.3 verify; the "Ca N" badge now has a data source.
- **P6-3 [HIGH] OA on Pos endpoints is invisible.** The `Api/V1/Pos` namespace is in NONE of the 4
  l5-swagger buckets, and existing `TillSessionController`/`TillController` carry ZERO OA. **User decision:
  DROP the OA sub-task** (matches reality); T4.1 updated with the rationale (avoids also propagating
  ~~`InvoiceController`~~ (**ĐÃ XOÁ ở #1779**, PR #1791)'s wrong `bearer_token` scheme — real one is `sanctum`).

**MEDIUM:**
- **P6-4 [was a self-inflicted no-op] the G2 tiebreaker (pass-4) doesn't work.** `->sortByDesc('chain_sequence')`
  runs on the mapped `['session'=>,'end'=>]` array collection → sorts on a null top-level key → no-op. Fixed to
  closure form `->sortByDesc(fn ($r) => (int) $r['session']->chain_sequence)->sortByDesc(fn ($r) => $r['session']->id)`.
- **P6-5 no shared chain test helper.** Till test helpers are all file-local; none settles-as-handover or opens-continuing.
  T7.1 now authors `seedHandoverChain`/`seedChainOfN` first (multi-shift, ≥10-shift, immutability scenarios need it).

**LOW:**
- **P6-6 TESTS scenario contradicted R3.** "omit opening_counts while continues_chain → 422" implied a conditional
  guard R3 forbids; `opening_counts` is unconditionally required. Reworded to match R3 (UI-only blind recount).

**Verified GOOD (no action):** raw-string `{chainId}` route (ResolvePosShop reads header only; `InvoiceController::show(string)`
precedent); pos-web types are HAND-WRITTEN (no omnify:gen reaches pos-web — T6.1 correct); workstation frontend
needs no type (Go serves lifecycle); audit metadata — actor auto-captured via `Auth::id()`, keys free-form,
underscore action names match; order-summary is close-only DISPLAY, not a settle guard (handover parity fine).

Tasks 35 → 36 (T4.3 new); tests 72 → 73.

## 2026-07-17 — Gap audit PASS 5 (previously-unaudited layers: Omnify codegen mechanics + ESC-POS + deploy skew; 2 HIGH + 5 MED + 2 LOW closed)

Passes 1–4 audited logic/architecture; pass-5 deliberately hit the **mechanical layers no prior pass
touched**: real Omnify YAML syntax, the ESC-POS print formatter, and version-skew. It found REAL bugs
(one would fail codegen outright; one cited a nonexistent precedent). I had predicted flat returns — wrong.
2 sub-agents read real schema + Go + generated code; I hand-verified the 3 contradiction-prone claims.

**HIGH:**
- **P5-1 index syntax would fail codegen. CLOSED.** Plan wrote `options.indexes: [[a,b],[c,d]]` (bare
  list-of-lists). Real convention (verified `TillSession.yaml:58-77`) is a list of `- columns: [...]`
  objects. T1.1 + DESIGN fixed with the exact YAML.
- **P5-2 "duplicate-migration guard test" is a FICTIONAL precedent. CLOSED.** Plan (T1.4 + TESTS)
  claimed a plan-045 guard rejects duplicate migration numbers. Hand-verified: `migrate.go:74-86`
  parses the prefix and **silently `continue`s** on a recorded version (this is why the `040` collision
  shipped — `040_payments_sync_target.sql` + `040_plan045_refund_rounding.sql`, hand-healed in
  `repair.go`). The only related test is `payment_sync_target_repair_test.go` (heals columns, does NOT
  guard prefixes). My own session memory of a `TestMigrations_NoUnexpectedDuplicateVersions` was wrong.
  Fix: drop the false claim; add a NEW CI-time `TestMigrations_UniquePrefixes` (walk the dir, fail on a
  shared prefix, allowlist the legacy `040`) — NOT runtime rejection (would break existing `040` installs
  on boot). T1.4 + TESTS.

**MEDIUM:**
- **P5-3** migration number `0NN` → `044` (highest is `043_payments_rehomed_at.sql`; `040` already dup).
- **P5-4** `chain_id` → `type: Uuid` (matches `*_by_id` cols), not `type: String`.
- **P5-5** `type: Integer` renders a **VARCHAR** column repo-wide (Omnify quirk) → `chain_sequence`
  DB-side `ORDER BY` sorts lexically ('10'<'2'); `chainSummary`/`buildChainReport` must sort int-wise.
  T1.1/T2.4/T5.8 + a ≥10-shift ordering test.
- **P5-6** `PerRateTaxBuckets(orders)` signature wrong — the block is a SQLite `GROUP BY` on `s.db`,
  needs `(q rowQueryer, step float64, lower, upper string)` (rowQueryer exists `pricing.go:13`). T5.4.
- **P5-7** deploy skew liveness: new-WS→old-Cloud, Laravel `validate()` silently strips chain keys →
  write-back finds no snapshot → unconditional retry **wedges the sync queue** (head-of-line). Fix:
  bounded retry then ACK-with-provisional (T5.5); deploy-order is liveness-critical (T8.3). +GoInt test.

**LOW:**
- **P5-8** no ESC/POS pagination → a 10-shift chain of full 9-section blocks = 200–400 lines. Chain
  per-shift blocks must be CONDENSED (3–4 lines); only grand-total is full. T5.8 + line-count test.
- **P5-9** "respects ShowTaxBreakdown exactly like FormatShiftReport" imprecise — chain reads FROZEN
  snapshot buckets, toggle read live at print; documented. Also pinned R4 = `Σ(rounded per-shift)`, both
  stacks sum the same snapshots (no whole-chain re-round). DESIGN R4 + T5.8.

**Verified GOOD (no action):** EnumRef syntax; `type: Json`→auto `'array'` cast on the generated BASE
model (precedent `OrderPayment.metadata`) — **NOTE (corrected in pass-6): this "Resource auto-include"
was WRONG for the POS path — pos endpoints use a HAND-ROLLED `TillSessionResource` (whitelist, not
extending the base), so the chain fields must be added manually; see P6-2/T4.3**; nullable/default;
`report_kind` header slot (`L.Title` single
anchor); i18n `shiftLabelsJA/EN/VI`+`labelsFor` + real VI ASCII-fold (`StripAccents`); unknown-field
tolerance (no `DisallowUnknownFields`, explicit columns, no `SELECT *`); `FormatChainReport` layout
(pure primitives, no single-session footer).

Tasks 35 (edits, no new task — the CI test folds into T1.4); tests 67 → 72.

## 2026-07-17 — Gap audit PASS 4 (adversarial self-check of pass-3 fixes + pos-web; 2 new gaps + 1 trap closed)

Fourth pass, deliberately aimed at **the pass-3 fixes themselves** (R7 write-back + R8 guard were large
redesigns — if they were wrong the plan would now be *confidently* wrong) plus **pos-web Phase 6**
(never audited). 3 sub-agents read real Go + PHP + pos-web.

**Pass-3 fixes VALIDATED against real code** (they hold):
- R7 write-back is REAL: `handlePaymentAttribute` (`sync_service.go:1377-1408`) is the exact plan-044
  endpoint-D pattern (cloudPost → read resp field → `errDependencyNotReady` retry → write-back local);
  `cloudPost` unwraps `{data:{…}}`; ACK gated on handler return (`:734-737`), not the raw 2xx.
- R2 upheld under re-drain: `settleFromWorkstation` idempotent path (`:878-880`) returns the frozen model
  via `shape()`, recompute only on the first accept.
- R8 sound + bounded: only write path is `ShopOrderSettingsController::update()`, all 3 sites use the
  guard; abandon/expire/manualSettle set NO `settlement_kind` → no over-block, no hole.
- pos-web: ALL files/hooks/print-service/routing exist as claimed (close-page, open-page, shift-gate,
  `useCloseShift`/`useTillCurrent`, `till-service` via `apiFetch`→`resolveBaseUrl`, `workstation-print-service`,
  react-router `:shopSlug`, i18n `shift.*`). Backfill null-safe (no migration). open() race serialized by
  `lockTill` (second terminal 409s before duplicating `chain_sequence`).

**2 new gaps + 1 trap CLOSED:**
- **G1 [warning] chain-summary null-deref.** T2.4 query was `where chain_id` with no snapshot filter — a
  member with `chain_id` but NULL snapshot (abandoned mid-chain under R5, race, cast-miss) would null-deref
  the Σ. Fixed: query `whereIn('settlement_kind',['handover','final'])` + null-guard every block. +1 test
  (abandoned member skipped). DESIGN endpoint-3 + T2.4.
- **G2 [minor, but hits BOTH R1 & R8] resolver tie-break non-determinism.** `previousTerminalSessionForTill`
  (`:226-233`) sorts `sortByDesc('end')->first()` with no secondary sort → two terminal sessions with an
  equal `end` timestamp resolve non-deterministically. Both R1 (continue-vs-new-chain) and R8
  (block-vs-allow currency) key off this one resolver. Fixed: add `->sortByDesc('chain_sequence')->sortByDesc('id')`
  tiebreaker (safe — only changes exact-tie behaviour, incl. for plan-044 gap machinery). +1 test. R1 + T2.3.
- **G3 [low, silent-failure trap] R7 response data-nesting.** `cloudPost` returns ONLY the inner `data` map
  (`:2889-2893`) — the snapshot MUST be nested inside `data` via `TillController::shape()` (`:445-464`), not
  a top-level sibling (silently dropped → write-back reads empty forever), and must serialize as a JSON
  object not a string (ties to the T1.3 `array` cast). +1 test (nesting + retryable-when-absent). DESIGN R7 +
  Cloud-accept + T2.6 + T5.5.

Cosmetics fixed: print signature is `printShiftReport({shopSlug, sessionId})` → `printChainReport({shopSlug, chainId})`
(LAN body needs `shop_slug`), T6.5; i18n files are FLAT dot-key `Record<string,string>`, not nested, T6.4;
R8 uses a dedicated sibling `branchHasOpenChain()` (branch-scope windowed query, not a one-line condition),
T2.7; `handleTillSessionClose` is currently fire-and-forget — write-back is NEW logic on `handlePaymentAttribute`'s
model, T5.5.

Tasks unchanged at 35 (edits, no new task); tests 64 → 67.

## 2026-07-17 — Gap audit PASS 3 (4 parallel code-reading agents; 3 CRITICAL + 5 warnings closed)

Third pass, this time with 3 adversarial sub-agents reading the REAL code end-to-end (Cloud settle
state machine, `open()` chain seam, workstation local+sync) + direct grep verification. Unlike passes
1–2 (which found refinements), pass-3 surfaced **three genuine CRITICAL holes** the earlier passes
missed, plus five warnings. All closed:

- **C1 — chain can span two currencies (money-correctness bug plan-046 introduces). CLOSED via R8 + T2.7.**
  A handover clears `Till.current_session_id`; the config-change guard `branchHasOpenShift()`
  (`ShopOrderSettingsController.php:610`) joins on that column, so between a handover and the next open
  it returns false → admin can flip currency/rounding → the continued chain stamps a different currency
  on later shifts while the aggregate blindly Σ's them. Old standalone-close model was immune (separate
  reports); the chain aggregate makes it a bug. Fix: R8 + T2.7 extend the guard to also block while the
  till's most-recent terminal session is `settlement_kind=handover`. +3 tests (block currency, block
  rounding, R8 doesn't over-block a normal close).
- **C2 — R7 snapshot convergence was infeasible as designed. CLOSED by redesign (response write-back).**
  Original R7 said the workstation "adopts Cloud's authoritative snapshot on the next PullTillSessions."
  But `PullTillSessions` reads `/till-sessions/active`, and `TillController::activeSessions:84-91`
  returns ONLY open/closing — a settled handover/final session is never in that feed, so its snapshot
  would NEVER sync DOWN. Redesigned: the Cloud accept **returns the authoritative snapshot in the 200
  response body**, and the sync handler writes it back onto the local row when the op drains
  (retry-until-applied — the plan-044 `payment.attribute`/endpoint-D pattern). No new pull feed. DESIGN
  R7 + Cloud-accept + sync-DOWN sections + T2.6/T5.5/T5.6 rewritten. +1 test (R7 negative: prove pull
  alone doesn't adopt).
- **C3 — "reuse existing seam" was FALSE at BOTH tiers. CLOSED (wording + real seam).**
  Cloud: `open()` does NOT call `previousTerminalSessionForTill` at L264/L414 (those are `gapPreview`
  L264 / `claimGapPayments` L414; open reaches it only indirectly, gated behind an early-return) — must
  add a NEW direct call in `open()`. Workstation: `handleLocalPosTillOpenSession` (L215-355) has NO
  prior-session read at all; the cited `local_pos_till.go:865` is the gap-preview handler — must extract
  the latest-terminal query into a shared helper and call it. R1 + T2.3 + T5.3 corrected.

Warnings closed: **W1** Cloud sync-UP accept is `settleFromWorkstation()` (L866, idempotent-on-settled,
compute-don't-enforce variance), NOT `close()` — T2.6 retargeted. **W2** handover error table missing
`PENDING_PAYMENTS_BLOCK_CLOSE` 409 + must use `lockTillForSession()` (session's own till, not MAIN) —
DESIGN + T2.1 fixed; +1 test. **W3** `opening_counts` already unconditionally required (both stacks) →
R3 conditional-422 was a no-op, simplified to "UI banner only, no new validation." **W4** per-rate tax
is inline in `buildShiftReport` (`lan_shift_report.go:197-269`), NOT reusable from `FormatShiftReport` —
must extract a `PerRateTaxBuckets` helper first; T5.4 + a regression test. **W5** `Str::uuid()` not
imported in `TillSessionService.php` (`HasUuids` only fills the PK) — must add `use …Str;` + explicit
generation; DESIGN + T2.3 fixed.

Minors: R5 line 947→1346 (947 is `settleFromWorkstation`, three Settled paths total); logAudit is a
MODEL method `$session->logAudit(...)` with underscore action names (`till_session_handover`, not
dotted); file-path citations corrected (`sync_pull_pos.go:1400`, `routes.go:253/265`); +codegen blocker
check that `settlement_snapshot` gets an `array` cast (T1.3 verify). Uncertain (deferred to execution):
the exact Cloud handover-accept endpoint shape lives partly in `backend/` sync routes not fully audited.

Tasks 34 → 35 (added T2.7 currency guard); tests 59 → 64.

## 2026-07-17 — Gap audit PASS 2 (re-read whole project; 3 refinements + 1 risk, NO new critical)

Second full pass against the real code after the pass-1 close-out. Verdict: **the 5 pass-1 gaps
covered every critical hole** — nothing structural left. Pass 2 surfaced only low-severity
refinements, all now folded in:

- **GAP-6 (R5 completeness) — closed.** `manualSettle()` (plan-032) sets `status=Settled` on its OWN
  path (`TillSessionService.php:947`), NOT via `close()`. Verified the ONLY caller of the service
  `close()` is `TillSessionController::close` (:137) — so repurposing `close()`→final touches nothing
  else. R5 now explicitly lists manual-settle alongside abandon/expire as chain-terminators that do
  NOT set `settlement_kind` (only `handover()`/`close()` do), so R1 correctly starts a fresh chain
  after them and they're never chain-aggregate members.
- **GAP-7 (chain report toggle) — closed.** The single-shift slip gates per-rate tax rows on
  `close_report_tax_breakdown` (`ShowTaxBreakdown`, `print_shift_report.go:121`). `FormatChainReport`
  (T5.8) must honor the SAME toggle (off → collapse per-shift + grand-total tax to one figure) or the
  two report kinds disagree. Added to T5.8 + its verify line.
- **GAP-8 (Go enum regen + SQLite type) — closed.** `omnify:gen` will regenerate the workstation Go
  enum `till_settlement_kind.go` (parity with existing `till_session_status.go`) — noted in T1.2 so
  nobody hand-writes it. `settlement_snapshot` is `type: Json` (confirmed keyword, `MenuPromotion.yaml:164`)
  → MySQL JSON on Cloud, **TEXT (JSON string) in SQLite**; store Cloud JSON verbatim on sync DOWN
  (T1.4).
- **GAP-9 (failover chain-break) — documented as accepted risk, NOT fixed.** If the workstation does a
  local handover then dies before the `till_session.handover` op syncs UP, and pos-web fails over to
  Cloud-direct for the next open, Cloud can't see the un-synced handover → starts a new chain (the
  chain splits). Low likelihood, LOW impact: no money lost (snapshots immutable, each part still
  aggregates correctly) — purely a reporting-granularity loss. Sync UP fires seconds after the local
  settle. Added to the risk table; explicitly NOT worth a distributed-lock fix.

Confirmed NON-gaps this pass: TillSessionPolicy exists with `close()` (:77) → adding `@handover` is
feasible; `reconcile()` scopes by `till_session_id=session.id` (:343) so a handover shift's attributed
payments are captured correctly; Omnify `Json` type is real (3 schema precedents).

## 2026-07-17 — Gap audit + close-out (5 gaps found against real code)

Re-reviewed the plan against the actual codebase. Found + closed 5 gaps:

- **GAP-1 (per-rate tax source) — CONFIRMED, fixed.** `reconcile()` returns only a single `revenue.tax`
  total (`SUM(tax_amount)`), NOT the 8%/10% split. The snapshot's `tax_breakdown` MUST come from
  `OrderTaxBreakdownAggregator::forOrders($sessionOrderIds)` (plan-043). DESIGN §Data model + T2.5 fixed;
  `$sessionOrderIds` = distinct `order_payments.customer_order_id where till_session_id`. +2 tests.
- **GAP-2 (workstation local lifecycle) — CONFIRMED, biggest fix.** Workstation OWNS open/close in SQLite
  (`handleLocalPosTillClose` routes.go:265, *"Workstation owns the lifecycle in SQLite"*). Original Phase 5
  only had sync-DOWN+print → offline handover would break. Added local `handleLocalPosTillHandover`,
  local close→final, local open chain-continuation, local snapshot builder (Phase 5 T5.1–T5.4). +2 tests.
- **GAP-3 (sync-UP ops) — CONFIRMED, fixed.** Only `till_session.{open,close,abandon}` ops existed; no
  handover op; close payload didn't carry chain/snapshot. Added `till_session.handover` op + extended
  close/open payloads (T5.5) + Cloud accept (T2.6). +1 test.
- **GAP-4 (snapshot convergence) — fixed with R7.** Workstation time-window vs Cloud attribution can
  diverge. R7: workstation snapshot is *provisional* (drives offline print); Cloud recomputes the
  *authoritative* snapshot on sync UP; workstation adopts it on sync DOWN (plan-044 write-back pattern).
  +1 GoInt test.
- **GAP-5 (reuse existing helper) — fixed.** R1 now cites `previousTerminalSessionForTill` (TillSessionService.php:216,
  exists) + the workstation `local_pos_till.go:865` latest-terminal query — reuse, don't rewrite.
  Backfill: pre-plan-046 sessions (null chain) = standalone chain-of-one → no backfill command needed.

Verified via code: `OrderTaxBreakdownAggregator::forOrders(iterable $orderIds)` (:47), `handleLocalPosTillClose`
(reconcileSession + `enqueueShiftClose` :184), sync ops map (`till_session.close` :192), Cloud workstation
till routes (`routes/api/workstation.php`). Scenarios 51 → 58; tasks 28 → 34 (T2.6 Cloud-accept + Phase 5 expanded from 4 to 9 local-lifecycle/sync-UP tasks).

## 2026-07-17 — Spec ↔ decisions reconciliation

The user's literal spec says the **final-close button appears from shift 2**. In the Phase-1
questions the user chose **"Ca 1 kết ca được (chuỗi 1 ca)"**. We follow the latter (DESIGN R6: both
buttons on every shift; a shift-1 final close = chain of one = today's 精算 behaviour). Hiding the
final-close button on shift 1 to match the literal spec is a trivial UI toggle — backend/model is
identical. Flagged for the user; awaiting no objection.

Locked decisions (Phase-1 answers):
1. **Blind re-count** opening float on handover (not auto-carry) — per-cashier variance isolation.
2. **Shift-1 final close allowed** (chain of one).
3. **Existing settle renamed → "kết ca cuối"** (final); handover is the new action.
4. **Aggregate report = per-shift blocks + grand total**.

Open (need ops sign-off): abandon/expire mid-chain ends the chain (R5); reprint policy.

## 2026-07-17 — Discovery: web best practices (X/Z reports)

Source: parallel Sonnet sub-agent (6 authoritative sources, adversarially verified).

- **X-report (点検 / read)** = non-destructive mid-shift snapshot, non-resetting, reprintable, covers
  last-settlement→now. **Z-report (精算 / settlement)** = official shift close, resets counters,
  irreversible, covers exactly one shift, is the cash-reconciliation record.
- **CRITICAL (verified FALSE claim):** Z-reports do **NOT** sum X-reports. Each Z covers only its own
  shift. A chain aggregate needs a **dedicated roll-up** that queries all chain shifts. → drove
  Decision 3 (Σ immutable snapshots).
- **Blind count** (count cash before seeing expected) = industry best practice, kills confirmation
  bias. → Decision 1.
- **Opening float re-declaration per shift** = per-cashier accountability; auto-carry merges variance.
  → Decision 1 (blind recount).
- **Per-rate tax lines** required (軽減税率 8/10 + インボイス). Aggregate must sum **per rate bucket**,
  never merge. → R4.
- **Failure modes captured as R-rules:** double-count when aggregate includes post-Z transactions
  (bind to shift at finalization → R2); void/refund across shift boundaries (refund reduces current
  shift, original immutable → R2 + edge test); Z-close race (DB lock → risk table); float not
  re-declared at handover (→ R3); tax re-derivation drift (aggregate from immutable snapshot, not live
  tax table → Decision 3).
- Sources: mobiletransaction.org (X/Z defs), MS Dynamics 365 Commerce (shift lifecycle,
  declare-start-amount, blind close, HQ aggregate ≠ POS Z), Lightspeed (X/Z field schema), Air Regi JP
  (点検/精算/売上報告 three-tier — confirms aggregate is a separate report), revenueregister (blind
  count), innovorder (X interim / Z fiscal).

## 2026-07-17 — Discovery: project domain

Source: parallel Sonnet sub-agent (10 files read).

- **TillSession** = one open→settle cycle on one till; status `open→closing→settled/abandoned/expired`;
  schema `schemas/Backend/Till/TillSession.yaml` (32 props). **No chain/grouping concept exists** — each
  session standalone. → Decision 2 (add chain fields, no new table).
- **Close report**: Cloud `TillSessionService::reconcile()` (attribution by `order_payments.till_session_id`)
  + workstation time-window (`reconcileSession`, disjoint per session). ESC/POS `FormatShiftReport`
  (`print_shift_report.go`) built by `buildShiftReport(session_id)` from local SQLite
  (`lan_shift_report.go`, `POST /api/lan/print/shift-report`). No Cloud PDF. → reuse for single-shift
  handover slip; mirror the pattern for `FormatChainReport`.
- **Service methods** (`TillSessionService.php`): open 473, saveDraft 634, close 733, abandon 1030,
  manualSettle 1241, reconcile 1390. → handover mirrors close; chainSummary is new.
- **pos-web**: `close-page.tsx` (`useCloseShift`, prints `printShiftReport({session_id})`),
  `open-page.tsx` (denomination count + plan-044 gap panel), `shift-gate.tsx`.
- **Conventions**: state transitions in `DB::transaction` + `Till::lockForUpdate`; Omnify schema →
  `omnify:gen`, never hand-edit generated; workstation migrations numbered SQL under
  `internal/store/migrations/` (guard against duplicate numbers — plan-045 lesson).
- **Idioms**: `ShiftReportInfo` is a pure data carrier, `FormatShiftReport` side-effect-free (byte-gen)
  → `FormatChainReport` follows the same split; report toggles come from `s.shopSetting(key,default)`;
  gap-preview's "most recent terminal session" pointer is exactly what R1 (chain continuation) keys off.
- **Gaps → design responses**: no chain concept (→ new fields); reconcile per-session only (→ Σ
  snapshots); workstation window vs Cloud attribution divergence (→ both sum the SAME per-session
  snapshot); opening float of handover shift (→ blind recount, R3); single-shift handover slip
  (→ reuse `handleLANPrintShiftReport`, any session_id).

## 2026-07-17 — Plan created
Initial scaffold via `/mcp__omnify__plan`. README + DESIGN + TESTS written; user confirmed intent;
TASKS + NOTES drafted. Status `draft` — awaiting approval before implementation.
