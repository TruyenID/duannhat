<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\ToppingGroupItemSkuStoreRequest;
use App\Http\Requests\ToppingGroupItemSkuUpdateRequest;
use App\Http\Resources\ToppingGroupItemSkuResource;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Services\Topping\ToppingGroupItemSkuService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class ToppingGroupItemSkuController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly ToppingGroupItemSkuService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/topping-groups/{group}/items/{item}/skus',
        summary: 'List price overrides for a topping item',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'SKU price override list'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function index(ToppingGroup $group, ToppingGroupItem $item): AnonymousResourceCollection
    {
        $this->authorizeOrganization($group);
        $this->authorize('view', $group);

        abort_if($item->topping_group_id !== $group->id, 404);

        return ToppingGroupItemSkuResource::collection(
            $this->service->list($item)
        );
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/topping-groups/{group}/items/{item}/skus',
        summary: 'Set a price override for a topping item SKU',
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
                required: ['extra_price'],
                properties: [
                    new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'extra_price', type: 'number', format: 'float', minimum: 0),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Price override created'),
            new OA\Response(response: 422, description: 'Validation failed or duplicate'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function store(ToppingGroupItemSkuStoreRequest $request, ToppingGroup $group, ToppingGroupItem $item): JsonResponse
    {
        $this->authorizeOrganization($group);
        $this->authorize('update', $group);

        abort_if($item->topping_group_id !== $group->id, 404);

        try {
            $itemSku = $this->service->create($item, $request->validated());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['product_sku_id' => $e->getMessage()]);
        }

        return (new ToppingGroupItemSkuResource($itemSku))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/topping-groups/{group}/items/{item}/skus/{itemSku}',
        summary: 'Update a price override',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'itemSku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['extra_price'],
                properties: [
                    new OA\Property(property: 'extra_price', type: 'number', format: 'float', minimum: 0),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(ToppingGroupItemSkuUpdateRequest $request, ToppingGroup $group, ToppingGroupItem $item, ToppingGroupItemSku $itemSku): ToppingGroupItemSkuResource
    {
        $this->authorizeOrganization($group);
        $this->authorize('update', $group);

        abort_if($item->topping_group_id !== $group->id, 404);
        abort_if($itemSku->topping_group_item_id !== $item->id, 404);

        $itemSku = $this->service->update($itemSku, $request->validated());

        return new ToppingGroupItemSkuResource($itemSku);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/topping-groups/{group}/items/{item}/skus/{itemSku}',
        summary: 'Delete a price override',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'itemSku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(ToppingGroup $group, ToppingGroupItem $item, ToppingGroupItemSku $itemSku): JsonResponse
    {
        $this->authorizeOrganization($group);
        $this->authorize('update', $group);

        abort_if($item->topping_group_id !== $group->id, 404);
        abort_if($itemSku->topping_group_item_id !== $item->id, 404);

        $this->service->delete($itemSku);

        return response()->json(null, 204);
    }
}
