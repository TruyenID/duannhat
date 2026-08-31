<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Api\V1\Shop\Concerns\ShopBoundController;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AudienceResource;
use App\Models\NotificationAudience;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/shops/{shopSlug}/notifications/audiences*` — shop-level
 * audience CRUD (plan-023 M6 T6.6).
 *
 * Mirrors `NotificationAudienceAdminController` but scoped to one
 * branch. The shop admin can only see + edit audiences with
 * `branch_id = current_shop`; brand-level audiences (branch_id=null)
 * stay HQ-managed.
 *
 * Cross-shop pollution guards:
 *   - `brand_id` + `branch_id` are pinned from the URL context, never
 *     accepted from the request body.
 *   - `is_system` is forced to false on shop-scoped rows.
 *   - List query filters `branch_id = $shop->id`.
 *   - Detail/update/delete use `findOrFail($id)` AFTER the branch
 *     filter so cross-shop access returns 404.
 */
class ShopNotificationAudienceController extends Controller
{
    use AuthorizesRequests, ShopBoundController;

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/notifications/audiences',
        summary: 'List audiences scoped to this shop',
        tags: ['Shop - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated audience list')]
    )]
    public function index(Request $request): JsonResponse
    {
        $shop = $this->requireShop($request);
        $this->authorize('shop.notifications.manageAudiences', $shop);

        $paginator = NotificationAudience::query()
            ->where('branch_id', $shop->id)
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => AudienceResource::collection($paginator->items())->toArray($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/notifications/audiences',
        summary: 'Create a shop-scoped audience',
        tags: ['Shop - Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'Created')]
    )]
    public function store(Request $request): JsonResponse
    {
        $shop = $this->requireShop($request);
        $this->authorize('shop.notifications.manageAudiences', $shop);

        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'rule' => ['required', 'array'],
        ])->validate();

        $brand = $this->requireBrandForShop($shop);
        $orgIds = $this->shopOrgIds($shop);

        $row = NotificationAudience::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $orgIds[0] ?? null,
            'brand_id' => $brand?->id,
            'branch_id' => $shop->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'rule' => $data['rule'],
            'is_system' => false,                              // shop-scope cannot mint system rows
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => (new AudienceResource($row))->toArray($request)], 201);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/notifications/audiences/{id}',
        summary: 'Show one shop-scoped audience',
        tags: ['Shop - Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Audience'),
            new OA\Response(response: 404, description: 'Not found in this shop'),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $shop = $this->requireShop($request);
        $this->authorize('shop.notifications.manageAudiences', $shop);

        $row = NotificationAudience::query()
            ->where('branch_id', $shop->id)
            ->findOrFail($id);

        return response()->json(['data' => (new AudienceResource($row))->toArray($request)]);
    }

    #[OA\Patch(
        path: '/api/v1/shops/{shopSlug}/notifications/audiences/{id}',
        summary: 'Update a shop-scoped audience',
        tags: ['Shop - Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'Not found in this shop'),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $shop = $this->requireShop($request);
        $this->authorize('shop.notifications.manageAudiences', $shop);

        $data = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'rule' => ['sometimes', 'array'],
        ])->validate();

        $row = NotificationAudience::query()
            ->where('branch_id', $shop->id)
            ->findOrFail($id);

        $row->fill($data)->save();

        return response()->json(['data' => (new AudienceResource($row->fresh()))->toArray($request)]);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/notifications/audiences/{id}',
        summary: 'Delete a shop-scoped audience',
        tags: ['Shop - Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found in this shop'),
        ]
    )]
    public function destroy(Request $request, string $id): Response
    {
        $shop = $this->requireShop($request);
        $this->authorize('shop.notifications.manageAudiences', $shop);

        $row = NotificationAudience::query()
            ->where('branch_id', $shop->id)
            ->findOrFail($id);

        $row->delete();

        return response()->noContent();
    }
}
