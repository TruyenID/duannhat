<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\ZoneTemplateStoreRequest;
use App\Http\Requests\ZoneTemplateUpdateRequest;
use App\Http\Resources\ZoneTemplateResource;
use App\Models\ZoneTemplate;
use App\Services\Shop\ZoneTemplateService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * HQ CRUD for brand-scoped zone templates (issue #890) — the "default floor
 * layout" a shop can pull down via POST /shops/{shopSlug}/tables/apply-defaults.
 */
class ZoneTemplateController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly ZoneTemplateService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/zone-templates',
        summary: 'List zone templates',
        description: 'Returns a paginated list of default-zone templates for the brand, ordered by display_order by default.',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Search by name or code', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'with_trashed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'display_order')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of zone templates'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Brand not found'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ZoneTemplate::class);

        $zoneTemplates = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'brand_id' => $request->attributes->get('brand_id'),
            'search' => $request->input('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', 'display_order'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return ZoneTemplateResource::collection($zoneTemplates);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/zone-templates',
        summary: 'Create a zone template',
        description: 'Creates a new default-zone template for the brand.',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 50, description: 'Unique per brand. Alphanumeric + hyphens only.', example: 'TER'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Terrace'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'display_order', type: 'integer', minimum: 0, default: 0),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Zone template created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function store(ZoneTemplateStoreRequest $request): JsonResponse
    {
        $this->authorize('create', ZoneTemplate::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        // brand_id comes from the {brandSlug} URL segment (ResolveBrandFromSlug)
        // — never from the client.
        $data['brand_id'] = $request->attributes->get('brand_id');

        $zoneTemplate = $this->service->create($data);

        return (new ZoneTemplateResource($zoneTemplate))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/zone-templates/{zoneTemplate}',
        summary: 'Get a zone template',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zoneTemplate', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Zone template details'),
            new OA\Response(response: 404, description: 'Not found in this brand'),
        ],
    )]
    public function show(Request $request): ZoneTemplateResource
    {
        $zoneTemplate = $this->resolveZoneTemplate($request);
        $this->authorize('view', $zoneTemplate);

        return new ZoneTemplateResource($this->service->findById($zoneTemplate->id));
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/zone-templates/{zoneTemplate}',
        summary: 'Update a zone template',
        description: 'Partial update. All fields optional. Changes never sync to shops that already applied the defaults.',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zoneTemplate', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'code', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'display_order', type: 'integer', minimum: 0, nullable: true),
                new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
            ]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Zone template updated'),
            new OA\Response(response: 404, description: 'Not found in this brand'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function update(ZoneTemplateUpdateRequest $request): ZoneTemplateResource
    {
        $zoneTemplate = $this->resolveZoneTemplate($request);
        $this->authorize('update', $zoneTemplate);

        return new ZoneTemplateResource(
            $this->service->update($zoneTemplate, $request->validated())
        );
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/zone-templates/{zoneTemplate}',
        summary: 'Soft-delete a zone template',
        description: 'Soft-deletes the zone template and cascades soft-delete to its table templates (BR-ZT02). Shops that already applied the defaults are untouched.',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zoneTemplate', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Zone template soft-deleted'),
            new OA\Response(response: 404, description: 'Not found in this brand'),
        ],
    )]
    public function destroy(Request $request): JsonResponse
    {
        $zoneTemplate = $this->resolveZoneTemplate($request);
        $this->authorize('delete', $zoneTemplate);

        $this->service->delete($zoneTemplate);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/zone-templates/{zoneTemplate}/restore',
        summary: 'Restore a soft-deleted zone template',
        description: 'Restores the zone template but does NOT auto-restore its table templates (BR-ZT03).',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zoneTemplate', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Zone template restored'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function restore(Request $request): ZoneTemplateResource
    {
        $zoneTemplate = $this->resolveZoneTemplate($request, withTrashed: true);

        $this->authorize('restore', $zoneTemplate);

        return new ZoneTemplateResource($this->service->restore($zoneTemplate));
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/zone-templates/{zoneTemplate}/toggle-active',
        summary: 'Toggle is_active flag',
        description: 'Flips the is_active flag. Inactive templates are skipped when a shop applies brand defaults.',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zoneTemplate', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Zone template toggled'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function toggleActive(Request $request): ZoneTemplateResource
    {
        $zoneTemplate = $this->resolveZoneTemplate($request);
        $this->authorize('toggleActive', $zoneTemplate);

        return new ZoneTemplateResource($this->service->toggleActive($zoneTemplate));
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/zone-templates/lookup',
        summary: 'Lightweight zone-template lookup',
        description: 'Returns active zone templates [{id, code, name, display_order}] for select/combobox controls.',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ZoneTemplate::class);

        return response()->json([
            'data' => $this->service->lookup(
                $this->getOrganizationId(),
                $request->attributes->get('brand_id'),
            ),
        ]);
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    /**
     * Resolve the route's zone template scoped to the current org + brand so a
     * caller can never reach another brand's templates by guessing UUIDs.
     */
    private function resolveZoneTemplate(Request $request, bool $withTrashed = false): ZoneTemplate
    {
        $query = $withTrashed ? ZoneTemplate::withTrashed() : ZoneTemplate::query();

        return $query
            ->where('organization_id', $this->getOrganizationId())
            ->where('brand_id', $request->attributes->get('brand_id'))
            ->findOrFail((string) $request->route('zoneTemplate'));
    }
}
