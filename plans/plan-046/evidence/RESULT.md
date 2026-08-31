# Plan-046 — E2E test evidence (curl + Playwright)

Environment: backend docker `:5400` (MySQL), pos-web dev `:5440`, shop `hq`, auth
`Bearer dev:<console_user_id>` (LOCAL SSO bypass) + `X-Shop-Slug: hq`. Real data —
every row below is a live API response / DB read, saved alongside this file.

## curl — API data evidence

| # | Scenario | Assertion | Result | Evidence |
|---|----------|-----------|--------|----------|
| 1 | Open shift 1 | `chain_sequence=1`, `continues_chain=false`, fresh `chain_id` | ✅ PASS | `01-open-shift1.json` |
| 2 | **Handover** shift 1 | `status=settled`, `settlement_kind=handover`, `chain_id` unchanged | ✅ PASS | `02-handover-shift1.json` |
| 3 | Open shift 2 | `continues_chain=true`, `chain_sequence=2`, **same** `chain_id` | ✅ PASS | `03-open-shift2.json` |
| 4 | **Final close** shift 2 | `settlement_kind=final`, `chain_summary_ready=true` | ✅ PASS | `04-final-close-shift2.json` |
| 5 | **Chain summary** | 2 shifts `[1,2]` `[handover,final]`, `chain_open=false`, **grand_total.counted = Σ per-shift = 106,000** | ✅ PASS | `05-chain-summary.json` |
| A | Snapshot shape | 6 keys: `cash / orders / revenue / tenders / opening_float / tax_breakdown` | ✅ | `A-snapshot-structure.txt` |
| A2 | **Snapshot 2-source (GAP-1)** | with 8%+10% taxed sales → `tax_breakdown=[{rate:8,tax:80,taxable:1000},{rate:10,tax:200,taxable:2000}]` (buckets SEPARATE, from `OrderTaxBreakdownAggregator`); `revenue` from `reconcile()` | ✅ PASS | `A2-snapshot-tax-populated.txt`, `A2-handover-with-tax.json` |
| B | Chain of one | open → final directly → summary has **1** block, kind `final` | ✅ PASS | `B-chain-of-one.json` |
| C | **R8 currency guard** | handover (chain awaiting continuation) → `PATCH /shops/hq/settings/order {currency_code}` → **HTTP 409 `CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT`** | ✅ PASS | `C-r8-currency-blocked.json` |
| D | Cross-branch isolation | chain of `hq` requested with `X-Shop-Slug: sby` → **HTTP 404** | ✅ PASS | `D-cross-branch-404.json` |

Also observed (correct guard behaviour): handover with cash variance and **no reason
→ 422 `VARIANCE_REASON_REQUIRED`** (before adding a `closing_note`).

## Playwright — UI image evidence

pos-web `/shop/hq/shift/close` with an open shift continuing a chain (chain_sequence=2),
authed via injected `pos_device_token` (Cloud mode). **0 console errors** in all shots.

| Screenshot | Shows |
|------------|-------|
| `ui-01-close-page.png` | Close screen with BOTH settle buttons (**引き継ぎ** handover + **精算** final) + the chain badge **「チェーン - ca 2」** + no JS errors |
| `ui-02-handover-confirm.png` | Handover confirm dialog **「シフトを引き継ぎますか？」** + counted/expected/variance review + 「締めて引き継ぎ」 |
| `ui-03-final-confirm.png` | Final-close confirm dialog **「チェーンを精算しますか？」** + 「締めて精算」 |

## Verdict

Every new plan-046 surface verified end-to-end against a live backend with real data +
UI screenshots: chain open/continue/final, aggregate summary (grand total = Σ snapshots),
per-rate tax two-source snapshot (GAP-1), R8 currency invariance, cross-branch isolation,
and the pos-web handover/final UI + chain badge + confirm dialogs. All ✅.

Backend automated coverage complements this: `TillSessionChainTest` (8), R8 guard (2),
`WorkstationTillSyncUpTest` chain sync (2), `FormatChainReport` Go (3), migration guards.
