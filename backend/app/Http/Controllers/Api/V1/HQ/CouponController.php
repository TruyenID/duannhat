<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Requests\CouponStoreRequest;
use App\Http\Requests\CouponUpdateRequest;
use App\Http\Resources\CouponRedemptionResource;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use App\Services\Promotion\CouponService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Plan-019 — HQ Coupon CRUD + lifecycle.
 *
 * Brand-scoped under /api/v1/hq/{brandSlug}/coupons. The shop-side
 * apply / release endpoints live on Shop\CustomerOrderController; the
 * customer-web preview lives on Customer\CouponController.
 */
class CouponController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CouponService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/coupons',
        summary: 'List coupons in a brand',
        tags: ['Coupons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['draft', 'paused', 'scheduled', 'active', 'expired', 'exhausted'])),
            new OA\Parameter(name: 'discount_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['fixed', 'percent'])),
            new OA\Parameter(name: 'branch_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated list of CouponResource')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Coupon::class);

        $filters = $request->only(['status', 'discount_type', 'branch_id', 'search', 'valid_at', 'sort', 'per_page', 'with_trashed']);
        $filters['organization_id'] = $request->attributes->get('organization_id');
        $filters['brand_id'] = $request->attributes->get('brand_id');

        // #1700 — nối lại cái cờ vốn đã có trong service từ #1441 nhưng chưa
        // bao giờ với tới được từ HTTP: nó không nằm trong `only(...)` ở trên,
        // nên cho tới giờ chỉ có test gọi thẳng service mới bật được. Mặc định
        // vẫn TẮT — danh sách quản trị không phải nhật ký đổi điểm.
        $filters['include_point_rewards'] = $request->boolean('include_point_rewards');

        return CouponResource::collection($this->service->list($filters));
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/coupons',
        summary: 'Create a coupon',
        tags: ['Coupons'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['code', 'discount_type', 'discount_value', 'valid_from', 'valid_until'],
            properties: [
                new OA\Property(property: 'code', type: 'string', maxLength: 50, pattern: '^[A-Z0-9_\\-]+$'),
                new OA\Property(property: 'discount_type', type: 'string', enum: ['fixed', 'percent']),
                new OA\Property(property: 'discount_value', type: 'number'),
                new OA\Property(property: 'max_discount_cap', type: 'number', nullable: true),
                new OA\Property(property: 'min_order_subtotal', type: 'number'),
                new OA\Property(property: 'usage_limit_total', type: 'integer', nullable: true),
                new OA\Property(property: 'usage_limit_per_customer', type: 'integer'),
                new OA\Property(property: 'valid_from', type: 'string', format: 'date-time'),
                new OA\Property(property: 'valid_until', type: 'string', format: 'date-time'),
                new OA\Property(property: 'status', type: 'string', enum: ['draft', 'paused']),
                new OA\Property(property: 'applicable_branch_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                new OA\Property(property: 'name', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
                new OA\Property(property: 'description', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Coupon created — CouponResource'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function store(CouponStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Coupon::class);

        $data = $request->validated();
        $data['organization_id'] = $request->attributes->get('organization_id');
        $data['brand_id'] = $request->attributes->get('brand_id');

        $coupon = $this->service->create($data);

        return (new CouponResource($coupon))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/coupons/{coupon}',
        summary: 'Show one coupon (incl. branches + active redemption count)',
        tags: ['Coupons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'coupon', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'CouponResource')],
    )]
    public function show(Coupon $coupon): CouponResource
    {
        $this->authorize('view', $coupon);

        return new CouponResource($this->service->findById($coupon->id));
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/coupons/{coupon}',
        summary: 'Update a coupon (locked-fields rule applies once times_used > 0)',
        tags: ['Coupons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'coupon', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'CouponResource'),
            new OA\Response(response: 422, description: 'Validation or coupon_field_locked'),
        ],
    )]
    public function update(CouponUpdateRequest $request, Coupon $coupon): CouponResource
    {
        $this->authorize('update', $coupon);

        $updated = $this->service->update($coupon, $request->validated());

        return new CouponResource($updated);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/coupons/{coupon}',
        summary: 'Soft-delete a coupon (only when times_used = 0)',
        tags: ['Coupons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'coupon', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 409, description: 'coupon_already_redeemed_use_pause_instead'),
        ],
    )]
    public function destroy(Coupon $coupon): JsonResponse
    {
        $this->authorize('delete', $coupon);

        $this->service->delete($coupon);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/coupons/{coupon}/restore',
        summary: 'Restore a soft-deleted coupon',
        tags: ['Coupons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'coupon', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CouponResource'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function restore(Request $request): CouponResource
    {
        $id = $request->route('coupon');

        $coupon = Coupon::withTrashed()
            ->where('organization_id', $request->attributes->get('organization_id'))
            ->findOrFail($id);

        $this->authorize('restore', $coupon);

        return new CouponResource($this->service->restore($coupon));
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/coupons/bulk-delete',
        summary: 'Bulk soft-delete coupons (only when times_used = 0)',
        description: 'Soft-deletes up to 100 coupons. Skips any that have been redeemed or are unauthorized.',
        tags: ['Coupons'],
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
            new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'deleted', type: 'integer'),
                new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'object')),
            ])),
        ]
    )]
    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $orgId = $request->attributes->get('organization_id');
        $deleted = 0;
        $errors = [];

        foreach ($request->input('ids') as $id) {
            $coupon = Coupon::where('organization_id', $orgId)->find($id);

            if (! $coupon) {
                $errors[] = ['id' => $id, 'message' => 'Not found'];

                continue;
            }

            try {
                $this->authorize('delete', $coupon);
                $this->service->delete($coupon);
                $deleted++;
            } catch (\Exception $e) {
                $errors[] = [
                    'id' => $id,
                    'name' => $coupon->code ?? null,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => "{$deleted} items deleted.",
            'deleted' => $deleted,
            'errors' => $errors,
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/coupons/{coupon}/pause',
        summary: 'Pause a coupon (new applies reject)',
        tags: ['Coupons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'coupon', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'CouponResource')],
    )]
    public function pause(Coupon $coupon): CouponResource
    {
        $this->authorize('pause', $coupon);

        return new CouponResource($this->service->pause($coupon));
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/coupons/{coupon}/resume',
        summary: 'Resume a paused coupon',
        tags: ['Coupons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'coupon', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'CouponResource')],
    )]
    public function resume(Coupon $coupon): CouponResource
    {
        $this->authorize('resume', $coupon);

        return new CouponResource($this->service->resume($coupon));
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/coupons/{coupon}/redemptions',
        summary: 'Paginated redemption history for a coupon',
        tags: ['Coupons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'coupon', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated CouponRedemptionResource (incl. customer_name + order_code)')],
    )]
    public function redemptions(Request $request, Coupon $coupon): AnonymousResourceCollection
    {
        $this->authorize('view', $coupon);

        $perPage = (int) $request->integer('per_page', 25);

        return CouponRedemptionResource::collection(
            $this->service->listRedemptions($coupon, $perPage),
        );
    }
}
