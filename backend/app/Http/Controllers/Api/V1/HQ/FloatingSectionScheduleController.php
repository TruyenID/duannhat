<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\FloatingSectionScheduleStoreRequest;
use App\Http\Requests\FloatingSectionScheduleUpdateRequest;
use App\Http\Resources\FloatingSectionScheduleResource;
use App\Models\FloatingSection;
use App\Models\FloatingSectionSchedule;
use App\Services\Product\FloatingSectionScheduleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Mirrors MenuScheduleController — same CRUD + reorder shape, parented to a
 * FloatingSection instead of a Menu.
 */
class FloatingSectionScheduleController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly FloatingSectionScheduleService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/schedules',
        summary: 'List schedules for a floating section',
        tags: ['Floating Section Schedules'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Schedule list')],
    )]
    public function index(FloatingSection $floatingSection): AnonymousResourceCollection
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('view', $floatingSection);

        $schedules = $this->service->listForSection($floatingSection);

        return FloatingSectionScheduleResource::collection($schedules);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/schedules',
        summary: 'Create a schedule for a floating section',
        tags: ['Floating Section Schedules'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['start_time', 'end_time', 'days_of_week'],
                properties: [
                    new OA\Property(property: 'start_time', type: 'string', example: '17:00'),
                    new OA\Property(property: 'end_time', type: 'string', example: '19:00'),
                    new OA\Property(property: 'days_of_week', type: 'integer', minimum: 1, maximum: 127, example: 127),
                    new OA\Property(property: 'is_active', type: 'boolean', default: true),
                    new OA\Property(property: 'priority', type: 'integer', minimum: 0, default: 0),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Schedule created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(FloatingSectionScheduleStoreRequest $request, FloatingSection $floatingSection): JsonResponse
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('update', $floatingSection);

        $schedule = $this->service->create($floatingSection, $request->validated());

        return (new FloatingSectionScheduleResource($schedule))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/schedules/{schedule}',
        summary: 'Update a floating section schedule',
        tags: ['Floating Section Schedules'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated schedule'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(FloatingSectionScheduleUpdateRequest $request, FloatingSection $floatingSection, FloatingSectionSchedule $schedule): FloatingSectionScheduleResource
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('update', $schedule);

        $updated = $this->service->update($schedule, $request->validated());

        return new FloatingSectionScheduleResource($updated);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/schedules/{schedule}',
        summary: 'Delete a floating section schedule',
        tags: ['Floating Section Schedules'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(FloatingSection $floatingSection, FloatingSectionSchedule $schedule): JsonResponse
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('delete', $schedule);

        $this->service->delete($schedule);

        return response()->json(null, 204);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/schedules/reorder',
        summary: 'Reorder schedule windows for a floating section',
        tags: ['Floating Section Schedules'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'schedule_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Reordered'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function reorder(Request $request, FloatingSection $floatingSection): JsonResponse
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('update', $floatingSection);

        $validated = $request->validate([
            'schedule_ids' => ['required', 'array'],
            'schedule_ids.*' => ['uuid'],
        ]);

        $this->service->reorder($floatingSection, $validated['schedule_ids']);

        return response()->json(['message' => 'Schedules reordered.']);
    }
}
