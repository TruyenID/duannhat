<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Resources\StockAlertResource;
use App\Models\StockAlert;
use App\Services\Inventory\StockAlertService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class StockAlertController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly StockAlertService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/stock-alerts',
        summary: 'List stock alerts',
        description: 'Returns a paginated list of stock alerts (low stock, out of stock, expiring). Filter by warehouse, status, and alert type.',
        tags: ['Stock Alerts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'alert_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Free-text search over the alerted item (SKU or name).', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: '-created_at')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of stock alerts',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StockAlert')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockAlert::class);

        $alerts = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'warehouse_id' => $request->input('warehouse_id'),
            'status' => $request->input('status'),
            'alert_type' => $request->input('alert_type'),
            'search' => $request->input('search'),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return StockAlertResource::collection($alerts);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/stock-alerts/summary',
        summary: 'Stock alert summary',
        description: 'Returns a quick count summary of stock alerts by type for dashboard display.',
        tags: ['Stock Alerts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Alert summary', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', StockAlert::class);

        $summary = $this->service->summary($this->getOrganizationId());

        return response()->json(['data' => $summary]);
    }
}
