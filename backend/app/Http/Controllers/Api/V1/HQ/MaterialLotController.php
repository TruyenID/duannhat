<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\MaterialLotDisposeRequest;
use App\Http\Requests\MaterialLotQuarantineRequest;
use App\Http\Requests\MaterialLotReleaseRequest;
use App\Http\Resources\MaterialLotResource;
use App\Models\MaterialLot;
use App\Services\Inventory\LotTimelineService;
use App\Services\Inventory\MaterialLotService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class MaterialLotController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly MaterialLotService $service,
        private readonly LotTimelineService $timelineService,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/material-lots',
        summary: 'List material lots (HQ scope)',
        description: 'Paginated list of material lots, brand-scoped. The optional `search` term matches lot_code / supplier_lot_code / supplier_name and the parent material\'s sku / translated name.',
        tags: ['MaterialLots'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'brand_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'material_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'quarantined', 'depleted', 'expired', 'disposed'])),
            new OA\Parameter(name: 'expiring_within_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Free-text. Matches lot_code, supplier_lot_code, supplier_name, material.sku, material.name (translated).', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: '-received_at')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MaterialLot::class);

        $lots = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            // plan-040 fix: this is a brand-context page (/hq/{brandSlug}/material-lots),
            // so the list must be scoped to the ROUTE brand — not an optional client
            // query param. Reading brand_id from input left it org-wide, which (a) showed
            // other brands' lots on the HQ page and (b) fed cross-brand lots into the
            // recall lot-picker, where the recall's brand-scoped root resolution then
            // 404'd ("No query results for MaterialLot"). Force the route brand.
            'brand_id' => $request->attributes->get('brand_id'),
            'warehouse_id' => $request->input('warehouse_id'),
            'material_id' => $request->input('material_id'),
            'status' => $request->input('status'),
            'source' => $request->input('source'),
            'expiring_within_days' => $request->integer('expiring_within_days') ?: null,
            'search' => $request->input('search'),
            'sort' => $request->input('sort', '-received_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
            'with_trashed' => $request->boolean('with_trashed'),
        ]);

        return MaterialLotResource::collection($lots);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/material-lots/{lot}',
        summary: 'Get a material lot',
        tags: ['MaterialLots'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lot', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Material lot'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(MaterialLot $lot): MaterialLotResource
    {
        $this->authorizeOrganization($lot);
        $this->authorizeBrand($lot);
        $this->authorize('view', $lot);

        return new MaterialLotResource($this->service->findById($lot->id));
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/material-lots/{lot}/quarantine',
        summary: 'Quarantine a lot',
        tags: ['MaterialLots'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lot', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['reason'],
            properties: [new OA\Property(property: 'reason', type: 'string', minLength: 3, maxLength: 1000)]
        )),
        responses: [new OA\Response(response: 200, description: 'Quarantined')],
    )]
    public function quarantine(MaterialLotQuarantineRequest $request, MaterialLot $lot): MaterialLotResource
    {
        $this->authorizeOrganization($lot);
        $this->authorizeBrand($lot);
        $this->authorize('quarantine', $lot);

        return new MaterialLotResource(
            $this->service->quarantine($lot, $request->input('reason'))
        );
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/material-lots/{lot}/release',
        summary: 'Release a lot from quarantine',
        tags: ['MaterialLots'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lot', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Released')],
    )]
    public function release(MaterialLotReleaseRequest $request, MaterialLot $lot): MaterialLotResource
    {
        $this->authorizeOrganization($lot);
        $this->authorizeBrand($lot);
        $this->authorize('release', $lot);

        return new MaterialLotResource($this->service->release($lot));
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/material-lots/{lot}/dispose',
        summary: 'Dispose a lot',
        tags: ['MaterialLots'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lot', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(
            properties: [new OA\Property(property: 'force', type: 'boolean', default: false)]
        )),
        responses: [new OA\Response(response: 200, description: 'Disposed')],
    )]
    public function dispose(MaterialLotDisposeRequest $request, MaterialLot $lot): MaterialLotResource
    {
        $this->authorizeOrganization($lot);
        $this->authorizeBrand($lot);
        $this->authorize('dispose', $lot);

        return new MaterialLotResource(
            $this->service->dispose($lot, $request->boolean('force', false))
        );
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/material-lots/{lot}/timeline',
        summary: 'Get unified timeline for a material lot',
        description: 'Merges audit logs, stock movements, genealogy links, expiry alerts, and recalls into a single chronological feed.',
        tags: ['MaterialLots'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lot', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'types[]', in: 'query', required: false, description: 'Filter by event type(s)', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', enum: ['audit', 'movement', 'genealogy', 'expiry_alert', 'recall']))),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated timeline events'),
            new OA\Response(response: 404, description: 'Lot not found'),
        ],
    )]
    public function timeline(Request $request, MaterialLot $lot): JsonResponse
    {
        $this->authorizeOrganization($lot);
        $this->authorizeBrand($lot);
        $this->authorize('view', $lot);

        $result = $this->timelineService->timeline($lot, [
            'types' => $request->input('types', []),
            'per_page' => min($request->integer('per_page', 50), 100),
            'page' => $request->integer('page', 1),
        ]);

        return response()->json([
            'data' => $result->items(),
            'meta' => [
                'total' => $result->total(),
                'per_page' => $result->perPage(),
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/material-lots/bulk-delete',
        summary: 'Bulk-delete material lots',
        tags: ['MaterialLots'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ids'],
            properties: [new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), minItems: 1, maxItems: 100)]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Bulk-delete result'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $orgId = $this->getOrganizationId();
        $deleted = 0;
        $errors = [];

        foreach ($request->input('ids') as $id) {
            $lot = MaterialLot::where('organization_id', $orgId)->find($id);

            if (! $lot) {
                $errors[] = ['id' => $id, 'message' => 'Not found'];

                continue;
            }

            try {
                $this->authorize('delete', $lot);
                $lot->delete();
                $deleted++;
            } catch (\Exception $e) {
                $errors[] = ['id' => $id, 'name' => $lot->lot_code ?? null, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => "{$deleted} items deleted.",
            'deleted' => $deleted,
            'errors' => $errors,
        ]);
    }
}
