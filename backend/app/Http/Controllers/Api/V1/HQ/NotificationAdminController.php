<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Requests\HQ\Notification\IndexAdminNotificationRequest;
use App\Http\Resources\Admin\AdminNotificationDetailResource;
use App\Http\Resources\Admin\AdminNotificationListResource;
use App\Models\Brand;
use App\Models\Notification;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/hq/{brandSlug}/notifications/*` — brand-scoped audit of every
 * notification in the brand's organizations. Read-only in Phase A.
 */
class NotificationAdminController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly NotificationService $service) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications',
        summary: 'Audit list of every notification in the brand',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(oneOf: [new OA\Schema(type: 'string'), new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))])),
            new OA\Parameter(name: 'actor_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['User', 'Device', 'null'])),
            new OA\Parameter(name: 'actor_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'recipient_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['User', 'Device'])),
            new OA\Parameter(name: 'recipient_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'priority', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['low', 'normal', 'high', 'urgent'])),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'organization_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated audit list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden — not an HQ admin of this brand'),
            new OA\Response(response: 422, description: 'Invalid filter'),
        ]
    )]
    public function index(IndexAdminNotificationRequest $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('viewAdmin', [Notification::class, $brand]);

        $paginator = $this->service->listForBrand($brand, $request->validated());

        return response()->json([
            'data' => AdminNotificationListResource::collection($paginator->items())->toArray($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/{id}',
        summary: 'Notification detail — full fan-out with per-recipient state',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Notification not in this brand / does not exist'),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('viewAdmin', [Notification::class, $brand]);

        $notification = $this->service->showForBrand($brand, $id);

        return response()->json([
            'data' => (new AdminNotificationDetailResource($notification))->toArray($request),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/types',
        summary: 'Distinct notification types in use within the brand (for filter dropdown)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Types with counts'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function types(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('viewAdmin', [Notification::class, $brand]);

        return response()->json([
            'data' => $this->service->typesForBrand($brand)->all(),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/stats',
        summary: 'Quick header counts: sent / scheduled / failed (TC-NOTIF-ROOT04)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Counts'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function stats(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('viewAdmin', [Notification::class, $brand]);

        return response()->json([
            'data' => $this->service->statsForBrand($brand),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/notifications/{id}',
        summary: 'Cancel a scheduled broadcast (plan-012 T4.14)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Cancelled'),
            new OA\Response(response: 404, description: 'Notification not in this brand'),
            new OA\Response(response: 422, description: 'Cannot cancel — already dispatched or within the 60s freeze'),
        ]
    )]
    public function destroy(Request $request, string $id): Response
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('compose', [Notification::class, $brand]);

        // Guards + delete live in the service (#1666); the 60-second freeze
        // window is now ONE constant shared with the schedule canceller.
        $this->service->cancelScheduled($this->service->showForBrand($brand, $id));

        return response()->noContent();
    }
}
