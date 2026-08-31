<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasBulkOperations;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\WarehouseStoreRequest;
use App\Http\Requests\WarehouseUpdateRequest;
use App\Http\Resources\WarehouseMemberResource;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use App\Models\WarehouseMember;
use App\Services\Inventory\WarehouseService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/shops/{shopSlug}/warehouses/bulk-delete',
    summary: 'Bulk-delete warehouses',
    description: 'Soft-deletes up to 100 warehouses at once. Returns deleted count and any per-id errors.',
    tags: ['Warehouses'],
    security: [['sanctum' => []]],
    parameters: [
        new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['ids'],
            properties: [
                new OA\Property(property: 'ids', type: 'array', maxItems: 100, items: new OA\Items(type: 'string', format: 'uuid')),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Bulk-delete result'),
        new OA\Response(response: 401, description: 'Unauthenticated'),
        new OA\Response(response: 422, description: 'Validation failed'),
    ]
)]
class WarehouseController extends Controller
{
    use AuthorizesRequests;
    use HasBulkOperations;
    use HasOrganizationContext;

    public function __construct(
        private readonly WarehouseService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/warehouses',
        summary: 'List warehouses',
        description: 'Returns a paginated list of warehouses in the shop. Supports search, active filter, type, branch filter, sort and pagination.',
        tags: ['Warehouses'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'with_trashed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: '-created_at')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of warehouses',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Warehouse')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Warehouse::class);

        // Shop endpoints are branch-scoped: a shop owns a single branch
        // (ResolveShopFromSlug sets `branch_id` on the request). Default to
        // that branch so the warehouse picker on every shop screen only
        // surfaces warehouses the shop's brand actually owns. The explicit
        // `branch_id` query param still wins for HQ-level callers.
        $branchId = $request->input('branch_id') ?? $request->attributes->get('shop_id');

        $warehouses = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'search' => $request->input('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'type' => $request->input('type'),
            'branch_id' => $branchId,
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return WarehouseResource::collection($warehouses);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/warehouses',
        summary: 'Create a warehouse',
        description: 'Creates a new warehouse in the shop. Code auto-generated when empty.',
        tags: ['Warehouses'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'type'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'type', type: 'string'),
                    new OA\Property(property: 'code', type: 'string', maxLength: 50, nullable: true),
                    new OA\Property(property: 'branch_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'address', type: 'string', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
                    new OA\Property(property: 'auto_approve_stock_in', type: 'boolean', nullable: true),
                    new OA\Property(property: 'auto_approve_stock_out', type: 'boolean', nullable: true),
                    new OA\Property(property: 'auto_approve_batch', type: 'boolean', nullable: true),
                    new OA\Property(property: 'auto_approve_disposal', type: 'boolean', nullable: true),
                    new OA\Property(property: 'disposal_approval_threshold', type: 'number', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Warehouse created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Warehouse')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(WarehouseStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Warehouse::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        // Shop-scoped create defaults branch_id to the resolved shop branch
        // so newly created warehouses surface in the same list the picker
        // uses (index() filters by `branch_id = shop branch`).
        if (empty($data['branch_id']) && $request->attributes->has('shop_id')) {
            $data['branch_id'] = $request->attributes->get('shop_id');
        }

        $warehouse = $this->service->create($data);

        return (new WarehouseResource($warehouse))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/warehouses/{warehouse}',
        summary: 'Get a warehouse',
        description: 'Returns a single warehouse by UUID, including relations.',
        tags: ['Warehouses'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Warehouse details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Warehouse')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Warehouse not found'),
        ]
    )]
    public function show(Warehouse $warehouse): WarehouseResource
    {
        $this->authorizeOrganization($warehouse);
        $this->authorize('view', $warehouse);

        return new WarehouseResource(
            $this->service->findById($warehouse->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/warehouses/{warehouse}',
        summary: 'Update a warehouse',
        description: 'Updates a warehouse. All fields are optional for partial updates.',
        tags: ['Warehouses'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'type', type: 'string', nullable: true),
                new OA\Property(property: 'code', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'branch_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'address', type: 'string', nullable: true),
                new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Warehouse updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Warehouse')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Warehouse not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(WarehouseUpdateRequest $request, Warehouse $warehouse): WarehouseResource
    {
        $this->authorizeOrganization($warehouse);
        $this->authorize('update', $warehouse);

        $warehouse = $this->service->update($warehouse, $request->validated());

        return new WarehouseResource($warehouse);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/warehouses/{warehouse}',
        summary: 'Delete a warehouse',
        description: 'Soft-deletes a warehouse. Use the restore endpoint to recover.',
        tags: ['Warehouses'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Warehouse deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Warehouse not found'),
        ]
    )]
    public function destroy(Warehouse $warehouse): JsonResponse
    {
        $this->authorizeOrganization($warehouse);
        $this->authorize('delete', $warehouse);

        $this->service->delete($warehouse);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/warehouses/{warehouse}/restore',
        summary: 'Restore a soft-deleted warehouse',
        description: 'Restores a previously soft-deleted warehouse.',
        tags: ['Warehouses'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Warehouse restored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Warehouse')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Warehouse not found'),
        ]
    )]
    public function restore(string $id): WarehouseResource
    {
        $warehouse = Warehouse::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            ->findOrFail($id);

        $this->authorize('restore', $warehouse);

        $warehouse = $this->service->restore($warehouse);

        return new WarehouseResource($warehouse);
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/warehouses/lookup',
        summary: 'Lookup warehouses for select/combobox',
        description: 'Returns a lightweight list of warehouses (id + label) for use in dropdowns.',
        tags: ['Warehouses'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Warehouse::class);

        $branchId = $request->attributes->get('shop_id');

        return response()->json([
            'data' => $this->service->lookup($this->getOrganizationId(), $branchId),
        ]);
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/warehouses/{warehouse}/toggle-active',
        summary: 'Toggle warehouse active flag',
        description: 'Flips the warehouse is_active state. Inactive warehouses cannot receive new stock transactions.',
        tags: ['Warehouses'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Warehouse updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Warehouse')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Warehouse not found'),
        ]
    )]
    public function toggleActive(Warehouse $warehouse): WarehouseResource
    {
        $this->authorizeOrganization($warehouse);
        $this->authorize('toggleActive', $warehouse);

        $warehouse = $this->service->toggleActive($warehouse);

        return new WarehouseResource($warehouse);
    }

    #[OA\Patch(
        path: '/api/v1/shops/{shopSlug}/warehouses/{warehouse}/settings',
        summary: 'Update warehouse auto-approval settings',
        description: 'Updates auto-approval flags and disposal thresholds for a warehouse.',
        tags: ['Warehouses'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'auto_approve_stock_in', type: 'boolean', nullable: true),
                new OA\Property(property: 'auto_approve_stock_out', type: 'boolean', nullable: true),
                new OA\Property(property: 'auto_approve_batch', type: 'boolean', nullable: true),
                new OA\Property(property: 'auto_approve_disposal', type: 'boolean', nullable: true),
                new OA\Property(property: 'disposal_approval_threshold', type: 'number', nullable: true),
                new OA\Property(property: 'allow_negative_sales', type: 'boolean', nullable: true, description: 'Plan-024 — allow sales-flow stock_out to drive StockLevel.quantity below zero. Manual transactions remain strict.'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Settings updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Warehouse')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Warehouse not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function updateSettings(Request $request, Warehouse $warehouse): WarehouseResource
    {
        $this->authorizeOrganization($warehouse);
        $this->authorize('updateSettings', $warehouse);

        $validated = $request->validate([
            'auto_approve_stock_in' => ['sometimes', 'boolean'],
            'auto_approve_stock_out' => ['sometimes', 'boolean'],
            'auto_approve_batch' => ['sometimes', 'boolean'],
            'auto_approve_disposal' => ['sometimes', 'boolean'],
            'disposal_approval_threshold' => ['nullable', 'numeric', 'min:0'],
            // Plan-024 — sales-flow allow-negative policy. Strict by default.
            'allow_negative_sales' => ['sometimes', 'boolean'],
        ]);

        $warehouse = $this->service->updateSettings($warehouse, $validated);

        return new WarehouseResource($warehouse);
    }

    // =========================================================================
    //  Members
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/warehouses/{warehouse}/members',
        summary: 'List warehouse members',
        description: 'Returns the staff/managers assigned to the warehouse.',
        tags: ['Warehouses'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Member list', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Warehouse not found'),
        ]
    )]
    public function listMembers(Warehouse $warehouse): AnonymousResourceCollection
    {
        $this->authorizeOrganization($warehouse);
        $this->authorize('manageMembers', $warehouse);

        $members = $this->service->listMembers($warehouse);

        return WarehouseMemberResource::collection($members);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/warehouses/{warehouse}/members',
        summary: 'Add a member to the warehouse',
        description: 'Assigns a user to the warehouse with a manager or staff role.',
        tags: ['Warehouses'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['user_id', 'role'],
                properties: [
                    new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'role', type: 'string', enum: ['manager', 'staff']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Member added'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function addMember(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeOrganization($warehouse);
        $this->authorize('manageMembers', $warehouse);

        $validated = $request->validate([
            'user_id' => ['required', 'string', 'uuid'],
            'role' => ['required', 'string', 'in:manager,staff'],
        ]);

        $member = $this->service->addMember($warehouse, $validated['user_id'], $validated['role']);

        return (new WarehouseMemberResource($member))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/warehouses/{warehouse}/members/{member}',
        summary: 'Update a warehouse member role',
        description: 'Updates an existing member assignment to a different role.',
        tags: ['Warehouses'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'member', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['role'],
                properties: [
                    new OA\Property(property: 'role', type: 'string', enum: ['manager', 'staff']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Member updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Member not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function updateMemberRole(Request $request, Warehouse $warehouse, WarehouseMember $member): WarehouseMemberResource
    {
        $this->authorizeOrganization($warehouse);
        $this->authorize('manageMembers', $warehouse);

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:manager,staff'],
        ]);

        $member = $this->service->updateMemberRole($member, $validated['role']);

        return new WarehouseMemberResource($member);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/warehouses/{warehouse}/members/{member}',
        summary: 'Remove a warehouse member',
        description: 'Removes a user assignment from the warehouse.',
        tags: ['Warehouses'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'member', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Member removed'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Member not found'),
        ]
    )]
    public function removeMember(Warehouse $warehouse, WarehouseMember $member): JsonResponse
    {
        $this->authorizeOrganization($warehouse);
        $this->authorize('manageMembers', $warehouse);

        $this->service->removeMember($member);

        return response()->json(null, 204);
    }

    // =========================================================================
    //  Bulk Operations
    // =========================================================================

    protected function getModelClass(): string
    {
        return Warehouse::class;
    }
}
