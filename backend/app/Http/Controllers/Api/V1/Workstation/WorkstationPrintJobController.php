<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workstation\WorkstationPrintJobBatchRequest;
use App\Services\Printing\PrintJobJournalIngestService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/workstation/print-jobs — plan-052 T1.2 (#1166).
 *
 * Sync UP of the workstation's local print JOURNAL. The workstation prints
 * offline-first and owns its queue entirely (DESIGN §1b); this endpoint exists
 * so Cloud can eventually KNOW what a shop printed, never so it can decide.
 *
 * Consequences that the response shape makes explicit: an already-known id is
 * reported as a `duplicate`, not an error, so the device's retry loop settles
 * instead of spinning (P-09). A batch is accepted whole; nothing here can ever
 * answer "stop printing".
 */
class WorkstationPrintJobController extends Controller
{
    public function __construct(private readonly PrintJobJournalIngestService $ingest) {}

    public function store(WorkstationPrintJobBatchRequest $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $organizationId = $device?->organization_id;
        $branchId = $device?->branch_id;

        if (! $organizationId || ! $branchId) {
            return response()->json(['message' => 'device is not paired to a branch'], 422);
        }

        /** @var list<array<string, mixed>> $jobs */
        $jobs = $request->validated('jobs');

        $result = $this->ingest->ingest((string) $organizationId, (string) $branchId, $jobs);

        return response()->json([
            'data' => [
                'accepted' => $result['accepted'],
                'duplicates' => $result['duplicates'],
            ],
        ], 202);
    }
}
