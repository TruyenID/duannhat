<?php

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrinterResource;
use App\Models\Printer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/kiosk/printers
 *
 * Read-only replica of the branch's printer config for kiosk / self_regi
 * devices. Cloud owns the config (name / roles / LAN address / paper); the
 * kiosk reads it to know which physical printer holds each role and where it
 * lives on the LAN, then drives printing through the workstation gateway.
 *
 * Mirrors WorkstationPrinterReplicaController — same scope (org + branch from
 * the device token), same PrinterResource contract, so kiosk and workstation
 * read one shape. CRUD stays in admin-web under sso.auth; the device token can
 * only read. Printer uses SoftDeletes, so the default query already excludes
 * trashed rows — a soft-deleted printer never leaks to the floor.
 */
class KioskPrinterReplicaController extends Controller
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
                'branch_id', 'organization_id', 'created_at', 'updated_at',
            ]);

        return response()->json([
            'data' => PrinterResource::collection($printers)->resolve($request),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
