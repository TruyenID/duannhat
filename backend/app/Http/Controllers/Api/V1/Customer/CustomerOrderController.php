<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Exceptions\OverpaymentRejectedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CustomerOrderStoreRequest;
use App\Mail\OrderPlacedMail;
use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\CustomerOrderTypeEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\CustomerQrOrderService;
use App\Services\Customer\CustomerTakeawayOrderService;
use App\Services\Customer\OrderClosingService;
use App\Services\Customer\OrderPricingCalculator;
use App\Services\Customer\PayPayPaymentService;
use App\Services\Customer\PayPayUnavailable;
use App\Services\Customer\StripePaymentService;
use App\Services\CustomerPickupService;
use App\Services\Order\Commands\ApplyOrderCouponCommand;
use App\Services\Order\Commands\ChangeOrderSplitModeCommand;
use App\Services\Order\Commands\CommitOrderConfirmationCommand;
use App\Services\Order\Commands\RemoveOrderItemCommand;
use App\Services\Order\Commands\VoidAwaitingConfirmationOrderCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Order\CustomerTableOrderService;
use App\Services\Order\Enums\OrderSplitMode;
use App\Services\Order\Internal\OrderMutationContextFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
// plan-054's two PayPay handlers call `Log::error` unqualified, and this import
// was missing — so the catch-all that exists to turn an outage into a clean 500
// instead threw `Class "…\Customer\Log" not found`, losing both the log line and
// the intended response. Nothing exercised either catch until #1296.
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Ramsey\Uuid\Uuid;

class CustomerOrderController extends Controller
{
    /**
     * TTL for the branch-order idempotency map (plan-037). A takeaway checkout
     * draft is short-lived, but 24h comfortably covers any client retry window
     * while bounding the cache footprint. Mirrors the workstation sync path.
     */
    private const BRANCH_ORDER_IDEMPOTENCY_TTL_SECONDS = 86400;

    public function __construct(
        private CustomerQrOrderService $orderService,
        private CustomerOrderService $customerOrderService,
        private CustomerPickupService $pickupService,
        private OrderMutationFacade $orders,
        private CustomerTakeawayOrderService $takeawayOrders,
        private CustomerTableOrderService $tableOrders,
    ) {}

    /**
     * Plan-048 T2.5 — hand the client-echoed policy identity to the Stripe
     * service for drift logging. Fail-open by design: the echo is a pure
     * observability hint, so malformed values are DROPPED (never a 422) — a
     * garbage hint must not block a real card payment. The server's own policy
     * resolution stays authoritative either way.
     */
    private function applyClientPolicyHint(Request $request, StripePaymentService $stripe): void
    {
        $revision = $request->input('policy_revision');
        $optionId = $request->input('gateway_option_id');

        $cleanRevision = is_numeric($revision) && (int) $revision >= 1 ? (int) $revision : null;
        $cleanOptionId = is_string($optionId) && Uuid::isValid($optionId) ? $optionId : null;
        if ($cleanRevision === null && $cleanOptionId === null) {
            return;
        }

        $stripe->withClientPolicyHint($cleanRevision, $cleanOptionId);
    }

