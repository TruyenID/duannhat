<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Services\Inventory\SupplierYieldReportService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ReportController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly SupplierYieldReportService $supplierYieldService,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/reports/supplier-yield',
        summary: 'Supplier yield variance report',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'supplier_name', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Aggregated supplier yield data',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'suppliers',
                            type: 'array',
                            items: new OA\Items(properties: [
                                new OA\Property(property: 'supplier_name', type: 'string'),
                                new OA\Property(property: 'lots_count', type: 'integer'),
                                new OA\Property(property: 'total_qty_consumed', type: 'string'),
                                new OA\Property(property: 'total_actual_yield', type: 'string'),
                                new OA\Property(property: 'yield_percent', type: 'number'),
                                new OA\Property(property: 'variance_from_planned', type: 'number'),
                            ]),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function supplierYield(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'supplier_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $brandId = $request->attributes->get('brand_id');

        $result = $this->supplierYieldService->aggregate(
            $brandId,
            $data,
            $data['supplier_name'] ?? null,
        );

        return response()->json($result);
    }
}
