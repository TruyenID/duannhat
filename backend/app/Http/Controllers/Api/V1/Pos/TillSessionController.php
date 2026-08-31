<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\AbandonTillSessionRequest;
use App\Http\Requests\Pos\CloseTillSessionRequest;
use App\Http\Requests\Pos\ForceAbandonTillSessionRequest;
use App\Http\Requests\Pos\HandoverTillSessionRequest;
use App\Http\Requests\Pos\ManualSettleTillSessionRequest;
use App\Http\Requests\Pos\OpenTillSessionRequest;
use App\Http\Requests\Pos\TillCashEventRequest;
use App\Http\Requests\Pos\TillDraftRequest;
use App\Http\Resources\Pos\TillSessionResource;
use App\Models\TillSession;
use App\Models\User;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Services\Pos\TillSessionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Plan 030 — POST/PATCH/GET endpoints for TillSession workflow.
 *
 * Thin controller: authorize → delegate → respond. All business logic lives
 * in TillSessionService.
 */
class TillSessionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly TillSessionService $sessions,
    ) {}

    public function show(Request $request, TillSession $session): JsonResponse
    {
        $this->ensureShopMatch($request, $session);
        $this->authorize('view', $session);

        $session->load([
            'openingCounts.denomination',
            'closingCounts',
            'cashEvents',
            'settlementDetails.tenderType',
        ]);

        return response()->json([
            'data' => (new TillSessionResource($session))->toArray($request),
        ]);
    }

    public function store(OpenTillSessionRequest $request): JsonResponse
    {
        $this->authorize('open', TillSession::class);

        $data = $request->validated();
        $data['branch_id'] = $request->attributes->get('shop_id');
        $data['organization_id'] = $request->attributes->get('organization_id');
        $data['brand_id'] = $request->attributes->get('brand_id');

        $session = $this->sessions->open($data);

        // Plan-044 R2 — surface how many gap payments the claim attributed (transient
        // attribute set by open(); the audit `till_session.gap_claim` is the record).
        $payload = (new TillSessionResource($session))->toArray($request);
        $payload['gap_payments_claimed'] = $session->gapPaymentsClaimed;
        // Plan-046 (T4.2) — true when this open continued a chain (prior shift was
        // a handover); pos-web shows the "Tiếp tục chuỗi — ca N" banner + blind
        // re-count. chain_id/chain_sequence ride the resource (T4.3).
        $payload['continues_chain'] = $session->continuesChain;

        return response()->json(['data' => $payload], 201);
    }

    public function reconciliation(Request $request, TillSession $session): JsonResponse
    {
        $this->ensureShopMatch($request, $session);
        $this->authorize('view', $session);

        return response()->json([
            'data' => $this->sessions->reconcile($session),
        ]);
    }

    /**
     * Plan-044 R2 — close-screen paid / unpaid-carry summary. Read-only; the
     * money reconciliation is unchanged (see reconciliation()).
     */
    public function orderSummary(Request $request, TillSession $session): JsonResponse
    {
        $this->ensureShopMatch($request, $session);
        $this->authorize('view', $session);

        return response()->json([
            'data' => $this->sessions->orderSummary($session),
        ]);
    }

    public function cashEvent(TillCashEventRequest $request, TillSession $session): JsonResponse
    {
        $this->ensureShopMatch($request, $session);
        $this->authorize('recordEvent', $session);

        $event = $this->sessions->recordCashEvent($session, $request->validated());

        return response()->json([
            'data' => [
                'id' => $event->id,
                'session_id' => $event->session_id,
                'event_type' => $event->event_type instanceof \BackedEnum
                    ? $event->event_type->value
                    : $event->event_type,
                'amount' => (float) $event->amount,
                'currency_code' => $event->currency_code,
                'reason' => $event->reason,
                'reference_no' => $event->reference_no,
                'occurred_at' => optional($event->occurred_at)?->toIso8601String(),
                'performed_by_id' => $event->performed_by_id,
            ],
        ], 201);
    }

    public function draft(TillDraftRequest $request, TillSession $session): JsonResponse
    {
        $this->ensureShopMatch($request, $session);
        $this->authorize('close', $session);

        $session = $this->sessions->saveDraft($session, $request->validated());

        return response()->json([
            'data' => (new TillSessionResource($session))->toArray($request),
        ]);
    }

    public function close(CloseTillSessionRequest $request, TillSession $session): JsonResponse
    {
        $this->ensureShopMatch($request, $session);
        $this->authorize('close', $session);

        $session = $this->sessions->close($session, $request->validated());

        // Plan-046 (T4.2) — a final close ends the chain; signal the client to
        // fetch the chain summary + print the aggregate slip. chain_id/
        // chain_sequence ride the resource (T4.3).
        $payload = (new TillSessionResource($session))->toArray($request);
        $payload['chain_summary_ready'] = true;

        return response()->json(['data' => $payload]);
    }

    /**
     * Plan-046 (T4.1) — HANDOVER settle. Settles the current shift as a handover
     * (keeps the chain open); the client prints a single-shift 引き継ぎ slip and
     * opens the next shift, which continues the same chain (R1).
     *
     * POST /pos/till/sessions/{session}/handover
     */
    public function handover(HandoverTillSessionRequest $request, TillSession $session): JsonResponse
    {
        $this->ensureShopMatch($request, $session);
        $this->authorize('handover', $session);

        $session = $this->sessions->handover($session, $request->validated());

        return response()->json([
            'data' => (new TillSessionResource($session))->toArray($request),
        ]);
    }

    /**
     * Plan-046 (T4.1) — aggregate chain summary. Branch-scoped: chainSummary
     * filters by the resolved shop_id and 404s an unknown/cross-branch chain, so
     * a chain of another branch is never disclosed.
     *
     * GET /pos/till/chains/{chainId}/summary
     */
    public function chainSummary(Request $request, string $chainId): JsonResponse
    {
        $this->authorize('viewAny', TillSession::class);

        $branchId = (string) $request->attributes->get('shop_id');

        return response()->json([
            'data' => $this->sessions->chainSummary($chainId, $branchId),
        ]);
    }

    public function abandon(AbandonTillSessionRequest $request, TillSession $session): JsonResponse
    {
        $this->ensureShopMatch($request, $session);
        $this->authorize('abandon', $session);

        $session = $this->sessions->abandon($session, $request->input('abandon_reason'));

        return response()->json([
            'data' => (new TillSessionResource($session))->toArray($request),
        ]);
    }

    /**
     * Plan-032 — Manager force-abandon. Bypasses the SHIFT_HAS_PAYMENTS guard
     * that the cashier-driven `abandon` endpoint enforces.
     *
     * POST /pos/till/sessions/{session}/force-abandon
     */
    public function forceAbandon(ForceAbandonTillSessionRequest $request, TillSession $session): JsonResponse
    {
        $this->ensureShopMatch($request, $session);
        $this->authorize('forceAbandon', $session);

        /** @var User $manager */
        $manager = Auth::user();

        $data = $request->validated();
        $session = $this->sessions->forceAbandon(
            $session,
            $data['reason_code'],
            $data['reason_detail'] ?? null,
            $manager,
        );

        return response()->json([
            'data' => (new TillSessionResource($session))->toArray($request),
        ]);
    }

    /**
     * Plan-032 — Manager manual-settle of an expired session.
     *
     * POST /pos/till/sessions/{session}/manual-settle
     */
    public function manualSettle(ManualSettleTillSessionRequest $request, TillSession $session): JsonResponse
    {
        $this->ensureShopMatch($request, $session);
        $this->authorize('manualSettle', $session);

        /** @var User $manager */
        $manager = Auth::user();

        $session = $this->sessions->manualSettle($session, $request->validated(), $manager);

        return response()->json([
            'data' => (new TillSessionResource($session))->toArray($request),
        ]);
    }

    /**
     * Plan-032 — List stale (open-overdue) and expired sessions for the
     * manager dashboard / "Ca treo" tab. Scoped to the request's organization;
     * `branch_id` filter narrows further.
     *
     * GET /pos/till/sessions/stale?filter=open_overdue|expired|all_terminal
     *                                &branch_id=<uuid>&per_page=25
     *
     * #1220 — `open_overdue` uses `pos.shift.manager_view.overdue_hours` (24h),
     * the SAME predicate the dashboard KPI counts with. It previously used the
     * reaper's `stale_timeout_hours` (48h), so the KPI counted 24-48h shifts
     * that no filter could list. `meta.threshold_hours` therefore reports the
     * threshold applied to the REQUESTED filter, not one global number.
     */
    public function stale(Request $request): JsonResponse
    {
        $this->authorize('viewStale', TillSession::class);

        $orgId = $request->attributes->get('organization_id');

        // `organization_id` is NOT NULL on till_sessions, so a null $orgId would
        // compile to `whereNull(...)` and silently return an empty list — visually
        // identical to "no stale shifts". Only device-auth requests can reach here
        // with it unset (ResolvesShopContext sets `$organization?->id`), and the
        // viewStale policy already rejects those, but fail loudly rather than lie.
        // Must precede the group_by branch: staleActorSummary() takes `string $orgId`.
        if (! $orgId) {
            abort(500, 'Shop context is missing an organization; cannot scope stale sessions.');
        }

        $branchId = $request->query('branch_id') ?: $request->attributes->get('shop_id');
        $filter = $request->query('filter', 'open_overdue');
        $perPage = min((int) $request->query('per_page', 25), 100);

        // Plan-032 T5.5c — `group_by=actor` returns a per-manager summary instead
        // of the paginated list. Used by the admin-web "Force-abandon hoạt động"
        // widget to render a bar chart by manager.
        if ($request->query('group_by') === 'actor') {
            return $this->staleActorSummary($orgId, $branchId, (int) ($request->query('within_days', 30)));
        }

        $overdueHours = (int) config('pos.shift.manager_view.overdue_hours', 24);
        $expireHours = (int) config('pos.shift.stale_timeout_hours', 48);

        // What `meta.threshold_hours` means depends on the filter: for open_overdue
        // it is the band that put a shift in the list; for the terminal filters it is
        // informational — the reaper cutoff that expired them. Unknown filters fall
        // through to open_overdue below, so they report the overdue band too.
        $thresholdHours = in_array($filter, ['expired', 'all_terminal'], true)
            ? $expireHours
            : $overdueHours;

        $query = TillSession::query()->where('organization_id', $orgId);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        match ($filter) {
            // `closed_at` guard mirrors the dashboard KPI: expire() never writes one,
            // but workstation sync-up accepts it, and such a shift is already resolved.
            'expired' => $query->where('status', TillSessionStatusEnum::Expired->value)
                ->whereNull('closed_at')
                ->orderByDesc('expired_at'),
            'all_terminal' => $query->whereIn('status', [
                TillSessionStatusEnum::Settled->value,
                TillSessionStatusEnum::Abandoned->value,
                TillSessionStatusEnum::Expired->value,
            ])->orderByDesc('updated_at'),
            default => $query->whereIn('status', [
                TillSessionStatusEnum::Open->value,
                TillSessionStatusEnum::Closing->value,
            ])->where('opened_at', '<', now()->subHours($overdueHours))
                ->orderBy('opened_at'),
        };

        $paginator = $query->with([
            'till',
            'openingCounts.denomination',
            'closingCounts',
        ])
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(
                fn (TillSession $s) => (new TillSessionResource($s))->toArray($request)
            )->all(),
            'meta' => [
                'filter' => $filter,
                'threshold_hours' => $thresholdHours,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Plan-032 T5.5c — Per-manager rollup of force-abandon + expire counts
     * over the trailing window. Feeds the admin-web activity widget.
     *
     * Returns `{ data: { force_abandoned_count, expired_count, per_manager:
     * [{ manager_id, count }] }, meta: { within_days, branch_id } }`.
     * Resolving manager display names happens client-side via the SSO user
     * cache (we don't JOIN here to keep the query cheap).
     */
    private function staleActorSummary(string $orgId, ?string $branchId, int $withinDays): JsonResponse
    {
        $since = now()->subDays($withinDays);
        $base = TillSession::query()->where('organization_id', $orgId);
        if ($branchId) {
            $base->where('branch_id', $branchId);
        }

        $forceAbandonedCount = (clone $base)
            ->where('force_abandoned', true)
            ->where('abandoned_at', '>=', $since)
            ->count();

        $expiredCount = (clone $base)
            ->where('status', TillSessionStatusEnum::Expired->value)
            ->where('expired_at', '>=', $since)
            ->count();

        $perManager = (clone $base)
            ->select('force_abandoned_by_id')
            ->selectRaw('COUNT(*) AS count')
            ->where('force_abandoned', true)
            ->whereNotNull('force_abandoned_by_id')
            ->where('abandoned_at', '>=', $since)
            ->groupBy('force_abandoned_by_id')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => [
                'manager_id' => $r->force_abandoned_by_id,
                'count' => (int) $r->count,
            ])
            ->all();

        return response()->json([
            'data' => [
                'force_abandoned_count' => $forceAbandonedCount,
                'expired_count' => $expiredCount,
                'per_manager' => $perManager,
            ],
            'meta' => [
                'within_days' => $withinDays,
                'branch_id' => $branchId,
            ],
        ]);
    }

    /**
     * Belt-and-braces cross-branch guard: the policy already does an org
     * check, but the operational invariant is "your shop_id MUST equal the
     * session's branch_id". 404 to avoid leaking existence across branches.
     */
    private function ensureShopMatch(Request $request, TillSession $session): void
    {
        $shopId = $request->attributes->get('shop_id');
        if ($shopId && $session->branch_id !== $shopId) {
            abort(404);
        }
    }
}
