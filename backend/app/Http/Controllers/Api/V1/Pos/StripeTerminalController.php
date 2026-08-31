<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\PeripheralDevice;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Payment\Terminal\StripeTerminalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1088 — POS surface for Stripe Terminal (server-driven card_present).
 *
 * charge: hands the order's outstanding balance to a branch reader and
 * ledgers the awaiting-async PENDING row; pos-web then watches the order
 * (poll / realtime) — the payment_intent.succeeded webhook settles it
 * exactly like every other async money path (#1125).
 * cancel: aborts the reader action + intent and releases the order.
 */
class StripeTerminalController extends Controller
{
    public function __construct(
        private readonly StripeTerminalService $terminal,
        private readonly OrderQueryPort $orders,
    ) {}

    public function charge(Request $request, string $orderId): JsonResponse
    {
        StripeTerminalService::abortUnlessEnabled();

        $validated = $request->validate([
            'peripheral_device_id' => ['required', 'uuid'],
        ]);

        $order = CustomerOrder::query()->findOrFail($orderId);
        $shopId = (string) $request->attributes->get('shop_id');
        abort_if((string) $order->branch_id !== $shopId, 404, 'Order not found for this shop.');

        $readerRow = PeripheralDevice::query()->findOrFail($validated['peripheral_device_id']);

        // #1643 — dịch vụ nhận ẢNH CHỤP, không nhận model. Controller là một
        // SURFACE nên nó được phép cầm cả hai, nhưng nó lấy ảnh chụp qua CỔNG
        // của Ordering chứ không tự dựng: `CustomerOrderSnapshot::fromModel()`
        // nằm trong `App\Services\Order\Internal` — với tay vào đó là đi cửa
        // sau của module khác, kể cả khi deptrac không đếm surface.
        //
        // Cái giá là MỘT truy vấn nữa (đơn vừa được nạp ngay trên để kiểm shop).
        // Chấp nhận: đường này ngay sau đó đi mạng tới Stripe và tới đầu đọc thẻ.
        $snapshot = $this->orders->findById((string) $order->organization_id, (string) $order->id);
        abort_if($snapshot === null, 404, 'Order not found for this shop.');

        $result = $this->terminal->charge($snapshot, $readerRow);

        return response()->json(['data' => $result], 202);
    }

    public function cancel(Request $request): JsonResponse
    {
        StripeTerminalService::abortUnlessEnabled();

        $validated = $request->validate([
            'peripheral_device_id' => ['required', 'uuid'],
            'payment_intent_id' => ['required', 'string', 'max:255'],
        ]);

        $readerRow = PeripheralDevice::query()->findOrFail($validated['peripheral_device_id']);
        $shopId = (string) $request->attributes->get('shop_id');
        abort_if((string) $readerRow->branch_id !== $shopId, 404, 'Reader not found for this shop.');

        $this->terminal->cancel($readerRow, $validated['payment_intent_id']);

        return response()->json(['data' => ['canceled' => true]]);
    }
}
