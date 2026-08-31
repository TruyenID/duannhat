<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Exceptions\NotificationException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\GuardsInlineAudienceTenancy;
use App\Http\Requests\HQ\Notification\PreviewAudienceRequest;
use App\Http\Requests\HQ\Notification\StoreAudienceRequest;
use App\Http\Requests\HQ\Notification\UpdateAudienceRequest;
use App\Http\Resources\Admin\AudiencePreviewResource;
use App\Http\Resources\Admin\AudienceResource;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationAudience;
use App\Models\NotificationSchedule;
use App\Models\Organization;
use App\Services\Notification\AudienceResolverService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/hq/{brandSlug}/notifications/audiences*` — admin CRUD for the
 * rule-based audience store (plan-012 T1.4).
 *
 * is_system rows are protected from PATCH / DELETE at the controller
 * layer via the NotificationPolicy::updateAudience row check.
 */
class NotificationAudienceAdminController extends Controller
{
    use AuthorizesRequests;
    use GuardsInlineAudienceTenancy;

    public function __construct(private readonly AudienceResolverService $resolver) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/audiences',
        summary: 'List saved notification audiences for this brand',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated audience list'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageAudiences', [Notification::class, $brand]);

        $orgIds = $this->brandOrgIds($brand);

        $paginator = NotificationAudience::query()
            ->whereIn('organization_id', $orgIds)
            ->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', $brand->id))
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
        path: '/api/v1/hq/{brandSlug}/notifications/audiences',
        summary: 'Create a notification audience',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'rule'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 160),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'rule', type: 'object'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(StoreAudienceRequest $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageAudiences', [Notification::class, $brand]);

        $orgIds = $this->brandOrgIds($brand);

        // `brand_id` is pinned to the route brand — never accept it from
        // input (cross-brand metadata pollution guard, see #171).
        $audience = NotificationAudience::query()->create([
            'organization_id' => $orgIds[0] ?? null,
            'brand_id' => $brand->id,
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'rule' => $request->input('rule'),
            'is_system' => false,
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => (new AudienceResource($audience))->toArray($request)], 201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/audiences/{id}',
        summary: 'Fetch a single notification audience',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Audience'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageAudiences', [Notification::class, $brand]);

        $audience = $this->findInBrand($id, $brand);

        return response()->json(['data' => (new AudienceResource($audience))->toArray($request)]);
    }

    #[OA\Patch(
        path: '/api/v1/hq/{brandSlug}/notifications/audiences/{id}',
        summary: 'Update a notification audience (rejects is_system=true rows)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 160),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'rule', type: 'object'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 403, description: 'Forbidden (is_system or cross-brand)'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(UpdateAudienceRequest $request, string $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageAudiences', [Notification::class, $brand]);
        $audience = $this->findInBrand($id, $brand);
        abort_if($audience->is_system, 403, 'system audience rows are read-only');

        $audience->fill(array_filter(
            $request->validated(),
            static fn ($_, $k) => in_array($k, ['name', 'description', 'rule'], true),
            ARRAY_FILTER_USE_BOTH,
        ))->save();

        return response()->json(['data' => (new AudienceResource($audience->fresh()))->toArray($request)]);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/notifications/audiences/{id}',
        summary: 'Delete a notification audience (rejects is_system=true rows)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 403, description: 'Forbidden (is_system)'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'audience_in_use — still referenced by schedules'),
        ]
    )]
    public function destroy(Request $request, string $id): Response
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageAudiences', [Notification::class, $brand]);
        $audience = $this->findInBrand($id, $brand);
        abort_if($audience->is_system, 403, 'system audience rows are read-only');

        // Audience is FK-RESTRICT on notification_schedules. Catch the
        // references up-front so the admin gets a friendly 422 with the
        // count instead of a 500 PDO constraint violation. Terminal rows
        // (completed/cancelled) still hold the FK and would block the
        // delete, so they count too.
        $scheduleCount = NotificationSchedule::query()
            ->where('audience_id', $audience->id)
            ->count();
        if ($scheduleCount > 0) {
            throw NotificationException::audienceInUse($scheduleCount);
        }

        $audience->delete();

        return response()->noContent();
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/notifications/audiences/preview',
        summary: 'Preview recipient count and sample for a rule JSON (rate-limited 10/min)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['rule'],
                properties: [new OA\Property(property: 'rule', type: 'object')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Preview counts'),
            new OA\Response(response: 422, description: 'Rule shape invalid or audience_too_large'),
            new OA\Response(response: 429, description: 'Rate limit hit (10/min)'),
        ]
    )]
    public function preview(PreviewAudienceRequest $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageAudiences', [Notification::class, $brand]);

        $resolution = $this->resolver->resolveWithTrace((array) $request->input('rule'), $brand);
        $recipients = $resolution['recipients'];

        // The `rule` here is fully caller-controlled JSON resolved through
        // brand-unscoped resolvers, exactly like the broadcast `audience_inline`
        // path. Without this post-resolution ownership guard, a Brand-A admin
        // could preview an inline rule naming Brand-B users/devices and read
        // back a recipient count + a 10-row sample of REAL foreign recipient
        // names — a cross-tenant PII leak. Reject before any count/sample is
        // computed so a violation leaks nothing.
        $this->assertInlineRecipientsOwned($recipients, $brand);

        $sample = $recipients->take(10)->map(fn (Model $m) => [
            'id' => $m->getKey(),
            'type' => $m->getMorphClass(),
            'label' => $m->name ?? (string) $m->getKey(),
        ])->values()->all();

        return response()->json([
            'data' => (new AudiencePreviewResource([
                'count' => $recipients->count(),
                'sample' => $sample,
            ]))->toArray($request),
        ]);
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

    private function findInBrand(string $id, Brand $brand): NotificationAudience
    {
        $orgIds = $this->brandOrgIds($brand);

        return NotificationAudience::query()
            ->whereIn('organization_id', $orgIds)
            ->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', $brand->id))
            ->findOrFail($id);
    }
}
