<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Resources\DisposalRecordResource;
use App\Models\DisposalRecord;
use App\Services\Inventory\DisposalService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class DisposalController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly DisposalService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/disposals',
        summary: 'List disposal records',
        description: 'Returns a paginated list of disposal records (write-offs, damages, expirations). Filter by transaction item, reason, and date range.',
        tags: ['Disposals'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stock_transaction_item_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'disposal_reason', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: '-created_at')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of disposal records',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DisposalRecord')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DisposalRecord::class);

        $disposals = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            // #1091 — branch context so date filters bind to the BRANCH's business day.
            'branch_id' => $request->attributes->get('shop_id'),
            'stock_transaction_item_id' => $request->input('stock_transaction_item_id'),
            'disposal_reason' => $request->input('disposal_reason'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return DisposalRecordResource::collection($disposals);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/disposals/{disposal}',
        summary: 'Get a disposal record',
        description: 'Returns a single disposal record with its related stock transaction item and approver.',
        tags: ['Disposals'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'disposal', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Disposal record details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DisposalRecord')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Disposal record not found'),
        ]
    )]
    public function show(DisposalRecord $disposal): DisposalRecordResource
    {
        $this->authorize('view', $disposal);

        return new DisposalRecordResource(
            $this->service->findById($disposal->id)
        );
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/disposals/waste-report',
        summary: 'Waste report — aggregate disposal value over a date range',
        description: 'Returns four aggregations used by the waste-report dashboard: by_reason, top_items, daily_trend, summary. Only completed disposals are counted.',
        tags: ['Disposals'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'date_from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Row count for top_items (default 10).', schema: new OA\Schema(type: 'integer', default: 10, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Waste report payload',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function wasteReport(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DisposalRecord::class);

        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'warehouse_id' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $report = $this->service->wasteReport([
            'organization_id' => $this->getOrganizationId(),
            // #1091 — branch context so date filters bind to the BRANCH's business day.
            'branch_id' => $request->attributes->get('shop_id'),
            'warehouse_id' => $request->input('warehouse_id'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'limit' => min((int) $request->input('limit', 10), 100),
        ]);

        return response()->json(['data' => $report]);
    }
}
