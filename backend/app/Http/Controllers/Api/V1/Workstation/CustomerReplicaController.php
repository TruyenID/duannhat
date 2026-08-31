<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/workstation/customers?updated_since=<ISO8601>
 *
 * Replica feed. Returns customers scoped to the device's branch. Supports
 * incremental sync via `updated_since` so a shift's first pull does NOT
 * download the full historical customer base; subsequent pulls only ship
 * deltas. Cap of 1000 rows per call keeps the LAN response bounded.
 */
class CustomerReplicaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;

        $query = Customer::query()
            ->where('branch_id', $branchId)
            ->orderBy('updated_at')
            ->limit(1000);

        if ($since = $request->query('updated_since')) {
            $query->where('updated_at', '>', $since);
        }

        $rows = $query->get([
            'id', 'first_name', 'last_name', 'phone', 'email',
            'organization_id', 'brand_id', 'branch_id', 'updated_at',
        ])->map(function ($c) {
            return [
                'id' => $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'full_name' => trim(($c->first_name ?? '').' '.($c->last_name ?? '')),
                'phone' => $c->phone,
                'email' => $c->email,
                'organization_id' => $c->organization_id,
                'brand_id' => $c->brand_id,
                'branch_id' => $c->branch_id,
                'updated_at' => optional($c->updated_at)->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $rows,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
