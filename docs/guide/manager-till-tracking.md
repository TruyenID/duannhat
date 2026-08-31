---
title: Manager till tracking (plan-036)
category: guide
tags: [pos, cashier, shift, till, manager, dashboard, z-report, ops]
summary: How a shop manager monitors live cashier shifts, drills into historical reconciliation, prints Z-reports, and escalates stuck shifts.
related:
  - guide/cashier-shift-recovery.md
  - ../backend/docs/reference/api/shop-till-tracking.md
---

# Manager till tracking runbook

> Part of the payments cluster — the map of all twelve docs is [Payments — where to start](payments-overview.md).

> Plan-036 adds the manager's tracking surface on top of plan-030 (cashier
> shift state machine), plan-031 (currency mid-shift guard), and plan-032
> (stale shift reaper). It does **not** add new mutations — every "do
> something" link on these pages re-uses plan-032's `force-abandon` /
> `manual-settle` endpoints. The four pages below are pure read surfaces.

## Pages at a glance

| Page | Path | Refresh | Who can open |
|---|---|---|---|
| Dashboard | `/shop/{slug}/till` | polled 5s (pauses when tab hidden) | manager+ (server enforces) |
| History | `/shop/{slug}/till/sessions` | on-demand | manager+ |
| Detail | `/shop/{slug}/till/sessions/{id}` | on-demand | manager+ |
| Z-report PDF | download button on Detail page | n/a — blob download | manager+ |

"manager+" = `shop-manager`, `org-admin`, or `brand-admin` for the resolved
shop. A plain `staff` (cashier) role gets HTTP 403 inline-alert. The role
check is enforced in `ShopTillTrackingPolicy` — bypassing the sidebar by
typing the URL still 403s.

## 1. Dashboard — what to scan first

Open `/shop/<slug>/till` at the start of every shift. The page shows
four KPI cards across the top:

| KPI | Reads… | Action if abnormal |
|---|---|---|
| **Open tills** (`open/total`) | how many tills currently have an `open` shift | If `open=0` mid-day, no cashier has logged in — call the floor. If `open > expected`, two cashiers may be sharing a till. |
| **Settled today** (count + gross) | terminal shifts closed today | Drives the day's revenue picture. Click → jumps to History filtered by `from=today,to=today,status=settled`. |
| **Variance today** (signed net) | sum of `cash_variance_amount` for today's settled shifts | **Green** = balanced; **muted** = ¥0 (typical); **red** = short (cash missing); **emerald** = over (rare — investigate too). Drill into the abs-max session via Recent Settlements below. |
| **Stale shifts** | open shifts past the 24h warning band + expired shifts still unresolved (an expired shift that already carries a `closed_at` no longer counts) | > 0 means at least one till is stuck. Click → History `?tab=stale`, then use the [Cashier shift recovery runbook](cashier-shift-recovery.md) to pick force-abandon vs manual-settle. |

The Ca treo tab lists at the **same** 24h band the KPI counts at
(`pos.shift.manager_view.overdue_hours`), so the number and the list can never
disagree — see `TillStaleParityTest`. Do not confuse it with
`POS_SHIFT_STALE_TIMEOUT_HOURS` (48h), which only governs the auto-expire
reaper; the reaper additionally skips any shift with a payment in the trailing
6h, so "listed here" and "will be reaped" are deliberately different sets.

Underneath the KPIs:

- **Per-till status row** — one card per till. Badge shows `open` /
  `idle`. For an open shift, the card displays opener name, session
  code, hours elapsed, and a stale-warning badge once it crosses 24h
  (amber) or 40h (red).
- **Variance trend chart** — signed line chart (¥) over the period
  selector (7 / 14 / 30 / 90 days, default 14). The zero reference
  line is the eye anchor: a sustained dip below zero means the shop is
  systematically short.
- **Recent settlements** — last 5 settled / abandoned / expired shifts.
  Click any row → jumps to that shift's Detail page.
