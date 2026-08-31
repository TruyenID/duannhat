<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Requests\HQ\Notification\PreviewRruleRequest;
use App\Http\Requests\HQ\Notification\StoreScheduleRequest;
use App\Http\Requests\HQ\Notification\UpdateScheduleRequest;
use App\Http\Resources\Admin\NotificationScheduleResource;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationAudience;
use App\Models\NotificationSchedule;
use App\Models\Organization;
use App\Services\Notification\NotificationScheduleCanceller;
use App\Services\Notification\NotificationScheduleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;
use Recurr\Exception\InvalidRRule;

/**
 * `/api/v1/hq/{brandSlug}/notifications/schedules*` — admin CRUD +
 * lifecycle actions (pause / resume / cancel) + preview-rrule for the
 * plan-023 M3 recurring broadcast schedules.
 *
 * Authorization: all actions gate on `NotificationPolicy::manageSchedules`
 * which inherits the standard brand-admin viewAdmin gate.
 *
 * Cancel semantics: the controller delegates to
 * `NotificationScheduleCanceller` so the 60-second freeze window
 * (plan-023 T3.9) is enforced uniformly whether the schedule is
 * deleted via HTTP or via a future console command.
 */
class NotificationScheduleAdminController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly NotificationScheduleCanceller $canceller,
        private readonly NotificationScheduleService $schedules,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/schedules',
        summary: 'List recurring notification schedules for this brand',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'paused', 'completed', 'cancelled'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated schedule list')]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageSchedules', [Notification::class, $brand]);

        $query = NotificationSchedule::query()
            ->with('audience:id,name')
            ->where('brand_id', $brand->id)
            ->orderByRaw('next_occurrence_at IS NULL') // active rows first
            ->orderBy('next_occurrence_at')
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => NotificationScheduleResource::collection($paginator->items())->toArray($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/notifications/schedules',
        summary: 'Create a recurring schedule',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation failed (invalid RRULE or audience cross-brand)'),
        ]
    )]
    public function store(StoreScheduleRequest $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageSchedules', [Notification::class, $brand]);

        $orgIds = $this->brandOrgIds($brand);

        $audience = NotificationAudience::query()
            ->whereIn('organization_id', $orgIds)
            ->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', $brand->id))
            ->find($request->input('audience_id'));

        abort_if($audience === null, 422, 'audience_id not visible to this brand');

        $startsAt = Carbon::parse((string) $request->input('starts_at'));
        $endsAt = $request->has('ends_at') ? Carbon::parse($request->input('ends_at')) : null;
        $next = $this->schedules->firstOccurrence(
            $request->input('rrule'),
            $startsAt,
            $request->input('timezone'),
            $endsAt,
        );

        $schedule = NotificationSchedule::query()->create([
            'organization_id' => $orgIds[0] ?? null,
            'brand_id' => $brand->id,
            'template_key' => $request->input('template_key'),
            'audience_id' => $audience->id,
            'channels' => $request->input('channels'),
            'priority' => $request->input('priority', 'normal'),
            'params' => $request->input('params'),
            'rrule' => $request->input('rrule'),
            'timezone' => $request->input('timezone'),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'occurrences_remaining' => $request->input('occurrences_remaining'),
            'next_occurrence_at' => $next,
            'status' => 'active',
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => (new NotificationScheduleResource($schedule))->toArray($request)], 201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/schedules/{id}',
        summary: 'Fetch a single schedule (includes next_5_occurrences preview)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Schedule')]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageSchedules', [Notification::class, $brand]);

        $schedule = $this->findInBrand($id, $brand);

        return response()->json(['data' => (new NotificationScheduleResource($schedule))->toArray($request)]);
    }

    #[OA\Patch(
        path: '/api/v1/hq/{brandSlug}/notifications/schedules/{id}',
        summary: 'Edit a schedule (only future occurrences affected)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Updated')]
    )]
    public function update(UpdateScheduleRequest $request, string $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageSchedules', [Notification::class, $brand]);

        $schedule = $this->schedules->applyEdit(
            $this->findInBrand($id, $brand),
            $request->validated(),
        );

        return response()->json(['data' => (new NotificationScheduleResource($schedule->fresh()))->toArray($request)]);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/notifications/schedules/{id}',
        summary: 'Cancel a schedule (rejected within 60s of next occurrence)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Cancelled'),
            new OA\Response(response: 422, description: 'within_freeze_window'),
        ]
    )]
    public function destroy(Request $request, string $id): Response
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageSchedules', [Notification::class, $brand]);

        $schedule = $this->findInBrand($id, $brand);
        $this->canceller->cancel($schedule);

        return response()->noContent();
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/notifications/schedules/{id}/pause',
        summary: 'Pause an active schedule (tick worker stops materialising)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Paused')]
    )]
    public function pause(Request $request, string $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageSchedules', [Notification::class, $brand]);

        $schedule = $this->schedules->pause($this->findInBrand($id, $brand));

        return response()->json(['data' => (new NotificationScheduleResource($schedule->fresh()))->toArray($request)]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/notifications/schedules/{id}/resume',
        summary: 'Resume a paused schedule (recomputes next_occurrence_at from now())',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Resumed')]
    )]
    public function resume(Request $request, string $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageSchedules', [Notification::class, $brand]);

        $schedule = $this->schedules->resume($this->findInBrand($id, $brand));

        return response()->json(['data' => (new NotificationScheduleResource($schedule->fresh()))->toArray($request)]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/notifications/schedules/preview-rrule',
        summary: 'Preview the next N occurrences (default 5) of an RRULE',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Occurrences'),
            new OA\Response(response: 422, description: 'Invalid RRULE or timezone'),
        ]
    )]
    public function previewRrule(PreviewRruleRequest $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageSchedules', [Notification::class, $brand]);

        $tz = new \DateTimeZone((string) $request->input('timezone'));

        try {
            $occurrences = $this->schedules->previewOccurrences(
                (string) $request->input('rrule'),
                new \DateTime((string) $request->input('starts_at'), $tz),
                $tz->getName(),
                (int) $request->input('count', 5),
            );
        } catch (InvalidRRule $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'invalid_rrule'], 422);
        }

        return response()->json(['data' => ['occurrences' => $occurrences]]);
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

    private function findInBrand(string $id, Brand $brand): NotificationSchedule
    {
        return NotificationSchedule::query()
            ->with('audience:id,name')
            ->where('brand_id', $brand->id)
            ->findOrFail($id);
    }
}
