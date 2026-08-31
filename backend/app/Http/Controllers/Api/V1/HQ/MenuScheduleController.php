<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\MenuScheduleStoreRequest;
use App\Http\Requests\MenuScheduleUpdateRequest;
use App\Http\Resources\MenuScheduleResource;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Services\Product\MenuScheduleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class MenuScheduleController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly MenuScheduleService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/schedules',
        summary: 'List schedules for a menu',
        description: 'Returns all non-deleted schedule windows for a menu, ordered by priority then created_at.',
        tags: ['Menu Schedules'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Schedule list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MenuSchedule')),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden — user cannot view this menu'),
            new OA\Response(response: 404, description: 'Menu not found or not in brand'),
        ]
    )]
    public function index(Menu $menu): AnonymousResourceCollection
    {
        $this->authorizeOrganization($menu);
        $this->authorize('view', $menu);

        $schedules = $this->service->listForMenu($menu);

        return MenuScheduleResource::collection($schedules);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/schedules',
        summary: 'Create a schedule for a menu',
        description: 'Creates a new time-of-day + day-of-week schedule window for a menu. Creating a schedule is an update-level action on the parent menu.',
        tags: ['Menu Schedules'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['start_time', 'end_time', 'days_of_week'],
                properties: [
                    new OA\Property(property: 'start_time', type: 'string', example: '06:00', description: 'HH:MM or HH:MM:SS; must be before end_time'),
                    new OA\Property(property: 'end_time', type: 'string', example: '10:30', description: 'HH:MM or HH:MM:SS; must be after start_time'),
                    new OA\Property(property: 'days_of_week', type: 'integer', minimum: 1, maximum: 127, example: 127, description: 'Bitmask: bit0=Sun … bit6=Sat. 0 rejected (no days). 127 = all days.'),
                    new OA\Property(property: 'is_active', type: 'boolean', default: true),
                    new OA\Property(property: 'priority', type: 'integer', minimum: 0, default: 0, description: 'Lower number = higher priority tiebreak'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Schedule created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/MenuSchedule'),
            ])),
            new OA\Response(response: 422, description: 'Validation failed — start >= end, days_of_week out of range, or missing required field'),
            new OA\Response(response: 403, description: 'Forbidden — user cannot update this menu'),
        ]
    )]
    public function store(MenuScheduleStoreRequest $request, Menu $menu): JsonResponse
    {
        $this->authorizeOrganization($menu);
        $this->authorize('update', $menu);

        $schedule = $this->service->create($menu, $request->validated());

        // load, not loadMissing: create() just wrote the rows, so an already-set
        // (stale, empty) relation would answer for them (#1979).
        return (new MenuScheduleResource($schedule->load('scheduleDates')))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/schedules/{schedule}',
        summary: 'Update a menu schedule',
        description: 'Partially updates a schedule window. All fields are optional (PATCH semantics). Route binding scopes the schedule to its parent menu — cross-menu access returns 404.',
        tags: ['Menu Schedules'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'start_time', type: 'string', example: '06:00'),
                new OA\Property(property: 'end_time', type: 'string', example: '10:30'),
                new OA\Property(property: 'days_of_week', type: 'integer', minimum: 1, maximum: 127),
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'priority', type: 'integer', minimum: 0),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated schedule', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/MenuSchedule'),
            ])),
            new OA\Response(response: 404, description: 'Schedule not found or does not belong to this menu'),
            new OA\Response(response: 422, description: 'Validation failed'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function update(MenuScheduleUpdateRequest $request, Menu $menu, MenuSchedule $schedule): MenuScheduleResource
    {
        $this->authorizeOrganization($menu);
        $this->authorize('update', $schedule);

        $updated = $this->service->update($schedule, $request->validated());

        return new MenuScheduleResource($updated->load('scheduleDates'));
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/schedules/{schedule}',
        summary: 'Delete a menu schedule',
        description: 'Soft-deletes a schedule window. The menu falls back to always-on if no non-deleted schedule rows remain.',
        tags: ['Menu Schedules'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted — no content'),
            new OA\Response(response: 404, description: 'Schedule not found or does not belong to this menu'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function destroy(Menu $menu, MenuSchedule $schedule): JsonResponse
    {
        $this->authorizeOrganization($menu);
        $this->authorize('delete', $schedule);

        $this->service->delete($schedule);

        return response()->json(null, 204);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/schedules/reorder',
        summary: 'Reorder schedule windows for a menu',
        description: 'Reassigns priority (1-based) to all non-deleted schedules of a menu using the supplied ordered array of IDs. The array must include every non-deleted schedule ID that belongs to the menu.',
        tags: ['Menu Schedules'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'schedule_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), description: 'Ordered array of all non-deleted schedule IDs for this menu.'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Reordered'),
            new OA\Response(response: 422, description: 'Validation failed — duplicates, unknown IDs, or incomplete coverage'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function reorder(Request $request, Menu $menu): JsonResponse
    {
        $this->authorizeOrganization($menu);
        $this->authorize('update', $menu);

        $validated = $request->validate([
            'schedule_ids' => ['required', 'array'],
            'schedule_ids.*' => ['uuid'],
        ]);

        $this->service->reorder($menu, $validated['schedule_ids']);

        return response()->json(['message' => 'Schedules reordered.']);
    }
}
