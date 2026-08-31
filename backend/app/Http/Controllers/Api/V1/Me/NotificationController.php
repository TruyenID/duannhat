<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Requests\Me\Notification\BulkDismissRequest;
use App\Http\Requests\Me\Notification\BulkReadRequest;
use App\Http\Requests\Me\Notification\IndexNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Services\Notification\InboxCollapseService;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/me/notifications/*` — the authenticated user's own inbox.
 *
 * Row-level access is enforced at every endpoint via
 * `NotificationRecipient::forRecipient($user)` — a caller who isn't a
 * recipient of a notification gets 404 (existence-hiding).
 */
class NotificationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly NotificationService $service) {}

    #[OA\Get(
        path: '/api/v1/me/notifications',
        summary: "List the authenticated user's inbox",
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['unread', 'read', 'all'])),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'priority', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['low', 'normal', 'high', 'urgent'])),
            new OA\Parameter(name: 'since', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'include_dismissed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated inbox'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Invalid filter'),
        ]
    )]
    public function index(IndexNotificationRequest $request): JsonResponse
    {
        $this->authorize('viewInbox', Notification::class);

        $user = $request->user();
        $filters = $request->validated();

        // Plan-023 M5 T5.2 — `?collapse=true` (default) groups by
        // aggregation_key via InboxCollapseService; `?collapse=false`
        // keeps the original flat listing for back-compat with FE
        // that hasn't adopted the collapsed shape yet.
        $collapse = $request->boolean('collapse', true);
        if ($collapse) {
            return $this->collapsedIndex($request, $user, $filters);
        }

        $paginator = $this->service->listInbox($user, $filters);

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (NotificationRecipient $row) => NotificationResource::forRecipientRow($row)->toArray($request),
            )->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Plan-023 M5 T5.2 — collapsed inbox shape. Each `data` entry is
     * one bucket: collapsed rows carry `is_collapsed=true`, `count`,
     * `sample[≤3]`, and the latest notification at `latest`; uncollapsed
     * rows have `is_collapsed=false` and the same single-notification
     * payload the flat list returns.
     *
     * @param  array<string, mixed>  $filters
     */
    private function collapsedIndex(IndexNotificationRequest $request, $user, array $filters): JsonResponse
    {
        $collapser = app(InboxCollapseService::class);
        $paginator = $collapser->collapseFor($user, $filters);

        $data = collect($paginator->items())->map(function (array $bucket) use ($request) {
            /** @var NotificationRecipient $latest */
            $latest = $bucket['latest'];
            $latestPayload = NotificationResource::forRecipientRow($latest)->toArray($request);

            return [
                'id' => $bucket['id'],
                'is_collapsed' => $bucket['is_collapsed'],
                'aggregation_key' => $bucket['aggregation_key'],
                'count' => $bucket['count'],
                'first_at' => optional($bucket['first_at'])->toIso8601String(),
                'last_at' => optional($bucket['last_at'])->toIso8601String(),
                'latest' => $latestPayload,
                'sample' => $bucket['is_collapsed']
                    ? collect($bucket['sample'])
                        ->map(fn (NotificationRecipient $r) => NotificationResource::forRecipientRow($r)->toArray($request))
                        ->all()
                    : [],
            ];
        })->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'collapsed' => true,
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/me/notifications/summary',
        summary: 'Unread count + per-priority breakdown for the bell badge',
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Summary'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewInbox', Notification::class);

        return response()->json([
            'data' => $this->service->summaryFor($request->user()),
        ]);
    }

    #[OA\Patch(
        path: '/api/v1/me/notifications/{notificationId}/seen',
        summary: 'Mark single notification as seen',
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'notificationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Seen'),
            new OA\Response(response: 404, description: 'Not a recipient / notification does not exist'),
        ]
    )]
    public function seen(Request $request, string $notificationId): JsonResponse
    {
        $row = $this->resolveRecipientRow($request, $notificationId);
        $this->service->markSeen($row);

        return response()->json(status: 204);
    }

    #[OA\Patch(
        path: '/api/v1/me/notifications/{notificationId}/read',
        summary: 'Mark single notification as read (implies seen)',
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'notificationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Read'),
            new OA\Response(response: 404, description: 'Not a recipient'),
        ]
    )]
    public function read(Request $request, string $notificationId): JsonResponse
    {
        $row = $this->resolveRecipientRow($request, $notificationId);
        $this->service->markRead($row);

        return response()->json(status: 204);
    }

    #[OA\Patch(
        path: '/api/v1/me/notifications/{notificationId}/dismissed',
        summary: 'Archive a notification',
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'notificationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Dismissed'),
            new OA\Response(response: 404, description: 'Not a recipient'),
        ]
    )]
    public function dismissed(Request $request, string $notificationId): JsonResponse
    {
        $row = $this->resolveRecipientRow($request, $notificationId);
        $this->service->markDismissed($row);

        return response()->json(status: 204);
    }

    #[OA\Post(
        path: '/api/v1/me/notifications/bulk-read',
        summary: 'Mark many / all unread as read',
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                    new OA\Property(property: 'all_unread', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Marked count'),
            new OA\Response(response: 422, description: 'Validation error — neither or both of ids / all_unread'),
        ]
    )]
    public function bulkRead(BulkReadRequest $request): JsonResponse
    {
        $this->authorize('viewInbox', Notification::class);

        $marked = $this->service->bulkMarkRead(
            $request->user(),
            $request->input('ids'),
            (bool) $request->input('all_unread', false),
        );

        return response()->json(['data' => ['marked_count' => $marked]]);
    }

    #[OA\Post(
        path: '/api/v1/me/notifications/bulk-dismiss',
        summary: 'Dismiss many / all',
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                    new OA\Property(property: 'all_read', type: 'boolean'),
                    new OA\Property(property: 'all', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Dismissed count'),
            new OA\Response(response: 422, description: 'Validation error — exactly one of ids/all_read/all required'),
        ]
    )]
    public function bulkDismiss(BulkDismissRequest $request): JsonResponse
    {
        $this->authorize('viewInbox', Notification::class);

        $dismissed = $this->service->bulkMarkDismissed(
            $request->user(),
            $request->input('ids'),
            (bool) $request->input('all_read', false),
            (bool) $request->input('all', false),
        );

        return response()->json(['data' => ['dismissed_count' => $dismissed]]);
    }

    /**
     * GDPR right-to-be-forgotten endpoint (issue #179 part C).
     *
     * Purges every NotificationRecipient row addressed to the caller plus
     * the cascading NotificationDelivery children. Other recipients of the
     * same parent Notification are NOT affected — a stock alert sent to 5
     * managers stays visible to the other 4 if one purges their inbox.
     *
     * Idempotent — second call returns count=0. Always 200, never 204, so
     * the count is observable to the caller (UI can confirm "wiped 87 rows").
     */
    #[OA\Delete(
        path: '/api/v1/me/notifications',
        summary: 'GDPR right-to-be-forgotten — wipe own inbox',
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Wiped count', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'wiped_count', type: 'integer'),
                    ]),
                ]
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function destroyAll(Request $request): JsonResponse
    {
        $this->authorize('viewInbox', Notification::class);

        $wiped = $this->service->forgetRecipient($request->user());

        return response()->json(['data' => ['wiped_count' => $wiped]]);
    }

    /**
     * Resolve a NotificationRecipient row for the current caller by
     * notification id. 404 on not-a-recipient (existence-hiding per DESIGN) —
     * the firstOrFail() below collapses both "notification does not exist"
     * and "caller is not a recipient" to the same 404 response, avoiding
     * information leaks about other users' notifications.
     */
    private function resolveRecipientRow(Request $request, string $notificationId): NotificationRecipient
    {
        return NotificationRecipient::query()
            ->forRecipient($request->user())
            ->where('notification_id', $notificationId)
            ->firstOrFail();
    }
}
