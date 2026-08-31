<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Requests\HQ\Notification\RenderTemplateRequest;
use App\Http\Requests\HQ\Notification\StoreTemplateRequest;
use App\Http\Requests\HQ\Notification\UpdateTemplateRequest;
use App\Http\Resources\Admin\TemplateResource;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Services\Notification\TemplateRenderer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/hq/{brandSlug}/notifications/templates*` (plan-012 T2.4).
 */
class NotificationTemplateAdminController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly TemplateRenderer $renderer) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/templates',
        summary: 'List notification templates for this brand',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated templates'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageTemplates', [Notification::class, $brand]);

        $orgIds = $this->brandOrgIds($brand);

        $paginator = NotificationTemplate::query()
            ->where(function ($q) use ($orgIds, $brand) {
                $q->where('is_system', true)
                    ->orWhereIn('organization_id', $orgIds)
                    ->orWhere('brand_id', $brand->id);
            })
            ->orderBy('key')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => TemplateResource::collection($paginator->items())->toArray($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/notifications/templates',
        summary: 'Create a notification template (rejects when ja/en/vi content incomplete)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['key', 'content'],
                properties: [
                    new OA\Property(property: 'key', type: 'string', maxLength: 100),
                    new OA\Property(property: 'content', type: 'object'),
                    new OA\Property(property: 'default_channels', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'params_schema', type: 'object', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Locale content incomplete'),
        ]
    )]
    public function store(StoreTemplateRequest $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageTemplates', [Notification::class, $brand]);

        $orgIds = $this->brandOrgIds($brand);

        // `brand_id` is pinned to the route brand — never accept it from
        // input (cross-brand metadata pollution guard, see #171).
        $template = NotificationTemplate::query()->create([
            'organization_id' => $orgIds[0] ?? null,
            'brand_id' => $brand->id,
            'key' => $request->input('key'),
            'content' => $request->input('content'),
            'default_channels' => $request->input('default_channels', ['in_app']),
            'params_schema' => $request->input('params_schema'),
            'is_system' => false,
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => (new TemplateResource($template))->toArray($request)], 201);
    }

    #[OA\Patch(
        path: '/api/v1/hq/{brandSlug}/notifications/templates/{id}',
        summary: 'Update a notification template (rejects is_system rows)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 403, description: 'Forbidden (is_system)'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(UpdateTemplateRequest $request, string $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageTemplates', [Notification::class, $brand]);

        $template = $this->findInBrand($id, $brand);
        abort_if($template->is_system, 403, 'system template rows are read-only');

        $template->fill($request->validated())->save();

        return response()->json(['data' => (new TemplateResource($template->fresh()))->toArray($request)]);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/notifications/templates/{id}',
        summary: 'Delete a notification template (rejects is_system rows)',
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
            new OA\Response(response: 409, description: 'Template is referenced by a rule and cannot be deleted'),
        ]
    )]
    public function destroy(Request $request, string $id): Response|JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageTemplates', [Notification::class, $brand]);

        $template = $this->findInBrand($id, $brand);
        abort_if($template->is_system, 403, 'system template rows are read-only');

        // Block deleting a template still referenced by a notification rule —
        // the rule's action.template_key points at this template's (globally
        // unique) key, so removing it would orphan the rule (TC-NOTIF-TPL05).
        if (NotificationRule::query()->where('action->template_key', $template->key)->exists()) {
            return response()->json([
                'message' => 'This template is used by one or more notification rules and cannot be deleted.',
                'error' => 'IN_USE',
            ], 409);
        }

        $template->delete();

        return response()->noContent();
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/notifications/templates/{id}/duplicate',
        summary: 'Duplicate a template into a new brand-owned, editable copy',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Duplicated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function duplicate(Request $request, string $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageTemplates', [Notification::class, $brand]);

        $source = $this->findInBrand($id, $brand);
        $orgIds = $this->brandOrgIds($brand);

        // Copies are always brand-owned + editable, regardless of the source
        // being a system template (TC-NOTIF-TPL06).
        $copy = NotificationTemplate::query()->create([
            'organization_id' => $orgIds[0] ?? null,
            'brand_id' => $brand->id,
            'key' => $this->uniqueCopyKey($source->key),
            'content' => $source->content,
            'default_channels' => $source->default_channels,
            'params_schema' => $source->params_schema,
            'is_system' => false,
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => (new TemplateResource($copy))->toArray($request)], 201);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/notifications/templates/render',
        summary: 'Render a template (saved OR inline) against sample params — composer live preview',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'template_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'template_inline', type: 'object', nullable: true),
                    new OA\Property(property: 'params', type: 'object', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Rendered per locale'),
            new OA\Response(response: 422, description: 'Invalid input'),
            new OA\Response(response: 404, description: 'Saved template not found'),
        ]
    )]
    public function render(RenderTemplateRequest $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageTemplates', [Notification::class, $brand]);

        $params = (array) $request->input('params', []);

        if ($request->filled('template_id')) {
            $template = $this->findInBrand((string) $request->input('template_id'), $brand);
        } else {
            $inline = (array) $request->input('template_inline', []);
            $template = new NotificationTemplate([
                'key' => $inline['key'] ?? 'inline',
                'content' => $inline['content'] ?? [],
            ]);
        }

        return response()->json(['data' => $this->renderer->renderAll($template, $params)]);
    }

    /**
     * Build a unique key for a duplicated template. Tries `{base}-copy`, then
     * `{base}-copy-2`, `{base}-copy-3`, … until one is free. The key column is
     * globally unique and capped at 100 chars, so the base is truncated to make
     * room for the suffix.
     */
    private function uniqueCopyKey(string $base): string
    {
        for ($i = 1; ; $i++) {
            $suffix = $i === 1 ? '-copy' : "-copy-{$i}";
            $candidate = mb_substr($base, 0, 100 - mb_strlen($suffix)).$suffix;

            if (! NotificationTemplate::query()->where('key', $candidate)->exists()) {
                return $candidate;
            }
        }
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

    private function findInBrand(string $id, Brand $brand): NotificationTemplate
    {
        $orgIds = $this->brandOrgIds($brand);

        return NotificationTemplate::query()
            ->where(function ($q) use ($orgIds, $brand) {
                $q->where('is_system', true)
                    ->orWhereIn('organization_id', $orgIds)
                    ->orWhere('brand_id', $brand->id);
            })
            ->findOrFail($id);
    }
}
