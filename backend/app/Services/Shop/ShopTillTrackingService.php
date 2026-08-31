<?php

namespace App\Services\Shop;

use App\Exceptions\Till\ZReportNotReadyException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\OrderPayment;
use App\Models\Till;
use App\Models\TillCashEvent;
use App\Models\TillSession;
use App\Models\TillSettlementTenderDetail;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Services\Order\Contracts\OrderTaxBreakdownReads;
use App\Services\Pos\TillSessionService;
use App\Services\Till\TillSessionListFilter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plan 036 — Manager Till Tracking read service.
 *
 * Owns the four read endpoints behind /shops/{shopSlug}/till/*:
 *
 *   - dashboard()          : KPI cards + tills live + variance trend +
 *                            recent settlements + 30d force-abandon activity.
 *                            5s Redis cache; single hand-tuned SQL aggregate
 *                            (NO per-KPI ORM count() calls).
 *   - listSessions()       : Paginated session history with date / till /
 *                            status / opener / variance / force-abandoned
 *                            filters + per-row gross_revenue subquery.
 *   - loadSessionDetail()  : Full eager-loaded session + audit trail +
 *                            reconciliation snapshot (reuses TillSessionService
 *                            math — never re-derives).
 *   - renderZReport()      : Returns raw PDF binary via barryvdh/laravel-dompdf
 *                            and the till/z-report Blade template (Japanese
 *                            レジ締めレポート 18-section layout).
 *
 * All four methods assume the caller has already passed the
 * ShopTillTrackingPolicy gates — the service does NOT re-authorize. Branch
 * isolation on the per-session methods is the controller's job; the service
 * assumes whatever session it receives is in-scope.
 */
class ShopTillTrackingService
{
    /**
     * Manager-view thresholds, from `config('pos.shift.manager_view.*')`.
     *
     * `overdueHours()` is the 24h warning band that defines what the "Ca treo"
     * KPI counts — and, since #1220, what `GET /pos/till/sessions/stale
     * ?filter=open_overdue` lists. The two used to disagree (24h here, the
     * reaper's 48h there), so the KPI counted shifts no filter could show.
     * TillStaleParityTest pins them together; read the config, never re-inline
     * a literal. Both reads are defaulted because a stale config cache would
     * otherwise yield `subHours(null)` → 0h → every open shift is "overdue".
     *
     * Deliberately not env-tunable — see the note in config/pos.php.
     */
    private function overdueHours(): int
    {
        return (int) config('pos.shift.manager_view.overdue_hours', 24);
    }

    private function criticalHours(): int
    {
        return (int) config('pos.shift.manager_view.critical_hours', 40);
    }

    public function __construct(
        private readonly TillSessionService $tillSessions,
        // #962 (7b) — cổng Ordering công bố thay cho `OrderTaxBreakdownAggregator`.
        private readonly OrderTaxBreakdownReads $taxBreakdown,
    ) {}

    // =========================================================================
    //  1. Dashboard
    // =========================================================================

