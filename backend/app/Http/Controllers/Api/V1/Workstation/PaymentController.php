<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\TillSession;
use App\Services\Customer\OrderPaymentService;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Commands\PromoteOrderForPaymentCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Payment\Policy\Admin\PosEffectivePaymentOptionEnricher;
use App\Services\Pos\TillSessionService;
use App\Support\Logging\MoneyOrchestrationLog;
use App\Support\PaymentPollStatus;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Workstation-scoped payment endpoints.
 *
 * Mirrors KioskController's payment operations but lives behind
 * `device.auth:workstation`. Used by the workstation's sync worker when a
 * POS terminal (SSO-authenticated, no device token of its own) records a
 * payment locally — the workstation forwards it to Cloud under its own
 * device identity. Body schema matches the workstation sync payload
 * (`payment_method` rather than the kiosk's `method`, optional
 * `terminal_response` for card-terminal raw output).
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly OrderPaymentService $paymentService,
        private readonly OrderMutationFacade $orders,
        private readonly PosEffectivePaymentOptionEnricher $posEnricher,
        private readonly TillSessionService $tillSessions,
    ) {}

    #[OA\Post(
        path: '/api/v1/workstation/payments',
        summary: 'Record a payment from a workstation-attached terminal',
        description: 'Workstation owns the payment locally (SQLite) and forwards each new payment here. Pass `Idempotency-Key` header so retries after network failures dedupe to the same Cloud row.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_id', 'payment_method', 'amount'],
            properties: [
                new OA\Property(property: 'order_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'payment_method', type: 'string', maxLength: 50, description: 'PaymentMethod code (e.g. cash, card, qr, emoney)'),
                new OA\Property(property: 'amount', type: 'integer', minimum: 1, maximum: 99999999),
                new OA\Property(property: 'tip_amount', type: 'integer', minimum: 0, maximum: 99999999, nullable: true, description: 'Cash tip retained in the drawer (default 0). reconcile() adds cash-method tips back into expected_cash.'),
                new OA\Property(property: 'tendered_amount', type: 'integer', minimum: 0, maximum: 99999999, nullable: true, description: 'Cash the customer actually handed over, for methods with requires_tendered. change_amount is derived as tendered − amount − tip. Omitted, or short of amount + tip, falls back to auto-tendering amount + tip — never a 422, since the money is already in the drawer.'),
                new OA\Property(property: 'terminal_response', type: 'string', maxLength: 1000, nullable: true),
                new OA\Property(property: 'till_session_id', type: 'string', format: 'uuid', nullable: true, description: 'Cloud cashier-shift id the workstation attributes this payment to (local→cloud remapped before send). Cloud-authoritative (plan-044 R6): kept only if it belongs to this branch and is still in-progress, else dropped to the branch current shift; an invalid value is tolerated, never 422.'),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Payment created'),
            new OA\Response(response: 404, description: 'Order not found in this branch'),
            new OA\Response(response: 409, description: 'Order can no longer accept a payment (e.g. the customer already settled it online while the cashier was collecting). Terminal for the sync queue; the refused amount is alarmed on the `payment_orchestration` channel as `workstation_payment_stranded_at_the_drawer`.'),
            new OA\Response(response: 422, description: 'Validation failed or payment method unavailable'),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => ['required', 'uuid'],
            'payment_method' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'integer', 'min:1', 'max:99999999'],
            // #817 B4 — cash tips physically stay in the drawer; reconcile() adds
            // cash-method tip_amount back into expected_cash, so a dropped tip
            // makes every tipped shift over-close. Forward it (default 0).
            'tip_amount' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            // The cash the customer actually handed over. The workstation has
            // always forwarded it (sync_service.go) but this endpoint neither
            // accepted nor kept it, so Cloud's copy of every cash sale read
            // "tendered = amount, change = 0" no matter what the cashier
            // counted. Nullable + never rejected — see the auto-tender below.
            'tendered_amount' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'terminal_response' => ['nullable', 'string', 'max:1000'],
            // R6: shape-validated only. A foreign/terminal/unknown session id is
            // NOT rejected here — it is dropped to the branch fallback below so a
            // money-bearing sync item never dead-letters on attribution.
            'till_session_id' => ['nullable', 'uuid'],
            'gateway_option_id' => ['nullable', 'uuid', 'exists:payment_gateway_options,id'],
            'gateway_connection_id' => ['nullable', 'uuid', 'exists:payment_gateway_connections,id'],
            'policy_revision' => ['required_with:gateway_option_id', 'integer', 'min:1'],
            // #2535 B9 — mã giao dịch của thiết bị ngoại vi (釣銭機 Glory, máy
            // quẹt thẻ). Cột `order_payments.reference_no` là string(100).
            'reference_no' => ['nullable', 'string', 'max:100'],
        ]);

        $device = $request->attributes->get('device');
        $device->loadMissing('branch.brand');
        $branch = $device->branch;

        if (! $branch) {
            abort(422, 'Device is not associated with an active branch.');
        }

        if (! $branch->brand) {
            abort(422, 'Branch is not associated with a brand.');
        }

        $order = CustomerOrder::where('id', $request->order_id)
            ->where('branch_id', $device->branch_id)
            ->where('organization_id', $device->organization_id)
            ->first();

        if (! $order) {
            abort(404);
        }

        $this->orders->promoteForPayment(new PromoteOrderForPaymentCommand(
            new MutationContext(
                (string) $device->organization_id,
                (string) $device->id,
                'workstation-pay:'.$order->id,
                $request->header('Idempotency-Key') ?? (string) Str::uuid(),
                expectedVersion: 1,
            ),
            (string) $order->id,
        ));
        $order->refresh();

        $paymentMethod = $this->posEnricher->resolveMethodByCode(
            (string) $request->payment_method,
            (string) $device->organization_id,
            (string) $device->branch_id,
        ) ?? abort(422, 'Payment method not available');

        $note = null;
        if ($tr = $request->input('terminal_response')) {
            $note = "terminal_response: {$tr}";
        }

        // plan-044 headline bug fix — attribute the payment to a cashier shift.
        // Before this, workstation-forwarded payments (POS LAN + kiosk) reached
        // Cloud with till_session_id = NULL and were silently excluded from every
        // per-shift report (ShopTillTrackingService, reconcile). Resolve the
        // Cloud-authoritative session id (R6): honour a same-branch in-progress
        // id the workstation supplies, else fall back to the branch's current
        // in-progress shift, else NULL (no open drawer → true gap payment,
        // adopted by the next shift's carry-over re-stamp).
        $tillSessionId = $this->tillSessions->resolveSyncedSessionId(
            $request->input('till_session_id'),
            $device->branch_id,
            openOnly: false,
        );

        $tipAmount = (float) ($request->input('tip_amount') ?? 0);

        $createData = [
            'customer_order_id' => $order->id,
            'payment_method_id' => $paymentMethod->id,
            'amount' => (float) $request->amount,
            'tip_amount' => $tipAmount,
            'received_by_id' => $device->id,
            'organization_id' => $device->organization_id,
            'branch_id' => $device->branch_id,
            'brand_id' => $branch->brand->id,
            'idempotency_key' => $request->header('Idempotency-Key'),
            'note' => $note,
            'till_session_id' => $tillSessionId,
            'orchestrator_transport' => 'workstation',
            'device_id' => (string) $device->id,
            'gateway_option_id' => $request->input('gateway_option_id'),
            'gateway_connection_id' => $request->input('gateway_connection_id'),
            'policy_revision' => $request->input('policy_revision'),
            // #2535 B9 — mã giao dịch Glory là thứ DUY NHẤT nối được dòng tiền
            // trong sổ của máy với dòng payment ở Cloud. Trước đây nó dừng lại ở
            // SQLite của máy trạm: `handlePaymentCreate` không gửi, và chỗ này
            // không đọc — nên khi cần đối soát (đúng lúc nghi ngờ thất thoát
            // tiền mặt) thì không có gì để so.
            'reference_no' => $request->input('reference_no'),
        ];

        // Cash tendering. The floor already handed the change back, but Cloud is
        // the system of record every downstream document reads, so it must carry
        // the figure the cashier actually counted rather than a synthetic one:
        // this endpoint used to overwrite the tender with `amount + tip`
        // unconditionally, which is why a ¥2,000 note against a ¥1,793 bill was
        // stored — and reprinted from Cloud — as "tendered ¥1,793 / change ¥0".
        //
        // Honour what the workstation sent when it covers the charge; otherwise
        // fall back to the auto-tender. Both branches keep #817 B4 alive
        // (`tendered >= amount + tip` in OrderPaymentService::create — tendering
        // only `amount` when a tip is present threw InvalidArgumentException →
        // 500), and neither can reject: everything reaching this line is money
        // already in the drawer, and a 4xx here dead-letters the sync item
        // instead of retrying, so a short/absent tender degrades to the
        // auto-tender rather than stranding the payment (see the note below).
        if ($paymentMethod->requires_tendered) {
            $autoTender = (float) $request->amount + $tipAmount;
            $sentTender = $request->input('tendered_amount');

            $createData['tendered_amount'] = $sentTender !== null && (float) $sentTender >= $autoTender
                ? (float) $sentTender
                : $autoTender;
        }

        // plan-054 T9.3 — the reverse race. Everything that reaches this line was
        // already collected on the shop floor: the workstation owns the payment
        // locally and only forwards it afterwards. A 4xx here is therefore not a
        // rejected request, it is money in a drawer with no row in Cloud — and the
        // workstation dead-letters a 4xx instead of retrying it, so without this
        // the loss stays invisible until the shift's 過不足 comes up short.
        try {
            $payment = $this->paymentService->create($createData);
        } catch (HttpResponseException|HttpExceptionInterface $refusal) {
            $this->alarmStrandedAtTheDrawer(
                $refusal,
                $request,
                $order,
                $device,
                (string) $request->payment_method,
                (float) $request->amount,
                $tipAmount,
            );

            throw $refusal;
        }

        return response()->json([
            'data' => [
                'id' => $payment->id,
                'payment_code' => $payment->payment_code,
                'status' => $payment->status->value,
                'amount' => (float) $payment->amount,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'expires_at' => $payment->expires_at?->toIso8601String(),
                'confirm_type' => $paymentMethod->is_auto_confirm ? 'auto' : 'manual',
            ],
        ], 201, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Name the money a refused sync-UP left on the shop floor.
     *
     * The canonical case (plan-054 T9.3) is the reverse race: the customer
     * settles their own bill with a PayPay QR, and inside the workstation's next
     * ≤5 s pull window the cashier — still looking at an unpaid ticket — takes
     * cash at the drawer. Cloud correctly refuses the second payment (the order
     * closed the moment PayPay paid it in full, so
     * `OrderPaymentService::create()` aborts 409), and exactly one payment row
     * survives. But a bare 409 is a silent loss: the cash is physically in the
     * till, nothing in Cloud says so, and the shift comes up over at 精算 with no
     * trace of why. This is the trace.
     *
     * Deliberately scoped to 4xx. A 5xx is a transport failure the workstation's
     * sync queue retries until it lands, so alarming on it would only add noise;
     * a 4xx is terminal for the queue item — nobody is coming back for it.
     */
    private function alarmStrandedAtTheDrawer(
        HttpResponseException|HttpExceptionInterface $refusal,
        Request $request,
        CustomerOrder $order,
        Device $device,
        string $paymentMethodCode,
        float $amount,
        float $tipAmount,
    ): void {
        $status = $refusal instanceof HttpResponseException
            ? $refusal->getResponse()->getStatusCode()
            : $refusal->getStatusCode();

        if ($status < 400 || $status >= 500) {
            return;
        }

        $body = [];
        if ($refusal instanceof HttpResponseException) {
            $decoded = json_decode((string) $refusal->getResponse()->getContent(), true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $order = $order->fresh() ?? $order;
        $orderStatus = $order->status;

        MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_STRANDED, 'workstation_payment_stranded_at_the_drawer', [
            'order_id' => (string) $order->id,
            'order_code' => $order->order_code,
            'order_status' => is_object($orderStatus) ? $orderStatus->value : $orderStatus,
            // What a human has to physically reconcile: the tender the cashier
            // took, tip included, none of which Cloud recorded.
            'stranded_amount' => $amount + $tipAmount,
            'amount' => $amount,
            'tip_amount' => $tipAmount,
            'payment_method' => $paymentMethodCode,
            'order_total_amount' => (float) $order->total_amount,
            'order_paid_amount' => (float) $order->paid_amount,
            'http_status' => $status,
            'reason_code' => $body['code'] ?? null,
            'reason' => $body['message'] ?? $refusal->getMessage(),
            'device_id' => (string) $device->id,
            'branch_id' => (string) $device->branch_id,
            'organization_id' => (string) $device->organization_id,
            'idempotency_key' => $request->header('Idempotency-Key'),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/workstation/payments/{id}/status',
        summary: 'Poll payment status',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Status returned'),
            new OA\Response(response: 404, description: 'Payment not found in this branch'),
        ],
    )]
    public function status(Request $request, string $id): JsonResponse
    {
        $device = $request->attributes->get('device');

        $payment = OrderPayment::where('id', $id)
            ->where('branch_id', $device->branch_id)
            ->where('organization_id', $device->organization_id)
            ->first();

        if (! $payment) {
            abort(404);
        }

        return response()->json([
            'data' => [
                'id' => $payment->id,
                'status' => PaymentPollStatus::forWorkstationPoll($payment->status),
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/workstation/payments/{payment}/confirm',
        summary: 'Confirm a pending payment',
        description: 'Manual-confirm methods (cash, e-money) record the payment as pending and confirm separately after physical settlement.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Confirmed'),
            new OA\Response(response: 404, description: 'Payment not found in this branch'),
            new OA\Response(response: 409, description: 'Payment not in pending state'),
        ],
    )]
    public function confirm(Request $request, string $payment): JsonResponse
    {
        $device = $request->attributes->get('device');

        $record = OrderPayment::where('id', $payment)
            ->where('branch_id', $device->branch_id)
            ->where('organization_id', $device->organization_id)
            ->first();

        if (! $record) {
            abort(404);
        }

        $record = $this->paymentService->confirm($record);

        return response()->json([
            'data' => [
                'id' => $record->id,
                'status' => PaymentPollStatus::forWorkstationPoll($record->status),
                'paid_at' => $record->paid_at?->toIso8601String(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/workstation/payments/{payment}/attribution',
        summary: 'Propagate a gap-claim cashier-shift attribution (plan-044 R2, endpoint D)',
        description: "Workstation-initiated sync of a POST-CREATION till_session_id change (a gap payment claimed at a local shift open). R6: applied ONLY if the id resolves to a session of the device's branch; a foreign/unknown id is a no-op that NEVER nullifies an existing attribution and NEVER 422s (attribution must not dead-letter a money-bearing sync). Idempotent — re-posting the same value changes nothing.",
        tags: ['Workstation'],
        security: [['device_token' => []]],
        parameters: [
            new OA\Parameter(name: 'payment', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'till_session_id', type: 'string', format: 'uuid', nullable: true, description: 'Cloud cashier-shift id (workstation remaps local→cloud before send).'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Applied or no-op; returns the payment id + resolved till_session_id.'),
            new OA\Response(response: 404, description: 'Payment not found in the device branch.'),
        ],
    )]
    public function attribution(Request $request, string $payment): JsonResponse
    {
        $device = $request->attributes->get('device');

        $record = OrderPayment::where('id', $payment)
            ->where('branch_id', $device->branch_id)
            ->where('organization_id', $device->organization_id)
            ->first();

        if (! $record) {
            abort(404);
        }

        $validated = $request->validate([
            'till_session_id' => ['nullable', 'uuid'],
        ]);
        $sessionId = $validated['till_session_id'] ?? null;

        // R6 — apply only if the session exists AND belongs to this branch. A
        // foreign/unknown id is a no-op that never nullifies an existing
        // attribution and never 422s (attribution must not dead-letter a sync).
        $resolved = null;
        if ($sessionId !== null
            && TillSession::whereKey($sessionId)->where('branch_id', $device->branch_id)->exists()) {
            $resolved = $sessionId;
        }

        // Idempotent: only write when it resolves AND actually changes.
        if ($resolved !== null && (string) $record->till_session_id !== (string) $resolved) {
            $record = $this->paymentService->attributeTillSession($record, $resolved);
            TillSession::find($resolved)?->logAudit('till_session.gap_claim_sync', [
                'payment_id' => $record->id,
                'source' => 'workstation',
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $record->id,
                'till_session_id' => $record->fresh()->till_session_id,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/workstation/payments/{payment}/fail',
        summary: 'Mark a pending payment as failed',
        description: 'Used when a terminal/auto-confirm method reports an unrecoverable error.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Marked failed'),
            new OA\Response(response: 404, description: 'Payment not found in this branch'),
            new OA\Response(response: 409, description: 'Payment not in pending state'),
        ],
    )]
    public function fail(Request $request, string $payment): JsonResponse
    {
        $device = $request->attributes->get('device');

        $record = OrderPayment::where('id', $payment)
            ->where('branch_id', $device->branch_id)
            ->where('organization_id', $device->organization_id)
            ->first();

        if (! $record) {
            abort(404);
        }

        // M12 (#555) — delegate to the locked service mutator so a concurrent
        // confirm() can't be stomped succeeded → failed (see fail() docblock).
        $record = $this->paymentService->fail($record);

        return response()->json([
            'data' => [
                'id' => $record->id,
                'status' => PaymentPollStatus::forWorkstationPoll($record->status),
            ],
        ]);
    }
}
