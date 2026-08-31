<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Services\Order\Enums\OrderSplitMode;
use Illuminate\Http\JsonResponse;

/**
 * CustomerOrderSplitStatusController — return split-payment progress for dine-in
 * orders. Used by customer-web PaymentView to display "Khách X/Y" and disable
 * the guest count input when not the first payer.
 *
 * Flow:
 * 1. Người đầu tiên chọn "Chia đều 4 người" → POST /split-mode ghi
 *    customer_orders.split_mode='even' + split_people_count=4 ngay
 *    KHI CHỐT (chưa pay). /split-status trả tentative_split_count=4 để
 *    tab khác lock UI sớm.
 * 2. Người 1 pay (Stripe online hoặc kiosk QR) → payment 1 carries
 *    metadata { split_count: 4, amount_per_person: 78300 }. Confirmed
 *    split_count + amount_per_person nay là authoritative.
 * 3. Người 2→4 vào trang thanh toán → /split-status nhận:
 *    { split_count: 4, amount_per_person: 78300, paid_count: 1, is_first_payment: false }
 * 4. Frontend disable input số người, hiển thị "Khách 2/4" và force amount = 78300.
 */
class CustomerOrderSplitStatusController extends Controller
{
    /**
     * GET /api/v1/customer/orders/{id}/split-status
     *
     * Return split payment metadata + current paid count. Public endpoint (no auth)
     * vì dine-in customer không login.
     *
     * Response:
     * {
     *   data: {
     *     split_count: 4 | null,            // confirmed by first SUCCEEDED payment metadata
     *     amount_per_person: 78300 | null,  // confirmed amount/person from first payment
     *     tentative_split_count: 4 | null,  // headcount stamped via /split-mode but no payment yet
     *     paid_count: 1,                    // số payment succeeded hiện tại
     *     is_first_payment: false,          // true nếu chưa có payment succeeded nào
     *     split_mode: "even" | null,   // mirror of customer_orders.split_mode
     *   }
     * }
     *
     * UX rules consuming this response (FE):
     *  - `split_count` non-null → hard lock (someone has committed money).
     *  - `tentative_split_count` non-null + `split_count` null → soft lock
     *    (someone has chosen but not paid; subsequent tabs preview the choice
     *    but the first payer can still change their mind).
     *  - Both null → no split state, free for the user to pick.
     */
    public function show(string $id): JsonResponse
    {
        $order = CustomerOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Đếm số payment succeeded (bỏ qua pending/failed/refunded)
        $paidCount = OrderPayment::where('customer_order_id', $order->id)
            ->where('status', 'succeeded')
            ->count();

        // Lấy payment đầu tiên (succeeded) để đọc metadata split_count.
        // Đây là CONFIRMED split — đã có money committed → hard lock.
        /** @var OrderPayment|null $firstPayment */
        $firstPayment = OrderPayment::where('customer_order_id', $order->id)
            ->where('status', 'succeeded')
            ->orderBy('paid_at', 'asc')
            ->first();

        $splitCount = null;
        $amountPerPerson = null;

        if ($firstPayment && is_array($firstPayment->metadata)) {
            // Cast tới int/float vì kiosk payload đôi khi gửi string, vì Stripe
            // metadata always stringifies values, và FE cần raw numerics để so
            // sánh / format. Same casting StripePaymentService applies on the
            // intent-metadata copy path.
            $rawCount = $firstPayment->metadata['split_count'] ?? null;
            $rawAmount = $firstPayment->metadata['amount_per_person'] ?? null;
            $splitCount = $rawCount !== null ? (int) $rawCount : null;
            $amountPerPerson = $rawAmount !== null ? (float) $rawAmount : null;
        }

        // Soft-lock signal: customer-web's /split-mode POST writes
        // customer_orders.split_mode + split_people_count the moment the user
        // chooses headcount, BEFORE any payment. Other tabs viewing the same
        // order can preview the choice so a second guest doesn't try to
        // pick a different count and then get rejected at pay-time.
        //
        // Confirmed `split_count` (from payment metadata) trumps tentative —
        // never overwrite a hard lock with a soft one if both happen to exist
        // (BE can in theory mutate split_people_count via /split-mode while a
        // payment is succeeded, though the SPLIT_MODE_LOCKED guard at 409
        // should prevent it).
        $tentativeSplitCount = null;
        if ($order->split_mode === OrderSplitMode::Even->value
            && $order->split_people_count !== null
            && (int) $order->split_people_count >= 2) {
            $tentativeSplitCount = (int) $order->split_people_count;
        }

        return response()->json([
            'data' => [
                'split_count' => $splitCount,
                'amount_per_person' => $amountPerPerson,
                'tentative_split_count' => $tentativeSplitCount,
                'paid_count' => $paidCount,
                'is_first_payment' => $paidCount === 0,
                'split_mode' => $order->split_mode,
            ],
        ]);
    }
}
