<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\PresentsPointLedger;
use App\Http\Requests\PointRewardStoreRequest;
use App\Http\Requests\PointRewardUpdateRequest;
use App\Models\CustomerPointEntry;
use App\Models\PointReward;
use App\Services\Loyalty\PointRewardService;
use App\Support\BusinessClock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * #1514 — CRUD catalog đổi điểm cho HQ.
 *
 * Trước issue này bảng `point_rewards` không có màn hình nào: header schema
 * ghi thẳng "chưa có UI — seed/tinker cho tới khi admin-web làm". Thêm một
 * phần thưởng nghĩa là mở `php artisan tinker` trên production.
 *
 * Phạm vi là BRAND (BR-PR01) — `brand_id` lấy từ `{brandSlug}` trên URL qua
 * `ResolveBrandFromSlug`, KHÔNG lấy từ body. Nhận từ body thì màn hình của
 * brand A ghi được phần thưởng cho brand B.
 */
class PointRewardController extends Controller
{
    use AuthorizesRequests;
    use PresentsPointLedger;

    public function __construct(private readonly PointRewardService $service) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/point-rewards',
        summary: 'List point rewards in a brand',
        description: 'Includes inactive rewards — the admin screen must be able to see what it just switched off.',
        tags: ['PointRewards'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of point rewards'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PointReward::class);

        $filters = $request->only(['is_active', 'search', 'per_page']);
        $filters['brand_id'] = $request->attributes->get('brand_id');

        $page = $this->service->list($filters);

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (PointReward $r): array => $this->toArray($r))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/point-rewards',
        summary: 'Create a point reward',
        tags: ['PointRewards'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(PointRewardStoreRequest $request): JsonResponse
    {
        $this->authorize('create', PointReward::class);

        $reward = $this->service->create(
            $request->validated(),
            $request->attributes->get('brand_id'),
            $request->attributes->get('organization_id'),
        );

        return response()->json(['data' => $this->toArray($reward)], 201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/point-rewards/{pointReward}',
        summary: 'Show a point reward',
        tags: ['PointRewards'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'pointReward', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Point reward')]
    )]
    public function show(Request $request, PointReward $pointReward): JsonResponse
    {
        $this->authorize('view', $pointReward);
        $this->assertInBrand($request, $pointReward);

        return response()->json([
            'data' => $this->toArray(
                $pointReward->load(['translations', 'image']),
                withDisabledBranches: true,
            ),
        ]);
    }

    #[OA\Patch(
        path: '/api/v1/hq/{brandSlug}/point-rewards/{pointReward}',
        summary: 'Update a point reward',
        description: 'Partial update. Omitting image_file_id leaves the image untouched; sending null removes it.',
        tags: ['PointRewards'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'pointReward', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(PointRewardUpdateRequest $request, PointReward $pointReward): JsonResponse
    {
        $this->authorize('update', $pointReward);
        $this->assertInBrand($request, $pointReward);

        $reward = $this->service->update($pointReward, $request->validated());

        return response()->json(['data' => $this->toArray($reward)]);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/point-rewards/{pointReward}',
        summary: 'Delete a point reward',
        description: 'Soft delete — coupons already minted keep working, and point history keeps its reward name.',
        tags: ['PointRewards'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'pointReward', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 204, description: 'Deleted')]
    )]
    public function destroy(Request $request, PointReward $pointReward): JsonResponse
    {
        $this->authorize('delete', $pointReward);
        $this->assertInBrand($request, $pointReward);

        $this->service->delete($pointReward);

        return response()->json(null, 204);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/point-rewards/redemptions',
        summary: 'Redemption log for the whole brand',
        description: 'Who redeemed what, when, and which personal coupon it minted. Rows come from the point ledger, so a deleted reward or coupon still shows its redemption.',
        tags: ['PointRewards'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'point_reward_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'), description: 'Interpreted in meta.timezone, not UTC'),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'), description: 'Inclusive'),
            new OA\Parameter(name: 'coupon_status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['unused', 'used', 'expired'])),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Customer name / phone / email, or coupon code'),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated redemption log'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function redemptions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PointReward::class);

        $validated = $request->validate([
            'point_reward_id' => ['nullable', 'string', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'coupon_status' => ['nullable', 'in:unused,used,expired'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $brandId = (string) $request->attributes->get('brand_id');
        $clockBranchId = $this->service->clockBranchIdForBrand($brandId);

        $page = $this->service->redemptionLog([
            'brand_id' => $brandId,
            'clock_branch_id' => $clockBranchId,
            'point_reward_id' => $validated['point_reward_id'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'coupon_status' => $validated['coupon_status'] ?? null,
            'search' => $validated['search'] ?? null,
            'per_page' => (int) ($validated['per_page'] ?? 25),
        ]);

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (CustomerPointEntry $entry): array => $this->redemptionToArray($entry))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                // Múi giờ mà `date_from`/`date_to` được hiểu theo. Màn hình
                // phải nói ra con số này: một lượt đổi lúc 23:30 giờ Hà Nội
                // rơi vào ngày nào là câu hỏi có hai đáp án đúng.
                'timezone' => BusinessClock::timezoneForBranch($clockBranchId),
            ],
        ]);
    }

    /**
     * Phần thưởng phải thuộc đúng brand trên URL.
     *
     * Policy chỉ kiểm tổ chức, mà một tổ chức có nhiều brand — thiếu bước này
     * thì màn hình brand A sửa được phần thưởng của brand B chỉ bằng cách đổi
     * uuid trên URL.
     *
     * 404 chứ không 403: 403 xác nhận "id có thật, chỉ là không phải của
     * bạn", tức biến endpoint thành máy dò id.
     */
    private function assertInBrand(Request $request, PointReward $reward): void
    {
        if ($reward->brand_id !== $request->attributes->get('brand_id')) {
            abort(404, 'Point reward not found.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(PointReward $reward, bool $withDisabledBranches = false): array
    {
        $payload = [
            'id' => $reward->id,
            'name' => $reward->name,
            'description' => $reward->description,
            'cost_points' => (int) $reward->cost_points,
            'discount_type' => $reward->discount_type instanceof \BackedEnum
                ? $reward->discount_type->value
                : $reward->discount_type,
            'discount_value' => $reward->discount_value,
            'max_discount_cap' => $reward->max_discount_cap,
            'min_order_subtotal' => $reward->min_order_subtotal,
            'valid_days' => (int) $reward->valid_days,
            'stock_quantity' => $reward->stock_quantity === null ? null : (int) $reward->stock_quantity,
            'redeemed_count' => (int) $reward->redeemed_count,
            'remaining_stock' => $reward->remainingStock(),
            'is_out_of_stock' => $reward->isOutOfStock(),
            'service_condition' => $reward->service_condition instanceof \BackedEnum
                ? $reward->service_condition->value
                : $reward->service_condition,
            'is_active' => (bool) $reward->is_active,
            'sort_order' => (int) $reward->sort_order,
            'image_url' => $reward->image?->getUrl(),
            'image_file_id' => $reward->image?->id,
            'translations' => collect(['ja', 'en', 'vi'])
                ->mapWithKeys(fn (string $locale): array => [
                    $locale => [
                        'name' => $reward->translate($locale)?->name,
                        'description' => $reward->translate($locale)?->description,
                    ],
                ])
                ->all(),
            'created_at' => $reward->created_at?->toISOString(),
            'updated_at' => $reward->updated_at?->toISOString(),
        ];

        // Chỉ ở màn chi tiết: danh sách 20 phần thưởng mà mỗi cái một truy vấn
        // pivot là 20 query thừa cho một cột không hiện trên bảng.
        if ($withDisabledBranches) {
            $payload['disabled_branch_ids'] = $this->service->disabledBranchIds($reward);
        }

        return $payload;
    }
}
