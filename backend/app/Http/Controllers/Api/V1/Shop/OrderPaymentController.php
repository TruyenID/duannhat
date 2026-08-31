<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\OrderPaymentStoreRequest;
use App\Http\Resources\OrderPaymentResource;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Customer\OrderPaymentService;
use App\Services\Pos\TillSessionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class OrderPaymentController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly OrderPaymentService $service,
        private readonly TillSessionService $tillSessions,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/payments',
        summary: 'List payments for an order',
        tags: ['Order Payments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payment list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderPayment')),
            ])),
            new OA\Response(response: 404, description: 'Order not found'),
        ]
    )]
    public function index(Request $request, CustomerOrder $customerOrder): AnonymousResourceCollection
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('viewAny', OrderPayment::class);

        $payments = $this->service->list([
            'customer_order_id' => $customerOrder->id,
        ]);

        return OrderPaymentResource::collection($payments);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/payments',
        summary: 'Create a payment for an order',
        description: 'Creates a payment record. If the payment method is auto-confirm (e.g. cash), the payment is immediately succeeded and the order may auto-close.',
        tags: ['Order Payments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['payment_method_id', 'amount'],
            properties: [
                new OA\Property(property: 'payment_method_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'amount', type: 'number', exclusiveMinimum: 0),
                new OA\Property(property: 'tip_amount', type: 'number', minimum: 0, nullable: true),
                new OA\Property(property: 'tendered_amount', type: 'number', nullable: true, description: 'Required for cash payments'),
                new OA\Property(property: 'reference_no', type: 'string', nullable: true, maxLength: 100),
                new OA\Property(property: 'note', type: 'string', nullable: true),
                new OA\Property(property: 'idempotency_key', type: 'string', nullable: true, maxLength: 64, description: 'Optional client-supplied UUID. If a payment with this key already exists for the order, that payment is returned instead of creating a duplicate — lets retries after network errors stay safe.'),
                new OA\Property(property: 'expected_total_amount', type: 'number', nullable: true, description: 'Plan-007 split-bill drift guard. Server rejects with code split_bill_total_drift when this disagrees with the current order.total_amount.'),
                new OA\Property(
                    property: 'metadata',
                    type: 'object',
                    nullable: true,
                    description: 'Plan-021/038 split-bill audit metadata. Persisted as JSON on the row; informational only. Shapes: even-mode {split_mode:"even", bill_index, total_bills}, by-items-mode {split_mode:"by_items", bill_index, total_bills?, label, item_allocations:[{item_id, units}]}, or by-amount-mode {split_mode:"by_amount", bill_index, total_bills, label, amount}.',
                    properties: [
                        new OA\Property(property: 'split_mode', type: 'string', enum: ['even', 'by_items', 'by_amount'], nullable: true),
                        new OA\Property(property: 'bill_index', type: 'integer', minimum: 0, nullable: true, description: '0-based person index — may be sparse for by_items mode when empty bills are skipped.'),
                        new OA\Property(property: 'total_bills', type: 'integer', minimum: 1, nullable: true),
                        new OA\Property(property: 'label', type: 'string', maxLength: 50, nullable: true),
                        new OA\Property(
                            property: 'item_allocations',
                            type: 'array',
                            nullable: true,
                            items: new OA\Items(properties: [
                                new OA\Property(property: 'item_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'units', type: 'integer', minimum: 1),
                            ])
                        ),
                    ]
                ),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Payment created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/OrderPayment'),
            ])),
            new OA\Response(response: 409, description: 'Order not in checkout/paying status'),
            new OA\Response(response: 422, description: 'Validation failed or payment exceeds balance'),
        ]
    )]
    public function store(OrderPaymentStoreRequest $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('create', OrderPayment::class);

        $data = $request->validated();
        $data['customer_order_id'] = $customerOrder->id;
        $data['organization_id'] = $this->getOrganizationId();
        $data['branch_id'] = $request->attributes->get('shop_id');
        $data['brand_id'] = $request->attributes->get('brand_id');
        $data['received_by_id'] = $request->user()->id;
        // Plan 007 (Decision 12 / NOTES 2026-04-20) — walk-in partial-payment
        // black hole. The POS cashier path must not accept a shortfall payment
        // on an order with no customer to bill later: the outstanding-debt
        // resurfacing pipe is keyed on customer_id, so a walk-in partial strands
        // the balance forever and corrupts shift reconciliation. pos-web blocks
        // this in the PaymentDialog, but the guard must also hold server-side
        // (direct API / other clients bypass the UI). Only the shop/POS namespace
        // opts in; workstation / kiosk / customer-web split flows deliberately
        // allow partial installments and stay unaffected.
        $data['enforce_walkin_full_payment'] = true;
        // Plan 030 — stamp the open shift when ResolveOpenTillSession set it
        // (POS namespace only; null on Shop/Customer namespace where the
        // guard middleware does not run, preserving legacy behaviour).
        $data['till_session_id'] = $request->attributes->get('till_session_id');
        $data['orchestrator_transport'] = 'pos';
        $data['gateway_option_id'] = $request->input('gateway_option_id');
        $data['gateway_connection_id'] = $request->input('gateway_connection_id');
        $data['policy_revision'] = $request->input('policy_revision');
        $deviceId = $request->attributes->get('device_id');
        if (is_string($deviceId) && $deviceId !== '') {
            $data['device_id'] = $deviceId;
        }

        $payment = $this->service->create($data);

        return (new OrderPaymentResource($payment))
            ->response()
            ->setStatusCode(201);
    }

    // =========================================================================
    //  Workflow
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/payments/{payment}/confirm',
        summary: 'Confirm a pending payment',
        description: 'Confirms a pending payment (e.g. card/transfer verified). May auto-close the order if fully paid.',
        tags: ['Order Payments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'payment', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payment confirmed', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/OrderPayment'),
            ])),
            new OA\Response(response: 409, description: 'Payment is not in pending status'),
        ]
    )]
    public function confirm(Request $request, CustomerOrder $customerOrder, OrderPayment $payment): OrderPaymentResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('confirm', $payment);

        $confirmedPayment = $this->service->confirm($payment);

        return new OrderPaymentResource($confirmedPayment);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/payments/{payment}/refund',
        summary: 'Refund a succeeded payment',
        description: 'Creates a refund record for a succeeded payment. Partial refunds are supported via the amount field.',
        tags: ['Order Payments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'payment', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 64, nullable: true, description: 'Stable key for replay-safe refunds.'),
                new OA\Property(property: 'amount', type: 'number', minimum: 0, nullable: true, description: 'Partial refund amount. Defaults to full payment amount.'),
                new OA\Property(property: 'note', type: 'string', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Payment refunded', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/OrderPayment'),
            ])),
            new OA\Response(response: 409, description: 'Payment is not in succeeded status, or REFUND_BLOCKED_WORKSTATION_OPEN_SHIFT (#2657) — the payment was taken on the shop workstation and its shift is still holding the drawer'),
            new OA\Response(response: 422, description: 'Refund amount exceeds original payment'),
        ]
    )]
    public function refund(Request $request, CustomerOrder $customerOrder, OrderPayment $payment): OrderPaymentResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('refund', $payment);

        if ($headerKey = $request->header('Idempotency-Key')) {
            $request->merge(['idempotency_key' => $headerKey]);
        }

        $data = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);

        $this->refuseRefundOwnedByAnOpenDrawer($payment);

        $refundPayment = $this->service->refund($payment, $data);

        return new OrderPaymentResource($refundPayment);
    }

    /**
     * #2657 (layer 3 of #2580) — Cloud must not refund money that a shop
     * workstation is still counting.
     *
     * ## The hole this closes
     *
     * pos-web reaches this endpoint by accident, not by design: `apiFetch`
     * (`web/pos/src/lib/api.ts`) retries a LAN network error once against Cloud
     * on a 3s timeout, and nothing pins the refund call to LAN. So a LAN hiccup
     * mid-refund lands the refund HERE — attributed to whatever shift is open in
     * Cloud — while the workstation, which serves the 精算 screen and owns the
     * drawer, never hears about it. The cashier then reconciles against a
     * pre-refund figure and cannot balance.
     *
     * Refunds deliberately do NOT sync DOWN: orders/payments are
     * workstation-source-of-truth (`sync_pull.go:8`), and Cloud's
     * `payment_summary` is a label source that "must never become a row in
     * `payments`" (`sync_pull.go:2749`, migration 066) — otherwise online money
     * would present itself as claimable till cash. That forecloses a DOWN feed,
     * so the fix is to refuse here and send staff back to the shop's POS, where
     * the refund reaches the drawer it must come out of. Turning a silent money
     * discrepancy into a loud error, while staff can still act, is the trade.
     *
     * ## Scope — deliberately narrow
     *
     * Blocks ONLY a workstation-origin payment whose own shift is still
     * in-progress. It does not touch:
     * - Cloud-origin payments (customer web, kiosk, self-regi, and `pos` rows
     *   created straight against Cloud) — no workstation ever held that money;
     * - payments whose shift already reached settled/abandoned/expired — the
     *   Z-report is done and nothing local is left to diverge from;
     * - the workstation's OWN sync-UP refund, which is a different controller
     *   (`Api/V1/Workstation/OrderLifecycleController::refundPayment`) and is the
     *   one legitimate path. It shares `OrderPaymentService::refund`, which is
     *   exactly why this guard lives in the controller and NOT in the service —
     *   putting it there would break the only correct way to refund.
     *
     * Provenance is the server-owned `channel` column stamped by
     * `Api/V1/Workstation/PaymentController` (`orchestrator_transport =>
     * 'workstation'`). Rows written before #2612 carry `channel = NULL` and are
     * intentionally NOT rescued by a heuristic here: per the no-legacy ruling
     * (#2188) we do not add fallback branches for old data, and the guard only
     * fires while a shift is still in progress — shifts close daily, so that
     * population ages out on its own rather than needing a compatibility path.
     */
    private function refuseRefundOwnedByAnOpenDrawer(OrderPayment $payment): void
    {
        if ($payment->channel !== PaymentChannelEnum::Workstation->value) {
            return;
        }

        if (! $this->tillSessions->sessionIsInProgress($payment->till_session_id)) {
            return;
        }

        abort(response()->json([
            'message' => 'This payment was taken on the shop workstation and its cashier shift is still open. Refund it on the shop POS so the money comes out of the drawer it went into.',
            'code' => 'REFUND_BLOCKED_WORKSTATION_OPEN_SHIFT',
            'payment_id' => (string) $payment->id,
            'till_session_id' => (string) $payment->till_session_id,
        ], 409));
    }
}
