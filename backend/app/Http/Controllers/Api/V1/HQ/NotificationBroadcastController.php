<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Exceptions\NotificationException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\GuardsInlineAudienceTenancy;
use App\Http\Requests\HQ\Notification\BroadcastRequest;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationAudience;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Services\Notification\AudienceResolverService;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/hq/{brandSlug}/notifications/broadcast` (plan-012 T4.11).
 *
 * The admin broadcast composer submits to this endpoint — audience + template
 * + channels + priority + optional scheduled_for. The controller resolves
 * the audience and template, then calls NotificationService::dispatch. When
 * `scheduled_for` is in the future, dispatch creates the parent row and
 * delegates actual fan-out to the delayed job (T4.14).
 */
class NotificationBroadcastController extends Controller
{
    use AuthorizesRequests;
    use GuardsInlineAudienceTenancy;

    public function __construct(
        private readonly NotificationService $service,
        private readonly AudienceResolverService $resolver,
    ) {}

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/notifications/broadcast',
        summary: 'Dispatch a custom broadcast via the admin composer',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['channels', 'priority'],
                properties: [
                    new OA\Property(property: 'audience_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'audience_inline', type: 'object', nullable: true),
                    new OA\Property(property: 'template_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'template_inline', type: 'object', nullable: true),
                    new OA\Property(property: 'params', type: 'object', nullable: true),
                    new OA\Property(property: 'channels', type: 'array', items: new OA\Items(type: 'string', enum: ['in_app', 'realtime', 'email', 'push'])),
                    new OA\Property(property: 'priority', type: 'string', enum: ['low', 'normal', 'high', 'urgent']),
                    new OA\Property(property: 'scheduled_for', type: 'string', format: 'date-time', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Broadcast dispatched'),
            new OA\Response(response: 422, description: 'audience_empty or payload invalid'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function store(BroadcastRequest $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('compose', [Notification::class, $brand]);

        $template = $this->resolveTemplate($request, $brand);
        $orgIds = $this->brandOrgIds($brand);
        $orgId = $orgIds[0] ?? null;
        abort_if($orgId === null, 422, 'Brand has no local organization.');

        // A stored audience ($audience_id) is already org-scoped by
        // resolveRule()/findInBrand(). An inline rule, however, is fully
        // caller-controlled JSON handed straight to the resolvers — and the
        // resolvers happily expand arbitrary user_ids / role scopes / shop_ids
        // / device_ids from ANY organization. Without a post-resolution
        // ownership guard, a Brand-A admin can inline-target Brand-B users and
        // fan a broadcast across the tenant boundary. Remember whether this
        // request took the inline path so we can enforce ownership below.
        $isInline = ! $request->filled('audience_id');

        $rule = $this->resolveRule($request, $brand);

        // Upfront emptiness check — audience_empty is the documented
        // non-crash-y response code. Without this, NotificationService would
        // throw a generic recipients_empty exception instead.
        $preview = $this->resolver->resolveWithTrace($rule, $brand);
        if ($preview['recipients']->isEmpty()) {
            abort(response()->json(['message' => 'audience_empty'], 422));
        }

        if ($isInline) {
            $this->assertInlineRecipientsOwned($preview['recipients'], $brand);
        }

        try {
            $notification = $this->service->dispatch([
                'type' => $template->key,
                'template_key' => $template->key,
                'organization_id' => $orgId,
                'brand' => $brand,
                'recipients' => $preview['recipients'],
                'params' => (array) $request->input('params', []),
                'priority' => $request->input('priority'),
                'scheduled_for' => $request->input('scheduled_for'),
                'audience_id' => $request->input('audience_id'),
                'channels' => (array) $request->input('channels', []),
            ]);
        } catch (NotificationException $e) {
            abort(response()->json(['message' => $e->getMessage()], $e->getCode() ?: 422));
        }

        return response()->json([
            'data' => [
                'id' => $notification->id,
                'type' => $notification->type,
                'scheduled_for' => $notification->scheduled_for?->toISOString(),
            ],
        ], 201);
    }

    /**
     * Resolve a template the caller is allowed to broadcast with.
     *
     * Mirrors `NotificationTemplateAdminController::findInBrand()`: caller
     * may reference (a) system templates, (b) any template owned by an
     * org that maps to the route brand's console org, or (c) any template
     * pinned directly to the route brand. Anything else → 404 (existence
     * hiding — same response any unscoped findOrFail would have given for
     * a missing UUID, so no information leak about other tenants).
     */
    private function resolveTemplate(BroadcastRequest $request, Brand $brand): NotificationTemplate
    {
        if ($request->filled('template_id')) {
            $orgIds = $this->brandOrgIds($brand);

            return NotificationTemplate::query()
                ->where(function ($q) use ($orgIds, $brand) {
                    $q->where('is_system', true)
                        ->orWhereIn('organization_id', $orgIds)
                        ->orWhere('brand_id', $brand->id);
                })
                ->findOrFail($request->input('template_id'));
        }

        $inline = (array) $request->input('template_inline', []);

        return new NotificationTemplate([
            'key' => $inline['key'] ?? 'inline.'.uniqid(),
            'content' => $inline['content'] ?? [],
        ]);
    }

    /**
     * Resolve an audience rule the caller is allowed to broadcast with.
     *
     * Mirrors `NotificationAudienceAdminController::findInBrand()`: must
     * be in one of the route brand's local orgs, and either brand-scoped
     * to this brand or org-wide (null brand_id). 404 on miss.
     *
     * @return array<string, mixed>
     */
    private function resolveRule(BroadcastRequest $request, Brand $brand): array
    {
        if ($request->filled('audience_id')) {
            $orgIds = $this->brandOrgIds($brand);

            /** @var NotificationAudience $model */
            $model = NotificationAudience::query()
                ->whereIn('organization_id', $orgIds)
                ->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', $brand->id))
                ->findOrFail($request->input('audience_id'));

            return (array) $model->rule;
        }

        return (array) $request->input('audience_inline', []);
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
