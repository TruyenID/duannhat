<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Services\Inventory\ProductionCalculatorService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Read-only production capacity planner. See
 * App\Services\Inventory\ProductionCalculatorService for the
 * calculation semantics.
 */
class ProductionCalculatorController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly ProductionCalculatorService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/production-calculator/variants',
        summary: 'List SKU variants that have a recipe attached',
        tags: ['Production Calculator'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of variants with recipes', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function variants(Request $request): JsonResponse
    {
        $brandId = $request->attributes->get('brand_id');

        return response()->json([
            'data' => $this->service->variantsWithRecipe(
                organizationId: $this->getOrganizationId(),
                brandId: $brandId,
            ),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/production-calculator/calculate',
        summary: 'Compute max producible quantity per selected variant',
        tags: ['Production Calculator'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['warehouse_id', 'variant_ids'],
                properties: [
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'variant_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Calculation result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'variant_ids' => ['required', 'array', 'min:1'],
            'variant_ids.*' => ['uuid'],
        ]);

        return response()->json([
            'data' => $this->service->calculate(
                organizationId: $this->getOrganizationId(),
                warehouseId: $data['warehouse_id'],
                variantIds: $data['variant_ids'],
            ),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/production-calculator/shortage',
        summary: 'Reverse calculation — list ingredient shortages for a target output',
        tags: ['Production Calculator'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['warehouse_id', 'variant_id', 'desired_quantity'],
                properties: [
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'variant_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'desired_quantity', type: 'number', format: 'float'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Shortage result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function shortage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'variant_id' => ['required', 'uuid'],
            'desired_quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        return response()->json([
            'data' => $this->service->calculateShortage(
                organizationId: $this->getOrganizationId(),
                warehouseId: $data['warehouse_id'],
                variantId: $data['variant_id'],
                desiredQuantity: (float) $data['desired_quantity'],
            ),
        ]);
    }
}
