<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\FloatingSectionScheduleOverrideRequest;
use App\Http\Resources\FloatingSectionScheduleResource;
use App\Models\Branch;
use App\Models\FloatingSection;
use App\Models\FloatingSectionSchedule;
use App\Services\Product\FloatingSectionScheduleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Shop-side schedule management for a floating section clone.
 *
 * The clone's schedule rows are the shop's own copy (no ongoing sync from
 * HQ). Shop staff may view them, flip is_active on/off, and override the
 * displayed time window (start_time/end_time/days_of_week) — but cannot
 * create or delete a schedule, and cannot touch the calendar date range.
 * That stays HQ-only.
 */
class FloatingSectionScheduleController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly FloatingSectionScheduleService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/floating-sections/{floatingSection}/schedules',
        summary: 'List schedules for the shop floating section',
        tags: ['Shop Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Schedule list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $floatingSection = $this->resolveFloatingSection($request);
        $this->authorize('shopView', $floatingSection);

        return FloatingSectionScheduleResource::collection(
            $this->service->listForSection($floatingSection)
        );
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/floating-sections/{floatingSection}/schedules/{schedule}/toggle',
        summary: 'Toggle a shop floating section schedule on/off',
        tags: ['Shop Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Toggled schedule')],
    )]
    public function toggle(Request $request): FloatingSectionScheduleResource
    {
        $floatingSection = $this->resolveFloatingSection($request);
        $this->authorize('shopUpdateAvailability', $floatingSection);

        $schedule = $this->resolveSchedule($floatingSection, $request);
        $updated = $this->service->toggleActive($schedule);

        return new FloatingSectionScheduleResource($updated);
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/floating-sections/{floatingSection}/schedules/{schedule}/override',
        summary: "Override a shop floating section schedule's displayed time",
        description: 'start_time/end_time/days_of_week only. Survives a later HQ sync until reset.',
        tags: ['Shop Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Overridden schedule')],
    )]
    public function override(FloatingSectionScheduleOverrideRequest $request): FloatingSectionScheduleResource
    {
        $floatingSection = $this->resolveFloatingSection($request);
        $this->authorize('shopUpdateAvailability', $floatingSection);

        $schedule = $this->resolveSchedule($floatingSection, $request);
        $updated = $this->service->overrideTime($schedule, $request->validated());

        return new FloatingSectionScheduleResource($updated);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/floating-sections/{floatingSection}/schedules/{schedule}/override',
        summary: "Reset a shop floating section schedule's time back to HQ's",
        tags: ['Shop Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Reset schedule')],
    )]
    public function resetOverride(Request $request): FloatingSectionScheduleResource
    {
        $floatingSection = $this->resolveFloatingSection($request);
        $this->authorize('shopUpdateAvailability', $floatingSection);

        $schedule = $this->resolveSchedule($floatingSection, $request);
        $updated = $this->service->resetTimeOverride($schedule);

        return new FloatingSectionScheduleResource($updated);
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    private function resolvedShop(Request $request): Branch
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        return $shop;
    }

    private function resolveFloatingSection(Request $request): FloatingSection
    {
        $shop = $this->resolvedShop($request);
        $id = (string) $request->route('floatingSection');

        return FloatingSection::query()
            ->where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail($id);
    }

    private function resolveSchedule(FloatingSection $floatingSection, Request $request): FloatingSectionSchedule
    {
        $id = (string) $request->route('schedule');

        return $floatingSection->schedules()->findOrFail($id);
    }
}
