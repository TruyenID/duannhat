<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\ToppingGroupItemStoreRequest;
use App\Http\Resources\ToppingGroupItemResource;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Services\Topping\ToppingGroupItemService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class ToppingGroupItemController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly ToppingGroupItemService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/topping-groups/{group}/items',
        summary: 'List items in a topping group',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item list'),
            new OA\Response(response: 404, description: 'Group not found'),
        ]
    )]
    public function index(ToppingGroup $group): AnonymousResourceCollection
    {
        $this->authorizeOrganization($group);
        $this->authorize('view', $group);

        return ToppingGroupItemResource::collection(
            $this->service->list($group)
        );
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/topping-groups/{group}/items',
        summary: 'Add a topping product to a group',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_id'],
                properties: [
                    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'sort_order', type: 'integer', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Item added'),
            new OA\Response(response: 422, description: 'Validation failed or product type mismatch'),
            new OA\Response(response: 404, description: 'Group or product not found'),
        ]
    )]
    public function store(ToppingGroupItemStoreRequest $request, ToppingGroup $group): JsonResponse
    {
        $this->authorizeOrganization($group);
        $this->authorize('update', $group);

        try {
            $item = $this->service->addItem($group, $request->validated());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['product_id' => $e->getMessage()]);
        }

        return (new ToppingGroupItemResource($item))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/topping-groups/{group}/items/{item}',
        summary: 'Update a topping group item (sort order)',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sort_order'],
                properties: [
                    new OA\Property(property: 'sort_order', type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(Request $request, ToppingGroup $group, ToppingGroupItem $item): ToppingGroupItemResource
    {
        $this->authorizeOrganization($group);
        $this->authorize('update', $group);

        abort_if($item->topping_group_id !== $group->id, 404);

        $validated = $request->validate([
            'sort_order' => ['sometimes', 'integer'],
            // is_default — admin can flip the default after creation. Service
            // enforces single-select uniqueness (at most one default per group).
            'is_default' => ['sometimes', 'boolean'],
        ]);

        try {
            $item = $this->service->updateItem($item, $validated);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['is_default' => $e->getMessage()]);
        }

        return new ToppingGroupItemResource($item);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/topping-groups/{group}/items/reorder',
        summary: 'Reorder items in a topping group',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['item_ids'],
                properties: [
                    new OA\Property(property: 'item_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Reordered'),
            new OA\Response(response: 404, description: 'Group not found'),
        ]
    )]
    public function reorder(Request $request, ToppingGroup $group): JsonResponse
    {
        $this->authorizeOrganization($group);
        $this->authorize('update', $group);

        $validated = $request->validate([
            'item_ids' => ['required', 'array'],
            'item_ids.*' => ['required', 'uuid'],
        ]);

        $this->service->reorderItems($group, $validated['item_ids']);

        return response()->json(['message' => 'Reordered successfully']);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/topping-groups/{group}/items/sync',
        summary: 'Full-sync a group\'s items to a product list (add + reorder + remove)',
        description: 'Replaces the group\'s product set with product_ids, in order. Products not in the list are removed; new ones are added; sort_order matches the array. The admin panel edits client-side and saves once via this endpoint.',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_ids'],
                properties: [
                    new OA\Property(property: 'product_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Synced item list'),
            new OA\Response(response: 422, description: 'Validation failed or product type mismatch'),
            new OA\Response(response: 404, description: 'Group not found'),
        ]
    )]
    public function sync(Request $request, ToppingGroup $group): AnonymousResourceCollection
    {
        $this->authorizeOrganization($group);
        $this->authorize('update', $group);

        $validated = $request->validate([
            'product_ids' => ['present', 'array'],
            'product_ids.*' => ['required', 'uuid'],
        ]);

        try {
            $items = $this->service->syncItems($group, $validated['product_ids']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['product_ids' => $e->getMessage()]);
        }

        return ToppingGroupItemResource::collection($items);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/topping-groups/{group}/items/{item}',
        summary: 'Remove a topping item from a group (soft delete)',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Removed'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(ToppingGroup $group, ToppingGroupItem $item): JsonResponse
    {
        $this->authorizeOrganization($group);
        $this->authorize('update', $group);

        abort_if($item->topping_group_id !== $group->id, 404);

        $this->service->removeItem($item);

        return response()->json(null, 204);
    }
}
