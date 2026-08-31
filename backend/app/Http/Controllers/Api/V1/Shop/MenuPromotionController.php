<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\MenuPromotionStoreRequest;
use App\Http\Requests\MenuPromotionUpdateRequest;
use App\Http\Resources\MenuPromotionResource;
use App\Models\MenuPromotion;
use App\Services\Promotion\Contracts\MenuPromotionMutationFacade;
use App\Services\Promotion\MenuPromotionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Plan-019 — shop-scoped MenuPromotion CRUD + toggle.
 *
 * Mounted under /api/v1/shops/{shopSlug}/promotions. The `branch_id`
 * resolver attribute is set by ResolveShopFromSlug.
 */
class MenuPromotionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MenuPromotionService $service,
        private readonly MenuPromotionMutationFacade $mutations,
    ) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/promotions',
        summary: 'List promotions for a shop',
        tags: ['Promotions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'currently_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'applies_to', in: 'query', schema: new OA\Schema(type: 'string', enum: ['all_items', 'categories', 'products', 'mixed'])),
            new OA\Parameter(name: 'stacking_mode', in: 'query', schema: new OA\Schema(type: 'string', enum: ['exclusive_with_coupons', 'stackable_with_coupons'])),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated MenuPromotionResource')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MenuPromotion::class);

        $branchId = (string) $request->attributes->get('shop_id');
        $filters = $request->only(['is_active', 'currently_active', 'applies_to', 'stacking_mode', 'search', 'sort', 'per_page', 'with_trashed']);
        $filters['branch_id'] = $branchId;
        $filters['organization_id'] = $request->attributes->get('organization_id');
        $filters['brand_id'] = $request->attributes->get('brand_id');

        return MenuPromotionResource::collection($this->service->list($filters));
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/promotions',
        summary: 'Create a promotion',
        tags: ['Promotions'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['discount_percent', 'applies_to', 'valid_from', 'valid_until'],
            properties: [
                new OA\Property(property: 'discount_percent', type: 'number'),
                new OA\Property(property: 'applies_to', type: 'string', enum: ['all_items', 'categories', 'products', 'mixed']),
                new OA\Property(property: 'daily_time_from', type: 'string', nullable: true),
                new OA\Property(property: 'daily_time_to', type: 'string', nullable: true),
                new OA\Property(property: 'weekdays', type: 'array', items: new OA\Items(type: 'integer')),
                new OA\Property(property: 'valid_from', type: 'string', format: 'date-time'),
                new OA\Property(property: 'valid_until', type: 'string', format: 'date-time'),
                new OA\Property(property: 'stacking_mode', type: 'string', enum: ['exclusive_with_coupons', 'stackable_with_coupons']),
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'applicable_category_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                new OA\Property(property: 'applicable_product_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                new OA\Property(property: 'name', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
                new OA\Property(property: 'description', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'MenuPromotionResource'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function store(MenuPromotionStoreRequest $request): JsonResponse
    {
        $this->authorize('create', MenuPromotion::class);

        $data = $request->validated();
        $data['branch_id'] = $request->attributes->get('shop_id');
        $data['organization_id'] = $request->attributes->get('organization_id');
        $data['brand_id'] = $request->attributes->get('brand_id');

        $promotion = $this->mutations->create($data);

        return (new MenuPromotionResource($promotion))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/promotions/{promotion}',
        summary: 'Show one promotion',
        tags: ['Promotions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'promotion', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'MenuPromotionResource')],
    )]
    public function show(MenuPromotion $promotion): JsonResponse
    {
        $this->authorize('view', $promotion);

        $resource = new MenuPromotionResource($this->service->findById($promotion->id));

        return response()->json([
            'data' => $resource->resolve(request()),
            'meta' => $resource->withReport(),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/promotions/{promotion}/recent-items',
        summary: 'List recent order items that applied this promotion',
        tags: ['Promotions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'promotion', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 50, maximum: 200)),
        ],
        responses: [new OA\Response(response: 200, description: 'List of OrderItemSummary {id, customer_order_id, order_code, product_name, original_unit_price, unit_price, applied_at}')],
    )]
    public function recentItems(MenuPromotion $promotion, Request $request): JsonResponse
    {
        $this->authorize('view', $promotion);

        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));

        $items = $this->service->recentItems($promotion, $limit);

        return response()->json(['data' => $items]);
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/promotions/{promotion}',
        summary: 'Update a promotion (BR-MP-LOCK once items have applied)',
        tags: ['Promotions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'promotion', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'MenuPromotionResource'),
            new OA\Response(response: 422, description: 'Validation or promotion_field_locked'),
        ],
    )]
    public function update(MenuPromotionUpdateRequest $request, MenuPromotion $promotion): MenuPromotionResource
    {
        $this->authorize('update', $promotion);

        return new MenuPromotionResource($this->mutations->update($promotion, $request->validated()));
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/promotions/{promotion}',
        summary: 'Soft-delete a promotion (only when no order items have applied it)',
        tags: ['Promotions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'promotion', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 409, description: 'promotion_already_used_use_deactivate_instead'),
        ],
    )]
    public function destroy(MenuPromotion $promotion): JsonResponse
    {
        $this->authorize('delete', $promotion);

        $this->mutations->delete($promotion);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/promotions/{promotion}/toggle',
        summary: 'Toggle is_active on a promotion',
        tags: ['Promotions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'promotion', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'MenuPromotionResource')],
    )]
    public function toggle(MenuPromotion $promotion): MenuPromotionResource
    {
        $this->authorize('toggle', $promotion);

        return new MenuPromotionResource($this->mutations->toggle($promotion));
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/promotions/{promotion}/restore',
        summary: 'Restore a soft-deleted promotion',
        tags: ['Promotions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'promotion', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'MenuPromotionResource'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function restore(Request $request): MenuPromotionResource
    {
        $branchId = (string) $request->attributes->get('shop_id');
        $id = (string) $request->route('promotion');

        $promotion = MenuPromotion::withTrashed()
            ->where('branch_id', $branchId)
            ->findOrFail($id);

        $this->authorize('restore', $promotion);

        return new MenuPromotionResource($this->mutations->restore($promotion));
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/promotions/bulk-delete',
        summary: 'Bulk soft-delete promotions (skips those already used)',
        tags: ['Promotions'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ids'],
            properties: [new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))],
        )),
        responses: [new OA\Response(response: 200, description: '{deleted, errors[]}')],
    )]
    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $branchId = (string) $request->attributes->get('shop_id');
        $deleted = 0;
        $errors = [];

        foreach ($request->input('ids') as $id) {
            $model = MenuPromotion::where('branch_id', $branchId)->find($id);

            if (! $model) {
                $errors[] = ['id' => $id, 'message' => 'Not found'];

                continue;
            }

            try {
                $this->authorize('delete', $model);
                $this->mutations->delete($model);
                $deleted++;
            } catch (\Exception $e) {
                $errors[] = ['id' => $id, 'name' => $model->name ?? null, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'deleted' => $deleted,
            'errors' => $errors,
        ]);
    }
}
