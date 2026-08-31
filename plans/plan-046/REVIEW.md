# Plan 046 — Code Review

> Reviewer: automated unbiased review of `feature/plan-046-shift-handover-chain-reports`
> against DESIGN.md / TASKS.md / TESTS.md. No files modified except this one.

## Summary

**has-critical** — one critical functional bug (the R7 snapshot write-back never
executes due to a double-unwrap) plus one shipped-but-failing workstation test
(`TestFormatChainReport_ChainOfOne`, which fails `make test`). The backend is
solid (Till suite 348 green, chain suite 8 green, correct R1/R2/R4/R8/G1/G2/G3
implementation). The main risks are on the workstation stack and in **test
coverage**: several core TESTS.md scenarios (R8 currency invariance, per-rate
bucket sum, ≥10-shift ordering, snapshot immutability, authorization, the entire
local Go chain lifecycle, all pos-web vitest) have **no test at all**.

## What is correct (verified)

- **R1 chain continuation** — `open()` reads `$prev = previousTerminalSessionForTill($till)`
  and accesses `$prev['session']->settlement_kind` (array, NOT `$prev?->`). Correct per P6-1.
  (`TillSessionService.php:529-539`)
- **R2 write-once** — snapshot written inside the settle tx via `settleShift()`; the
  `settleFromWorkstation` idempotent early-return (`:1120-1121`) re-returns the frozen
  row through `shape()`, so recompute happens only on the first accept.
- **R4 grand total** — `sumChainSnapshots()` sums per rate BUCKET (`byRate` keyed by rate,
  `ksort SORT_NUMERIC`), cash/revenue straight-add, no re-round. (`:1035-1066`)
- **R8 currency guard** — `branchHasOpenChain()` OR'd into all 3 config sites (currency L312,
  tax-mode L334, rounding L367); boundary (final close → allowed) holds because it keys on
  `settlement_kind === Handover`. (`ShopOrderSettingsController.php:314/336/369/621-643`)
- **G1** — `chainSummary` filters `whereIn settlement_kind [handover,final]` + null-guards every
  `$snap['cash'] ?? []`; abandoned-member test passes. (`:975-987`)
- **G2 tie-break** — deterministic `end DESC, (int)chain_sequence DESC, id DESC` closure on the
  mapped array collection (P6-4). (`:241-248`)
- **G3 nesting** — `TillController::shape()` puts `settlement_snapshot` INSIDE `data`, cast as
  `array` (JSON object). (`TillController.php:489-495`, base model cast `:199`)
- **P5-5 int sort** — `chainSummary` `->sortBy(fn ($s) => (int) $s->chain_sequence)`; Go
  `buildChainReport` / `latestTerminalChainForTill` use `CAST(chain_sequence AS INTEGER)`. No lexical sort.
- **Schema** — YAML has header + per-property comments; generated migration/model/requests are
  untouched by hand; `chain_sequence` correctly renders VARCHAR (P5-5 confirmed).
- **Workstation local lifecycle** — INSERT writes chain cols (P7-A), all reader SELECTs extended,
  settle body shared by handover/final clears the till lock, enqueue threads chain fields at the
  enqueue site (P7-B), handover POSTs to `/close` with `settlement_kind` (P7-C), pending-deps guard +
  manifest mirrored (P7-D — existing close-sync tests still green).
- **Migration 044 + repair + unique-prefix test** — apply cleanly, `040` allowlisted, all store tests green.
- **PerRateTaxBuckets extraction** — byte-identical SQL to the inline block (`paidPaymentsWindow` predicate),
  shared by Z-report + snapshot (P5-6, W4). No mass-assignment leak (POS open computes chain server-side).

## Issues

