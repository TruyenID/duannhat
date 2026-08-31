<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\ZoneStoreRequest;
use App\Http\Requests\ZoneUpdateRequest;
use App\Http\Resources\ZoneResource;
use App\Models\Branch;
use App\Models\Zone;
use App\Services\Shop\ZoneService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class ZoneController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly ZoneService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/zones',
        summary: 'List zones in a shop',
        description: 'Returns a paginated list of zones (areas) for the resolved shop, ordered by display_order by default.',
        tags: ['Zones'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Search by name or code', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'with_trashed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'display_order')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of zones',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Zone')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Shop not found'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Zone::class);

        $zones = $this->service->list([
            'branch_id' => $this->resolvedShopId($request),
            'search' => $request->input('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', 'display_order'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return ZoneResource::collection($zones);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/zones',
        summary: 'Create a zone',
        description: 'Creates a new zone in the resolved shop. Requires Shop Manager or Org Admin role.',
        tags: ['Zones'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 50, description: 'Unique per shop. Alphanumeric + hyphens only.', example: 'TER'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Terrace'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'display_order', type: 'integer', minimum: 0, default: 0),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Zone created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Zone')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden — caller lacks manager role'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(ZoneStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Zone::class);

        $shop = $this->resolvedShop($request);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['branch_id'] = $shop->id;

        $zone = $this->service->create($data);

        return (new ZoneResource($zone))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/zones/{zone}',
        summary: 'Get a zone',
        description: 'Returns a single zone with table_count.',
        tags: ['Zones'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zone', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Zone details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Zone')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Zone not found in this shop'),
        ],
    )]
    public function show(Request $request): ZoneResource
    {
        $zone = $this->resolveZone($request);
        $this->authorize('view', $zone);

        return new ZoneResource($this->service->findById($zone->id));
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/zones/{zone}',
        summary: 'Update a zone',
        description: 'Partial update. All fields optional. Requires Shop Manager or Org Admin role.',
        tags: ['Zones'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zone', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'code', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'display_order', type: 'integer', minimum: 0, nullable: true),
                new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
            ]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Zone updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Zone')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Zone not found in this shop'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function update(ZoneUpdateRequest $request): ZoneResource
    {
        $zone = $this->resolveZone($request);
        $this->authorize('update', $zone);

        return new ZoneResource(
            $this->service->update($zone, $request->validated())
        );
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/zones/{zone}',
        summary: 'Soft-delete a zone',
        description: 'Soft-deletes the zone and cascades soft-delete to all tables in the zone (BR-Z02). Restoring the zone does NOT auto-restore the tables.',
        tags: ['Zones'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zone', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Zone soft-deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Zone not found in this shop'),
        ],
    )]
    public function destroy(Request $request): JsonResponse
    {
        $zone = $this->resolveZone($request);
        $this->authorize('delete', $zone);

        $this->service->delete($zone);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/zones/{zone}/restore',
        summary: 'Restore a soft-deleted zone',
        description: 'Restores the zone but does NOT auto-restore its tables (BR-Z03).',
        tags: ['Zones'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zone', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Zone restored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Zone')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Zone not found'),
        ],
    )]
    public function restore(Request $request): ZoneResource
    {
        $shop = $this->resolvedShop($request);
        $id = (string) $request->route('zone');

        $model = Zone::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail($id);

        $this->authorize('restore', $model);

        return new ZoneResource($this->service->restore($model));
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/zones/{zone}/toggle-active',
        summary: 'Toggle is_active flag',
        description: 'Flips the is_active flag on the zone.',
        tags: ['Zones'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zone', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Zone toggled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Zone')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Zone not found'),
        ],
    )]
    public function toggleActive(Request $request): ZoneResource
    {
        $zone = $this->resolveZone($request);
        $this->authorize('toggleActive', $zone);

        return new ZoneResource($this->service->toggleActive($zone));
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/zones/lookup',
        summary: 'Lightweight zone lookup',
        description: 'Returns active zones [{id, code, name, display_order}] for select/combobox controls.',
        tags: ['Zones'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'display_order', type: 'integer'),
                ])),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Zone::class);

        return response()->json([
            'data' => $this->service->lookup($this->resolvedShopId($request)),
        ]);
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    private function resolvedShop(Request $request): Branch
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        return $shop;
    }

    private function resolvedShopId(Request $request): string
    {
        return $request->attributes->get('shop_id');
    }

    private function resolveZone(Request $request): Zone
    {
        $shop = $this->resolvedShop($request);
        $id = (string) $request->route('zone');

        return Zone::where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail($id);
    }
}
