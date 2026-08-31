<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Api\V1\Shop\Concerns\ShopBoundController;
use App\Http\Controllers\Controller;
use App\Models\NotificationChannelRoute;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/shops/{shopSlug}/notifications/channel-routes*` — shop-
 * level routing override (plan-023 M6 T6.8).
 *
 * A shop admin sets `notification_channel_routes` rows pinned to
 * `branch_id = current_shop`. EffectiveChannelService consumes the
 * routes in priority order branch → brand → org/system, so a shop
 * row wins for any notification dispatched inside that shop.
 *
 * Surface mirrors the HQ channel-routes controller: GET list, PUT
 * upsert by type, DELETE by type.
 */
class ShopNotificationChannelRouteController extends Controller
{
    use AuthorizesRequests, ShopBoundController;

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/notifications/channel-routes',
        summary: 'List shop-scoped routing overrides',
        tags: ['Shop - Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Per-type matrix')]
    )]
    public function index(Request $request): JsonResponse
    {
        $shop = $this->requireShop($request);
        $this->authorize('shop.notifications.manageRouting', $shop);

        $rows = NotificationChannelRoute::query()
            ->where('branch_id', $shop->id)
            ->orderBy('type')
            ->get();

        return response()->json(['data' => $rows]);
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/notifications/channel-routes',
        summary: 'Upsert shop-scoped routing for a notification type',
        tags: ['Shop - Notifications'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'channels'],
                properties: [
                    new OA\Property(property: 'type', type: 'string'),
                    new OA\Property(property: 'channels', type: 'object'),
                ]
            )
        ),
        responses: [new OA\Response(response: 200, description: 'Upserted')]
    )]
    public function upsert(Request $request): JsonResponse
    {
        $shop = $this->requireShop($request);
        $this->authorize('shop.notifications.manageRouting', $shop);

        $data = Validator::make($request->all(), [
            'type' => ['required', 'string', 'regex:/^[a-z0-9._-]+$/'],
            'channels' => ['required', 'array'],
            'priority_overrides' => ['nullable', 'array'],
        ])->validate();

        $brand = $this->requireBrandForShop($shop);
        $orgIds = $this->shopOrgIds($shop);

        $row = NotificationChannelRoute::query()->updateOrCreate(
            [
                'organization_id' => $orgIds[0] ?? null,
                'brand_id' => $brand?->id,
                'branch_id' => $shop->id,
                'type' => $data['type'],
            ],
            [
                'id' => (string) Str::uuid(),
                'channels' => $data['channels'],
                'priority_overrides' => $data['priority_overrides'] ?? null,
            ],
        );

        return response()->json(['data' => $row->fresh()]);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/notifications/channel-routes/{type}',
        summary: 'Drop a shop-scoped routing override',
        tags: ['Shop - Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Deleted')]
    )]
    public function destroy(Request $request, string $type): Response
    {
        $shop = $this->requireShop($request);
        $this->authorize('shop.notifications.manageRouting', $shop);

        NotificationChannelRoute::query()
            ->where('branch_id', $shop->id)
            ->where('type', $type)
            ->delete();

        return response()->noContent();
    }
}
