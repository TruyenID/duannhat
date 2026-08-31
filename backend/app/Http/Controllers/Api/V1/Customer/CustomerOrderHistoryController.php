<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\CustomerOrderDetailResource;
use App\Http\Resources\Customer\CustomerOrderSummaryResource;
use App\Models\CustomerOrder;
use App\Services\Customer\CustomerOrderHistoryService;
use App\Services\Order\Commands\ClaimGuestOrdersCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Internal\OrderMutationContextFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerOrderHistoryController extends Controller
{
    public function __construct(
        private CustomerOrderHistoryService $service,
        private OrderMutationFacade $orders,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->service->list(
            $request->user('customer'),
            $request->only(['status', 'branch_slug', 'from', 'to', 'cursor', 'payment_status']),
        );

        return response()->json([
            'data' => CustomerOrderSummaryResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $order = $this->service->detail($request->user('customer'), $id);
        $order->load('payments.paymentMethod');

        return response()->json([
            'data' => new CustomerOrderDetailResource($order),
        ]);
    }

    /**
     * plan-031: Fetch multiple orders by IDs for guest users.
     * Guest users track order IDs in localStorage and fetch fresh data from DB.
     */
    public function batchShow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:50'],
            'ids.*' => ['required', 'string', 'uuid'],
        ]);

        $orders = CustomerOrder::query()
            ->whereIn('id', $validated['ids'])
            ->with([
                'branch:id,name,slug,currency',
                'items.productSku.product.galleryFirst',
                'items.productSku.product.thumbnail',
                'payments',
            ])
            // #1758 — `is_reviewed` trên card, giống hệt đường của khách đã
            // đăng nhập (CustomerOrderHistoryService::list). Thiếu ở đây thì
            // khách vãng lai vẫn thấy nút "Viết đánh giá" trên đơn đã đánh giá.
            ->withExists(['productReviews', 'branchReviews'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => CustomerOrderSummaryResource::collection($orders),
        ]);
    }

    /**
     * Claim guest orders after login.
     * Links guest orders (stored in localStorage) to the logged-in customer account.
     */
    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'max:50'],
            'order_ids.*' => ['required', 'string', 'uuid'],
        ]);

        $customer = $request->user('customer');

        $result = $this->orders->claimGuestOrders(new ClaimGuestOrdersCommand(
            OrderMutationContextFactory::system($customer->organization_id),
            $customer->id,
            $validated['order_ids'],
        ));

        return response()->json([
            'message' => 'Orders claimed successfully.',
            'claimed_count' => $result->version ?? 0,
        ]);
    }
}
