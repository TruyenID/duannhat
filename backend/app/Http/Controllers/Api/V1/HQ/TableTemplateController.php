<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\TableTemplateStoreRequest;
use App\Http\Requests\TableTemplateUpdateRequest;
use App\Http\Resources\TableTemplateResource;
use App\Models\TableTemplate;
use App\Services\Shop\TableTemplateService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * HQ CRUD for brand-scoped table templates (issue #890) — the "default tables"
 * a shop can pull down via POST /shops/{shopSlug}/tables/apply-defaults.
 */
class TableTemplateController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly TableTemplateService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/table-templates',
        summary: 'List table templates',
        description: 'Returns a paginated list of default-table templates for the brand, ordered by code by default.',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zone_template_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Search by name or code', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'with_trashed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'code')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of table templates'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Brand not found'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TableTemplate::class);

        $tableTemplates = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'brand_id' => $request->attributes->get('brand_id'),
            'zone_template_id' => $request->input('zone_template_id'),
            'search' => $request->input('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', 'code'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return TableTemplateResource::collection($tableTemplates);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/table-templates',
        summary: 'Create a table template',
        description: 'Creates a new default-table template inside one of the brand\'s zone templates.',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'zone_template_id'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 50, description: 'Unique per brand. Alphanumeric + hyphens only.', example: 'T-01'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                    new OA\Property(property: 'seat_count', type: 'integer', minimum: 1, default: 2),
                    new OA\Property(property: 'zone_template_id', type: 'string', format: 'uuid'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Table template created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function store(TableTemplateStoreRequest $request): JsonResponse
    {
        $this->authorize('create', TableTemplate::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        // brand_id comes from the {brandSlug} URL segment (ResolveBrandFromSlug)
        // — never from the client.
        $data['brand_id'] = $request->attributes->get('brand_id');

        $tableTemplate = $this->service->create($data);

        return (new TableTemplateResource($tableTemplate))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/table-templates/{tableTemplate}',
        summary: 'Get a table template',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tableTemplate', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Table template details'),
            new OA\Response(response: 404, description: 'Not found in this brand'),
        ],
    )]
    public function show(Request $request): TableTemplateResource
    {
        $tableTemplate = $this->resolveTableTemplate($request);
        $this->authorize('view', $tableTemplate);

        return new TableTemplateResource($this->service->findById($tableTemplate->id));
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/table-templates/{tableTemplate}',
        summary: 'Update a table template',
        description: 'Partial update. All fields optional. Changes never sync to shops that already applied the defaults.',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tableTemplate', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'code', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'seat_count', type: 'integer', minimum: 1, nullable: true),
                new OA\Property(property: 'zone_template_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
            ]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Table template updated'),
            new OA\Response(response: 404, description: 'Not found in this brand'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function update(TableTemplateUpdateRequest $request): TableTemplateResource
    {
        $tableTemplate = $this->resolveTableTemplate($request);
        $this->authorize('update', $tableTemplate);

        return new TableTemplateResource(
            $this->service->update($tableTemplate, $request->validated())
        );
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/table-templates/{tableTemplate}',
        summary: 'Soft-delete a table template',
        description: 'Soft-deletes the table template. Shops that already applied the defaults are untouched.',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tableTemplate', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Table template soft-deleted'),
            new OA\Response(response: 404, description: 'Not found in this brand'),
        ],
    )]
    public function destroy(Request $request): JsonResponse
    {
        $tableTemplate = $this->resolveTableTemplate($request);
        $this->authorize('delete', $tableTemplate);

        $this->service->delete($tableTemplate);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/table-templates/{tableTemplate}/restore',
        summary: 'Restore a soft-deleted table template',
        description: 'Restores the table template. Fails with 409 when its zone template is still soft-deleted.',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tableTemplate', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Table template restored'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 409, description: 'Zone template still deleted'),
        ],
    )]
    public function restore(Request $request): TableTemplateResource
    {
        $tableTemplate = $this->resolveTableTemplate($request, withTrashed: true);

        $this->authorize('restore', $tableTemplate);

        // Mirror BR-Z03/BR-ZT03: a table template cannot come back while its
        // zone template is still soft-deleted — restore the zone first.
        if ($tableTemplate->zoneTemplate()->withTrashed()->first()?->trashed()) {
            abort(response()->json([
                'message' => 'Restore the zone template first.',
                'code' => 'ZONE_TEMPLATE_DELETED',
            ], 409));
        }

        return new TableTemplateResource($this->service->restore($tableTemplate));
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/table-templates/{tableTemplate}/toggle-active',
        summary: 'Toggle is_active flag',
        description: 'Flips the is_active flag. Inactive templates are skipped when a shop applies brand defaults.',
        tags: ['TableTemplates'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tableTemplate', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Table template toggled'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function toggleActive(Request $request): TableTemplateResource
    {
        $tableTemplate = $this->resolveTableTemplate($request);
        $this->authorize('toggleActive', $tableTemplate);

        return new TableTemplateResource($this->service->toggleActive($tableTemplate));
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    /**
     * Resolve the route's table template scoped to the current org + brand so a
     * caller can never reach another brand's templates by guessing UUIDs.
     */
    private function resolveTableTemplate(Request $request, bool $withTrashed = false): TableTemplate
    {
        $query = $withTrashed ? TableTemplate::withTrashed() : TableTemplate::query();

        return $query
            ->where('organization_id', $this->getOrganizationId())
            ->where('brand_id', $request->attributes->get('brand_id'))
            ->findOrFail((string) $request->route('tableTemplate'));
    }
}
