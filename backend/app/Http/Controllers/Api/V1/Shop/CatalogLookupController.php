<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\Material;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Services\Product\MaterialService;
use App\Services\Product\ProductQueryService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Shop-scoped catalog lookups.
 *
 * The HQ controllers expose lookup endpoints scoped only by organization.
 * Shop-level screens (stock-transactions Create, transfers, counts, ...)
 * need lookups filtered down to the shop's brand. Rather than hard-coding a
 * brand slug into shop URLs, this controller reads the resolved brand_id off
 * the request (set by `ResolveShopFromSlug` middleware) and forwards it to
 * the existing lookup services.
 */
class CatalogLookupController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly ProductQueryService $productQueries,
        private readonly MaterialService $materialService,
    ) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/product-skus/lookup',
        summary: 'Lookup product SKUs (variants) for the shop\'s brand',
        description: 'Returns a lightweight list of active product SKUs for select/combobox usage on shop-level screens. Filtered by the shop\'s brand.',
        tags: ['Shop Catalog Lookup'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function productSkus(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductSku::class);

        $brandId = $request->attributes->get('brand_id');

        return response()->json([
            'data' => $this->productQueries->skuLookup(
                $this->getOrganizationId(),
                $brandId,
            ),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/materials/lookup',
        summary: 'Lookup materials for the shop\'s brand',
        description: 'Returns a lightweight list of active materials for select/combobox usage on shop-level screens. Filtered by the shop\'s brand.',
        tags: ['Shop Catalog Lookup'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function materials(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Material::class);

        $brandId = $request->attributes->get('brand_id');

        return response()->json([
            'data' => $this->materialService->lookup(
                organizationId: $this->getOrganizationId(),
                brandId: $brandId,
            ),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/recipes/lookup',
        summary: "Lookup recipes for the shop's brand",
        description: 'Returns active+approved recipes for batch creation. Filter by material_id to scope to a specific output material.',
        tags: ['Shop Catalog Lookup'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'material_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function recipes(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Recipe::class);

        $brandId = $request->attributes->get('brand_id');
        $materialId = $request->query('material_id');

        $recipes = Recipe::query()
            ->where('organization_id', $this->getOrganizationId())
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->when($materialId, fn ($q) => $q->where('material_id', $materialId))
            ->where('is_active', true)
            ->where('approval_status', ApprovalStatusEnum::Approved->value)
            ->orderByDesc('updated_at')
            ->get(['id', 'name', 'sku', 'material_id', 'output_quantity', 'output_unit', 'updated_at']);

        return response()->json([
            'data' => $recipes->map(fn (Recipe $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'sku' => $r->sku,
                'material_id' => $r->material_id,
                'output_quantity' => $r->output_quantity,
                'output_unit' => $r->output_unit,
                'updated_at' => $r->updated_at?->toISOString(),
            ]),
        ]);
    }
}
