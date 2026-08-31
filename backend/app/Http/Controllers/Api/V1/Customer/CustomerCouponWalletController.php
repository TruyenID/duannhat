<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\CustomerCouponWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1441 — ví coupon của khách đang đăng nhập.
 *
 * Xem `CustomerCouponWalletService` cho lý do ví này KHÔNG liệt kê mã khuyến
 * mãi công khai.
 *
 * #962 — payload từng được dựng ở đây từ model `Coupon`/`CouponRedemption`;
 * giờ service trả thẳng ba danh sách đã dựng xong, nên controller không còn
 * chạm model của Pricing.
 */
class CustomerCouponWalletController extends Controller
{
    public function __construct(private CustomerCouponWalletService $wallet) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->wallet->wallet($request->user('customer')),
        ]);
    }
}
