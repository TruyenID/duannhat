<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\PresentsPointLedger;
use App\Models\CustomerPointEntry;
use App\Models\PointReward;
use App\Models\PointRewardBranch;
use App\Services\Loyalty\PointRewardService;
use App\Support\BusinessClock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * #1514 — màn phần thưởng đổi điểm phía cửa hàng.
 *
 * CHỈ ĐỌC + MỘT CÔNG TẮC. Cửa hàng không tạo, không sửa giá điểm, không xoá:
 * phần thưởng thuộc brand (BR-PR01) và coupon nó mint ra tiêu được ở mọi chi
 * nhánh, nên để một cửa hàng đặt giá điểm là để cửa hàng đó phát hành giá trị
 * cho cả chuỗi.
 *
 * Cái cửa hàng ĐƯỢC quyết là "hôm nay ở đây có phục vụ món này không" — ghi
 * vào pivot `point_reward_branches`, không đụng `point_rewards`.
 */
class PointRewardController extends Controller
{
    use AuthorizesRequests;
    use PresentsPointLedger;

    public function __construct(private readonly PointRewardService $service) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/point-rewards/redemptions',
        summary: 'Redemption log, seen from a shop',
        description: 'Brand-wide, NOT branch-scoped: a redemption happens on customer-web and carries no branch. Date filters use THIS branch clock (meta.timezone).',
        tags: ['PointRewards'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'point_reward_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'coupon_status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['unused', 'used', 'expired'])),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
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

        // Phạm vi vẫn là BRAND, y hệt bản HQ. Một lượt đổi điểm không gắn chi
        // nhánh nào — khách bấm ở customer-web, không đứng ở quán — nên "lọc
        // theo chi nhánh tôi" là một câu hỏi không có dữ liệu để trả lời. Màn
        // hình nói thẳng ra điều đó thay vì lọc bừa cho có.
        $branchId = (string) $request->attributes->get('shop_id');

        $page = $this->service->redemptionLog([
            'brand_id' => (string) $request->attributes->get('brand_id'),
            // Khác bản HQ ở đúng chỗ này, và là chỗ TỐT HƠN: cửa hàng biết
            // chính xác chi nhánh của mình nên bộ lọc ngày chạy trên đồng hồ
            // thật, không phải đồng hồ đoán bằng `clockBranchIdForBrand()`.
            'clock_branch_id' => $branchId,
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
                'timezone' => BusinessClock::timezoneForBranch($branchId),
                // Nói rõ cho màn hình: đây là dữ liệu cấp brand. Cửa hàng đọc
                // xong không được kết luận "chừng này lượt đổi là của quán tôi".
                'scope' => 'brand',
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/point-rewards',
        summary: 'List point rewards visible to a shop',
        description: 'Brand catalog plus this branch\'s own on/off switch. Read-only: the shop cannot edit reward terms.',
        tags: ['PointRewards'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of point rewards with per-branch availability'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PointReward::class);

        $branchId = $this->branchId($request);

        $page = $this->service->list([
            'brand_id' => $request->attributes->get('brand_id'),
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page'),
        ]);

        // Một truy vấn pivot cho cả trang thay vì một truy vấn mỗi phần thưởng.
        $disabled = PointRewardBranch::query()
            ->where('branch_id', $branchId)
            ->where('is_available', false)
            ->pluck('point_reward_id')
            ->flip();

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (PointReward $r): array => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'description' => $r->description,
                    'cost_points' => (int) $r->cost_points,
                    'service_condition' => $r->service_condition instanceof \BackedEnum
                        ? $r->service_condition->value
                        : $r->service_condition,
                    'remaining_stock' => $r->remainingStock(),
                    'is_out_of_stock' => $r->isOutOfStock(),
                    'image_url' => $r->image?->getUrl(),
                    // Cấp brand — cửa hàng không đổi được, nhưng phải THẤY:
                    // HQ tắt rồi mà cửa hàng vẫn bật thì khách vẫn không thấy
                    // (BR-PRB02), và nhân viên cần biết vì sao.
                    'is_active' => (bool) $r->is_active,
                    // Cấp chi nhánh — cái công tắc trên màn hình này.
                    'is_available_at_branch' => ! $disabled->has($r->id),
                ])
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    #[OA\Patch(
        path: '/api/v1/shops/{shopSlug}/point-rewards/{pointReward}/availability',
        summary: 'Turn a point reward on or off for this branch',
        description: 'Writes the branch pivot only. Brand-level terms are untouched, and other branches are unaffected.',
        tags: ['PointRewards'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'pointReward', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'is_available', type: 'boolean'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Switch applied'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Reward not in this shop\'s brand'),
        ]
    )]
    public function setAvailability(Request $request, PointReward $pointReward): JsonResponse
    {
        $this->authorize('setAvailability', PointRewardBranch::class);
        $this->assertInBrand($request, $pointReward);

        $validated = $request->validate([
            'is_available' => ['required', 'boolean'],
        ]);

        $branchId = $this->branchId($request);

        // Người bấm truyền TAY vào service: cột `toggled_by_id` là Association
        // thật chứ không phải `options.audit` (#1723), nên không còn hook nào
        // tự điền. Xem `PointRewardService::setBranchAvailability()`.
        $this->service->setBranchAvailability(
            $pointReward,
            $branchId,
            $validated['is_available'],
            $request->user()?->getKey(),
        );

        return response()->json([
            'data' => [
                'id' => $pointReward->id,
                'is_available_at_branch' => $validated['is_available'],
            ],
        ]);
    }

    /**
     * Id chi nhánh của cửa hàng trên URL.
     *
     * `ResolveShopFromSlug` đặt tên thuộc tính là **`shop_id`**, không phải
     * `branch_id` — dù giá trị chính là khoá chính của `branches`. Đọc nhầm
     * sang `branch_id` thì được `null` và mọi ghi pivot chết ở tầng kiểu.
     * (`ResolveBranchFromSlug`, dùng cho nhánh route khác, mới đặt `branch_id`.)
     */
    private function branchId(Request $request): string
    {
        $id = $request->attributes->get('shop_id')
            ?? $request->attributes->get('branch_id');

        if ($id === null) {
            abort(400, 'Shop context is missing.');
        }

        return (string) $id;
    }

    /**
     * Phần thưởng phải thuộc brand của chính cửa hàng này.
     *
     * 404 chứ không 403 — cùng lý do như bên HQ: 403 xác nhận id có thật.
     */
    private function assertInBrand(Request $request, PointReward $reward): void
    {
        if ($reward->brand_id !== $request->attributes->get('brand_id')) {
            abort(404, 'Point reward not found.');
        }
    }
}
