<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\TableChangeStatusRequest;
use App\Http\Requests\TableStoreRequest;
use App\Http\Requests\TableUpdateRequest;
use App\Http\Resources\TableResource;
use App\Http\Resources\TableStatusChangeResource;
use App\Models\Branch;
use App\Models\Table;
use App\Services\Shop\TableService;
use App\Services\Shop\TableStatusService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class TableController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly TableService $service,
        private readonly TableStatusService $statusService,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/tables',
        summary: 'List tables in a shop',
        description: 'Returns a paginated list of tables for the resolved shop. Filterable by zone_id, status, is_active, search.',
        tags: ['Tables'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zone_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['free', 'occupied', 'reserved', 'cleaning', 'out_of_service'])),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'with_trashed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'code')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of tables',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Table')),
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
        $this->authorize('viewAny', Table::class);

        $tables = $this->service->list([
            'branch_id' => $this->resolvedShopId($request),
            'zone_id' => $request->input('zone_id'),
            'status' => $request->input('status'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'search' => $request->input('search'),
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', 'code'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return TableResource::collection($tables);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/tables',
        summary: 'Create a table',
        description: 'Creates a new table in the resolved shop. status defaults to free, is_active to true, qr_token is auto-minted server-side. zone_id must belong to the resolved shop. Requires Shop Manager or Org Admin.',
        tags: ['Tables'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'seat_count', 'zone_id'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 50, description: 'Unique per shop. Alphanumeric + hyphens only.', example: 'T-01'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true, example: 'Window seat'),
                    new OA\Property(property: 'seat_count', type: 'integer', minimum: 1, maximum: 1000, example: 4),
                    new OA\Property(property: 'zone_id', type: 'string', format: 'uuid'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Table created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Table')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden — caller lacks manager role'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(TableStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Table::class);

        $shop = $this->resolvedShop($request);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['branch_id'] = $shop->id;

        $table = $this->service->create($data);

        return (new TableResource($table))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/tables/{table}',
        summary: 'Get a table',
        description: 'Returns a single table with zone snippet and last_status_change.',
        tags: ['Tables'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'table', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Table details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Table')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Table not found in this shop'),
        ],
    )]
    public function show(Request $request): TableResource
    {
        $table = $this->resolveTable($request);
        $this->authorize('view', $table);

        return new TableResource($this->service->findById($table->id));
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/tables/{table}',
        summary: 'Update a table',
        description: 'Partial update. status and qr_token are NOT mutable through this endpoint — use /status and /regenerate-qr respectively.',
        tags: ['Tables'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'table', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'code', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'seat_count', type: 'integer', minimum: 1, maximum: 1000, nullable: true),
                new OA\Property(property: 'zone_id', type: 'string', format: 'uuid', nullable: true, description: 'Must belong to the resolved shop.'),
            ]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Table updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Table')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Table not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function update(TableUpdateRequest $request): TableResource
    {
        $table = $this->resolveTable($request);
        $this->authorize('update', $table);

        return new TableResource(
            $this->service->update($table, $request->validated())
        );
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/tables/{table}',
        summary: 'Soft-delete a table',
        tags: ['Tables'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'table', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Table soft-deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Table not found'),
        ],
    )]
    public function destroy(Request $request): JsonResponse
    {
        $table = $this->resolveTable($request);
        $this->authorize('delete', $table);

        $this->service->delete($table);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/tables/{table}/restore',
        summary: 'Restore a soft-deleted table',
        tags: ['Tables'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'table', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Table restored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Table')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Table not found'),
        ],
    )]
    public function restore(Request $request): TableResource
    {
        $shop = $this->resolvedShop($request);
        $id = (string) $request->route('table');

        $model = Table::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail($id);

        $this->authorize('restore', $model);

        return new TableResource($this->service->restore($model));
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/tables/{table}/toggle-active',
        summary: 'Toggle is_active flag',
        description: 'Inactive tables cannot have their runtime status changed (BR-T03).',
        tags: ['Tables'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'table', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Table toggled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Table')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Table not found'),
        ],
    )]
    public function toggleActive(Request $request): TableResource
    {
        $table = $this->resolveTable($request);
        $this->authorize('toggleActive', $table);

        return new TableResource($this->service->toggleActive($table));
    }

    // =========================================================================
    //  Runtime status
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/tables/{table}/status',
        summary: 'Change runtime table status',
        description: 'Transitions the table to a new runtime status and writes one row in table_status_changes. Allowed for Shop Staff (the only mutation Staff can do). Free transitions in v1 — any status can move to any other. Inactive tables are blocked.',
        tags: ['Tables'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'table', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['free', 'occupied', 'reserved', 'cleaning', 'out_of_service']),
                    new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Status changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Table')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Table not found'),
            new OA\Response(response: 422, description: 'Invalid status enum', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
            new OA\Response(response: 500, description: 'Inactive table — cannot change runtime status'),
        ],
    )]
    public function changeStatus(TableChangeStatusRequest $request): TableResource
    {
        $table = $this->resolveTable($request);
        $this->authorize('changeStatus', $table);

        $updated = $this->statusService->changeStatus(
            table: $table,
            toStatus: $request->validated('status'),
            userId: $request->user()->id,
            note: $request->validated('note'),
        );

        return new TableResource($updated);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/tables/{table}/status-history',
        summary: 'List status transition history for a table',
        description: 'Returns paginated TableStatusChange rows newest-first. Append-only audit log.',
        tags: ['Tables'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'table', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated status history',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TableStatusChange')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Table not found'),
        ],
    )]
    public function statusHistory(Request $request): AnonymousResourceCollection
    {
        $table = $this->resolveTable($request);
        $this->authorize('view', $table);

        $perPage = min($request->integer('per_page', 25), 100);

        return TableStatusChangeResource::collection(
            $this->statusService->history($table, $perPage)
        );
    }

    // =========================================================================
    //  QR token rotation
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/tables/{table}/regenerate-qr',
        summary: 'Rotate the table QR token',
        description: 'Issues a new opaque qr_token, invalidating the previous one. The frontend renders the QR client-side from the returned token. Manager+Admin only.',
        tags: ['Tables'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'table', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'QR token rotated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Table')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden — caller lacks manager role'),
            new OA\Response(response: 404, description: 'Table not found'),
        ],
    )]
    public function regenerateQr(Request $request): TableResource
    {
        $table = $this->resolveTable($request);
        $this->authorize('regenerateQr', $table);

        return new TableResource($this->service->regenerateQr($table));
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

    private function resolveTable(Request $request): Table
    {
        $shop = $this->resolvedShop($request);
        $id = (string) $request->route('table');

        return Table::where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail($id);
    }
}