    /**
     * Top-level entry: Redis cache wrap around dashboardUncached().
     * Cache key bucket-stamps the 5s window so a manager-tab burst within
     * one bucket all returns the same JSON without re-hitting the DB.
     *
     * Redis failure falls through to dashboardUncached() — cache is an
     * optimisation, not a correctness requirement.
     *
     * @return array<string, mixed>
     */
    public function dashboard(Branch $branch, int $trendDays): array
    {
        $startedAt = microtime(true);
        $bucket = (int) floor(now()->getTimestamp() / 5);
        $key = "shop:{$branch->id}:till:dashboard:{$trendDays}:{$bucket}";

        // Track whether the closure executed. The remember() callback runs only
        // on a cache miss; flipping the flag inside the closure is the only
        // observation point that distinguishes hit from miss without an extra
        // Redis round-trip. Captured by reference so the outer log sees it.
        $cacheHit = true;

        try {
            $result = Cache::remember(
                $key,
                5,
                function () use (&$cacheHit, $branch, $trendDays) {
                    $cacheHit = false;

                    return $this->dashboardUncached($branch, $trendDays);
                },
            );
        } catch (\Throwable $e) {
            Log::warning('[pos.till.tracking] redis_cache_failed', [
                'shop_id' => $branch->id,
                'error' => $e->getMessage(),
            ]);
            $cacheHit = false;
            $result = $this->dashboardUncached($branch, $trendDays);
        }

        Log::info('[pos.till.tracking] dashboard_served', [
            'shop_id' => $branch->id,
            'trend_days' => $trendDays,
            'cache_hit' => $cacheHit,
            'ms_elapsed' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }

    /**
     * Direct DB path. Emits ≤ 6 SQL statements (asserted in performance
     * test). The shape is the canonical Dashboard response — the cache
     * stores this map verbatim.
     *
     * @return array<string, mixed>
     */
    public function dashboardUncached(Branch $branch, int $trendDays): array
    {
        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();
        $trendStart = $now->copy()->subDays($trendDays - 1)->startOfDay();
        $staleWarnFrom = $now->copy()->subHours($this->overdueHours());
        $staleCritFrom = $now->copy()->subHours($this->criticalHours());

        // Query 1 — Tills + current open session.
        /** @var Collection<int, Till> $tills */
        $tills = Till::query()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->with(['currentSession' => function ($q) {
                $q->select([
                    'id', 'session_code', 'status', 'opener_name', 'opened_by_id',
                    'opened_at', 'default_currency_code', 'till_id', 'branch_id',
                ])->with('openedBy:id,name');
            }])
            ->get();

        $openTillCount = 0;
        $totalTillCount = $tills->count();
        $tillsOut = [];

        foreach ($tills as $till) {
            $session = $till->currentSession;
            $sessionOpen = $session !== null
                && ($session->status instanceof TillSessionStatusEnum
                    ? $session->status
                    : TillSessionStatusEnum::tryFrom((string) $session->status))
                    === TillSessionStatusEnum::Open;

            if ($sessionOpen) {
                $openTillCount++;
            }

            $sessionPayload = null;
            if ($session !== null) {
                $openedAt = $session->opened_at instanceof Carbon
                    ? $session->opened_at
                    : Carbon::parse($session->opened_at);
                $durationHours = round($openedAt->floatDiffInHours($now), 2);
                $sessionPayload = [
                    'id' => $session->id,
                    'session_code' => $session->session_code,
                    // #1005 — same fallback as the list/detail payloads so the
                    // dashboard tile never shows "—" when only opened_by is set.
                    'opener_name' => $session->opener_name ?? $session->openedBy?->name,
                    'opened_at' => $openedAt->toIso8601String(),
                    'duration_hours' => $durationHours,
                    'stale_warning' => $openedAt->lt($staleWarnFrom),
                    'stale_critical' => $openedAt->lt($staleCritFrom),
                ];
            }

            $tillsOut[] = [
                'id' => $till->id,
                'name' => $till->name ?? $till->till_code,
                'status' => $sessionOpen ? 'open' : 'idle',
                'current_session' => $sessionPayload,
            ];
        }

        // Query 2 — Today's settled KPI block (count + gross sum + variance summary).
        $settledRow = TillSession::query()
            ->where('branch_id', $branch->id)
            ->where('status', TillSessionStatusEnum::Settled->value)
            ->whereBetween('closed_at', [$todayStart, $todayEnd])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(counted_cash_amount), 0) as cash_total')
            ->first();

        // Query 3 — Today's GROSS revenue across settled sessions: the sale rows
        // stamped to today's settled sessions, before refunds.
        //
        // #816 — `whereNull(refund_of_id)` correctly drops the negative refund
        // rows, but pairing it with `status = succeeded` ALSO dropped the sale
        // itself the moment it flipped to `refunded`. A refunded sale then
        // contributed nothing from either row and simply vanished from gross.
        // Worse for a PARTIAL refund: refund() flips the original to `refunded`
        // whatever the amount, so refunding 1 unit of a 1000 sale erased the
        // whole 1000 from the shift's revenue.
        //
        // A sale that happened stays in gross even if it was later refunded —
        // that is what "gross" means, and the refund outflow is accounted for
        // separately against expected_cash. Same sale-row rule as
        // TillSessionService::(cash reconciliation).
        $grossTotal = (float) (OrderPayment::query()
            ->join('till_sessions', 'till_sessions.id', '=', 'order_payments.till_session_id')
            ->where('till_sessions.branch_id', $branch->id)
            ->where('till_sessions.status', TillSessionStatusEnum::Settled->value)
            ->whereBetween('till_sessions.closed_at', [$todayStart, $todayEnd])
            ->whereIn('order_payments.status', [
                PaymentStatusEnum::Succeeded->value,
                PaymentStatusEnum::Refunded->value,
            ])
            ->whereNull('order_payments.refund_of_id')
            ->sum('order_payments.amount') ?? 0);

        // Query 4 — Variance summary across today's terminal sessions.
        $varianceRow = TillSession::query()
            ->where('branch_id', $branch->id)
            ->whereIn('status', [
                TillSessionStatusEnum::Settled->value,
                TillSessionStatusEnum::Expired->value,
            ])
            ->whereBetween('closed_at', [$todayStart, $todayEnd])
            ->whereNotNull('cash_variance_amount')
            ->selectRaw('
                COALESCE(SUM(cash_variance_amount), 0) as net_variance,
                MAX(ABS(cash_variance_amount)) as abs_max
            ')
            ->first();

        $absMaxSessionId = null;
        $absMaxAmount = 0.0;
        if ($varianceRow && $varianceRow->abs_max !== null) {
            /** @var TillSession|null $absMaxSession */
            $absMaxSession = TillSession::query()
                ->where('branch_id', $branch->id)
                ->whereIn('status', [
                    TillSessionStatusEnum::Settled->value,
                    TillSessionStatusEnum::Expired->value,
                ])
                ->whereBetween('closed_at', [$todayStart, $todayEnd])
                ->whereNotNull('cash_variance_amount')
                ->orderByRaw('ABS(cash_variance_amount) DESC')
                ->limit(1)
                ->first(['id', 'cash_variance_amount']);
            if ($absMaxSession !== null) {
                $absMaxSessionId = $absMaxSession->id;
                $absMaxAmount = (float) $absMaxSession->cash_variance_amount;
            }
        }

        // Query 5 — Stale counts. open_overdue = open|closing where opened_at is older
        // than `pos.shift.manager_view.overdue_hours` (the 24h warning band). The stale
        // LIST endpoint must apply the identical predicate — see TillStaleParityTest.
        // `expired` excludes rows that already carry a closed_at: expire() never writes
        // one, but workstation sync-up can, and such a shift is already resolved.
        $openOverdue = TillSession::query()
            ->where('branch_id', $branch->id)
            ->whereIn('status', [TillSessionStatusEnum::Open->value, TillSessionStatusEnum::Closing->value])
            ->where('opened_at', '<', $staleWarnFrom)
            ->count();
        $expiredCount = TillSession::query()
            ->where('branch_id', $branch->id)
            ->where('status', TillSessionStatusEnum::Expired->value)
            ->whereNull('closed_at')
            ->count();

        // Query 6 — Variance trend (per-day aggregate over trend window).
        $trendRows = TillSession::query()
            ->where('branch_id', $branch->id)
            ->whereIn('status', [
                TillSessionStatusEnum::Settled->value,
                TillSessionStatusEnum::Expired->value,
            ])
            ->whereBetween('business_date', [$trendStart, $now->copy()->endOfDay()])
            ->selectRaw('
                business_date,
                COUNT(*) as session_count,
                COALESCE(AVG(cash_variance_amount), 0) as avg_amount,
                COALESCE(MAX(ABS(cash_variance_amount)), 0) as abs_max_amount
            ')
            ->groupBy('business_date')
            ->orderBy('business_date')
            ->get()
            // business_date casts to a Carbon datetime, so a plain string cast yields
            // "2026-05-24 00:00:00" — key on the date-only form to match the toDateString()
            // lookup below, otherwise every day misses and the trend flatlines to zero.
            ->keyBy(fn ($row) => $row->business_date instanceof \DateTimeInterface
                ? $row->business_date->format('Y-m-d')
                : substr((string) $row->business_date, 0, 10));

        $trend = [];
        for ($i = 0; $i < $trendDays; $i++) {
            $date = $trendStart->copy()->addDays($i)->toDateString();
            $row = $trendRows->get($date);
            $trend[] = [
                'date' => $date,
                'avg_amount' => $row ? (float) $row->avg_amount : 0.0,
                'abs_max_amount' => $row ? (float) $row->abs_max_amount : 0.0,
                'session_count' => $row ? (int) $row->session_count : 0,
            ];
        }

        // Query 7 — Recent settlements (top 5).
        $recent = TillSession::query()
            ->where('branch_id', $branch->id)
            ->whereIn('status', [
                TillSessionStatusEnum::Settled->value,
                TillSessionStatusEnum::Expired->value,
                TillSessionStatusEnum::Abandoned->value,
            ])
            ->whereNotNull('closed_at')
            ->with(['till:id,till_code', 'openedBy:id,name'])
            ->orderByDesc('closed_at')
            ->limit(5)
            ->get([
                'id', 'session_code', 'opener_name', 'opened_by_id', 'opened_at', 'closed_at',
                'expected_cash_amount', 'counted_cash_amount', 'cash_variance_amount',
                'till_id', 'branch_id',
            ])
            ->map(fn (TillSession $s) => [
                'id' => $s->id,
                'session_code' => $s->session_code,
                'till_name' => $s->till?->name ?? $s->till?->till_code,
                'opener_name' => $s->opener_name ?? $s->openedBy?->name,
                'expected_cash_amount' => (float) ($s->expected_cash_amount ?? 0),
                'counted_cash_amount' => (float) ($s->counted_cash_amount ?? 0),
                'variance_amount' => (float) ($s->cash_variance_amount ?? 0),
                'closed_at' => $s->closed_at?->toIso8601String(),
            ])
            ->all();

        // Query 8 — 30d force-abandon actor summary (matches plan-032).
        $thirtyDaysAgo = $now->copy()->subDays(30);
        $actorSummary = TillSession::query()
            ->where('branch_id', $branch->id)
            ->where('force_abandoned', true)
            ->whereBetween('abandoned_at', [$thirtyDaysAgo, $now])
            ->whereNotNull('force_abandoned_by_id')
            ->join('users', 'users.id', '=', 'till_sessions.force_abandoned_by_id')
            ->selectRaw('
                till_sessions.force_abandoned_by_id as actor_id,
                users.name as actor_name,
                COUNT(*) as force_abandon_count
            ')
            ->groupBy('till_sessions.force_abandoned_by_id', 'users.name')
            ->orderByDesc('force_abandon_count')
            ->get()
            ->map(fn ($r) => [
                'actor_id' => $r->actor_id,
                'actor_name' => $r->actor_name,
                'force_abandon_count' => (int) $r->force_abandon_count,
            ])
            ->all();

        $currency = $tills->first()?->default_currency_code ?? 'JPY';

        $payload = [
            'data' => [
                'currency_code' => $currency,
                'tills' => $tillsOut,
                'kpis' => [
                    'open_till_count' => $openTillCount,
                    'total_till_count' => $totalTillCount,
                    'settled_today' => [
                        'count' => (int) ($settledRow->count ?? 0),
                        'gross_total_amount' => $grossTotal,
                        'currency_code' => $currency,
                    ],
                    'variance_today' => [
                        'net_amount' => (float) ($varianceRow->net_variance ?? 0),
                        'abs_max_session_id' => $absMaxSessionId,
                        'abs_max_amount' => $absMaxAmount,
                    ],
                    'stale_count' => [
                        'open_overdue' => $openOverdue,
                        'expired' => $expiredCount,
                    ],
                ],
                'variance_trend' => $trend,
                'recent_settlements' => $recent,
                'force_abandon_activity_30d' => $actorSummary,
            ],
            'meta' => [
                // plan-036 §API (plan đã xoá #2188 — git history) specifies stale_threshold_hours as the
                // reaper's POS_SHIFT_STALE_TIMEOUT_HOURS. It shipped emitting the
                // 40h critical-badge constant instead — corrected in #1220. The
                // counts above are computed at `warning_hours`, not this value.
                'stale_threshold_hours' => (int) config('pos.shift.stale_timeout_hours', 48),
                'warning_hours' => $this->overdueHours(),
                'trend_days' => $trendDays,
                'cached_at' => $now->toIso8601String(),
            ],
        ];

        return $payload;
    }

    // =========================================================================
    //  2. Sessions history
    // =========================================================================

    /**
     * Paginated history with revenue rollup. Per-row gross_revenue_amount
     * is a correlated SELECT subquery so it doesn't fan-out into N+1.
     *
     * @return LengthAwarePaginator<TillSession>
     */
    public function listSessions(Branch $branch, TillSessionListFilter $filter): LengthAwarePaginator
    {
        return $this->buildSessionsQuery($branch, $filter)
            ->paginate($filter->perPage)
            ->withQueryString();
    }

    /**
     * Full unpaginated session set for CSV export. The CSV path must not be
     * capped by the per-page limit (max 100) — a manager exporting a 365-day
     * range needs every matching row, not just the first page.
     *
     * @return Collection<int, TillSession>
     */
    public function listAllSessions(Branch $branch, TillSessionListFilter $filter): Collection
    {
        return $this->buildSessionsQuery($branch, $filter)->get();
    }

    /**
     * Shared query builder for the session-history list — used by both the
     * paginated JSON path and the unpaginated CSV export so the two stay in
     * lockstep on filters, revenue rollup, and sort.
     *
     * @return Builder<TillSession>
     */
    private function buildSessionsQuery(Branch $branch, TillSessionListFilter $filter): Builder
    {
        // business_date is stored as DATETIME in the underlying table even though
        // the model casts it as `date`, so we widen the bounds to startOfDay() /
        // endOfDay() to avoid the comparison silently dropping rows.
        $query = TillSession::query()
            ->where('branch_id', $branch->id)
            ->whereBetween('business_date', [
                $filter->from->copy()->startOfDay(),
                $filter->to->copy()->endOfDay(),
            ])
            ->with(['till:id,till_code', 'openedBy:id,name']);

        if ($filter->tillIds !== []) {
            $query->whereIn('till_id', $filter->tillIds);
        }
        if ($filter->statuses !== []) {
            $query->whereIn('status', $filter->statuses);
        }
        if ($filter->openerId) {
            $query->where('opened_by_id', $filter->openerId);
        }
        if (! is_null($filter->forceAbandoned)) {
            $query->where('force_abandoned', $filter->forceAbandoned);
        }

        $this->applyVarianceFilter($query, $filter->variance, $branch);

        // Correlated gross_revenue subquery + payment_count subquery.
        //
        // #816 — sale rows are `succeeded` OR `refunded` (refund() flips the
        // original and adds a separate negative row, which `refund_of_id`
        // already excludes). Filtering on `succeeded` alone made a refunded
        // sale — including one refunded by a single unit — disappear from both
        // the revenue sum and the payment count. See the gross-revenue note in
        // buildTodayKpis() above.
        $saleRows = fn ($q) => $q
            ->whereColumn('till_session_id', 'till_sessions.id')
            ->whereIn('status', [
                PaymentStatusEnum::Succeeded->value,
                PaymentStatusEnum::Refunded->value,
            ])
            ->whereNull('refund_of_id');

        $query->addSelect([
            '*',
            'gross_revenue_amount' => $saleRows(OrderPayment::query())
                ->selectRaw('COALESCE(SUM(amount), 0)'),
            'payment_count' => $saleRows(OrderPayment::query())
                ->selectRaw('COUNT(*)'),
        ]);

        $this->applySort($query, $filter->sort);

        return $query;
    }

    private function applyVarianceFilter(Builder $query, ?string $bucket, Branch $branch): void
    {
        if ($bucket === null) {
            return;
        }
        match ($bucket) {
            'none' => $query->where('cash_variance_amount', 0),
            'over' => $query->where('cash_variance_amount', '>', 0),
            'short' => $query->where('cash_variance_amount', '<', 0),
            'out_of_tolerance' => $query->whereRaw('
                ABS(COALESCE(cash_variance_amount, 0)) > (
                    SELECT COALESCE(variance_tolerance_amount, 0)
                    FROM tills
                    WHERE tills.id = till_sessions.till_id
                )
            '),
            default => null,
        };
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'opened_at_asc' => $query->orderBy('opened_at'),
            'variance_abs_desc' => $query->orderByRaw('ABS(COALESCE(cash_variance_amount, 0)) DESC'),
            default => $query->orderByDesc('opened_at'),
        };
    }

    /**
     * Shape a session row for the list response. Caller wraps in pagination
     * envelope. Kept in the service (not a Resource) because the list
     * endpoint has both JSON and CSV variants — easier to apply a single
     * field map in both paths than to maintain two Resource classes.
     *
     * @return array<string, mixed>
     */
    public function sessionListRowToArray(TillSession $session): array
    {
        $openedAt = $session->opened_at;
        $closedAt = $session->closed_at ?? $session->abandoned_at ?? $session->expired_at;
        $duration = null;
        if ($openedAt && $closedAt) {
            $openedC = $openedAt instanceof Carbon ? $openedAt : Carbon::parse($openedAt);
            $closedC = $closedAt instanceof Carbon ? $closedAt : Carbon::parse($closedAt);
            $duration = round($openedC->floatDiffInHours($closedC), 2);
        }

        return [
            'id' => $session->id,
            'session_code' => $session->session_code,
            'status' => $session->status instanceof TillSessionStatusEnum
                ? $session->status->value
                : $session->status,
            'till' => $session->till ? [
                'id' => $session->till->id,
                'name' => $session->till->name ?? $session->till->till_code,
            ] : null,
            'opener_name' => $session->opener_name ?? $session->openedBy?->name,
            'opened_by_id' => $session->opened_by_id,
            'closed_by_id' => $session->closed_by_id,
            'opened_at' => $openedAt?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'abandoned_at' => $session->abandoned_at?->toIso8601String(),
            'expired_at' => $session->expired_at?->toIso8601String(),
            'duration_hours' => $duration,
            'currency_code' => $session->default_currency_code,
            'opening_float_amount' => $this->floatOrNull($session->opening_float_amount),
            'expected_cash_amount' => $this->floatOrNull($session->expected_cash_amount),
            'counted_cash_amount' => $this->floatOrNull($session->counted_cash_amount),
            'cash_variance_amount' => $this->floatOrNull($session->cash_variance_amount),
            'gross_revenue_amount' => (float) ($session->gross_revenue_amount ?? 0),
            'payment_count' => (int) ($session->payment_count ?? 0),
            'force_abandoned' => (bool) $session->force_abandoned,
            // `expire_reason` is cast to `ExpireReasonEnum` on the model.
            // fputcsv can't auto-coerce a BackedEnum to string — emit raw value.
            'expire_reason' => $this->enumValue($session->expire_reason),
        ];
    }

    // =========================================================================
    //  3. Session detail
    // =========================================================================

    /**
     * Eager-load every relation the detail page renders. Audit trail is a
     * separate polymorphic query (no `for()` scope on AuditLogBaseModel).
     *
     * @return array<string, mixed>
     */
    public function loadSessionDetail(TillSession $session): array
    {
        $session = TillSession::query()
            ->with([
                'till:id,till_code,variance_tolerance_amount,branch_id',
                'branch:id,name',
                'openedBy:id,name',
                'closedBy:id,name',
                'forceAbandonedBy:id,name',
                'openingCounts.denomination',
                'closingCounts.denomination',
                'cashEvents',
                'settlementDetails.tenderType',
            ])
            ->findOrFail($session->id);

        $reconciliation = $this->buildReconciliationView($session);

        $auditTrail = AuditLog::query()
            ->where('auditable_type', class_basename(TillSession::class))
            ->where('auditable_id', $session->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor_id' => $log->user_id,
                'actor_name' => $log->user?->name,
                'actor_role' => null,
                'logged_at' => $log->created_at?->toIso8601String(),
                'payload' => $log->metadata ?? [],
            ])
            ->all();

        $status = $session->status instanceof TillSessionStatusEnum
            ? $session->status
            : TillSessionStatusEnum::from($session->status);
        $terminal = in_array($status, [
            TillSessionStatusEnum::Settled,
            TillSessionStatusEnum::Expired,
            TillSessionStatusEnum::Abandoned,
        ], true);

        $duration = null;
        $openedAt = $session->opened_at;
        $closedAt = $session->closed_at ?? $session->abandoned_at ?? $session->expired_at;
        if ($openedAt && $closedAt) {
            $duration = round(
                ($openedAt instanceof Carbon ? $openedAt : Carbon::parse($openedAt))
                    ->floatDiffInHours($closedAt instanceof Carbon ? $closedAt : Carbon::parse($closedAt)),
                2,
            );
        }

        return [
            'id' => $session->id,
            'session_code' => $session->session_code,
            'status' => $status->value,
            'till' => $session->till ? [
                'id' => $session->till->id,
                'name' => $session->till->name ?? $session->till->till_code,
                'variance_tolerance_amount' => $this->floatOrNull($session->till->variance_tolerance_amount),
            ] : null,
            'branch' => $session->branch ? [
                'id' => $session->branch->id,
                'name' => $session->branch->name,
            ] : null,
            'currency_code' => $session->default_currency_code,
            'business_date' => $session->business_date instanceof Carbon
                ? $session->business_date->toDateString()
                : $session->business_date,
            // plan-043 audit B-III.11 — the tax mode snapshotted at shift open,
            // so admin-web can show it during reconciliation (the close report
            // must be read under the same mode). null on pre-plan-043 sessions.
            'prices_include_tax_at_open' => $session->prices_include_tax_at_open === null
                ? null
                : (bool) $session->prices_include_tax_at_open,
            'opener_name' => $session->opener_name,
            'opened_by' => $session->openedBy ? [
                'id' => $session->openedBy->id,
                'name' => $session->openedBy->name,
            ] : null,
            'closed_by' => $session->closedBy ? [
                'id' => $session->closedBy->id,
                'name' => $session->closedBy->name,
            ] : null,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'abandoned_at' => $session->abandoned_at?->toIso8601String(),
            'expired_at' => $session->expired_at?->toIso8601String(),
            'expire_reason' => $this->enumValue($session->expire_reason),
            'expire_threshold_hours' => $session->expire_threshold_hours,
            'duration_hours' => $duration,
            'force_abandoned' => (bool) $session->force_abandoned,
            'force_abandoned_by' => $session->forceAbandonedBy ? [
                'id' => $session->forceAbandonedBy->id,
                'name' => $session->forceAbandonedBy->name,
            ] : null,
            'force_abandon_reason_code' => $this->enumValue($session->force_abandon_reason_code),
            'force_abandon_reason_detail' => $session->force_abandon_reason_detail,
            'opening_float_amount' => $this->floatOrNull($session->opening_float_amount),
            'opening_note' => $session->opening_note,
            'closing_note' => $session->closing_note,
            'expected_cash_amount' => $this->floatOrNull($session->expected_cash_amount),
            'counted_cash_amount' => $this->floatOrNull($session->counted_cash_amount),
            'cash_variance_amount' => $this->floatOrNull($session->cash_variance_amount),
            'opening_counts' => $session->openingCounts->map(fn ($row) => [
                'denomination_id' => $row->denomination_id,
                'label' => $row->denomination?->label ?? ($row->denomination?->code ?? null),
                'denomination_value' => (float) ($row->denomination_value ?? 0),
                'quantity' => (int) ($row->quantity ?? 0),
                'subtotal_amount' => (float) ($row->subtotal_amount ?? 0),
            ])->all(),
            'closing_counts' => $session->closingCounts->map(fn ($row) => [
                'denomination_id' => $row->denomination_id,
                'label' => $row->denomination?->label ?? ($row->denomination?->code ?? null),
                'denomination_value' => (float) ($row->denomination_value ?? 0),
                'quantity' => (int) ($row->quantity ?? 0),
                'subtotal_amount' => (float) ($row->subtotal_amount ?? 0),
            ])->all(),
            'cash_events' => $session->cashEvents->map(fn (TillCashEvent $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type instanceof \BackedEnum
                    ? $event->event_type->value
                    : $event->event_type,
                'amount' => (float) ($event->amount ?? 0),
                'currency_code' => $event->currency_code,
                'reason' => $event->reason,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'manual_adjustment' => (bool) ($event->manual_adjustment ?? false),
                'performed_by_id' => $event->performed_by_id,
            ])->all(),
            'reconciliation' => $reconciliation,
            'audit_trail' => $auditTrail,
            'links' => [
                'z_report_pdf' => $this->buildZReportUrl($session),
                'z_report_available' => $terminal,
            ],
        ];
    }

    /**
     * Build the by-tender-category reconciliation view. Reads existing
     * TillSettlementTenderDetail rows when the session is settled; otherwise
     * computes expected-side from TillSessionService::reconcile() so the
     * detail page can show "expected vs declared" for closing-state shifts
     * (manager opens detail to monitor a closing cashier).
     *
     * @return array<string, mixed>
     */
    private function buildReconciliationView(TillSession $session): array
    {
        // Group existing settlement details by category.
        $byCategory = [];
        foreach ($session->settlementDetails as $detail) {
            $cat = $detail->category instanceof \BackedEnum
                ? $detail->category->value
                : $detail->category;
            $row = [
                'tender_key' => $detail->tender_key,
                'tender_name' => $detail->tenderType?->name ?? $detail->tender_key,
                'expected_amount' => $this->floatOrNull($detail->expected_amount),
                'declared_amount' => $this->floatOrNull($detail->declared_amount),
                'variance_amount' => $this->floatOrNull($detail->variance_amount),
                'transaction_count' => 0,
                'variance_reason' => $detail->variance_reason,
            ];

            // #1006 — Cash reconciles physically via the drawer count, so
            // persistSettlementDetails() intentionally nulls the cash tender
            // detail's expected/variance (see TillSessionService — carrying the
            // cash expected onto the tender row would double-reconcile cash and
            // surface a phantom variance that blocks close). That leaves the
            // "by tender" cash row at 0/0/0 while the flat "cash reconciliation"
            // block shows the real drawer figures — a display mismatch. For the
            // DISPLAY only, mirror the authoritative drawer figures onto the
            // cash anchor row so the two blocks agree. Do NOT change
            // persistSettlementDetails — this is presentation-layer only, and we
            // override just the anchor (`tender_key === 'cash'`) so a branch with
            // extra cash-category tenders never double-counts the subtotal.
            if ($cat === 'cash' && $detail->tender_key === 'cash') {
                $row['expected_amount'] = $this->floatOrNull($session->expected_cash_amount);
                $row['declared_amount'] = $this->floatOrNull($session->counted_cash_amount);
                $row['variance_amount'] = $this->floatOrNull($session->cash_variance_amount);
            }

            $byCategory[$cat][] = $row;
        }

        $categoryOrder = ['cash', 'card', 'qr', 'emoney'];
        $out = [];
        foreach ($categoryOrder as $cat) {
            $rows = $byCategory[$cat] ?? [];
            $subExpected = 0.0;
            $subDeclared = 0.0;
            $subVariance = 0.0;
            foreach ($rows as $row) {
                $subExpected += (float) ($row['expected_amount'] ?? 0);
                $subDeclared += (float) ($row['declared_amount'] ?? 0);
                $subVariance += (float) ($row['variance_amount'] ?? 0);
            }
            $out[] = [
                'category' => $cat,
                'tender_rows' => $rows,
                'subtotal_expected_amount' => $subExpected,
                'subtotal_declared_amount' => $subDeclared,
                'subtotal_variance_amount' => $subVariance,
            ];
        }

        return ['by_tender_category' => $out];
    }

    // =========================================================================
    //  4. Z-report PDF
    // =========================================================================

    /**
     * Render the Z-report PDF binary. Caller (controller) wraps in an HTTP
     * response with application/pdf headers + Content-Disposition.
     *
     * @throws ZReportNotReadyException when status ∈ {open, closing}
     */
    public function renderZReport(TillSession $session): string
    {
        $status = $session->status instanceof TillSessionStatusEnum
            ? $session->status
            : TillSessionStatusEnum::from($session->status);
        if (! in_array($status, [
            TillSessionStatusEnum::Settled,
            TillSessionStatusEnum::Expired,
            TillSessionStatusEnum::Abandoned,
        ], true)) {
            throw new ZReportNotReadyException($status->value);
        }

        try {
            $payload = $this->buildZReportPayload($session);
            $pdf = Pdf::loadView('till.z-report', $payload);
            $pdf->setPaper('a4', 'portrait');

            return $pdf->output();
        } catch (\Throwable $e) {
            Log::error('[pos.till.tracking] z_report_render_failed', [
                'session_id' => $session->id,
                'session_code' => $session->session_code,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Assemble the 18-section payload for the Z-report Blade template.
     * Field ordering mirrors workstation reconcileSession() Go code so
     * the future ESC/POS slip print can adopt this template wholesale.
     *
     * @return array<string, mixed>
     */
    public function buildZReportPayload(TillSession $session): array
    {
        $session->load([
            'till', 'branch.brand', 'openedBy', 'closedBy', 'forceAbandonedBy',
            'openingCounts.denomination', 'closingCounts.denomination',
            'cashEvents.performedBy',
            'settlementDetails.tenderType',
        ]);

        $recon = $this->tillSessions->reconcile($session);

        $tenderOrder = ['cash', 'card', 'qr', 'emoney'];
        $tendersByCategory = [];
        foreach ($session->settlementDetails as $detail) {
            $cat = $detail->category instanceof \BackedEnum
                ? $detail->category->value
                : $detail->category;
            // #1006 — mirror the drawer figures onto the cash anchor row so the
            // Z-report cash tender section matches the on-screen reconciliation
            // (persistSettlementDetails nulls the cash detail by design; see
            // buildReconciliationView). Presentation-layer only, anchor row only.
            $isCashAnchor = $cat === 'cash' && $detail->tender_key === 'cash';
            $tendersByCategory[$cat][] = [
                'tender_key' => $detail->tender_key,
                'tender_name' => $detail->tenderType?->name ?? $detail->tender_key,
                'expected_amount' => $isCashAnchor
                    ? (float) ($session->expected_cash_amount ?? 0)
                    : (float) ($detail->expected_amount ?? 0),
                'declared_amount' => $isCashAnchor
                    ? (float) ($session->counted_cash_amount ?? 0)
                    : (float) ($detail->declared_amount ?? 0),
                'variance_amount' => $isCashAnchor
                    ? (float) ($session->cash_variance_amount ?? 0)
                    : (float) ($detail->variance_amount ?? 0),
                'transaction_count' => 0,
                'variance_reason' => $detail->variance_reason,
            ];
        }
        $tenderSections = [];
        foreach ($tenderOrder as $cat) {
            if (! empty($tendersByCategory[$cat])) {
                $tenderSections[$cat] = $tendersByCategory[$cat];
            }
        }

        $zNumber = 'Z-'.$session->session_code;
        $status = $session->status instanceof TillSessionStatusEnum
            ? $session->status
            : TillSessionStatusEnum::from($session->status);

        return [
            'session' => $session,
            'status' => $status->value,
            'z_number' => $zNumber,
            'generated_at' => now(),
            'header' => [
                'brand_name' => $session->branch?->brand?->name,
                'branch_name' => $session->branch?->name,
                'till_name' => $session->till?->name ?? $session->till?->till_code,
                'register_number' => $session->till?->till_code,
            ],
            'period' => [
                'opened_at' => $session->opened_at,
                'closed_at' => $session->closed_at ?? $session->abandoned_at ?? $session->expired_at,
            ],
            'operator' => [
                'opener_name' => $session->opener_name ?? $session->openedBy?->name,
                'closer_name' => $session->closedBy?->name,
            ],
            'opening_float' => [
                'amount' => (float) ($session->opening_float_amount ?? 0),
                'denominations' => $session->openingCounts->map(fn ($row) => [
                    'label' => $row->denomination?->label ?? null,
                    'value' => (float) ($row->denomination_value ?? 0),
                    'quantity' => (int) ($row->quantity ?? 0),
                    'subtotal' => (float) ($row->subtotal_amount ?? 0),
                ])->all(),
            ],
            'sales_summary' => [
                'gross' => $recon['revenue']['gross'] ?? 0,
                'net' => $recon['revenue']['net'] ?? 0,
                'tax' => $recon['revenue']['tax'] ?? 0,
                'discount' => $recon['revenue']['discount'] ?? 0,
            ],
            // plan-043 §7.6 / Decision 8 — per-rate consumption-tax breakdown
            // (課税売上 + 消費税 per rate) derived from the session's orders'
            // immutable per-line snapshots. The Z-report PDF is the audit
            // document so it ALWAYS carries this breakdown — the thermal
            // close-report's close_report_tax_breakdown toggle does NOT gate it.
            'tax_breakdown' => $this->taxBreakdownForSession($session),
            'cash_events' => $session->cashEvents->map(fn (TillCashEvent $event) => [
                'event_type' => $event->event_type instanceof \BackedEnum
                    ? $event->event_type->value
                    : $event->event_type,
                'amount' => (float) ($event->amount ?? 0),
                'reason' => $event->reason,
                'occurred_at' => $event->occurred_at,
                'actor_name' => $event->performedBy?->name ?? null,
            ])->all(),
            'tender_sections' => $tenderSections,
            'cash_summary' => [
                'expected' => (float) ($session->expected_cash_amount ?? $recon['cash']['expected_cash'] ?? 0),
                'counted' => (float) ($session->counted_cash_amount ?? 0),
                'variance' => (float) ($session->cash_variance_amount ?? 0),
                'opening_float' => (float) ($recon['cash']['opening_float'] ?? 0),
                'cash_sales' => (float) ($recon['cash']['cash_sales'] ?? 0),
                'paid_in' => (float) ($recon['cash']['paid_in'] ?? 0),
                'paid_out' => (float) ($recon['cash']['paid_out'] ?? 0),
            ],
            'closing_count' => [
                'amount' => (float) ($session->counted_cash_amount ?? 0),
                'denominations' => $session->closingCounts->map(fn ($row) => [
                    'label' => $row->denomination?->label ?? null,
                    'value' => (float) ($row->denomination_value ?? 0),
                    'quantity' => (int) ($row->quantity ?? 0),
                    'subtotal' => (float) ($row->subtotal_amount ?? 0),
                ])->all(),
            ],
            'manager_intervention' => $this->managerInterventionBlock($session),
            'closing_note' => $session->closing_note,
        ];
    }

    /**
     * plan-043 §7.6 — per-rate consumption-tax breakdown for a session.
     *
     * Scopes to the same orders reconcile() attributes revenue to (the
     * distinct CustomerOrders behind this session's succeeded, non-refund
     * payments) then rolls their immutable per-line snapshots up by rate.
     *
     * @return array{net: float, tax: float, gross: float, by_rate: list<array{rate: float, taxable: float, tax: float}>}
     */
    private function taxBreakdownForSession(TillSession $session): array
    {
        $orderIds = OrderPayment::query()
            ->where('till_session_id', $session->id)
            ->where('status', PaymentStatusEnum::Succeeded->value)
            ->whereNull('refund_of_id')
            ->distinct()
            ->pluck('customer_order_id');

        return $this->taxBreakdown->forOrders($orderIds);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function managerInterventionBlock(TillSession $session): ?array
    {
        if (! $session->force_abandoned && $session->expired_at === null) {
            return null;
        }

        if ($session->expired_at !== null && ! $session->force_abandoned) {
            return [
                'type' => 'expired',
                'actor_name' => '(System: scheduler)',
                'reason_code' => $this->enumValue($session->expire_reason),
                'reason_detail' => null,
                'threshold_hours' => $session->expire_threshold_hours,
                'occurred_at' => $session->expired_at,
            ];
        }

        return [
            'type' => 'force_abandoned',
            'actor_name' => $session->forceAbandonedBy?->name,
            'reason_code' => $this->enumValue($session->force_abandon_reason_code),
            'reason_detail' => $session->force_abandon_reason_detail,
            'threshold_hours' => null,
            'occurred_at' => $session->abandoned_at,
        ];
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Unwrap a BackedEnum to its scalar value. Required at every API boundary
     * because fputcsv (CSV path) can't stringify enums and downstream JSON
     * consumers expect a flat string, not `{value: "...", name: "..."}`.
     */
    private function enumValue(mixed $value): null|string|int
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return is_scalar($value) ? $value : null;
    }

    /**
     * Build the Z-report URL without the named-route helper. The shop slug is
     * either on the still-loaded branch relation or on the dropped request
     * route param (ResolveShopFromSlug forgets it after binding). String
     * interpolation avoids a Missing parameter exception when both sources
     * are absent during a unit-scope service call.
     */
    private function buildZReportUrl(TillSession $session): string
    {
        $shopSlug = $session->branch?->slug
            ?? request()?->route('shopSlug')
            ?? request()?->attributes->get('shop')?->slug
            ?? 'unknown';

        return "/api/v1/shops/{$shopSlug}/till/sessions/{$session->id}/z-report.pdf";
    }
}