    /**
     * Create (or retrieve) a Stripe PaymentIntent for the given order and
     * return the client_secret needed by Stripe Elements on the frontend.
     */
    public function createPaymentIntent(Request $request, string $id, StripePaymentService $stripe): JsonResponse
    {
        $order = CustomerOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $this->applyClientPolicyHint($request, $stripe);

        if ((float) $order->total_amount <= 0) {
            return response()->json(['message' => 'Order amount must be greater than zero.'], 422);
        }

        try {
            $payload = $stripe->createOrRetrievePaymentIntent($order);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to create payment intent.', 'error' => $e->getMessage()], 500);
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * Create a fresh full-amount PaymentIntent for the customer-web Stripe
     * flow. Always charges `order.total_amount` regardless of any prior
     * `paid_amount` — the customer surface has no concept of an outstanding
     * balance, so we never split the charge.
     */
    public function createFullPaymentIntent(Request $request, string $id, StripePaymentService $stripe): JsonResponse
    {
        $order = CustomerOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $this->applyClientPolicyHint($request, $stripe);

        if (in_array($order->status, [CustomerOrderStatusEnum::Closed, CustomerOrderStatusEnum::Voided], true)) {
            return response()->json(['message' => 'Order is already closed or voided.'], 422);
        }

        if ((float) $order->total_amount <= 0) {
            return response()->json(['message' => 'Order amount must be greater than zero.'], 422);
        }

        try {
            $payload = $stripe->createFullPaymentIntent($order);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to create payment intent.', 'error' => $e->getMessage()], 500);
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * Create a PaymentIntent for a partial (split-bill) amount.
     * Validates amount > 0 and amount ≤ remaining balance.
     * Uses lockForUpdate() to prevent race conditions when multiple
     * customers attempt to pay concurrently.
     *
     * Accepts optional split_count + split_type for "Chia đều" mode:
     * - If split_count present → first payment in split-even flow → ghi metadata
     * - If split_count absent → follow-up payment → validate against first payment's metadata
     */
    public function createSplitPaymentIntent(Request $request, string $id, StripePaymentService $stripe): JsonResponse
    {
        $order = CustomerOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if (in_array($order->status, [CustomerOrderStatusEnum::Closed, CustomerOrderStatusEnum::Voided], true)) {
            return response()->json(['message' => 'Order is already closed or voided.'], 422);
        }

        $this->applyClientPolicyHint($request, $stripe);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            // #555 M10 — per-attempt client key. A retry after a poll timeout
            // re-sends the same key and gets the SAME PaymentIntent back
            // (Stripe-level dedupe) instead of minting a second real charge.
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'split_count' => ['nullable', 'integer', 'min:2'],
            'split_type' => ['nullable', 'string', OrderSplitMode::validationRule()],
            // Chia theo món: the units the payer is settling, per order item.
            // Threaded onto the Stripe PaymentIntent metadata so that when the
            // payment confirms (fast-path OR webhook) the recorded OrderPayment
            // carries split_mode=by_items + item_allocations — exactly the shape
            // formatOrder() aggregates into each item's `paid_quantity`. Without
            // this, an online by_items payment recorded only an amount, so paid
            // items never disabled in the customer-web bill (counter-pay already
            // carried allocations via the kiosk).
            'item_allocations' => ['nullable', 'array'],
            'item_allocations.*.item_id' => ['required_with:item_allocations', 'string'],
            'item_allocations.*.units' => ['required_with:item_allocations', 'integer', 'min:1'],
        ]);

        $amount = (float) $validated['amount'];
        $splitCount = isset($validated['split_count']) ? (int) $validated['split_count'] : null;
        // #2860 — `split_type` mang CÙNG từ vựng với `split_mode`, chỉ khác
        // tên trường (di sản: đường Stripe/PayPay đặt tên riêng). Chuẩn hoá
        // bằng chính normalizer để hai tên trường không trôi khỏi nhau.
        $splitType = OrderSplitMode::canonicalWire($validated['split_type'] ?? null);
        $itemAllocations = $validated['item_allocations'] ?? null;
        $idempotencyKey = $validated['idempotency_key'] ?? null;

        // #1666 — the row lock and the remaining-balance check moved into the
        // service, which is where the mint that depends on them lives.
        $payload = $stripe->createSplitPaymentIntentUnderLock(
            (string) $order->id,
            $amount,
            $splitCount,
            $splitType,
            $itemAllocations,
            $idempotencyKey,
        );

        return response()->json(['data' => $payload]);
    }

    /**
     * plan-054 — mint the PayPay QR for this order.
     *
     * Public, like the Stripe endpoints beside it: the order id is the opaque
     * token. Every call mints a fresh code and invalidates whatever was
     * outstanding, so two codes for one order can never both be scannable.
     *
     * `amount` is optional and means the payer's own share; omitting it asks for
     * whatever is still outstanding, computed server-side under the order row
     * lock. A split-bill caller MUST send its slice — the first of four payers
     * would otherwise be handed a code for the whole bill.
     *
     * The split fields say HOW the payer arrived at that share, and carry the
     * same names and shapes as `createSplitPaymentIntent` above so a dine-in
     * bill reads identically whichever gateway settled it. They are not money
     * inputs — `amount` is the only thing the code collects, and the server
     * still caps it at what is outstanding. Sending them is what makes the paid
     * dishes disable in the bill and what turns `/split-status` from a soft lock
     * into a hard one.
     */
    #[OA\Post(
        path: '/api/v1/customer/orders/{id}/paypay-qr',
        summary: 'Mint a PayPay dynamic QR code for this order (plan-054)',
        description: 'Public, like the Stripe endpoints beside it — the order id is the opaque token. Every call mints a FRESH code and invalidates whatever was outstanding, so two codes for one order can never both be scannable. Throttled per ORDER ID (10/min), not per IP: every phone in a shop shares one NAT egress address. JPY only; PayPay must be enabled for the branch (see `GET /customer/branches/{slug}/payment-context` → `paypay_enabled`).',
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'amount', type: 'number', minimum: 1, nullable: true, description: "The payer's own share. Omit to ask for whatever is still outstanding, computed server-side under the order row lock. A split-bill caller MUST send its slice — the first of four payers would otherwise be handed a code for the whole bill. The server still caps it at what is outstanding."),
                new OA\Property(property: 'split_type', type: 'string', enum: ['even', 'by_items', 'by_amount'], nullable: true, description: 'HOW the payer arrived at that share. Not a money input; same names and shapes as the Stripe split-intent endpoint so a dine-in bill reads identically whichever gateway settled it. Sending it is what makes paid dishes disable in the bill and turns `/split-status` from a soft lock into a hard one.'),
                new OA\Property(property: 'split_count', type: 'integer', minimum: 2, nullable: true),
                new OA\Property(property: 'item_allocations', type: 'array', nullable: true, items: new OA\Items(
                    required: ['item_id', 'units'],
                    properties: [
                        new OA\Property(property: 'item_id', type: 'string'),
                        new OA\Property(property: 'units', type: 'integer', minimum: 1),
                    ],
                )),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'QR minted.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'qr_url', type: 'string', description: 'Encode this into the QR image shown to the customer.'),
                    new OA\Property(property: 'deeplink', type: 'string', nullable: true, description: 'Opens the PayPay app directly on the payer\'s own phone.'),
                    new OA\Property(property: 'merchant_payment_id', type: 'string', description: 'Prefixed `tempoqr-`. Also the ledger `idempotency_key` once the money is booked.'),
                    new OA\Property(property: 'amount', type: 'number'),
                    new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'expires_in_seconds', type: 'integer', nullable: true, description: 'Server-anchored — build the countdown from THIS, not from `expires_at`. A client with a skewed clock would otherwise show a code that "expired" hours ago.'),
                ]),
            ])),
            new OA\Response(response: 404, description: 'Order not found.'),
            new OA\Response(response: 422, description: 'PAYPAY_NOT_AVAILABLE — PayPay is off for this branch, the order is already settled, or the amount does not match what is owed.'),
            new OA\Response(response: 429, description: 'Per-order mint throttle (10/min).'),
            new OA\Response(response: 500, description: 'PayPay could not be reached or the deployment is misconfigured. Nothing is marked locally: plan-054 never concludes from local state.'),
        ],
    )]
    public function createPayPayQrCode(
        Request $request,
        string $id,
        PayPayPaymentService $paypay,
        OrderQueryPort $orderQueries,
    ): JsonResponse {
        $order = CustomerOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // #1594 — dịch vụ nhận ẢNH CHỤP. Controller là SURFACE nên nó được cầm
        // model, nhưng ảnh chụp phải lấy qua CỔNG chứ không tự dựng:
        // `CustomerOrderSnapshot::fromModel()` nằm trong `Order\Internal`. Cùng
        // tiền lệ `StripeTerminalController` (#1643).
        $snapshot = $orderQueries->findById((string) $order->organization_id, (string) $order->id);

        if ($snapshot === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $validated = $request->validate([
            'amount' => ['sometimes', 'numeric', 'min:1'],
            'split_type' => ['nullable', 'string', OrderSplitMode::validationRule()],
            'split_count' => ['nullable', 'integer', 'min:2'],
            'item_allocations' => ['nullable', 'array'],
            'item_allocations.*.item_id' => ['required_with:item_allocations', 'string'],
            'item_allocations.*.units' => ['required_with:item_allocations', 'integer', 'min:1'],
        ]);

        try {
            $payload = $paypay->createQrCode(
                $snapshot,
                isset($validated['amount']) ? (float) $validated['amount'] : null,
                $validated,
            );
        } catch (PayPayUnavailable $e) {
            // The guest can act on these: PayPay is off for the branch, the order
            // is already settled, the amount does not match what is owed.
            return response()->json(['message' => $e->getMessage(), 'code' => 'PAYPAY_NOT_AVAILABLE'], 422);
        } catch (\Throwable $e) {
            Log::error('paypay_qr_endpoint_failed', ['order_id' => $id, 'exception' => $e::class]);

            return response()->json(['message' => 'Unable to start a PayPay payment.'], 500);
        }

        return response()->json(['data' => $payload], 201);
    }

    /**
     * #1737 — huỷ mã QR PayPay đang sống, không mint mã mới.
     *
     * Nút "Đổi số tiền" trên màn thanh toán tại bàn trước đây chỉ gỡ panel khỏi
     * màn hình. Mã cũ vẫn quét trả được ~5 phút trong khi không ai còn poll —
     * khách chuyển sang trả quầy rồi mã bị quét là thu hai lần.
     *
     * `DELETE` chứ không phải `POST .../cancel`: nó **luôn 204**, kể cả khi
     * không còn mã nào. Chỗ gọi là một nút thoát khỏi màn hình, nên nó phải
     * bấm được nhiều lần mà không sinh lỗi, và nó không có gì để làm khác đi
     * giữa "vừa huỷ" và "không có gì để huỷ".
     *
     * PayPay lỗi ⇒ **500 và KHÔNG đánh dấu gì cục bộ**. Nguyên tắc của
     * plan-054 giữ nguyên: không kết luận từ trạng thái local. Sweeper
     * (`payments:sweep-paypay-qr`) là lưới an toàn, và một mã đã bị đánh dấu
     * chết ở đây trong khi PayPay vẫn giữ nó sống là đúng cái lưới đó không còn
     * cứu được.
     */
    #[OA\Delete(
        path: '/api/v1/customer/orders/{id}/paypay-qr',
        summary: 'Invalidate the outstanding PayPay QR without minting a new one (#1737)',
        description: 'ALWAYS 204, including when there is no code to cancel — the caller is an exit button on a screen, so it must be pressable repeatedly without producing an error, and it has nothing different to do between "just cancelled" and "nothing to cancel". Without this, the dine-in "change amount" button only removed the panel from the screen while the old code stayed scannable at PayPay for ~5 minutes with nobody polling — the customer pays at the counter, the code gets scanned, and the shop is paid twice.',
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Cancelled, or there was nothing outstanding.'),
            new OA\Response(response: 404, description: 'Order not found.'),
            new OA\Response(response: 500, description: 'PayPay refused the invalidation. NOTHING is marked locally — a code marked dead here while PayPay still holds it alive is exactly the case the sweeper (`payments:sweep-paypay-qr`) could no longer save.'),
        ],
    )]
    public function cancelPayPayQrCode(
        Request $request,
        string $id,
        PayPayPaymentService $paypay,
        OrderQueryPort $orderQueries,
    ): JsonResponse {
        $order = CustomerOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $snapshot = $orderQueries->findById((string) $order->organization_id, (string) $order->id);

        if ($snapshot === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // #1737 — WHICH code the caller is looking at. Optional so a client that
        // does not send it still gets the old behaviour, but the panel always
        // does: `liveAttempt()` resolves the NEWEST attempt, so an unqualified
        // cancel arriving late kills the code the guest just minted.
        $validated = $request->validate([
            'merchant_payment_id' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        try {
            $paypay->cancelOutstandingQr($snapshot, $validated['merchant_payment_id'] ?? null);
        } catch (\Throwable $e) {
            Log::error('paypay_qr_cancel_failed', ['order_id' => $id, 'exception' => $e::class]);

            return response()->json(['message' => 'Unable to cancel the PayPay code.'], 500);
        }

        // 204 whatever happened underneath: cancelled, nothing to cancel,
        // refused because a scan was in flight, or a payment booked instead.
        // The caller is an exit button — none of those change what it does.
        return response()->json(null, 204);
    }

    /**
     * plan-054 — is the QR paid yet?
     *
     * Asks PayPay rather than reading local state, and records the money if it
     * has moved, so the customer's own polling settles the order even when the
     * webhook is delayed or lost.
     *
     * Throttled per ORDER ID, not per IP: every phone in a shop shares one NAT
     * egress address, so an IP limit would punish a busy branch.
     */
    #[OA\Get(
        path: '/api/v1/customer/orders/{id}/paypay-qr/status',
        summary: 'Is the PayPay QR paid yet? (plan-054)',
        description: 'Asks PayPay rather than reading local state, and RECORDS the money if it has moved — so the customer\'s own polling settles the order even when the webhook is delayed or lost. Amounts come from PayPay\'s response, never from the order. Poll this from the QR screen; 10–15s is the client cadence, plus one immediate poll on `visibilitychange`.',
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Current status as PayPay reports it.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'status', type: 'string', description: 'Mapped PayPay QR state. `EXPIRED` maps to canceled.'),
                    new OA\Property(property: 'is_fully_paid', type: 'boolean'),
                    new OA\Property(property: 'order_status', type: 'string'),
                    new OA\Property(property: 'expires_in_seconds', type: 'integer', nullable: true),
                    new OA\Property(property: 'merchant_payment_id', type: 'string', nullable: true),
                ]),
            ])),
            new OA\Response(response: 404, description: 'Order not found.'),
            new OA\Response(response: 502, description: 'PayPay could not be read. Deliberately not a conclusion about the money — retry.'),
        ],
    )]
    public function payPayQrStatus(
        string $id,
        PayPayPaymentService $paypay,
        OrderQueryPort $orderQueries,
    ): JsonResponse {
        $order = CustomerOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $snapshot = $orderQueries->findById((string) $order->organization_id, (string) $order->id);

        if ($snapshot === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        try {
            $payload = $paypay->syncStatus($snapshot);
        } catch (\Throwable $e) {
            Log::error('paypay_qr_status_failed', ['order_id' => $id, 'exception' => $e::class]);

            return response()->json(['message' => 'Unable to read the PayPay payment status.'], 502);
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * Synchronously confirm a Stripe payment right after Stripe.js reports
     * success on the client. The backend re-fetches the PaymentIntent from
     * Stripe (authoritative — never trusts the client) and, if it really
     * succeeded, records the OrderPayment + closes the order exactly like the
     * webhook does. This makes admin reflect "paid" without anyone running
     * `stripe listen`; the webhook stays as a redundant safety net.
     */
    public function confirmPayment(Request $request, string $id, StripePaymentService $stripe): JsonResponse
    {
        $order = CustomerOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $validated = $request->validate([
            'payment_intent_id' => ['required', 'string', 'max:255'],
        ]);

        try {
            // #1125 option B — one authoritative retrieve, then either the
            // synchronous succeeded path or async pending tracking (Konbini
            // voucher printed / bank transfer in flight → awaiting, not 422).
            $outcome = $stripe->confirmOutcome($validated['payment_intent_id']);
            $updated = $outcome['order'];
        } catch (OverpaymentRejectedException $e) {
            // Card charged but the bill was already fully paid by a concurrent
            // payer — return a clear 409 (never a silent 200) so customer-web
            // can show "refund pending"; the intent is logged for reconciliation.
            return $e->render();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to confirm payment.', 'error' => $e->getMessage()], 422);
        }

        if (! $updated || (string) $updated->id !== (string) $order->id) {
            return response()->json(['message' => 'Payment does not match this order.'], 422);
        }

        return response()->json(['data' => [
            'order_id' => $updated->id,
            'paid_amount' => (float) $updated->paid_amount,
            'total_amount' => (float) $updated->total_amount,
            'status' => $updated->status,
            'is_fully_paid' => (float) $updated->paid_amount >= (float) $updated->total_amount,
            // 'succeeded' | 'awaiting_async_payment' — the pay page keeps the
            // guest on an awaiting screen (webhook settles later) for async.
            'payment_state' => $outcome['state'],
        ]]);
    }

    /**
     * Settle a zero-due bill without Stripe. A dine-in order can total ¥0 (a
     * fully-comped item, a 100%-off coupon), and Stripe refuses a 0-amount
     * PaymentIntent — so the customer-web pay screen has no way to close it.
     * This endpoint closes such an order the same way the paid flow does
     * (OrderClosingService::close → table release, session close, stock
     * deduction, OrderPaid broadcast) so the "waiting" screen swaps to
     * "completed" without charging a card.
     *
     * Money-safety: refuses any order that still owes a balance
     * (`total_amount - paid_amount > 0`) — a real bill must go through Stripe,
     * never this free-close path. Idempotent: an already-closed order returns
     * 200 so a double-tap / retry is a no-op rather than a 4xx.
     */
    public function settleZero(string $id, OrderClosingService $closing): JsonResponse
    {
        $order = $this->orderService->findById($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->status === CustomerOrderStatusEnum::Voided) {
            return response()->json(['message' => 'Order is already voided.'], 422);
        }

        // Idempotent success — already settled (e.g. a retried request).
        if ($order->status === CustomerOrderStatusEnum::Closed) {
            return response()->json(['data' => $this->formatOrder($order)]);
        }

        // Guard (MONEY): this path charges nothing, so it may only ever close a
        // bill with no outstanding balance. Anything owed must go via Stripe.
        $remaining = (float) $order->total_amount - (float) $order->paid_amount;
        if ($remaining > 0.0) {
            return response()->json([
                'message' => 'Order has an outstanding balance and cannot be settled for free.',
            ], 422);
        }

        $closing->close($order);

        return response()->json([
            'data' => $this->formatOrder($this->orderService->findById($id)),
        ]);
    }

    /**
     * Apply a coupon to an order (public endpoint for customer-web).
     * This is a simplified version of the shop API's applyCoupon that doesn't require authentication.
     */
    public function applyCoupon(Request $request, string $id): JsonResponse
    {
        $order = CustomerOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if (in_array($order->status, [CustomerOrderStatusEnum::Closed, CustomerOrderStatusEnum::Voided], true)) {
            return response()->json(['message' => 'Order is already closed or voided.'], 422);
        }

        if ($order->coupon_id) {
            return response()->json(['message' => 'Coupon already applied to this order.'], 422);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
        ]);

        try {
            // plan-047 T2.12 (#1090) — same typed facade as POS; the guest
            // transport has no actor, so the context carries only the tenant.
            $this->orders->applyCoupon(new ApplyOrderCouponCommand(
                OrderMutationContextFactory::fromOrder($order),
                (string) $order->id,
                (string) $validated['code'],
                null,
                'customer_web',
            ));

            $order->refresh();

            return response()->json([
                'message' => 'Coupon applied successfully.',
                'data' => [
                    'discount_amount' => $order->discount_amount,
                    'total_amount' => $order->total_amount,
                    'coupon_code' => $order->coupon_code_snapshot,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/customer/tables/{qrToken}/orders',
        summary: 'Create a dine-in order from a table QR token (atomic coupon apply)',
        description: 'Customer scans a table QR and submits the cart. The endpoint creates the order, adds the items, and (if `coupon_code` is supplied) applies the coupon inside the SAME transaction so an invalid code rolls the whole order back. CustomerService::apply enforces freshness + counter limits + branch eligibility + customer-per-limit at this point regardless of what the preview said.',
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'qrToken', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['items'],
            properties: [
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    required: ['product_sku_id', 'quantity'],
                    properties: [
                        new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                        new OA\Property(property: 'note', type: 'string', nullable: true),
                        new OA\Property(property: 'expected_unit_price', type: 'number', nullable: true, description: '#1715 — unit price the client is DISPLAYING for this line. Server never prices from it; it only REFUSES with 409 line_unit_price_drift when the resolved price is HIGHER (the customer would be charged more than shown). A lower resolved price is accepted silently — rule #514 lets the server legitimately charge less than the card the guest tapped. Omit it and the order behaves exactly as before.'),
                        new OA\Property(property: 'toppings', type: 'array', nullable: true, description: 'Plan 015 topping selections; empty/omitted = no toppings.', items: new OA\Items(
                            required: ['topping_group_item_id', 'product_sku_id', 'quantity'],
                            properties: [
                                new OA\Property(property: 'topping_group_item_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid', description: 'NOT NULL by Phase 2 contract.'),
                                new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                                new OA\Property(property: 'note', type: 'string', nullable: true, maxLength: 255),
                            ],
                        )),
                    ],
                )),
                new OA\Property(property: 'customer_name', type: 'string', nullable: true, maxLength: 255),
                new OA\Property(property: 'customer_phone', type: 'string', nullable: true, maxLength: 50),
                new OA\Property(property: 'note', type: 'string', nullable: true),
                new OA\Property(property: 'payment_method', type: 'string', enum: ['counter', 'transfer', 'call_staff', 'qr_pay', 'card'], nullable: true),
                new OA\Property(property: 'payment_timing', type: 'string', enum: ['before', 'after'], nullable: true),
                new OA\Property(
                    property: 'coupon_code',
                    type: 'string',
                    maxLength: 50,
                    nullable: true,
                    description: 'Plan-019 — optional coupon code applied atomically. Backend re-validates via CouponService::apply; on failure (paused, expired, branch mismatch, …) the whole order create rolls back with a 4xx CouponException.',
                    example: 'WELCOME10',
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Order created (and coupon applied if code was supplied).', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'subtotal', type: 'number'),
                    new OA\Property(property: 'total', type: 'number'),
                    new OA\Property(property: 'discount_amount', type: 'number', description: '0 if no coupon applied.'),
                    new OA\Property(property: 'coupon_code_snapshot', type: 'string', nullable: true, description: 'Frozen coupon code at apply time; null when no coupon.'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                ]),
            ])),
            new OA\Response(response: 404, description: 'Table or coupon not found (coupon_not_found surfaces here when coupon_code is supplied).'),
            new OA\Response(response: 422, description: 'Validation or CouponException (coupon_expired, coupon_paused, coupon_branch_not_eligible, …).'),
        ],
    )]
    public function store(CustomerOrderStoreRequest $request, string $qrToken): JsonResponse
    {
        $table = Table::where('qr_token', $qrToken)
            ->where('is_active', true)
            ->first();

        if (! $table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $account = $request->user('customer');
        $validated = $request->validated();

        // plan-034 — idempotency guard for the shared-append path. Multiple
        // devices re-POST to this same endpoint to append to the shared order,
        // and a single device may retry after a network timeout or double-tap.
        // Without a dedup key each retry runs addItems() again and double-adds
        // the cart. When the client supplies an `Idempotency-Key`, the first
        // request's response is cached (scoped to the table) and any replay of
        // the same key short-circuits to that cached response instead of
        // mutating the order. The check + write both live inside the service's
        // transaction, after the table row-lock, so two concurrent replays are
        // serialized: the second acquires the lock only once the first has
        // committed and populated the cache.
        $idemKey = $request->header('Idempotency-Key');
        $idemCacheKey = $idemKey
            ? 'customer:order-store:'.$table->id.':'.$idemKey
            : null;

        // #1688 — the atomic part (table lock → idempotency replay → create or
        // append → coupon → cache) belongs to the service; the controller only
        // renders. `storeOrderPayload` is handed over as the renderer because
        // its output is what gets cached against the key, so it has to run
        // inside the transaction — but it builds an ARRAY, never a response, so
        // no `JsonResponse` is constructed under an open transaction anymore.
        $result = $this->tableOrders->createOrAppend(
            (string) $table->id,
            $table->organization_id,
            $table->branch_id,
            $validated,
            $account?->id,
            $idemCacheKey,
            fn (CustomerOrder $order, bool $sharedSession): array => $this->storeOrderPayload($order, $sharedSession),
        );

        return response()->json($result->body, $result->status);
    }

    /**
     * Response payload for {@see store()} — the ONE shape both of its outcomes
     * return, differing only by the `shared_session` marker the append path
     * adds (device B/C/N joined an order that was already open on the table).
     *
     * @return array<string, mixed>
     */
    private function storeOrderPayload(CustomerOrder $order, bool $sharedSession): array
    {
        $data = [
            'id' => $order->id,
            'code' => $order->order_code,
            'status' => $order->status instanceof \BackedEnum
                ? $order->status->value
                : $order->status,
            'items' => $order->items->map(fn ($item) => $this->formatItem($item)),
            'subtotal' => (float) $order->subtotal,
            'total' => (float) $order->total_amount,
            'discount_amount' => (float) $order->discount_amount,
            'coupon_code_snapshot' => $order->coupon_code_snapshot,
            'tax_amount' => (float) $order->tax_amount,
            'service_charge' => (float) $order->service_charge,
        ];

        if ($sharedSession) {
            $data['shared_session'] = true;
        }

        return ['data' => $data];
    }

    /**
     * Place a takeaway / online order by branch slug (no table required).
     */
    #[OA\Post(
        path: '/api/v1/customer/branches/{branchSlug}/orders',
        summary: 'Create a takeaway order for a branch (atomic coupon apply)',
        description: 'Same transactional flow as the table-QR variant — create + addItems + couponService::apply in a single DB transaction. Adds takeaway-only fields (pickup_type, scheduled_pickup_time, takeaway contact). Same `coupon_code` field; same rollback semantics on failure.',
        tags: ['Customer Orders'],
        parameters: [
            new OA\Parameter(name: 'branchSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['items'],
            properties: [
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    required: ['product_sku_id', 'quantity'],
                    properties: [
                        new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                        new OA\Property(property: 'note', type: 'string', nullable: true),
                        new OA\Property(property: 'expected_unit_price', type: 'number', nullable: true, description: '#1715 — unit price the client is DISPLAYING for this line. Server never prices from it; it only REFUSES with 409 line_unit_price_drift when the resolved price is HIGHER (the customer would be charged more than shown). A lower resolved price is accepted silently — rule #514 lets the server legitimately charge less than the card the guest tapped. Omit it and the order behaves exactly as before.'),
                        new OA\Property(property: 'toppings', type: 'array', nullable: true, description: 'Plan 015 topping selections; empty/omitted = no toppings.', items: new OA\Items(
                            required: ['topping_group_item_id', 'product_sku_id', 'quantity'],
                            properties: [
                                new OA\Property(property: 'topping_group_item_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid', description: 'NOT NULL by Phase 2 contract.'),
                                new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                                new OA\Property(property: 'note', type: 'string', nullable: true, maxLength: 255),
                            ],
                        )),
                    ],
                )),
                new OA\Property(property: 'customer_takeaway_name', type: 'string', nullable: true, maxLength: 255),
                new OA\Property(property: 'customer_takeaway_phone', type: 'string', nullable: true, maxLength: 50),
                new OA\Property(property: 'note', type: 'string', nullable: true),
                new OA\Property(property: 'payment_method', type: 'string', enum: ['counter', 'transfer', 'call_staff', 'qr_pay', 'card'], nullable: true),
                new OA\Property(property: 'pickup_type', type: 'string', enum: ['immediate', 'scheduled'], nullable: true),
                new OA\Property(property: 'scheduled_pickup_time', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(
                    property: 'coupon_code',
                    type: 'string',
                    maxLength: 50,
                    nullable: true,
                    description: 'Plan-019 — optional coupon code applied atomically inside the order create transaction. Same semantics as the table-QR variant.',
                    example: 'WELCOME10',
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Order created.'),
            new OA\Response(response: 404, description: 'Branch or coupon not found.'),
            new OA\Response(response: 422, description: 'Validation or CouponException.'),
        ],
    )]
    public function storeByBranch(CustomerOrderStoreRequest $request, string $branchSlug): JsonResponse
    {
        $branch = Branch::with('brand')->where('slug', $branchSlug)->first();

        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        if (! $branch->console_organization_id) {
            return response()->json(['message' => 'Branch is not fully configured.'], 422);
        }

        $organization = Organization::where('console_organization_id', $branch->console_organization_id)->first();

        if (! $organization) {
            return response()->json(['message' => 'Organization not found.'], 422);
        }

        // plan-037 idempotency — customer-web sends `Idempotency-Key` (the
        // localStorage checkout-draft id) on the confirm-step POST. A double
        // tap or a network retry must resolve to the SAME order, never a
        // duplicate: two takeaway rows means double kitchen prep and a double
        // charge at the counter. Dedupe on a per-branch cache map, guarded by
        // a lock so concurrent submits of the same key still collapse to one
        // create. Mirrors the workstation sync-UP idempotency contract.
        $idemKey = $request->header('Idempotency-Key');
        $cacheKey = $idemKey ? $this->branchOrderIdempotencyKey($branch->id, $idemKey) : null;

        // Durable idempotency backstop (fixes the cache-only race). The Cache
        // map + lock below collapse the common double-tap, but a cache flush /
        // eviction between a submit and its retry would let a second create
        // slip through — two takeaway rows means double kitchen prep and a
        // double charge at the counter. Deriving a deterministic UUID from
        // branch + Idempotency-Key lets us reuse the durable
        // `unique(client_order_id)` constraint (plan-041) as the hard guard:
        // the retry resolves to the same order even with an empty cache.
        $durableOrderId = $idemKey ? $this->branchOrderDurableId($branch->id, $idemKey) : null;

        $lock = $cacheKey ? Cache::lock($cacheKey.':lock', 15) : null;
        // Wait up to 10s for an in-flight duplicate to finish creating its row
        // so we can replay it instead of racing a second insert.
        $lock?->block(10);

        try {
            if ($cacheKey && ($existingId = Cache::get($cacheKey)) && ($existing = CustomerOrder::find($existingId))) {
                return $this->storeByBranchResponse($existing);
            }

            // Durable replay path — survives a cache flush that the map above
            // would miss. withTrashed() so a since-cancelled order still
            // resolves idempotently (the unique index counts soft-deletes).
            if ($durableOrderId && ($existing = CustomerOrder::withTrashed()->where('client_order_id', $durableOrderId)->first())) {
                return $this->storeByBranchResponse($existing);
            }

            return $this->createBranchOrder($request, $branch, $organization, $cacheKey, $durableOrderId);
        } finally {
            $lock?->release();
        }
    }

    /**
     * Actual takeaway-order create body for {@see storeByBranch}. Split out so
     * the idempotency guard can wrap it in a lock and replay a cached order on
     * retry without duplicating this logic. When $cacheKey is non-null the
     * freshly created order id is recorded so subsequent same-key requests
     * short-circuit to the replay path.
     */
    private function createBranchOrder(CustomerOrderStoreRequest $request, Branch $branch, Organization $organization, ?string $cacheKey, ?string $durableOrderId = null): JsonResponse
    {
        $order = $this->takeawayOrders->place(
            $branch,
            $organization,
            $request->validated(),
            $request->user('customer')?->id,
            $durableOrderId,
            $this->resolveOrderLocale($request),
        );

        // Record the fresh (or replayed) order id so subsequent same-key
        // requests short-circuit to the storeByBranch replay path.
        if ($cacheKey) {
            Cache::put($cacheKey, $order->id, self::BRANCH_ORDER_IDEMPOTENCY_TTL_SECONDS);
        }

        return $this->storeByBranchResponse($order);
    }

    /**
     * Issue #365 — the active website locale to stamp on the order. Derived from
     * Accept-Language (which apiFetch sets from the customer-web `app_locale`
     * cookie) so OrderPaidInvoiceMail — dispatched later from a Stripe webhook
     * with no Accept-Language header — still renders in the chosen language.
     */
    private function resolveOrderLocale(CustomerOrderStoreRequest $request): string
    {
        $orderLocale = $request->header('Accept-Language')
            ? Str::before($request->header('Accept-Language'), ',')
            : app()->getLocale();

        return Str::before($orderLocale, '-');
    }

    /**
     * Cache key for the per-branch takeaway-order idempotency map.
     */
    private function branchOrderIdempotencyKey(string $branchId, string $idemKey): string
    {
        return "customer:branch-order:{$branchId}:{$idemKey}";
    }

    /**
     * Deterministic durable idempotency id for a takeaway submit, derived from
     * the branch + client-supplied `Idempotency-Key`. Stamped onto the order's
     * `client_order_id` so the DB `unique(client_order_id)` constraint (plan-041)
     * dedupes a retried submit even after the cache map has been flushed. A UUIDv5
     * keeps the value in the column's UUID shape regardless of the raw key format
     * and is namespaced so it can never collide with a workstation's local UUID.
     */
    private function branchOrderDurableId(string $branchId, string $idemKey): string
    {
        return (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            "customer:branch-order:{$branchId}:{$idemKey}",
        );
    }

    /**
     * Canonical 201 payload for a takeaway order created (or replayed) via
     * {@see storeByBranch}. Reloads the item graph so both the fresh-create
     * and the idempotent-replay paths return an identical shape.
     */
    private function storeByBranchResponse(CustomerOrder $order): JsonResponse
    {
        $order = CustomerOrder::with([
            'items.productSku.galleryFirst',
            'items.productSku.product.galleryFirst',
            'items.productSku.product.categories',
            'items.orderItemToppings.toppingGroupItem.product',
            'items.orderItemToppings.productSku.product',
        ])->findOrFail($order->id);

        return response()->json([
            'data' => [
                'id' => $order->id,
                'code' => $order->order_code,
                'status' => $order->status,
                'pickup_type' => $order->pickup_type,
                // Serialize as UTC ("…Z"), matching the status/detail endpoint
                // below and the app-wide majority convention. toIso8601String()
                // emits the APP-timezone wall-clock with a +09:00 suffix
                // ("2026-07-23T13:40:00+09:00"); a client that ignores the offset
                // reads that 13:40 as its own local time and re-applies its own
                // shift, so the same instant rendered from the create response
                // drifted against the status response. Same instant either way —
                // this form just can't be misread.
                'scheduled_pickup_time' => $order->scheduled_pickup_time?->toISOString(),
                'estimated_ready_time' => $order->estimated_ready_time?->toISOString(),
                'preparation_minutes' => $order->preparation_minutes,
                'customer_takeaway_name' => $order->customer_takeaway_name,
                'customer_takeaway_phone' => $order->customer_takeaway_phone,
                'customer_takeaway_email' => $order->customer_takeaway_email,
                'items' => $order->items->map(fn ($item) => $this->formatItem($item)),
                'subtotal' => (float) $order->subtotal,
                'total' => (float) $order->total_amount,
                'tax_amount' => (float) $order->tax_amount,
                'service_charge' => (float) $order->service_charge,
            ],
        ], 201);
    }

    public function currentOrder(string $qrToken): JsonResponse
    {
        $table = Table::where('qr_token', $qrToken)
            ->where('is_active', true)
            ->first();

        if (! $table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $order = $this->orderService->getCurrentOrder($table->current_order_id);

        if (! $order) {
            return response()->json(['data' => ['order' => null]]);
        }

        return response()->json([
            'data' => [
                'order' => $this->formatOrder($order),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $order = $this->orderService->findById($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json([
            'data' => $this->formatOrder($order),
        ]);
    }

    /**
     * plan-037 — customer-confirmed the order at the awaiting-confirmation
     * step. Transition awaiting_confirmation → pending so KDS / admin
     * pick it up, then fire the order-placed mail that was deferred at
     * create time.
     */
    public function commit(string $id): JsonResponse
    {
        $order = CustomerOrder::find($id);
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->status !== CustomerOrderStatusEnum::AwaitingConfirmation) {
            return response()->json([
                'error' => 'NOT_AWAITING_CONFIRMATION',
                'message' => 'Order is not in awaiting_confirmation state.',
                'current_status' => $order->status instanceof CustomerOrderStatusEnum ? $order->status->value : (string) $order->status,
            ], 409);
        }

        if ($order->confirmation_due_at && $order->confirmation_due_at->isPast()) {
            return response()->json([
                'error' => 'CONFIRMATION_EXPIRED',
                'message' => 'Confirmation window has expired.',
            ], 409);
        }

        $this->orders->commitConfirmation(new CommitOrderConfirmationCommand(
            OrderMutationContextFactory::fromOrder($order),
            $order->id,
        ));
        $order = $order->fresh();

        if ($order->customer_takeaway_email) {
            try {
                $loaded = CustomerOrder::with(['branch', 'items.productSku.product'])->find($order->id);
                Mail::to($order->customer_takeaway_email)->queue(new OrderPlacedMail($loaded));
            } catch (\Throwable $e) {
                \Log::warning('OrderPlacedMail queue (commit) failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'data' => $this->formatOrder($order->fresh(['items.productSku.product'])),
        ]);
    }

    /**
     * plan-037 — customer cancelled the order before the confirmation
     * countdown elapsed (or BE scheduler picks up stale rows separately).
     * Transition awaiting_confirmation → voided with a reason tag.
     */
    public function cancel(string $id): JsonResponse
    {
        $order = CustomerOrder::find($id);
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->status !== CustomerOrderStatusEnum::AwaitingConfirmation) {
            return response()->json([
                'error' => 'NOT_AWAITING_CONFIRMATION',
                'message' => 'Order is not in awaiting_confirmation state.',
                'current_status' => $order->status instanceof CustomerOrderStatusEnum ? $order->status->value : (string) $order->status,
            ], 409);
        }

        $this->orders->voidAwaitingConfirmation(new VoidAwaitingConfirmationOrderCommand(
            OrderMutationContextFactory::fromOrder($order),
            $order->id,
            'customer_cancelled_during_confirmation',
        ));
        $order = $order->fresh();

        return response()->json([
            'data' => $this->formatOrder($order->fresh(['items.productSku.product'])),
        ]);
    }

    public function destroyItem(string $qrToken, string $itemId): JsonResponse
    {
        $table = Table::where('qr_token', $qrToken)->where('is_active', true)->first();

        if (! $table || ! $table->current_order_id) {
            return response()->json(['message' => 'No active order found for this table.'], 404);
        }

        $order = $this->orderService->getCurrentOrder($table->current_order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // plan-047 T2.12 (#1090) — canonical facade; same legacy write path.
        $this->orders->removeItem(new RemoveOrderItemCommand(
            OrderMutationContextFactory::fromOrder($order),
            (string) $order->id,
            (string) $itemId,
            'customer-web item removal',
        ));

        return response()->json(['message' => 'Item removed.']);
    }

    private function formatOrder($order): array
    {
        // Voided lines are hidden from a LIVE bill (staff removed the món —
        // khách không được thấy / trả tiền cho nó). Nhưng khi cả đơn đã chết
        // (expired / cancelled / voided) thì auto-cancel void HẾT line: lọc
        // tiếp sẽ trả `items: []` → card lịch sử mất tên + ảnh, và "Đặt lại"
        // không còn SKU nào để dựng giỏ. Với đơn chết, giữ nguyên line.
        $orderStatus = $order->status instanceof \BackedEnum
            ? $order->status->value
            : (string) $order->status;
        $isDeadOrder = in_array($orderStatus, ['expired', 'cancelled', 'voided'], true);

        $items = $isDeadOrder
            ? $order->items->values()
            : $order->items
                ->filter(fn ($item) => $this->resolveItemStatus($item) !== OrderItemStatusEnum::Voided)
                ->values();

        $total = (float) $order->total_amount;
        $paid = (float) $order->paid_amount;
        $remaining = max(0, $total - $paid);

        // Per-item paid quantity, aggregated across non-failed payments whose
        // metadata declares `split_mode = by_items`. Powers customer-web's
        // "Đã thanh toán" badge so a guest reopening the bill cannot accidentally
        // re-pay for an item another guest has already settled. Mirrors the
        // same aggregation in CustomerOrderService::splitByItemsPreview() — see
        // that method for the canonical implementation referenced by POS/kiosk.
        $claimedByItem = [];
        if ($order->relationLoaded('payments')) {
            foreach ($order->payments as $payment) {
                $statusValue = $payment->status instanceof PaymentStatusEnum
                    ? $payment->status->value
                    : $payment->status;
                if ($statusValue === 'failed') {
                    continue;
                }
                if ($payment->refund_of_id !== null || (float) $payment->amount < 0) {
                    continue;
                }
                $meta = $payment->metadata;
                if (! is_array($meta) || ($meta['split_mode'] ?? null) !== 'by_items') {
                    continue;
                }
                foreach ($meta['item_allocations'] ?? [] as $alloc) {
                    $iid = (string) ($alloc['item_id'] ?? '');
                    $units = (int) ($alloc['units'] ?? 0);
                    if ($iid !== '' && $units > 0) {
                        $claimedByItem[$iid] = ($claimedByItem[$iid] ?? 0) + $units;
                    }
                }
            }
        }

        $payments = $order->relationLoaded('payments')
            ? $order->payments->map(fn ($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'status' => $p->status instanceof PaymentStatusEnum ? $p->status->value : $p->status,
                'paid_at' => $p->paid_at?->toISOString(),
                'payment_method' => $p->paymentMethod?->name,
            ])->values()
            : [];

        return [
            'id' => $order->id,
            'code' => $order->order_code,
            'status' => $order->status,
            'placed_at' => $order->opened_at?->toISOString(),
            'items' => $items->map(fn ($item) => $this->formatItem($item, $claimedByItem)),
            'subtotal' => (float) $order->subtotal,
            'total' => $total,
            // Currency the order was created in — customer-web formats every
            // money figure on the order/pay screens with THIS, not the ambient
            // selected-branch currency (a JPY order must never render as USD).
            'currency' => $order->branch?->currency ?? 'JPY',
            // plan-048 T2.5 — lets the standalone pay page (which has no branch
            // context of its own) fetch /branches/{slug}/payment-context for
            // the intent-call policy echo.
            'branch_slug' => $order->branch?->slug,
            // #815 — the CHARGE currency for the Stripe element = the branch's PRICED
            // currency (shop_order_settings.currency_code, JPY default), which may
            // differ from the display `currency` above (branches.currency). The Stripe
            // Elements currency MUST equal the PaymentIntent currency or Stripe.js
            // throws at confirm — so the pay page drives its card element from THIS.
            'charge_currency' => strtoupper((string) (ShopOrderSetting::query()
                ->where('branch_id', $order->branch_id)
                ->value('currency_code') ?: 'JPY')),
            'paid' => $paid,
            'remaining' => $remaining,
            'is_fully_paid' => $remaining <= 0 && $paid > 0,
            'payment_count' => count($payments),
            'payments' => $payments,
            // One coupon per order — FE uses these to gate the coupon input
            // so split-bill customers can't re-apply after the first payer.
            'discount_amount' => (float) $order->discount_amount,
            'coupon_code_snapshot' => $order->coupon_code_snapshot,
            // Tax + service charge từ ShopOrderSetting, đã rolled vào total.
            // FE hiển thị riêng từng dòng cho khách thấy break-down.
            'tax_amount' => (float) $order->tax_amount,
            'service_charge' => (float) $order->service_charge,
            // plan-043 T5.4 — per-rate breakdown (8%対象 / 10%対象) + tax mode
            // snapshot for インボイス display on the dine-in payment/summary
            // views. Additive; older clients ignore the extra keys.
            'is_tax_included' => (bool) $order->is_tax_included,
            'tax_breakdown' => app(OrderPricingCalculator::class)
                ->forOrder($order, ShopOrderSetting::where('branch_id', $order->branch_id)->first())
                ->groupsToArray(),
            // Kitchen prep timing — FE dùng để hiển thị ETA chính xác cho
            // khách takeaway biết khi nào đến lấy. Priority đọc ở FE:
            //   1. actual_ready_time (staff bếp đã mark xong)
            //   2. estimated_ready_time (BE/admin set sẵn)
            //   3. placed_at + preparation_minutes (compute từ 2 field)
            //   4. fallback heuristic phía FE khi cả 3 đều null.
            'scheduled_pickup_time' => $order->scheduled_pickup_time?->toISOString(),
            'estimated_ready_time' => $order->estimated_ready_time?->toISOString(),
            'actual_ready_time' => $order->actual_ready_time?->toISOString(),
            'preparation_minutes' => $order->preparation_minutes,
            // plan-031 — takeaway payment countdown. `seconds_until_due` là
            // delta server-side để FE đếm ngược không lệch khi máy khách sai giờ
            // (cùng contract với CustomerOrderSummaryResource).
            'payment_due_at' => $order->payment_due_at?->toISOString(),
            'seconds_until_due' => $order->payment_due_at
                ? max(0, (int) floor(now()->diffInSeconds($order->payment_due_at, false)))
                : null,
            // plan-037 — confirmation step countdown deadline. FE
            // /order-confirm reads this to render mm:ss + auto-expire.
            'confirmation_due_at' => $order->confirmation_due_at?->toISOString(),
            // #370 — FE gates "Pay now" button (counter-pay → switch to card)
            // on order_type=takeaway + status=pending. Without this the FE
            // would have to fetch a second endpoint just to know type.
            'order_type' => $order->order_type instanceof CustomerOrderTypeEnum
                ? $order->order_type->value
                : (string) $order->order_type,
        ];
    }

    /**
     * @param  array<string, int>  $claimedByItem  item_id → cumulative qty already
     *                                             allocated via by_items payments
     * @return array<string, mixed>
     */
    private function formatItem($item, array $claimedByItem = []): array
    {
        $sku = $item->productSku;
        $product = $sku?->product;

        $productName = $product?->name;
        $skuName = $sku?->name;
        $variant = ($skuName && $skuName !== $productName) ? $skuName : null;

        $category = $product?->categories?->first()?->name;

        // Topping name resolution: the topping itself is a Product (its
        // ToppingGroupItem is a junction row, so it has no own name column).
        // Prefer the picked SKU's product name, fall back to the group item's
        // product, and finally the topping's own note as a last-ditch label.
        //
        // plan-0xx — expose `product_sku_id` on both line items and toppings so
        // the customer-web can implement a "reorder" flow by reconstructing a
        // cart payload from a past order. Existing clients only read `name`,
        // `unit_price` and `quantity`, so adding this field is backwards-
        // compatible.
        $options = $item->relationLoaded('orderItemToppings')
            ? $item->orderItemToppings->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->productSku?->product?->name
                    ?? $t->toppingGroupItem?->product?->name
                    ?? $t->note,
                'unit_price' => (float) $t->unit_price,
                'quantity' => (int) $t->quantity,
                'product_sku_id' => $t->product_sku_id,
            ])->values()->all()
            : [];

        return [
            'id' => $item->id,
            'product_sku_id' => $item->product_sku_id,
            'name' => $productName ?? $skuName,
            'image_url' => $sku?->galleryFirst?->getUrl()
                ?? $product?->galleryFirst?->getUrl(),
            'variant' => $variant,
            'category' => $category,
            'qty' => (int) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'subtotal' => (float) $item->subtotal,
            'note' => $item->note,
            // plan-043 T5.4 — per-line tax snapshot (drives the ※ reduced-rate
            // marker + per-rate rendering on the dine-in surfaces).
            'tax_type_id' => $item->tax_type_id,
            'tax_rate' => $item->tax_rate !== null ? (float) $item->tax_rate : null,
            'tax_amount' => (float) $item->tax_amount,
            'options' => $options,
            'status' => $item->status instanceof OrderItemStatusEnum ? $item->status->value : $item->status,
            // Cumulative qty already paid for this item via by_items
            // payments (sums metadata.item_allocations across non-failed
            // payments). 0 when no by_items pay touched the item — Tùy chọn
            // / Chia đều flows pay by amount, not by item, so they don't
            // contribute. FE uses this to disable + show "đã thanh toán" on
            // items the next guest must not double-pay.
            'paid_quantity' => (int) ($claimedByItem[(string) $item->id] ?? 0),
            'created_at' => $item->created_at?->toISOString(),
        ];
    }

    private function resolveItemStatus($item): OrderItemStatusEnum
    {
        return $item->status instanceof OrderItemStatusEnum
            ? $item->status
            : OrderItemStatusEnum::from($item->status);
    }

    /**
     * Plan 039 — record customer's split-mode choice (`by_people` |
     * `by_items` | `custom`) so the kiosk can skip its `/split-options`
     * chooser when scanning the QR. Endpoint covers BOTH propagation
     * paths:
     *
     * 1. **Pay-online** (PR #377 use case): customer-web's payment-view
     *    fires this right before creating the payment intent. All 3
     *    modes accepted.
     * 2. **Counter-pay** (this plan's contract): customer-web's
     *    `/order-confirm/<id>` waiting screen fires this after the user
     *    taps the new "Chia hóa đơn?" card. `custom` mode is rejected —
     *    see ADR-1 in plan-039 DESIGN (archived, removed from tree #2188 — git history). Counter-pay is
     *    identified by the absence of `stripe_payment_intent_id` (no
     *    Stripe intent has been minted yet).
     *
     * Lock semantics:
     *   - Order paid (`paid_amount > 0`) → 409 SPLIT_MODE_LOCKED. The
     *     kiosk has already started allocating against a specific mode;
     *     swapping mid-flow would corrupt the allocation.
     *   - Order closed/voided → 422.
     *
     * Auth: same opaque-token pattern as the rest of
     * `/api/v1/customer/orders/*` — guest knows the UUIDv7 order id
     * from their localStorage / draft; no Sanctum guard.
     */
    public function setSplitMode(Request $request, string $id): JsonResponse
    {
        $order = CustomerOrder::find($id);
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if (in_array($order->status, [CustomerOrderStatusEnum::Closed, CustomerOrderStatusEnum::Voided], true)) {
            return response()->json([
                'error' => 'ORDER_FINALIZED',
                'message' => 'Order is already closed or voided.',
            ], 422);
        }

        if ((float) $order->paid_amount > 0) {
            return response()->json([
                'error' => 'SPLIT_MODE_LOCKED',
                'message' => 'Payment has started — split mode is locked.',
            ], 409);
        }

        $validated = $request->validate([
            // #2860 — luật `in:` sinh từ enum, không gõ tay. Hai validator (đây
            // và `OrderPaymentStoreRequest`) từng gõ tay hai tập KHÁC nhau, giao
            // nhau đúng một giá trị, và không gì đỏ.
            'split_mode' => ['required', 'string', OrderSplitMode::validationRule()],
            // plan-039 follow-up — optional headcount when split_mode is
            // even. Lets the kiosk show ¥X/người immediately instead
            // of asking the cashier to type the count again. Rejected for
            // other modes so we don't accumulate stale numbers.
            'split_count' => ['nullable', 'integer', 'min:2', 'max:99'],
        ]);

        // Chuẩn hoá NGAY tại biên. Từ dòng này trở xuống chỉ còn từ vựng
        // canonical — không nhánh nào bên dưới biết `by_people`/`custom` từng
        // tồn tại, và cột trong DB chỉ nhận giá trị canonical.
        $splitMode = OrderSplitMode::fromWire($validated['split_mode']);

        // ADR-1: counter-pay flow (no Stripe intent minted yet) rejects
        // by-amount because there is no "I pay X, you pay Y" UX surface
        // in the counter-pay path — customer brings QR to counter and
        // the cashier handles per-person amounts there. Pay-online keeps
        // it because payment-view has the input UI for it.
        $isCounterPay = $order->stripe_payment_intent_id === null;
        if ($isCounterPay && $splitMode === OrderSplitMode::ByAmount) {
            return response()->json([
                'error' => 'SPLIT_MODE_INVALID_FOR_COUNTER',
                'message' => 'By-amount split is not available for counter-pay orders. Choose even or by_items.',
            ], 422);
        }

        if (isset($validated['split_count']) && $splitMode !== OrderSplitMode::Even) {
            return response()->json([
                'error' => 'SPLIT_COUNT_INVALID_FOR_MODE',
                'message' => 'split_count is only valid when split_mode is even.',
            ], 422);
        }

        // plan-047 T2.12 (#1090) — canonical facade; the command enforces the
        // same even/split_count coupling the validator promised.
        $this->orders->changeSplitMode(new ChangeOrderSplitModeCommand(
            OrderMutationContextFactory::fromOrder($order),
            (string) $order->id,
            $splitMode,
            $splitMode === OrderSplitMode::Even
                ? ($validated['split_count'] ?? null)
                : null,
        ));
        $order = $order->refresh();

        return response()->json([
            'data' => [
                'split_mode' => $order->split_mode,
                'split_people_count' => $order->split_people_count,
                'split_mode_locked' => false,
            ],
        ]);
    }

    /**
     * Plan 033 — by-items preview, customer-web QR surface. Uses the same
     * "order id as opaque token" auth pattern as the rest of the customer
     * orders endpoints. Stateless read.
     */
    public function splitByItemsPreview(Request $request, string $id): JsonResponse
    {
        $order = CustomerOrder::find($id);
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $candidate = $this->parseAllocationsQuery($request);
        $result = $this->customerOrderService->splitByItemsPreview($order, $candidate);

        return response()->json(['data' => $result]);
    }

    /**
     * @return array<int, array{item_id: string, units: int, bill_index: int}>|null
     */
    private function parseAllocationsQuery(Request $request): ?array
    {
        $raw = $request->query('allocations');
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        if (strlen($raw) > 4096) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }
}
