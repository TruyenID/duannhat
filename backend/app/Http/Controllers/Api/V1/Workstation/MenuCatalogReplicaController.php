<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Services\Workstation\MenuCatalogReplicaBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/workstation/menu-catalog
 *
 * Flat sync-DOWN feed for the workstation's PullMenuCatalog. All query +
 * flattening logic lives in {@see MenuCatalogReplicaBuilder}; this controller
 * only resolves the paired device's branch and hands the feed back.
 */
class MenuCatalogReplicaController extends Controller
{
    public function __construct(private readonly MenuCatalogReplicaBuilder $builder) {}

    public function index(Request $request): JsonResponse
    {
        $branchId = $request->attributes->get('device')?->branch_id;

        return response()->json([
            'data' => $this->builder->buildForBranch($branchId ? (string) $branchId : null),
        ]);
    }
}
