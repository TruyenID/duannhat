<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrinterResource;
use App\Models\Printer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/workstation/printers
 *
 * Replica feed for the workstation's sync-down path. Cloud owns the printer
 * CONFIG (name / roles / LAN address / paper). The workstation mirrors this
 * feed into its local `printers` table under origin='cloud', so a manager can
 * configure printers centrally in admin-web and every station picks them up.
 *
 * The workstation keeps its own origin='local' printers (added directly in the
 * WS App) untouched — a Cloud outage must never stop a shop from printing, so
 * the local rows remain as an offline fallback.
 */
class WorkstationPrinterReplicaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;
        $organizationId = $device?->organization_id;

        if (! $organizationId || ! $branchId) {
            return response()->json(['data' => []], 200);
        }

        $printers = Printer::query()
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id', 'name', 'roles', 'connection_type', 'address',
                'paper_width', 'cut_type', 'encoding', 'is_active',
                // plan-052 (#1166) — the capability profile travels DOWN with
                // the printer config so the workstation renders correctly for
                // this machine while offline (DESIGN §3b).
                'transport', 'model_profile', 'last_status',
                'branch_id', 'organization_id', 'created_at', 'updated_at',
            ]);

        return response()->json([
            'data' => PrinterResource::collection($printers)->resolve($request),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
