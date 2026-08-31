<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Exceptions\Till\ZReportNotReadyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\ListTillSessionsRequest;
use App\Models\Branch;
use App\Models\TillSession;
use App\Services\Shop\ShopTillTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Plan 036 — Manager Till Tracking read controller.
 *
 * Four endpoints under /shops/{shopSlug}/till/*:
 *
 *   GET /dashboard                       → manager-only KPIs + polling
 *   GET /sessions                        → paginated history (or CSV)
 *   GET /sessions/{session}              → full detail + reconciliation
 *   GET /sessions/{session}/z-report.pdf → PDF binary
 *
 * Branch isolation for per-session endpoints: an explicit
 * session.branch_id === resolved shop branch check that returns 404
 * (NOT 403) for cross-shop access — DESIGN §Endpoint #3 explicitly
 * forbids leaking existence.
 *
 * Authorization rides on Gate-defined abilities; see AppServiceProvider
 * for the four `shop.till.tracking.*` definitions.
 */
class ShopTillTrackingController extends Controller
{
    public function __construct(
        private readonly ShopTillTrackingService $tracking,
    ) {}

    /**
     * GET /api/v1/shops/{shopSlug}/till/dashboard?trend_days=14
     */
    public function dashboard(Request $request): JsonResponse
    {
        Gate::authorize('shop.till.tracking.viewDashboard');

        /** @var Branch $branch */
        $branch = $request->attributes->get('shop');

        $trendDays = (int) $request->query('trend_days', 14);
        if (! in_array($trendDays, [7, 14, 30, 90], true)) {
            return response()->json([
                'message' => 'INVALID_TREND_DAYS: trend_days must be one of 7, 14, 30, 90.',
                'code' => 'INVALID_TREND_DAYS',
            ], 422);
        }

        return response()->json($this->tracking->dashboard($branch, $trendDays));
    }

    /**
     * GET /api/v1/shops/{shopSlug}/till/sessions
     */
    public function sessions(ListTillSessionsRequest $request): Response|JsonResponse
    {
        Gate::authorize('shop.till.tracking.viewSessions');

        /** @var Branch $branch */
        $branch = $request->attributes->get('shop');

        if ($request->isCsvExport()) {
            // CSV exports the full unpaginated result set — the per-page cap
            // (max 100) must not silently truncate a manager's export.
            return $this->renderSessionsCsv(
                $this->tracking->listAllSessions($branch, $request->toFilter())->all(),
                $request,
            );
        }

        $paginator = $this->tracking->listSessions($branch, $request->toFilter());

        $rows = array_map(
            fn ($session) => $this->tracking->sessionListRowToArray($session),
            $paginator->items(),
        );

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $request->resolvedFrom()->toDateString(),
                'to' => $request->resolvedTo()->toDateString(),
                'applied_filters' => [
                    'till_id' => $request->tillIds(),
                    'status' => $request->statuses(),
                    'opener_id' => $request->input('opener_id'),
                    'variance' => $request->input('variance'),
                    'force_abandoned' => $request->input('force_abandoned'),
                ],
            ],
        ]);
    }

    /**
     * GET /api/v1/shops/{shopSlug}/till/sessions/{session}
     */
    public function session(Request $request, TillSession $session): JsonResponse
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('shop');

        // Cross-shop access returns 404 (not 403) — DON'T leak existence.
        if ($session->branch_id !== $branch->id) {
            abort(404, 'SESSION_NOT_FOUND');
        }

        Gate::authorize('shop.till.tracking.viewSession', $session);

        return response()->json([
            'data' => $this->tracking->loadSessionDetail($session),
        ]);
    }

    /**
     * GET /api/v1/shops/{shopSlug}/till/sessions/{session}/z-report.pdf
     */
    public function zReport(Request $request, TillSession $session): Response|JsonResponse
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('shop');

        if ($session->branch_id !== $branch->id) {
            abort(404, 'SESSION_NOT_FOUND');
        }

        Gate::authorize('shop.till.tracking.printZReport', $session);

        try {
            $binary = $this->tracking->renderZReport($session);
        } catch (ZReportNotReadyException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode(),
            ], 422);
        }

        $filename = "z-report-{$session->session_code}.pdf";

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @param  array<int, TillSession>  $sessions
     */
    private function renderSessionsCsv(array $sessions, ListTillSessionsRequest $request): Response
    {
        $columns = [
            'session_code', 'business_date', 'till_name', 'opener_name',
            'opened_at', 'closed_at', 'duration_hours', 'status',
            'payment_count', 'gross_revenue_amount', 'cash_variance_amount',
            'force_abandoned', 'expire_reason',
        ];

        $rows = array_map(
            fn ($session) => $this->tracking->sessionListRowToArray($session),
            $sessions,
        );

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['session_code'] ?? '',
                isset($row['opened_at']) ? substr((string) $row['opened_at'], 0, 10) : '',
                $row['till']['name'] ?? '',
                $row['opener_name'] ?? '',
                $row['opened_at'] ?? '',
                $row['closed_at'] ?? '',
                $row['duration_hours'] ?? '',
                $row['status'] ?? '',
                $row['payment_count'] ?? 0,
                $row['gross_revenue_amount'] ?? 0,
                $row['cash_variance_amount'] ?? '',
                $row['force_abandoned'] ? '1' : '0',
                $row['expire_reason'] ?? '',
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $from = $request->resolvedFrom()->toDateString();
        $to = $request->resolvedTo()->toDateString();
        $filename = "sessions-{$from}-to-{$to}.csv";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