- **Force-abandon activity (30d)** — plan-032 card showing which
  manager has been force-abandoning shifts and how often. A spike
  signals either a broken cashier process or a single manager misusing
  the override.

The dashboard polls every 5s. The whole envelope is Redis-cached
server-side for 5s, so two managers watching at once costs one query.
Polling pauses when the tab is hidden (no background churn).

## 2. History — finding any shift

Open `/shop/<slug>/till/sessions`. Two top tabs:

- **Tất cả / All** (default) — full date-range history with filter bar
- **Ca treo / Stale** — the plan-032 stale-only view (reused)

### Filter bar (All tab)

- **From / To** — date range. Backend caps to 365 days per request.
- **Status chips** — toggle: `open`, `closing`, `settled`, `abandoned`,
  `expired`. Multi-select; empty = all statuses.
- **Export CSV** — downloads the current filter as a `.csv`. Header row +
  one line per shift, including signed variance and per-tender rollups.
- (Till multi-select, opener combobox, variance chip — surfaced in a
  later polish pass; the v1 filter set above handles ~95% of manager
  queries.)

The table itself follows the standard admin-web data-table layout:
session code (green link → Detail), till, opener, opened/closed times,
duration, status badge, gross revenue, signed variance (colored).

### Deep links

- `?filter=open_overdue` (plan-031 currency-settings link) — auto-
  selects the Stale tab. Kept working for backward compatibility.
- `?tab=stale` — same destination, explicit form.
- KPI clicks from the Dashboard set the right filter combo via URL.

## 3. Detail — what each section means

Open any session by clicking its code. Sections from top to bottom:

| Section | Reads… |
|---|---|
| Header | Session code, status badge, till name, business date, currency code. Action buttons (Force-abandon, Manual-settle, Z-report) appear conditionally — see below. |
| Manager intervention alert | Only shown when `force_abandoned=true`. Shows the reason code + reason detail + the manager who pressed the button. |
| Opening + Closing denomination grids (side-by-side) | Per-denomination quantity × value, with grand totals. Closing card is empty until the shift is `settled` / `abandoned` / `expired`. |
| Cash variance summary | `expected_cash`, `counted_cash`, signed `cash_variance`. The variance is what feeds the dashboard KPI. |
| Cash events | Pay-ins, payouts, manual adjustments — chronological list with amount + reason. Empty when no events occurred (most shifts). |
| Reconciliation table | Per-tender breakdown grouped by category (cash → card → QR → e-money). Each row: expected, declared, signed variance, optional cashier-entered variance reason. |
| Audit trail | Last 100 audit-log entries for this session (`till_session_opened`, `till_session_settled`, `till_session_force_abandoned`, etc.). Actor name shown when available. |

### Action buttons on Detail (T7.4)

The page conditionally renders manager-only action buttons depending on
session status:

| Status | Buttons shown | What happens |
|---|---|---|
| `open` / `closing` | **Force abandon** + Z-report (disabled) | Opens plan-032 `ForceAbandonDialog`. Behaviour identical to History page row action — same reason-code rules apply. |
| `expired` | **Manual settle** + Z-report (enabled) | Opens plan-032 `ManualSettleDialog`. Manager enters paper count + tender details to convert `expired → settled`. |
| `settled` / `abandoned` | Z-report (enabled) only | Terminal states — no further mutation possible. |

On success, both dialogs invalidate the Detail query + the Dashboard
query so the new state reflects immediately.

## 4. Z-report PDF

The **Print Z-report** button on the Detail header downloads a PDF for
the selected session. The button is:

