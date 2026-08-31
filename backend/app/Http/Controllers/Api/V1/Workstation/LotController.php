<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\MaterialLot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

class LotController extends Controller
{
    #[OA\Get(
        path: '/api/v1/workstation/lots',
        summary: 'List material lots for workstation display',
        description: 'Returns lots filtered by material, warehouse, or expiry. Rate limited to 60/min per device.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        parameters: [
            new OA\Parameter(name: 'material_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'expiring_within_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lot list with generated_at timestamp'),
            new OA\Response(response: 429, description: 'Rate limit exceeded'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;

        $query = MaterialLot::query()
            ->select(['id', 'lot_code', 'material_id', 'warehouse_id', 'qty_on_hand', 'expiry_date', 'status'])
            ->where('status', 'active')
            ->where('qty_on_hand', '>', 0);

        if ($branchId) {
            $query->whereHas('warehouse', fn ($q) => $q->where('branch_id', $branchId));
        }

        if ($request->filled('material_id')) {
            $query->where('material_id', $request->input('material_id'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        if ($request->filled('expiring_within_days')) {
            $days = $request->integer('expiring_within_days');
            $query->whereNotNull('expiry_date')
                ->where('expiry_date', '<=', Carbon::now()->addDays($days));
        }

        // plan-040 H4: null-expiry lots sort last so the FEFO list never surfaces a
        // no-expiry lot ahead of an expiring one.
        $lots = $query->orderByRaw('expiry_date is null, expiry_date asc')->limit(200)->get();

        $branchTz = $device?->branch?->timezone
            ?: config('app.default_branch_timezone', 'Asia/Tokyo');
        $today = Carbon::today($branchTz);

        return response()->json([
            'lots' => $lots->map(fn (MaterialLot $lot) => [
                'id' => $lot->id,
                'lot_code' => $lot->lot_code,
                'material_id' => $lot->material_id,
                'qty_on_hand' => $lot->qty_on_hand,
                'expiry_date' => $lot->expiry_date,
                'status' => $lot->status,
                'days_until_expiry' => $lot->expiry_date
                    ? (int) $today->diffInDays(Carbon::parse($lot->expiry_date), false)
                    : null,
            ]),
            'generated_at' => Carbon::now()->toIso8601String(),
        ]);
    }
}
