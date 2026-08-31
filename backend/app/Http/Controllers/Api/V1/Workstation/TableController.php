<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Http\Resources\TableResource;
use App\Models\Table;
use App\Omnify\Enums\TableStatusEnum;
use App\Services\Shop\TableStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workstation-token twin of the pos/shop table status endpoint. The LAN
 * workstation applies a table status change to its local mirror optimistically,
 * then syncs it UP here so Cloud (the source of truth the TMS + customer apps
 * read) converges. Cloud-authoritative: the workstation adopts the returned
 * status back onto its mirror.
 */
class TableController extends Controller
{
    public function __construct(private readonly TableStatusService $statusService) {}

    public function changeStatus(Request $request, string $table): JsonResponse
    {
        $device = $request->attributes->get('device');
        if (! $device || ! $device->branch_id) {
            abort(422, 'Device is not associated with an active branch.');
        }

        $tableModel = Table::where('branch_id', $device->branch_id)
            ->where('is_active', true)
            ->findOrFail($table);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', TableStatusEnum::values())],
        ]);

        // Sync-UP is intentionally LENIENT on transitions: pos-web already gates
        // valid transitions client-side, and rejecting one here would strand the
        // workstation sync op in a retry loop. Validate the enum only.
        $updated = $this->statusService->changeStatus(
            table: $tableModel,
            toStatus: $validated['status'],
            userId: (string) $device->id,
        );

        return response()->json(['data' => new TableResource($updated)]);
    }
}
