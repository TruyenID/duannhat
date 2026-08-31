<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Requests\HQ\Notification\UpsertChannelRoutesRequest;
use App\Http\Resources\NotificationChannelRouteResource;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationChannelRoute;
use App\Models\Organization;
use App\Services\Notification\NotificationChannelRouteService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/hq/{brandSlug}/notifications/channel-routes*` (plan-012 T3.9).
 *
 * Admin routing matrix — declares which channels a given notification type
 * uses by default, plus priority-level overrides. Read by the effective-
 * channel selector inside `NotificationService::dispatch`.
 */
class NotificationChannelRouteAdminController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/channel-routes',
        summary: 'List channel-routes for the brand (routing matrix)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Channel routes collection'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageRouting', [Notification::class, $brand]);

        $orgIds = $this->brandOrgIds($brand);

        $routes = NotificationChannelRoute::query()
            ->whereIn('organization_id', $orgIds)
            ->orderBy('type')
            ->get();

        return response()->json([
            'data' => NotificationChannelRouteResource::collection($routes)->toArray($request),
        ]);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/notifications/channel-routes',
        summary: 'Bulk upsert channel-routes for the brand',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['routes'],
                properties: [
                    new OA\Property(
                        property: 'routes',
                        type: 'array',
                        items: new OA\Items(
                            required: ['type', 'channels'],
                            properties: [
                                new OA\Property(property: 'type', type: 'string', maxLength: 100),
                                new OA\Property(property: 'channels', type: 'array', items: new OA\Items(type: 'string', enum: ['in_app', 'realtime', 'email', 'push'])),
                                new OA\Property(property: 'priority_overrides', type: 'object', nullable: true),
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Upserted routes'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function upsert(UpsertChannelRoutesRequest $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageRouting', [Notification::class, $brand]);

        $orgIds = $this->brandOrgIds($brand);
        $orgId = $orgIds[0] ?? null;
        abort_if($orgId === null, 422, 'Brand has no organization binding.');

        $upserted = app(NotificationChannelRouteService::class)->upsert(
            $orgId,
            $brand,
            (array) $request->input('routes', []),
        );

        return response()->json([
            'data' => NotificationChannelRouteResource::collection($upserted)->toArray($request),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/notifications/channel-routes/{type}',
        summary: 'Delete a channel route so the type falls back to template default',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted or already absent'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function destroy(Request $request, string $type): Response
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageRouting', [Notification::class, $brand]);

        $orgIds = $this->brandOrgIds($brand);
        NotificationChannelRoute::query()
            ->whereIn('organization_id', $orgIds)
            ->where('type', $type)
            ->delete();

        return response()->noContent();
    }

    /**
     * @return array<int, string>
     */
    private function brandOrgIds(Brand $brand): array
    {
        return Organization::query()
            ->where('console_organization_id', $brand->console_organization_id)
            ->pluck('id')
            ->all();
    }
}
