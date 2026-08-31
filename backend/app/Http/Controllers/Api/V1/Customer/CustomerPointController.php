<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerPointEntry;
use App\Models\PointReward;
use App\Services\Loyalty\CustomerPointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * #1441 — điểm tích luỹ của khách đang đăng nhập.
 *
 * Mọi route ở đây chạy sau `auth:customer`, nên `user('customer')` luôn có —
 * không endpoint nào nhận customer id từ request (đó sẽ là một IDOR mời sẵn).
 */
class CustomerPointController extends Controller
{
    public function __construct(private CustomerPointService $points) {}

    /** Số dư + hạng + lịch sử bút toán. */
    public function index(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $customer = $request->user('customer');

        $history = $this->points->history(
            $customer,
            perPage: (int) $request->query('per_page', '20'),
            page: max(1, (int) $request->query('page', '1')),
        );

        $tier = $this->points->tier($customer);

        return response()->json([
            'data' => [
                'balance' => $this->points->balance($customer),
                'lifetime_points' => $tier['lifetime_points'],
                'tier' => $tier['current'],
                'next_tier' => $tier['next'],
                'points_to_next_tier' => $tier['points_to_next'],
                'entries' => $history['data']->map(fn (CustomerPointEntry $e) => [
                    'id' => $e->id,
                    'points' => (int) $e->points,
                    'kind' => $e->kind instanceof \BackedEnum ? $e->kind->value : $e->kind,
                    'note' => $e->note,
                    'created_at' => $e->created_at?->toISOString(),
                    'order_code' => $e->customerOrder?->order_code,
                    'reward_name' => $e->pointReward?->name,
                    'coupon_code' => $e->coupon?->code,
                ])->values(),
            ],
            'meta' => $history['meta'],
        ]);
    }

    /**
     * Catalog đổi điểm. `brand_id` và `branch_id` đều là bộ lọc tuỳ chọn —
     * bỏ trống là toàn bộ.
     *
     * `branch_id` loại những phần thưởng mà chi nhánh đó đã tự tắt (#1514).
     * Khách mở trang tài khoản khi chưa chọn cửa hàng thì không truyền và
     * thấy catalog cấp brand — đúng, vì lúc đó chưa biết họ sẽ ăn ở đâu.
     */
    public function rewards(Request $request): JsonResponse
    {
        $this->assertEnabled();

        $rewards = $this->points->rewards(
            $request->query('brand_id'),
            $request->query('branch_id'),
        );

        return response()->json([
            'data' => $rewards->map(fn (PointReward $r) => $this->rewardPayload($r))->values(),
        ]);
    }

    /**
     * Đổi điểm lấy phần thưởng.
     *
     * Trả về luôn coupon vừa mint để customer-web hiện mã ngay, khỏi phải
     * quay lại gọi ví coupon — khách vừa tiêu điểm thì thứ họ muốn thấy là
     * cái vừa đổi được.
     */
    public function redeem(Request $request): JsonResponse
    {
        $this->assertEnabled();

        $validated = $request->validate([
            'reward_id' => ['required', 'string', 'uuid'],
        ]);

        $reward = PointReward::query()
            ->with('translations')
            ->findOrFail($validated['reward_id']);

        $result = $this->points->redeem($request->user('customer'), $reward);

        return response()->json([
            'data' => [
                'balance' => $result['balance'],
                'coupon' => [
                    'id' => $result['coupon']->id,
                    'code' => $result['coupon']->code,
                    'name' => $result['coupon']->name,
                    'discount_type' => $result['coupon']->discountType,
                    'discount_value' => $result['coupon']->discountValue,
                    'max_discount_cap' => $result['coupon']->maxDiscountCap,
                    'min_order_subtotal' => $result['coupon']->minOrderSubtotal,
                    'valid_until' => $result['coupon']->validUntil,
                ],
            ],
        ], 201);
    }

    /** @return array<string, mixed> */
    private function rewardPayload(PointReward $reward): array
    {
        return [
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
            'brand_id' => $reward->brand_id,
            'image_url' => $reward->image?->getUrl(),
            'service_condition' => $reward->service_condition instanceof \BackedEnum
                ? $reward->service_condition->value
                : $reward->service_condition,
            // `null` = không giới hạn. FE phân biệt "không giới hạn" với "còn
            // 0" bằng chính chỗ này, nên đừng ép về 0 cho gọn.
            'remaining_stock' => $reward->remainingStock(),
            'is_out_of_stock' => $reward->isOutOfStock(),
        ];
    }

    /**
     * Tắt tính năng ⇒ 404 chứ không phải 403: với khách chưa bật điểm, "trang
     * này không tồn tại" đúng hơn "bạn không được vào".
     */
    private function assertEnabled(): void
    {
        if (! $this->points->enabled()) {
            throw new NotFoundHttpException('Loyalty points are not enabled.');
        }
    }
}
