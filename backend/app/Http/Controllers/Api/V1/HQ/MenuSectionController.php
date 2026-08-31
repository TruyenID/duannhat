<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\MenuSectionStoreRequest;
use App\Http\Requests\MenuSectionUpdateRequest;
use App\Http\Resources\MenuSectionResource;
use App\Models\MenuSection;
use App\Services\Product\MenuSectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Menu Sections', description: 'Menu section (category grouping) management')]
class MenuSectionController extends Controller
{
    use HasOrganizationContext;

    public function __construct(
        private readonly MenuSectionService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/menu-sections',
        summary: 'List menu sections',
        description: 'Returns paginated list of menu sections.',
        tags: ['Menu Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: '-created_at')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $sections = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'brand_id' => $request->attributes->get('brand_id'),
            'search' => $request->input('search'),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return MenuSectionResource::collection($sections);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/menu-sections',
        summary: 'Create a menu section',
        description: 'Creates a new menu section.',
        tags: ['Menu Sections'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Section created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(MenuSectionStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['brand_id'] = $request->attributes->get('brand_id');

        $section = $this->service->create($data);

        return (new MenuSectionResource($section))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/menu-sections/{menuSection}',
        summary: 'Get a menu section',
        description: 'Returns a single menu section by UUID.',
        tags: ['Menu Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menuSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Section detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(MenuSection $menuSection): MenuSectionResource
    {
        $this->authorizeOrganization($menuSection);
        $this->authorizeBrand($menuSection);

        return new MenuSectionResource(
            $this->service->findById($menuSection->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/menu-sections/{menuSection}',
        summary: 'Update a menu section',
        description: 'Updates a menu section.',
        tags: ['Menu Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menuSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Section updated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(MenuSectionUpdateRequest $request, MenuSection $menuSection): MenuSectionResource
    {
        $this->authorizeOrganization($menuSection);
        $this->authorizeBrand($menuSection);

        $updated = $this->service->update($menuSection, $request->validated());

        return new MenuSectionResource($updated);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/menu-sections/{menuSection}',
        summary: 'Delete a menu section',
        description: 'Soft-deletes a menu section. Products referencing this section will have menu_section_id set to NULL.',
        tags: ['Menu Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menuSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(MenuSection $menuSection): JsonResponse
    {
        $this->authorizeOrganization($menuSection);
        $this->authorizeBrand($menuSection);

        $this->service->delete($menuSection);

        return response()->json(null, 204);
    }
}
