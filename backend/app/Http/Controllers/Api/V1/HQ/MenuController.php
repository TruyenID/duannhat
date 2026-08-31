<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasBulkOperations;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\MenuAddProductsRequest;
use App\Http\Requests\MenuStoreRequest;
use App\Http\Requests\MenuSyncLayoutRequest;
use App\Http\Requests\MenuUpdateRequest;
use App\Http\Resources\MenuProductResource;
use App\Http\Resources\MenuResource;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\Organization;
use App\Services\Product\MenuService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/menus/bulk-delete',
    summary: 'Bulk-delete menus',
    description: 'Soft-deletes up to 100 menus at once.',
    tags: ['Menus'],
    security: [['sanctum' => []]],
    parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['ids'],
            properties: [new OA\Property(property: 'ids', type: 'array', maxItems: 100, items: new OA\Items(type: 'string', format: 'uuid'))]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Bulk-delete result'),
        new OA\Response(response: 422, description: 'Validation failed'),
    ]
)]
class MenuController extends Controller
{
    use AuthorizesRequests;
    use HasBulkOperations;
    use HasOrganizationContext;

    public function __construct(
        private readonly MenuService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/menus',
        summary: 'List menus',
        description: 'Returns a paginated list of menus. Supports filtering by branch, status, and master flag.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'is_master', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Menu')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Menu::class);

        $menus = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'brand_id' => $request->attributes->get('brand_id'),
            'branch_id' => $request->input('branch_id'),
            'status' => $request->input('status'),
            'is_master' => $request->has('is_master') ? $request->boolean('is_master') : null,
            'search' => $request->input('search'),
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return MenuResource::collection($menus);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/menus',
        summary: 'Create a menu',
        description: 'Creates a new menu (defaults to Draft status). Optionally accepts product_ids to attach products.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'branch_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true),
                    new OA\Property(property: 'is_master', type: 'boolean', nullable: true),
                    new OA\Property(property: 'priority', type: 'integer', nullable: true),
                    new OA\Property(property: 'product_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Menu created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(MenuStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Menu::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['brand_id'] = $request->attributes->get('brand_id');
        $data['created_by_id'] = $request->user()->id;

        $menu = $this->service->create($data);

        return (new MenuResource($menu))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}',
        summary: 'Get a menu',
        description: 'Returns a single menu by UUID, including menu sections, products and their SKUs.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Menu', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Menu $menu): MenuResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('view', $menu);

        return new MenuResource(
            $this->service->findById($menu->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}',
        summary: 'Update a menu',
        description: 'Updates a menu. Fields are nullable for partial updates.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'priority', type: 'integer', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(MenuUpdateRequest $request, Menu $menu): MenuResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('update', $menu);

        $menu = $this->service->update($menu, $request->validated());

        return new MenuResource($menu);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}',
        summary: 'Delete a menu',
        description: 'Soft-deletes a menu.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Menu $menu): JsonResponse
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('delete', $menu);

        $this->service->delete($menu);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/restore',
        summary: 'Restore a soft-deleted menu',
        description: 'Restores a previously soft-deleted menu.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Restored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function restore(string $id): MenuResource
    {
        $menu = $this->service->restoreFromTrash(
            $id,
            $this->getOrganizationId(),
            request()->attributes->get('brand_id'),
        );

        $this->authorize('restore', $menu);

        $menu = $this->service->restore($menu);

        return new MenuResource($menu);
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/menus/lookup',
        summary: 'Lookup menus',
        description: 'Returns a lightweight list of menus for select/combobox.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        ]
    )]
    public function lookup(): JsonResponse
    {
        $this->authorize('viewAny', Menu::class);

        return response()->json([
            'data' => $this->service->lookup(
                $this->getOrganizationId(),
                request()->attributes->get('brand_id'),
            ),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/menus/current',
        summary: 'Get the current active menu for a branch',
        description: 'Returns the active menu for the given branch, or null if none.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Current menu (or null)', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu', nullable: true)])),
            new OA\Response(response: 422, description: 'branch_id missing'),
        ]
    )]
    public function current(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Menu::class);

        $request->validate([
            'branch_id' => [
                'required', 'string', 'uuid',
                Rule::exists('branches', 'id')->where(
                    fn ($query) => $query
                        ->where('console_brand_id', $request->attributes->get('brand')->console_brand_id)
                        ->whereNull('deleted_at'),
                ),
            ],
        ]);

        $menu = $this->service->getCurrentMenu(
            $request->input('branch_id'),
            $this->getOrganizationId(),
            $request->attributes->get('brand_id'),
        );

        return response()->json([
            'data' => $menu ? new MenuResource($menu) : null,
        ]);
    }

    // =========================================================================
    //  Reorder
    // =========================================================================

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/menus/reorder',
        summary: 'Reorder menus within a branch',
        description: 'Reassigns priority (1-based) to all menus of a branch using the supplied ordered array of IDs. The array must include every non-deleted menu ID that belongs to the branch.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['branch_id', 'menu_ids'],
                properties: [
                    new OA\Property(property: 'branch_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'menu_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), description: 'All menu IDs of the branch in the new desired order.'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Reordered'),
            new OA\Response(response: 422, description: 'Validation failed or menu_ids does not cover all branch menus'),
        ]
    )]
    public function reorderMenus(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Menu::class);

        $validated = $request->validate([
            'branch_id' => [
                'required', 'string', 'uuid',
                Rule::exists('branches', 'id')->where(
                    fn ($query) => $query
                        ->where('console_brand_id', $request->attributes->get('brand')->console_brand_id)
                        ->whereNull('deleted_at'),
                ),
            ],
            'menu_ids' => ['required', 'array', 'min:1'],
            'menu_ids.*' => ['required', 'string', 'uuid'],
        ]);

        $this->service->reorderMenus(
            $this->getOrganizationId(),
            $validated['branch_id'],
            $validated['menu_ids'],
            $request->attributes->get('brand_id'),
        );

        return response()->json(['message' => 'Menus reordered.']);
    }

    public function reorderMasterMenus(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Menu::class);

        $validated = $request->validate([
            'menu_ids' => ['required', 'array', 'min:1'],
            'menu_ids.*' => ['required', 'string', 'uuid'],
        ]);

        $this->service->reorderMasterMenus(
            $this->getOrganizationId(),
            $validated['menu_ids'],
            $request->attributes->get('brand_id'),
        );

        return response()->json(['message' => 'Master menus reordered.']);
    }

    // =========================================================================
    //  Products
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/products',
        summary: 'Add products to a menu',
        description: 'Adds one or more products to the menu. Duplicate product_ids are silently skipped.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_ids'],
                properties: [
                    new OA\Property(property: 'product_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                    new OA\Property(property: 'menu_section_id', type: 'string', format: 'uuid', nullable: true, description: 'Assign products to this menu section'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Products added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 404, description: 'Menu not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function addProducts(MenuAddProductsRequest $request, Menu $menu): JsonResponse
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('update', $menu);

        $menuProducts = $this->service->addProducts($menu, $request->validated('product_ids'), $request->validated('menu_section_id'));

        return MenuProductResource::collection($menuProducts)
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/products/{menuProduct}',
        summary: 'Remove a product from a menu',
        description: 'Removes a menu product and its associated SKU rows.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'menuProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Product removed'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function removeProduct(Menu $menu, string $menuProduct): JsonResponse
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('update', $menu);

        $mp = $this->resolveMenuProduct($menu, $menuProduct);

        $this->service->removeProduct($mp);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/products/{menuProduct}/toggle',
        summary: 'Toggle a menu product active/inactive',
        description: 'Flips the is_active flag on a menu product.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'menuProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Toggled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function toggleProduct(Menu $menu, string $menuProduct): MenuProductResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('update', $menu);

        $mp = $this->resolveMenuProduct($menu, $menuProduct);

        $toggled = $this->service->toggleProduct($mp);

        return new MenuProductResource($toggled);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/products/reorder',
        summary: 'Reorder products in a menu',
        description: 'Accepts an ordered array of menu product IDs and reassigns display_order.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['ordered_ids'],
                properties: [
                    new OA\Property(property: 'ordered_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Reordered', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function reorderProducts(Request $request, Menu $menu): MenuResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('update', $menu);

        $validated = $request->validate([
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['required', 'uuid'],
        ]);

        $menu = $this->service->reorderProducts($menu, $validated['ordered_ids']);

        return new MenuResource($menu);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/layout',
        summary: 'Sync the entire menu layout (sections + their products) in one call',
        description: 'Replaces the menu layout with the given grouped items. Sections are matched by name within this menu — new names create rows, removed names are detached. A product MAY appear in multiple sections (one menu_products row, multiple section pivot rows). Products absent from the payload are soft-deleted.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['menu_items'],
                properties: [
                    new OA\Property(
                        property: 'menu_items',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'section_name', type: 'string', maxLength: 255),
                                new OA\Property(property: 'product_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                            ],
                            type: 'object'
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Layout synced', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 404, description: 'Menu not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function syncLayout(MenuSyncLayoutRequest $request, Menu $menu): MenuResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('update', $menu);

        $menu = $this->service->syncLayout($menu, $request->validated('menu_items', []));

        return new MenuResource($menu);
    }

    // =========================================================================
    //  Menu Sections (N:N pivot)
    // =========================================================================

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/sections',
        summary: 'Sync sections for a menu',
        description: 'Replaces the menu\'s sections with the given list. Each entry can specify display_order.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sections'],
                properties: [
                    new OA\Property(
                        property: 'sections',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'display_order', type: 'integer'),
                            ],
                            type: 'object'
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Sections synced'),
            new OA\Response(response: 404, description: 'Menu not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function syncSections(Request $request, Menu $menu): MenuResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('update', $menu);

        $validated = $request->validate([
            'sections' => ['required', 'array'],
            'sections.*.id' => ['required', 'uuid', 'exists:menu_sections,id'],
            'sections.*.display_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $menu = $this->service->syncSections($menu, $validated['sections']);

        return new MenuResource($menu);
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/submit',
        summary: 'Submit menu for approval',
        description: 'Transitions a draft menu to pending.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function submit(Menu $menu): MenuResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('submit', $menu);

        $menu = $this->service->submit($menu);

        return new MenuResource($menu);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/approve',
        summary: 'Approve a menu',
        description: 'Approves a pending menu.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function approve(Menu $menu): MenuResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('approve', $menu);

        $menu = $this->service->approve(
            menu: $menu,
            approverId: request()->user()->id,
        );

        return new MenuResource($menu);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/reject',
        summary: 'Reject a menu',
        description: 'Rejects a pending menu with a required reason.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['rejection_reason'],
                properties: [new OA\Property(property: 'rejection_reason', type: 'string', maxLength: 1000)]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed or invalid state'),
        ]
    )]
    public function reject(Request $request, Menu $menu): MenuResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('reject', $menu);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $menu = $this->service->reject(
            menu: $menu,
            rejectedById: $request->user()->id,
            reason: $validated['rejection_reason'],
        );

        return new MenuResource($menu);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/activate',
        summary: 'Activate a menu',
        description: 'Activates an approved menu so it becomes the live menu.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Activated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function activate(Menu $menu): MenuResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('activate', $menu);

        $menu = $this->service->activate($menu);

        return new MenuResource($menu);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/deactivate',
        summary: 'Deactivate a menu',
        description: 'Deactivates a menu.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deactivated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function deactivate(Menu $menu): MenuResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('deactivate', $menu);

        $menu = $this->service->deactivate($menu);

        return new MenuResource($menu);
    }

    // =========================================================================
    //  Master Menu
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/master-menus',
        summary: 'List master menus',
        description: 'Returns the list of master menus for the brand.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of master menus', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Menu'))])),
        ]
    )]
    public function masterMenus(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Menu::class);

        $menus = $this->service->listMasterMenus(
            $this->getOrganizationId(),
            $request->attributes->get('brand_id'),
        );

        return MenuResource::collection($menus);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/master-menus',
        summary: 'Create a master menu',
        description: 'Creates a new master menu (template menu that can be cloned to branches).',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Master menu created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function storeMasterMenu(MenuStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Menu::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['brand_id'] = $request->attributes->get('brand_id');
        $data['created_by_id'] = $request->user()->id;

        $menu = $this->service->createMasterMenu($data);

        return (new MenuResource($menu))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/master-menus/lookup',
        summary: 'Lookup master menus',
        description: 'Returns a lightweight list of master menus for select/combobox.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        ]
    )]
    public function masterMenuLookup(): JsonResponse
    {
        $this->authorize('viewAny', Menu::class);

        return response()->json([
            'data' => $this->service->masterMenuDropdown(
                $this->getOrganizationId(),
                request()->attributes->get('brand_id'),
            ),
        ]);
    }

    /** @deprecated Use masterMenuLookup() instead */
    public function masterMenuDropdown(): JsonResponse
    {
        return $this->masterMenuLookup();
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/clone-to-branch',
        summary: 'Clone a master menu to a branch',
        description: 'Creates a branch-level menu by cloning products from the master menu.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['branch_id'],
                properties: [
                    new OA\Property(property: 'branch_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Branch menu created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 404, description: 'Master menu not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function cloneToBranch(Request $request, Menu $menu): JsonResponse
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('create', Menu::class);

        $validated = $request->validate([
            'branch_id' => [
                'required', 'string', 'uuid',
                // Tenant isolation — the target branch must belong to the master
                // menu's organization, else a cross-tenant clone would leak the
                // menu into a foreign org's branch. Fail as the same branch_id
                // validation error a bad uuid returns so existence never leaks.
                Rule::exists('branches', 'id')->where(
                    fn ($query) => $query
                        ->where('console_organization_id', Organization::whereKey($menu->organization_id)->value('console_organization_id'))
                        ->where('console_brand_id', $request->attributes->get('brand')->console_brand_id)
                        ->whereNull('deleted_at'),
                ),
            ],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $overrides = [];
        if (! empty($validated['name'])) {
            $overrides['name'] = $validated['name'];
        }
        $overrides['created_by_id'] = $request->user()->id;

        $branchMenu = $this->service->cloneToBranch($menu, $validated['branch_id'], $overrides);

        return (new MenuResource($branchMenu))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/duplicate',
        summary: 'Duplicate a menu',
        description: 'Creates an independent Draft copy of the menu (sections, products, SKUs, schedules) in the same scope — no master/clone linkage.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Menu duplicated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function duplicate(Request $request, Menu $menu): JsonResponse
    {
        $this->authorizeOrganization($menu);
        $this->authorize('create', Menu::class);

        $copy = $this->service->duplicate($menu, [
            'created_by_id' => $request->user()->id,
        ]);

        return (new MenuResource($copy))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/check-sync',
        summary: 'Check if master menu has new products to sync',
        description: 'Returns the list of new products in the master menu that are not in this branch menu.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sync availability', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'count', type: 'integer'),
            ])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function checkSync(Menu $menu): JsonResponse
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('view', $menu);

        $newProducts = $this->service->checkSyncAvailable($menu);

        return response()->json([
            'data' => MenuProductResource::collection($newProducts),
            'count' => $newProducts->count(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/sync-from-master',
        summary: 'Sync new products from master menu',
        description: 'Adds any new products from the master menu into this branch menu.',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Synced', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function syncFromMaster(Menu $menu): MenuResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('update', $menu);

        $menu = $this->service->syncFromMaster($menu);

        return new MenuResource($menu);
    }

    // =========================================================================
    //  Bulk Operations
    // =========================================================================

    protected function getModelClass(): string
    {
        return Menu::class;
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    private function resolveMenuProduct(Menu $menu, string $menuProductId): MenuProduct
    {
        return $menu->menuProducts()->findOrFail($menuProductId);
    }

    /**
     * plan-043 T2.3 — set/clear a menu item's tax-type override
     * (MenuProduct.tax_type_id). null = inherit from the product (resolver §7
     * tier 1). The type must belong to the brand + be active.
     */
    #[OA\Patch(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/products/{menuProduct}/tax-type',
        summary: "Set a menu item's tax-type override",
        description: 'null tax_type_id clears the override (inherit from the product).',
        tags: ['Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'menuProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'tax_type_id', type: 'string', format: 'uuid', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [
                // Inline shape (not a $ref): the MenuProduct component schema is
                // registered in the Shop docs bucket, not this (hq) one — cross-bucket
                // refs don't resolve. The tax-type override is the field that matters here.
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'menu_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'tax_type_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                ]),
            ])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed (foreign brand / inactive)'),
        ]
    )]
    public function updateProductTaxType(Request $request, Menu $menu, string $menuProduct): MenuProductResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('update', $menu);

        $mp = $this->resolveMenuProduct($menu, $menuProduct);

        $validated = $request->validate([
            'tax_type_id' => ['present', 'nullable', 'uuid'],
        ]);

        $updated = $this->service->updateProductTaxType(
            menuProduct: $mp,
            taxTypeId: $validated['tax_type_id'],
            brandId: $request->attributes->get('brand_id'),
        );

        return new MenuProductResource($updated);
    }

    /**
     * #1218 tier 3 — one tax type for the WHOLE menu (the 持ち帰り menu is 8%).
     * Null clears it and every line falls through to the product.
     */
    #[OA\Patch(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/tax-type',
        summary: 'Set the tax type for the whole menu (#1218 tier 3)',
        tags: ['HQ Menus'],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'tax_type_id', type: 'string', format: 'uuid', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed (foreign brand / inactive)'),
        ]
    )]
    public function updateMenuTaxType(Request $request, Menu $menu): MenuResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('update', $menu);

        $validated = $request->validate([
            'tax_type_id' => ['present', 'nullable', 'uuid'],
        ]);

        return new MenuResource($this->service->updateMenuTaxType(
            menu: $menu,
            taxTypeId: $validated['tax_type_id'],
            brandId: $request->attributes->get('brand_id'),
        ));
    }

    /**
     * #1218 tier 2 — tax type for one section IN THIS MENU. Stored on the pivot,
     * so the same section keeps its own value in every other menu that shows it.
     */
    #[OA\Patch(
        path: '/api/v1/hq/{brandSlug}/menus/{menu}/sections/{menuSection}/tax-type',
        summary: 'Set the tax type for a section within this menu (#1218 tier 2)',
        tags: ['HQ Menus'],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'tax_type_id', type: 'string', format: 'uuid', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Section not in this menu, or foreign/inactive tax type'),
        ]
    )]
    public function updateSectionTaxType(Request $request, Menu $menu, string $menuSection): MenuResource
    {
        $this->authorizeOrganization($menu);
        $this->authorizeBrand($menu);
        $this->authorize('update', $menu);

        $validated = $request->validate([
            'tax_type_id' => ['present', 'nullable', 'uuid'],
        ]);

        return new MenuResource($this->service->updateSectionTaxType(
            menu: $menu,
            menuSectionId: $menuSection,
            taxTypeId: $validated['tax_type_id'],
            brandId: $request->attributes->get('brand_id'),
        ));
    }
}
