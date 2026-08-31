<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Resources\VoidReasonResource;
use App\Models\VoidReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * plan-051 (#1149) — shop read-only list of the brand's ACTIVE void reasons,
 * ordered by sort_order. This is what pos-web renders in the void dialog's
 * reason picker (P4); the workstation mirrors the same rows to LAN SQLite
 * via the /workstation/branch settings payload.
 *
 * Read-only on purpose: the master lives at HQ
 * (/hq/{brand}/void-reasons); shops pick, they don't edit.
 */
class VoidReasonController extends Controller
{
    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/void-reasons',
        summary: 'List active void reasons for this shop',
        description: 'Active void reasons of the shop\'s brand, sorted by sort_order.',
        tags: ['Shops'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Active void reasons'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'No access to this shop'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        // Local brand PK — resolved from the shop's console_brand_id by
        // ResolveShopFromSlug. A shop without a brand mapping has no reasons.
        $brandId = $request->attributes->get('brand_id');

        $reasons = $brandId
            ? VoidReason::query()
                ->with('translations')
                ->where('brand_id', $brandId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->get()
            : collect();

        return response()->json([
            'data' => VoidReasonResource::collection($reasons)->resolve($request),
        ]);
    }
}