| Severity | File:line | Description | Suggested fix |
|----------|-----------|-------------|---------------|
| **critical** | `workstation-app/internal/service/sync_service.go:1585-1594` | **R7 snapshot write-back never runs — double-unwrap.** `cloudPost` already returns the inner `data` map (`:2934-2938` returns `wrap.Data`), proven by `handlePaymentAttribute` reading `resp["till_session_id"]` directly (`:1401`) and its passing test. But `settleSyncUp` reads `resp["data"].(map[string]any)` then `data["settlement_snapshot"]` — `resp["data"]` is `nil`, so the snapshot is **never adopted onto the local row**. The workstation keeps its provisional snapshot forever, defeating the entire T5.5 / R7 convergence mechanism. Verified by isolated repro. | Read `resp["settlement_snapshot"]` directly (single unwrap): `if snap, ok := resp["settlement_snapshot"]; ok && snap != nil { … }`. (Same latent double-unwrap exists at `handleTillSessionOpen:1458`, but there it is harmless because cloud_id == local id makes the write a no-op — out of plan-046 scope.) |
| **warning** | `workstation-app/internal/service/print_chain_report_i18n.go:29,33` (fails `print_chain_report_test.go:71`) | **`make test` is RED.** JA labels collide: `Shift: " シフト"` (block prefix) and `ShiftCount: "%d シフト"` (header count line). `TestFormatChainReport_ChainOfOne` counts `" シフト"` and finds 2 (the "1 シフト" header + 1 block) instead of 1, failing the chain-of-one parity assertion. T7.2/T8.2 are marked `[x]` but the suite does not pass. | Either change the JA header label so it does not contain `" シフト"` (e.g. `ShiftCount: "シフト数 %d"` or `"%d件"`), or make the test count a block-unique anchor (the `(handover)`/`(final)` kind token or `L.Shift` at line-start) instead of the bare substring. `TestFormatChainReport_Aggregate:43` (`>= 2`) masks the same collision. |
| **warning** | backend `tests/` (no file) | **R8 / C1 currency-invariance has NO test.** T2.7's Verify clause and TESTS.md "R8 currency invariance" + "R8 boundary" scenarios (handover → PATCH currency → 409; final close → 200) are unimplemented. `ShopOrderSettingsTest.php` was not touched. This is a high-impact money-correctness invariant shipping untested. | Add a Pest feature: open → handover → `PATCH /shops/{slug}/settings/order {currency_code}` asserts 409 `CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT` (+ rounding); and a normal final close → same PATCH → 200. |
| **warning** | `workstation-app/internal/{handler,service}/*_test.go` (no file) | **No Go test for the local chain lifecycle.** T5.1-T5.4 `[Go]` + P7-A ("continued-open row has chain_id set + chain_sequence=2 in SQLite") + the T5.5 `[GoInt]` snapshot-adopted / P5-7 skew scenarios are untested. The only new Go tests are `FormatChainReport` (service) + migration/repair (store). This is why the critical double-unwrap above was not caught. | Add handler tests for `handleLocalPosTillHandover` (settles + kind + snapshot + till cleared + enqueued), `handleLocalPosTillOpenSession` continuation (asserts SQLite `chain_id`/`chain_sequence=2`), and a `settleSyncUp` GoInt test with an httptest server returning `{"data":{"settlement_snapshot":{…}}}` asserting the local row adopts it (would have caught the double-unwrap) + the absent-snapshot ACK-with-provisional skew case. |
| **warning** | `pos-web/src/**/*.test.*` (no file) | **No pos-web vitest for plan-046 (T7.3).** No test references `handover`, `useChainSummary`, `printChainReport`, or `continues_chain`; no browser smoke. T7.3 is marked `[x]`. | Add a vitest for `tillService.handover`/`chainSummary` + `workstationPrintService.printChainReport` (asserts POST `/api/lan/print/chain-report` with `{shop_slug, chain_id}`), mirroring the plan-044 panel test. |
| **info** | `pos-web/src/i18n/{ja,en,vi}.json:590` + `close-page.tsx` | **Dead i18n key + missing UI element.** `shift.badge.chain` is defined in all 3 locales but never referenced. DESIGN screen #1 and the TESTS Browser scenario require the "Ca N" chain badge on `/shift/close`; it is not rendered. | Render the badge in `close-page.tsx` using `session?.chain_sequence` + `t("shift.badge.chain", {seq})`, or drop the unused key if the badge is intentionally deferred. |
| **info** | `pos-web/src/app/shift/open-page.tsx:192-199` | **Chain continuation uses a transient toast, not the persistent banner** DESIGN screen #2 specifies ("show a banner 'Tiếp tục chuỗi — ca N'"). Functionally surfaces the info but a toast disappears; the blind-recount is only implicit (opening_counts already required). Minor fidelity deviation. | If the persistent banner matters to ops, render it from `res.data.continues_chain` in the open form (not a toast). Otherwise document the toast choice. |
| **info** | `backend/app/Http/Controllers/Api/V1/Shop/ShopOrderSettingsController.php:632-641` | `branchHasOpenChain` calls `previousTerminalSessionForTill` per till, which `->get()`s ALL terminal sessions for that till then sorts in PHP (unbounded over a till's lifetime). Pre-existing plan-044 pattern; fires only on a rare admin config change, so acceptable, but worth noting as it now runs per-till per config PATCH. | Optionally bound the resolver query (`->latest('closed_at')->limit(N)`), or accept as-is given the low call frequency. |
