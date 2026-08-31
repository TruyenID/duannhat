<?php

namespace App\Http\Controllers\Api\V1\Branch;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\BranchMenuScheduleSetActiveRequest;
use App\Http\Requests\BranchMenuScheduleUpsertRequest;
use App\Http\Resources\BranchEffectiveScheduleResource;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Services\Product\BranchMenuScheduleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

class BranchMenuScheduleController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly BranchMenuScheduleService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/schedules',
        summary: 'List effective schedules for a menu (branch view)',
        description: 'Returns active schedules with COALESCED effective times — branch override times where set, HQ defaults otherwise. Includes hq_defaults and is_overridden for tooltip display.',
        tags: ['Shop Schedule Overrides'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Effective schedule list with COALESCED times'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden — user cannot view this menu'),
            new OA\Response(response: 404, description: 'Menu or branch not found'),
        ]
    )]
    public function index(Menu $menu): AnonymousResourceCollection
    {
        $this->authorizeOrganization($menu);
        $this->authorize('view', $menu);

        $branch = request()->attributes->get('shop');

        return BranchEffectiveScheduleResource::collection(
            $this->service->getEffectiveSchedules($menu, $branch)
        );
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/schedules/{schedule}/override',
        summary: 'Upsert a branch time override for a schedule',
        description: 'Creates or updates the branch-specific start_time/end_time/days_of_week/start_date/end_date override. Each field is independently overridable — send only the field(s) to change. Send null to reset a field to the HQ default. Cross-field validation runs on effective (COALESCED) values.',
        tags: ['Shop Schedule Overrides'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'start_time', type: 'string', nullable: true, example: '07:00', description: 'HH:MM or HH:MM:SS. null resets to HQ default.'),
                new OA\Property(property: 'end_time', type: 'string', nullable: true, example: '10:00', description: 'HH:MM or HH:MM:SS. null resets to HQ default.'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Override saved — effective schedule returned'),
            new OA\Response(response: 403, description: 'Forbidden — branch manager of own branch only'),
            new OA\Response(response: 404, description: 'Schedule not found or does not belong to this menu'),
            new OA\Response(response: 422, description: 'Validation failed — effective end time must be after effective start time'),
        ]
    )]
    public function upsertOverride(
        BranchMenuScheduleUpsertRequest $request,
        Menu $menu,
        MenuSchedule $schedule,
    ): JsonResponse {
        $this->authorizeOrganization($menu);
        // Branch menus own their own schedule rows (see BranchMenuScheduleService).
        abort_unless($schedule->menu_id === $menu->id, 404);

        $branch = request()->attributes->get('shop');
        $this->authorize('branch-schedule.upsert', [$schedule, $branch]);

        $this->service->upsertOverride($schedule, $branch, $request->validated());

        // Reload via LEFT JOIN query so the resource receives the SQL aliases
        // (effective_start_time, hq_start_time, is_overridden) that BranchEffectiveScheduleResource expects.
        $effective = $this->service->getEffectiveSchedules($menu, $branch)
            ->firstWhere('id', $schedule->id);

        return response()->json(['data' => new BranchEffectiveScheduleResource($effective)]);
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/schedules/{schedule}/active',
        summary: 'Activate or pause a schedule for this branch',
        description: 'Toggles the schedule visibility for this branch by writing is_active directly on the branch-owned schedule row. Paused schedules are still returned by the list endpoint so the branch can re-activate them.',
        tags: ['Shop Schedule Overrides'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'is_active', type: 'boolean', example: false, description: 'true = show this schedule window at the branch; false = pause it.'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Schedule active state updated — effective schedule returned'),
            new OA\Response(response: 403, description: 'Forbidden — branch manager of own branch only'),
            new OA\Response(response: 404, description: 'Schedule not found or does not belong to this menu'),
            new OA\Response(response: 422, description: 'Validation failed — is_active is required and must be boolean'),
        ]
    )]
    public function setActive(
        BranchMenuScheduleSetActiveRequest $request,
        Menu $menu,
        MenuSchedule $schedule,
    ): JsonResponse {
        $this->authorizeOrganization($menu);
        // Branch menus own their own schedule rows (see BranchMenuScheduleService).
        abort_unless($schedule->menu_id === $menu->id, 404);

        $branch = request()->attributes->get('shop');
        $this->authorize('branch-schedule.upsert', [$schedule, $branch]);

        $this->service->setActive($schedule, $request->boolean('is_active'));

        // Reload via LEFT JOIN query so the resource receives the SQL aliases
        // (effective_start_time, hq_start_time, is_overridden) that BranchEffectiveScheduleResource expects.
        $effective = $this->service->getEffectiveSchedules($menu, $branch)
            ->firstWhere('id', $schedule->id);

        return response()->json(['data' => new BranchEffectiveScheduleResource($effective)]);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/schedules/{schedule}/override',
        summary: 'Reset a branch schedule override to HQ defaults',
        description: 'Deletes the branch override row, reverting effective times to the HQ schedule values. Returns 404 if no override exists.',
        tags: ['Shop Schedule Overrides'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Override deleted — effective times revert to HQ defaults'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'No override exists for this schedule+branch pair'),
        ]
    )]
    public function destroyOverride(Menu $menu, MenuSchedule $schedule): Response
    {
        $this->authorizeOrganization($menu);
        abort_unless($schedule->menu_id === $menu->id, 404);

        $branch = request()->attributes->get('shop');
        $this->authorize('branch-schedule.reset', [$schedule, $branch]);

        $this->service->deleteOverride($schedule, $branch);

        return response()->noContent();
    }
}
