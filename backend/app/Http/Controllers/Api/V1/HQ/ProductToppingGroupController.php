<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\ProductToppingGroupItemOverrideSyncRequest;
use App\Http\Requests\ProductToppingGroupSyncRequest;
use App\Http\Resources\ProductToppingGroupItemOverrideResource;
use App\Http\Resources\ToppingGroupResource;
use App\Models\Product;
use App\Models\ToppingGroup;
use App\Services\Topping\ProductToppingGroupService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class ProductToppingGroupController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly ProductToppingGroupService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/products/{product}/topping-groups',
        summary: 'List topping groups assigned to a product',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Topping groups with items'),
            new OA\Response(response: 404, description: 'Product not found'),
        ]
    )]
    public function index(Product $product): AnonymousResourceCollection
    {
        $this->authorizeOrganization($product);
        $this->authorize('view', $product);

        return ToppingGroupResource::collection(
            $this->service->listForProduct($product)
        );
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/products/{product}/topping-groups/sync',
        summary: 'Sync topping groups assigned to a product',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['topping_group_ids'],
                properties: [
                    new OA\Property(property: 'topping_group_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                    new OA\Property(property: 'sort_orders', type: 'object', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Synced list of topping groups'),
            new OA\Response(response: 422, description: 'Cross-brand group assignment not allowed'),
            new OA\Response(response: 404, description: 'Product not found'),
        ]
    )]
    public function sync(ProductToppingGroupSyncRequest $request, Product $product): AnonymousResourceCollection
    {
        $this->authorizeOrganization($product);
        $this->authorize('update', $product);

        $validated = $request->validated();

        try {
            $groups = $this->service->syncForProduct(
                $product,
                $validated['topping_group_ids'],
                $validated['sort_orders'] ?? [],
                $validated['overrides'] ?? [],
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['topping_group_ids' => $e->getMessage()]);
        }

        return ToppingGroupResource::collection($groups);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/products/{product}/topping-groups/{group}/overrides',
        summary: 'List per-product overrides for a topping group attachment',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of override rows for this attachment'),
            new OA\Response(response: 404, description: 'Product or topping group not found'),
        ]
    )]
    public function listOverrides(Product $product, ToppingGroup $group): AnonymousResourceCollection
    {
        $this->authorizeOrganization($product);
        $this->authorize('view', $product);

        return ProductToppingGroupItemOverrideResource::collection(
            $this->service->listOverrides($product, $group)
        );
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/products/{product}/topping-groups/{group}/overrides/sync',
        summary: 'Sync per-product overrides for a topping group attachment',
        tags: ['ToppingGroups'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['overrides'],
                properties: [
                    new OA\Property(
                        property: 'overrides',
                        type: 'array',
                        items: new OA\Items(
                            required: ['topping_group_item_id'],
                            properties: [
                                new OA\Property(property: 'topping_group_item_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid', nullable: true),
                                new OA\Property(property: 'is_hidden', type: 'boolean'),
                                new OA\Property(property: 'override_price', type: 'number', nullable: true, minimum: 0),
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Saved override rows'),
            new OA\Response(response: 422, description: 'Validation error (item not in group, SKU mismatch, is_hidden+price conflict, duplicate row)'),
            new OA\Response(response: 404, description: 'Product or topping group not found'),
        ]
    )]
    public function syncOverrides(ProductToppingGroupItemOverrideSyncRequest $request, Product $product, ToppingGroup $group): AnonymousResourceCollection
    {
        $this->authorizeOrganization($product);
        $this->authorize('update', $product);

        try {
            $overrides = $this->service->syncOverrides(
                $product,
                $group,
                $request->validated('overrides', [])
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['overrides' => $e->getMessage()]);
        }

        return ProductToppingGroupItemOverrideResource::collection($overrides);
    }
}