- **Disabled** when status ∈ `{open, closing}` (the shift hasn't ended,
  there's nothing to print). The tooltip explains why.
- **Enabled** when status ∈ `{settled, abandoned, expired}`. Click →
  blob download → file name `z-report-<session_code>.pdf`.

Content follows the Japanese レジ締めレポート standard
([reference](../../backend/docs/reference/api/shop-till-tracking.md#4-z-report-pdf)):
store identification, period, opening float, gross/net/tax breakdown
(10% + 8% per インボイス制度), discount/void/refund totals, per-tender
sales (cash → card → IC/e-money → QR → gift card), expected vs
declared cash with denomination breakdown, signed cash variance,
non-cash total, transaction count by tender, average transaction
value, manager signature line, print datetime + Z-number.

If status is `expired` or `force_abandoned`, the PDF adds a "manager
intervention" block above the signature line documenting who and why —
auditor-friendly.

## 5. Escalation flowchart

When the Dashboard's Stale KPI is non-zero:

```
            Stale shift surfaced
                    │
       ┌────────────┴────────────┐
       │                         │
   ≤ 48h old                  > 48h old
       │                         │
   Manager intent?            Already expired?
       │                         │
  ┌────┴────┐               ┌────┴────┐
  │         │               │         │
 Yes        No             Yes        No
  │         │               │         │
Force-     Wait —      Manual-     Wait —
abandon    scheduler   settle      scheduler
(now)      auto-       (recover    will expire
           expires     count from  it within
           at 48h      paper)      1 hour
```

Same triage as [cashier-shift-recovery](cashier-shift-recovery.md) —
this page just gives the manager a faster on-ramp.

## 6. Performance + load notes

- Dashboard polls every 5s. With Redis cache TTL = 5s, two managers on
  one shop generate one backend query per 5s (cache absorbs 50%+ in
  practice).
- History list paginates; default 25 rows per page. CSV export honours
  the current filter set (server-side render — no in-browser
  generation).
- Strict-mode N+1 guard is enabled on the plan-036 endpoints in tests
  (`tests/Pest.php` — `Model::preventLazyLoading()` scoped to the four
  test files). Any future regression that lazy-loads a relation will
  throw `LazyLoadingViolationException` before reaching CI.

## 7. Common pitfalls

- **Variance KPI looks wrong** — confirm the till's
  `variance_tolerance_amount` is non-zero. A zero tolerance flags every
  ¥1 difference as out-of-tolerance. Edit on `/shop/<slug>/tills/<id>`.
- **Currency mid-shift error pointing here** — when admin gets the
  `409 CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT` from the Settings page
  (plan-031), the deep link drops them on `/till/sessions?filter=open_overdue`
  which now lands on the plan-036 Stale tab. Same workflow as before
  plan-036; only the wrapping tabs are new.
- **Z-report button disabled on a "settled" shift** — check
  `links.z_report_available` in the API response. The flag is true for
  `settled`, `abandoned`, `expired`; if false, the server denied
  rendering (very rare — typically a dompdf failure logged as
  `[pos.till.tracking] z_report_render_failed`).
- **Sidebar links missing** — the three nav entries (Cashier Tills /
  Shift History / Stale Shifts) live under the shop sidebar's "Floor"
  group. If they don't appear, the user isn't on a `/shop/...` URL or
  the layout's nav-groups array wasn't regenerated after the i18n
  keys landed.

## 8. Where to find the code

- Backend service: `backend/app/Services/Shop/ShopTillTrackingService.php`
- Backend controller: `backend/app/Http/Controllers/Api/V1/Shop/ShopTillTrackingController.php`
- Backend policy: `backend/app/Policies/ShopTillTrackingPolicy.php`
- Routes: `backend/routes/api/shops/till.php`
- Z-report template: `backend/resources/views/till/z-report.blade.php`
- Frontend pages: `web/admin/src/app/shop/[shopSlug]/till/`
- Frontend service: `web/admin/src/services/shop-till-tracking-service.ts`
- Frontend hooks: `web/admin/src/hooks/api/use-shop-till-tracking.ts`
- API reference: [shop-till-tracking](../../backend/docs/reference/api/shop-till-tracking.md)
