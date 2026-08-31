<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\CouponPreviewRequest;
use App\Services\Promotion\CouponService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Plan-019 — customer-web preview endpoint.
 *
 * Stateless, read-only validation pass — never increments times_used,
 * never inserts a redemption row. Throttled at the route layer
 * (60 req/min) so a typo-storm at checkout doesn't grind the DB.
 */
class CouponController extends Controller
{
    public function __construct(
        private readonly CouponService $service,
    ) {}

    #[OA\Post(
        path: '/api/v1/customer/coupons/preview',
        summary: 'Preview a coupon — read-only validation, no reservation',
        description: 'Returns is_valid:true + discount_applied_amount when the coupon would apply to the supplied subtotal under all the same rules as POST apply-coupon. Returns is_valid:false + error_code (+ optional meta) otherwise. Throttled 60/min.',
        tags: ['Coupons'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['code', 'brand_id', 'branch_id', 'subtotal'],
            properties: [
                new OA\Property(property: 'code', type: 'string', maxLength: 50),
                new OA\Property(property: 'brand_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'branch_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'customer_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'subtotal', type: 'number'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Preview result — is_valid:true with discount_applied_amount, or is_valid:false with error_code'),
            new OA\Response(response: 422, description: 'Request payload validation failure (NOT a coupon-domain error — coupon errors come back as is_valid:false)'),
            new OA\Response(response: 429, description: 'Throttled'),
        ]
    )]
    public function preview(CouponPreviewRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->service->preview(
            code: (string) $data['code'],
            brandId: (string) $data['brand_id'],
            branchId: (string) $data['branch_id'],
            customerId: $data['customer_id'] ?? null,
            subtotal: (float) $data['subtotal'],
        );

        return response()->json(['data' => $result], 200);
    }
}
