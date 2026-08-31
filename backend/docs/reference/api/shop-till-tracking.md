# Shop Till Tracking API

Plan-036 manager-only read endpoints for the cashier-shift tracking surface
under `/api/v1/shops/{shopSlug}/till/*`. All four endpoints share the same
auth + branch-isolation contract:

- `sso.auth` middleware (parent group on `/v1`)
- `ResolveShopFromSlug` middleware stamps `request.attributes.shop` (Branch),
  `shop_id`, `organization_id`
- `ShopTillTrackingPolicy` virtual gate (`Gate::define` mapping in
  `AppServiceProvider::boot`) — manager+ only:
  `shop-manager` (branch-scoped) | `org-manager` | `org-admin`
- `staff` / `shop-staff` → **403 `FORBIDDEN_NOT_MANAGER`**
- Cross-shop per-session access → **404 `SESSION_NOT_FOUND`** (never leak existence)

## Endpoint inventory

| # | Method | Path | Auth | Purpose |
|---|--------|------|------|---------|
| 1 | GET | `/shops/{shopSlug}/till/dashboard?trend_days=14` | manager+ | KPIs + variance trend + recents (Redis 5s) |
| 2 | GET | `/shops/{shopSlug}/till/sessions?from=&to=&...` | manager+ | Paginated history (or CSV via `?export=csv`) |
| 3 | GET | `/shops/{shopSlug}/till/sessions/{id}` | manager+ | Full reconciliation + audit trail |
| 4 | GET | `/shops/{shopSlug}/till/sessions/{id}/z-report.pdf` | manager+ | レジ締めレポート PDF binary |

## 1. Dashboard

Query params:

| Field | Type | Required | Default | Notes |
|-------|------|----------|---------|-------|
| `trend_days` | int (7/14/30/90) | No | 14 | 422 `INVALID_TREND_DAYS` outside set |

Response 200:

```json
{
  "data": {
    "currency_code": "JPY",
    "tills": [{ "id": "...", "name": "MAIN", "status": "open", "current_session": { ... } }],
    "kpis": {
      "open_till_count": 2,
      "total_till_count": 3,
      "settled_today": { "count": 8, "gross_total_amount": 420310, "currency_code": "JPY" },
      "variance_today": { "net_amount": 120, "abs_max_session_id": "...", "abs_max_amount": 3500 },
      "stale_count": { "open_overdue": 2, "expired": 0 }
    },
    "variance_trend": [ ... ],
    "recent_settlements": [ ... ],
    "force_abandon_activity_30d": [ ... ]
  },
  "meta": { "stale_threshold_hours": 48, "warning_hours": 24, "trend_days": 14, "cached_at": "..." }
}
```

`warning_hours` (`pos.shift.manager_view.overdue_hours`, 24) is the band
`stale_count.open_overdue` is computed at — and the same band
`GET /pos/till/sessions/stale?filter=open_overdue` lists at, so the KPI and the
list always describe the same rows. `stale_threshold_hours`
(`POS_SHIFT_STALE_TIMEOUT_HOURS`, 48) is the reaper cutoff, reported for
reference only; it is not what the KPI counts. `stale_count.expired` excludes
expired shifts that already carry a `closed_at`.

Caching: Redis key
`shop:{branch.id}:till:dashboard:{trend_days}:{floor(unix/5)}`, TTL 5s.
Redis errors fall through to direct DB (logged as `redis_cache_failed`).

## 2. Sessions list

Query params:

| Field | Type | Required | Default | Notes |
|-------|------|----------|---------|-------|
| `from` | YYYY-MM-DD | No | today − 7d | |
| `to` | YYYY-MM-DD | No | today | `from > to` → 422 `INVALID_DATE_RANGE` |
| `till_id[]` | uuid[] | No | all | |
| `status[]` | enum[] | No | all | open / closing / settled / abandoned / expired |
| `opener_id` | uuid | No | all | |
| `variance` | enum | No | all | none / over / short / out_of_tolerance |
| `force_abandoned` | bool | No | any | |
| `per_page` | 1..100 | No | 25 | |
| `page` | int | No | 1 | |
| `sort` | enum | No | `opened_at_desc` | `opened_at_desc` / `_asc` / `variance_abs_desc` |
| `export` | "csv" | No | — | When set returns text/csv attachment |

Date range hard-capped at 365 days → 422 `INVALID_DATE_RANGE`.

## 3. Session detail

Path param `{id}` → `TillSession` (explicit binding in
`routes/api/shops/till.php`). Cross-shop returns 404.

Response includes:

- `links.z_report_available` — only true when status ∈ {settled, expired, abandoned}
- `reconciliation.by_tender_category` — ordered cash → card → qr → emoney
- `audit_trail[]` — newest first, max 100 rows

## 4. Z-report PDF

- `Content-Type: application/pdf`
- `Content-Disposition: attachment; filename="z-report-{session_code}.pdf"`
- Status ∈ {open, closing} → 422 `Z_REPORT_NOT_READY`
- Template: `resources/views/till/z-report.blade.php` (18-section レジ締め standard)
- Tender ordering pinned to the workstation `reconcileSession()` Go function
- Manager intervention block rendered when `force_abandoned=true` OR `expired_at != null`

## Notes

- Dashboard query is 8 SQL statements at most (asserted under the `≤ 6` guard
  budget once additional eager-load optimisations land — see plan-036 TASKS T2.1).
- Listing endpoint uses correlated subqueries for `gross_revenue_amount` /
  `payment_count` so the row count stays N+0 regardless of page size.
- The detail page reuses `TillSessionService::reconcile()` from plan-030 for
  open/closing-state expected-side recomputation — never duplicate the math.
