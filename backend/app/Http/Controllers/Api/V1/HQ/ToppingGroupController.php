<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Exceptions\ToppingGroupInUseException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasBulkOperations;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\ToppingGroupStoreRequest;
use App\Http\Requests\ToppingGroupUpdateRequest;
use App\Http\Resources\ToppingGroupResource;
use App\Models\ToppingGroup;
use App\Services\Topping\ToppingGroupService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class ToppingGroupController extends Controller
{
    use AuthorizesRequests;
    use HasBulkOperations;
    use HasOrganizationContext;

    public function __construct(
        private readonly ToppingGroupService $service,
    ) {}

    protected function getModelClass(): string
    {
        return ToppingGroup::class;
    }

    protected function getBulkDeleteExtraScope(): array
    {
        return ['brand_id' => request()->attributes->get('brand_id')];
    }

    /**
     * Override the trait's bulkDelete so the in-use guard from
     * ToppingGroupService::delete() applies per id. Groups referenced by ≥1
     * product land in `errors[]` with their used_by list; the remaining
     * groups still soft-delete. Mirrors the trait's response shape so the
     * admin-web bulk-delete UI keeps working unchanged.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $orgId = $this->getOrganizationId();
        $brandId = $request->attributes->get('brand_id');
        $deleted = 0;
        $errors = [];

        foreach ($request->input('ids') as $id) {
            $group = ToppingGroup::where('organization_id', $orgId)
                ->where('brand_id', $brandId)
                ->find($id);

            if (! $group) {
                $errors[] = ['id' => $id, 'message' => 'Not found'];

                continue;
            }

            try {
                $this->authorize('delete', $group);
                $this->service->delete($group);
                $deleted++;
            } catch (ToppingGroupInUseException $e) {
                $errors[] = [
                    'id' => $id,
                    'message' => $e->getMessage(),
                    'error' => 'TOPPING_GROUP_IN_USE',
                    'used_by' => $e->usedBy,
                ];
            } catch (\Exception $e) {
                $errors[] = ['id' => $id, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => "{$deleted} items deleted.",
            'deleted' => $deleted,
            'errors' => $errors,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/topping-groups',
        summary: 'List topping groups',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ToppingGroup::class);

        $groups = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'brand_id' => $request->attributes->get('brand_id'),
            'search' => $request->input('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return ToppingGroupResource::collection($groups);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/topping-groups',
        summary: 'Create a topping group',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'min_select', 'max_qty_per_item'],
                properties: [
                    new OA\Property(property: 'name', type: 'object', description: 'Translatable name {ja, en, vi}'),
                    new OA\Property(property: 'min_select', type: 'integer', minimum: 0),
                    new OA\Property(property: 'max_select', type: 'integer', nullable: true),
                    new OA\Property(property: 'max_qty_per_item', type: 'integer', minimum: 1),
                    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation failed'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function store(ToppingGroupStoreRequest $request): JsonResponse
    {
        $this->authorize('create', ToppingGroup::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['brand_id'] = $request->attributes->get('brand_id');

        $group = $this->service->create($data);

        return (new ToppingGroupResource($group))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/topping-groups/{group}',
        summary: 'Show a topping group',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Topping group details'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(ToppingGroup $group): ToppingGroupResource
    {
        $this->authorizeOrganization($group);
        $this->authorize('view', $group);

        return new ToppingGroupResource($group->load('translations'));
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/topping-groups/{group}',
        summary: 'Update a topping group',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'object', nullable: true),
                    new OA\Property(property: 'min_select', type: 'integer', nullable: true),
                    new OA\Property(property: 'max_select', type: 'integer', nullable: true),
                    new OA\Property(property: 'max_qty_per_item', type: 'integer', nullable: true),
                    new OA\Property(property: 'sort_order', type: 'integer', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(ToppingGroupUpdateRequest $request, ToppingGroup $group): ToppingGroupResource
    {
        $this->authorizeOrganization($group);
        $this->authorize('update', $group);

        $group = $this->service->update($group, $request->validated());

        return new ToppingGroupResource($group);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/topping-groups/{group}',
        summary: 'Soft-delete a topping group',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(ToppingGroup $group): JsonResponse
    {
        $this->authorizeOrganization($group);
        $this->authorize('delete', $group);

        $this->service->delete($group);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/topping-groups/{group}/restore',
        summary: 'Restore a soft-deleted topping group',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Restored'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function restore(Request $request, string $group): ToppingGroupResource
    {
        $toppingGroup = ToppingGroup::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            ->where('brand_id', $request->attributes->get('brand_id'))
            ->findOrFail($group);

        $this->authorize('restore', $toppingGroup);

        $toppingGroup = $this->service->restore($toppingGroup);

        return new ToppingGroupResource($toppingGroup);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/topping-groups/reorder',
        summary: 'Reorder topping groups by sort_order',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['topping_group_ids'],
                properties: [
                    new OA\Property(property: 'topping_group_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Reordered'),
            new OA\Response(response: 422, description: 'Validation failed'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function reorder(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ToppingGroup::class);

        $data = $request->validate([
            'topping_group_ids' => ['required', 'array'],
            'topping_group_ids.*' => ['required', 'string', 'uuid'],
        ]);

        $this->service->reorder(
            $request->attributes->get('brand_id'),
            $data['topping_group_ids']
        );

        return response()->json(['message' => 'Topping groups reordered.']);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/topping-groups/lookup',
        summary: 'Lightweight lookup list of topping groups',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list'),
        ]
    )]
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ToppingGroup::class);

        return response()->json([
            'data' => $this->service->lookup(
                $request->attributes->get('brand_id'),
            ),
        ]);
    }
}
