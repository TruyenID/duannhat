<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\Shop\PrintJobIndexRequest;
use App\Http\Requests\Shop\ResolvePrintJobRequest;
use App\Http\Resources\PrintJobDetailResource;
use App\Http\Resources\PrintJobResource;
use App\Models\Branch;
use App\Models\PrintJob;
use App\Models\PrintJobResolution;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobResolutionKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\PrintJobAgingService;
use App\Services\Printing\PrintJobResolutionService;
use App\Support\BusinessClock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * plan-052 M2 / T2.2 — the shop's Print jobs screen, server side.
 *
 * Three endpoints, and one thing they all refuse to be: a retry button. The
 * ledger is a record, and the only write this controller performs is a HUMAN's
 * decision about a job the machine could not settle. Reprinting a money
 * document is a different action with a different gate (P-10), on purpose —
 * see {@see PrintJobResolutionKind}.
 *
 * Every query is scoped to the resolved shop's branch AND organization. A
 * print job carries the shop's order codes and printer names, so a leak here
 * is the same class of leak as plan-038's debts bug.
 */
class PrintJobController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(private readonly PrintJobAgingService $aging) {}

    /**
     * GET /api/v1/shops/{shopSlug}/print-jobs
     */
    public function index(PrintJobIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PrintJob::class);

        $branchId = $this->resolvedShopId($request);

        $query = PrintJob::query()
            ->where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $branchId)
            ->with(['printer:id,name', 'resolution']);

        if ($request->statuses() !== []) {
            $query->whereIn('status', $request->statuses());
        }

        if ($request->kinds() !== []) {
            $query->whereIn('kind', $request->kinds());
        }

        if ($request->filled('transport')) {
            $query->where('transport', $request->string('transport')->toString());
        }

        if ($request->filled('confidence')) {
            $query->where('confidence', $request->string('confidence')->toString());
        }

        if ($request->filled('printer_id')) {
            $query->where('printer_id', $request->string('printer_id')->toString());
        }

        // #1875 — answer "what has been printed for THIS order / THIS payer".
        // Applied after the organization + branch scoping above, so naming an
        // order from another shop narrows to nothing rather than reaching it.
        if ($request->filled('order_id')) {
            $query->where('order_id', $request->string('order_id')->toString());
        }

        if ($request->filled('payment_id')) {
            $query->where('payment_id', $request->string('payment_id')->toString());
        }

        if ($request->boolean('money_documents_only')) {
            $query->whereIn('kind', $this->moneyDocumentKinds());
        }

        if ($request->boolean('unresolved_only')) {
            $query->whereDoesntHave('resolution');
        }

        // #1091 — the date filter is the BRANCH's day, applied to the job's
        // REAL event time (P-07). Filtering on created_at would put an offline
        // evening's slips on the morning the shop reconnected.
        [$from, $until] = BusinessClock::utcRangeForBusinessDates(
            $branchId,
            $request->input('from'),
            $request->input('to'),
        );

        if ($from !== null) {
            $query->whereRaw('COALESCE(printed_reported_at, created_at) >= ?', [$from]);
        }

        if ($until !== null) {
            // BusinessClock already returns the EXCLUSIVE next branch-midnight,
            // so `to=2026-07-28` means "up to and including the branch's 28th"
            // — which is what a human typing one date into two boxes means.
            $query->whereRaw('COALESCE(printed_reported_at, created_at) < ?', [$until]);
        }

        // Newest first, id as the tiebreak: two slips printed in the same
        // second must not swap places between page 1 and page 2.
        $jobs = $query
            ->orderByRaw('COALESCE(printed_reported_at, created_at) DESC')
            ->orderBy('id', 'desc')
            ->paginate($request->perPage())
            ->withQueryString();

        return PrintJobResource::collection($jobs)->additional([
            'meta' => [
                'statuses' => array_column(PrintJobStatus::cases(), 'value'),
                'kinds' => array_column(PrintJobKind::cases(), 'value'),
                'aging' => $this->aging->branchAging($branchId),
                'silent_printers' => $this->aging->silentPrinters($branchId),
            ],
        ]);
    }

    /**
     * GET /api/v1/shops/{shopSlug}/print-jobs/{job}
     */
    public function show(Request $request): PrintJobDetailResource
    {
        $job = $this->resolveJob($request);
        $this->authorize('view', $job);

        return new PrintJobDetailResource($job->load(['printer:id,name', 'resolution']));
    }

    /**
     * POST /api/v1/shops/{shopSlug}/print-jobs/{job}/resolve
     *
     * Manager-only (policy). Idempotent: the FIRST resolution stands, and a
     * second call returns it rather than rewriting who decided and why.
     */
    public function resolve(ResolvePrintJobRequest $request): JsonResponse
    {
        $job = $this->resolveJob($request);
        $this->authorize('resolve', $job);

        // A job the ledger already reports as printed has nothing to resolve.
        // Letting a manager annotate it "printed by hand" would put two
        // contradictory statements about one slip in the record, and the
        // screen offers no reason to.
        if ($job->status === PrintJobStatus::Printed) {
            return response()->json([
                'message' => 'PRINT_JOB_ALREADY_PRINTED: the ledger already records this job as printed; there is nothing to resolve.',
                'code' => 'PRINT_JOB_ALREADY_PRINTED',
            ], 409);
        }

        $resolutions = app(PrintJobResolutionService::class);

        // 200 khi đã có người xử lý trước, 201 khi lần này tạo ra bản ghi —
        // hai mã trạng thái khác nhau nên lần đọc này ở lại controller.
        $existing = $resolutions->existingFor($job);

        if ($existing !== null) {
            return $this->resolutionResponse($job, $existing, 200);
        }

        $resolution = $resolutions->resolveOnce(
            $job,
            $request->string('resolution')->toString(),
            $request->string('reason')->toString(),
            $request->user()?->id,
        );

        return $this->resolutionResponse($job, $resolution, 201);
    }

    // =====================================================================
    //  Helpers
    // =====================================================================

    private function resolutionResponse(PrintJob $job, PrintJobResolution $resolution, int $status): JsonResponse
    {
        return response()->json([
            'data' => (new PrintJobDetailResource(
                $job->setRelation('resolution', $resolution)->load('printer:id,name'),
            ))->toArray(request()),
        ], $status);
    }

    /** @return list<string> */
    private function moneyDocumentKinds(): array
    {
        return array_values(array_map(
            static fn (PrintJobKind $k): string => $k->value,
            array_filter(PrintJobKind::cases(), static fn (PrintJobKind $k): bool => $k->isMoneyDocument()),
        ));
    }

    private function resolvedShop(Request $request): Branch
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        return $shop;
    }

    private function resolvedShopId(Request $request): string
    {
        return (string) $request->attributes->get('shop_id');
    }

    /**
     * Scoped lookup, never implicit binding: a job id from ANOTHER shop must
     * 404, not 403 — existence is itself information.
     */
    private function resolveJob(Request $request): PrintJob
    {
        return PrintJob::query()
            ->where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $this->resolvedShop($request)->id)
            ->findOrFail((string) $request->route('printJob'));
    }
}
