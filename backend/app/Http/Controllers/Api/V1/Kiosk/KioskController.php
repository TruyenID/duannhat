<?php

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\KioskOrderResource;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Table;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Audit\AuditLogWriter;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\OrderPaymentService;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Commands\PromoteOrderForPaymentCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Enums\OrderSplitMode;
use App\Services\Payment\Policy\Admin\PaymentPolicyEvaluationService;
use App\Services\Payment\Policy\Admin\PosEffectivePaymentOptionEnricher;
use App\Support\PaymentPollStatus;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class KioskController extends Controller
{
    public function __construct(
        private readonly OrderPaymentService $paymentService,
        private readonly OrderMutationFacade $orders,
        private readonly PaymentPolicyEvaluationService $paymentPolicyEvaluation,
        private readonly PosEffectivePaymentOptionEnricher $posEnricher,
    ) {}

    /**
     * Serialize a kiosk order through the one shared resource, preserving zero
     * fractions so JS reads numeric money fields consistently.
     */
    private function kioskOrderResponse(Request $request, CustomerOrder $order, Device $device, ?string $tableId, ?string $tableName): JsonResponse
    {
        $resource = new KioskOrderResource(
            $order,
            $device->branch?->currency ?? 'JPY',
            $tableId,
            $tableName,
        );

        // JSON_PRESERVE_ZERO_FRACTION so JS reads 2500.0 (not 2500) — matches
        // the long-standing kiosk numeric-money contract.
        return response()->json(['data' => $resource->resolve($request)], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * GET /kiosk/me — device info + branch info.
     */
    public function me(Request $request): DeviceResource
    {
        $device = $this->getDevice($request);
        $device->load(['branch', 'organization']);

        return new DeviceResource($device);
    }

    #[OA\Get(
        path: '/api/v1/kiosk/orders',
        summary: 'Get active order for a table or by order code',
        description: 'Returns the active order for the given table (via table_id) or by order code (via code). Only one lookup parameter should be provided. The order must be in an active status (open, dining, checkout, paying) and belong to the device\'s branch.',
        tags: ['Kiosk'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'table_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'), description: 'UUID of the table to look up'),
            new OA\Parameter(name: 'code', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 50), description: 'Order code (e.g. ORD-2026-0001)'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Order data or null if no active order found',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'table_id', type: 'string', format: 'uuid', nullable: true),
                        new OA\Property(property: 'table_name', type: 'string', nullable: true),
                        new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'product_name', type: 'string', nullable: true),
                            new OA\Property(property: 'sku_code', type: 'string', nullable: true),
                            new OA\Property(property: 'quantity', type: 'number', format: 'float'),
                            new OA\Property(property: 'unit_price', type: 'number', format: 'float'),
                            new OA\Property(property: 'image_url', type: 'string', nullable: true),
                        ])),
                        new OA\Property(property: 'subtotal', type: 'number', format: 'float'),
                        new OA\Property(property: 'discount', type: 'number', format: 'float'),
                        new OA\Property(property: 'tax_amount', type: 'number', format: 'float'),
                        new OA\Property(property: 'service_charge', type: 'number', format: 'float'),
                        new OA\Property(property: 'total', type: 'number', format: 'float'),
                        new OA\Property(property: 'paid_amount', type: 'number', format: 'float'),
                        new OA\Property(property: 'currency', type: 'string'),
                    ], type: 'object', nullable: true),
                ]),
            ),
            new OA\Response(response: 404, description: 'Table not found in this branch'),
            new OA\Response(response: 422, description: 'Validation error — missing both table_id and code'),
        ],
    )]
    /**
     * GET /kiosk/orders — active orders for a table or by order code.
     *
     * Query params (one of):
     *   - table_id (uuid): UUID of the table
     *   - code (string): Order code (e.g. ORD-2026-0001)
     */
    public function orders(Request $request): JsonResponse
    {
        $request->validate([
            'table_id' => ['required_without:code', 'uuid'],
            'code' => ['required_without:table_id', 'string', 'max:50'],
        ]);

        $device = $this->getDevice($request);
        $device->loadMissing('branch');

        if ($request->has('code')) {
            return $this->orderByCode($request, $device);
        }

        return $this->orderByTable($request, $device);
    }

    private function orderByTable(Request $request, Device $device): JsonResponse
    {
        $table = Table::where('id', $request->table_id)
            ->where('branch_id', $device->branch_id)
            ->first();

        if (! $table) {
            abort(404);
        }

        if (! $table->current_order_id) {
            return response()->json(['data' => null]);
        }

        $order = CustomerOrder::where('id', $table->current_order_id)
            ->where('branch_id', $device->branch_id)
            ->where('organization_id', $device->organization_id)
            ->active()
            ->with([
                'items.productSku.product' => fn ($q) => $q->withTrashed()->with(['galleryFirst', 'thumbnail', 'translations']),
                // Topping snapshots → KioskOrderResource exposes `extras[]`
                // (label, price). Without this chain the resource triggers
                // an N+1 per item per topping.
                'items.orderItemToppings.toppingGroupItem.product',
                'items.orderItemToppings.toppingGroupItem.toppingGroup',
            ])
            ->first();

        if (! $order) {
            return response()->json(['data' => null]);
        }

        return $this->kioskOrderResponse($request, $order, $device, $table->id, $table->name ?? $table->code);
    }

    private function orderByCode(Request $request, Device $device): JsonResponse
    {
        // Kiosk pickup lookup must include `pending` + `confirmed` orders —
        // takeaway customer-confirmed flow (Option A2) creates orders with
        // status `confirmed` ngay khi customer commit ở /order-confirm.
        // Model `scopeActive()` excludes các status này by design (dine-in
        // callers don't want to surface unstarted orders), nên whitelist
        // explicit ở đây.
        $order = CustomerOrder::where('order_code', $request->code)
            ->where('branch_id', $device->branch_id)
            ->where('organization_id', $device->organization_id)
            ->whereIn('status', [
                CustomerOrderStatusEnum::Pending,
                CustomerOrderStatusEnum::Confirmed,
                CustomerOrderStatusEnum::Open,
                CustomerOrderStatusEnum::Dining,
                CustomerOrderStatusEnum::Checkout,
                CustomerOrderStatusEnum::Paying,
                // `Closed` is whitelisted so the kiosk's post-payment
                // refreshOrder() still resolves the order on the FINAL
                // by_items split payment — the one that fully pays + closes
                // the order. Without it the lookup returned `{data:null}`,
                // refreshOrder kept the stale (pre-final-payment) snapshot,
                // the flow never saw remaining==0 (isComplete stayed false),
                // and the kiosk errored / looped to an all-claimed picker at
                // the final completion stage. A fresh scan of an already-paid
                // order now also resolves → bill.tsx shows "đã thanh toán"
                // instead of "không tìm thấy".
                CustomerOrderStatusEnum::Closed,
            ])
            ->with([
                'items.productSku.product' => fn ($q) => $q->withTrashed()->with(['galleryFirst', 'thumbnail', 'translations']),
                // Mirror orderByTable so by-code reads serialize `extras[]`
                // without N+1.
                'items.orderItemToppings.toppingGroupItem.product',
                'items.orderItemToppings.toppingGroupItem.toppingGroup',
            ])
            ->first();

        if (! $order) {
            return response()->json(['data' => null]);
        }

        return $this->kioskOrderResponse($request, $order, $device, $order->table_id, $order->table?->name ?? $order->table?->code);
    }

    /**
     * GET /kiosk/effective-payment-options — device-effective policy snapshot.
     */
    public function effectivePaymentOptions(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $this->getDevice($request);
        $device->loadMissing('branch');
        $branch = $device->branch;
        abort_if($branch === null, 404, 'Device branch not found.');

        $channel = self::channelForDevice($device);
        $evaluation = $this->paymentPolicyEvaluation->effectiveOptions(
            $branch,
            (string) $device->id,
            $channel,
        );

        // Internal tenders (cash via 釣銭機, card_terminal) have no connection, so
        // the policy resolver never produces a candidate for them — the enricher
        // is the ONLY path by which they reach a device. Which channel may use
        // which tender is answered by the CATALOG (`payment_gateway_options.
        // channels`), and `internalTenderOptions()` already filters on it.
        //
        // #1085 additionally hard-coded `channel === SelfRegi` here. That second
        // rule contradicted the first: the catalog certifies `internal.cash.v1`
        // for `kiosk`, yet the branch above dropped it, so an order-taking kiosk
        // could not offer cash at all — and since it has since moved entirely to
        // this endpoint (plan-047 T6.2), it ended up with ZERO usable options and
        // a disabled pay button (#1449).
        //
        // One source of truth: the catalog decides. A brand that does not want
        // cash at its kiosks removes `kiosk` from that option's `channels` — a
        // data change, no deploy. Self-regi still gets card_terminal because the
        // catalog lists it there and kiosk is deliberately absent from it.
        $evaluation = $this->posEnricher->enrichEvaluation($evaluation, $branch, $channel);

        return response()->json(['data' => $evaluation]);
    }

    /**
     * #1085 — a self-checkout register shares the kiosk SURFACE but resolves
     * its OWN capability channel (cash via 釣銭機 + card_present by default,
     * distinct from an order-taking kiosk that may be cashless-only).
     */
    private static function channelForDevice(Device $device): PaymentChannelEnum
    {
        $type = $device->type instanceof \BackedEnum ? $device->type->value : (string) $device->type;

        return $type === 'self_regi' ? PaymentChannelEnum::SelfRegi : PaymentChannelEnum::Kiosk;
    }

    /**
     * POST /kiosk/payments — submit a payment for an order.
     */
    public function pay(Request $request): JsonResponse
    {
        // Kiosk client (use-payment.ts) JSON-encodes `metadata` as a string
        // because the workstation Go backend's `CreatePaymentInput.metadata`
        // is typed `string`. When the request falls through to this Laravel
        // controller instead (workstation undiscoverable, cloud fallback),
        // the validator below expects an array — silently rejects the wire
        // string and kiosk shows a generic red error. Normalize first.
        if (is_string($request->input('metadata'))) {
            $decoded = json_decode((string) $request->input('metadata'), true);
            if (is_array($decoded)) {
                $request->merge(['metadata' => $decoded]);
            }
        }

        $request->validate([
            'order_id' => ['required', 'uuid'],
            // Kiosk sends `payment_method`; older clients send `method`. Accept
            // either (exactly one required) and normalise below.
            'method' => ['required_without:payment_method', 'string', 'max:50'],
            'payment_method' => ['required_without:method', 'string', 'max:50'],
            'amount' => ['required', 'integer', 'min:1', 'max:99999999'],
            'tendered_amount' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'metadata' => ['nullable', 'array'],
            // Kiosk gửi `metadata.split_mode` cho mọi luồng counter-pay.
            //
            // #2860 — luật sinh TỪ enum. Chỗ này từng gõ tay tập thứ BA
            // (`equal,by_people,by_items,custom`), khác cả tập của
            // `OrderPaymentStoreRequest` lẫn tập của `/split-mode`; ba tập cùng
            // tồn tại nhiều tháng và không gì đỏ. Rào
            // `SplitModeVocabularyIsSingleTest` bắt được đúng dòng này.
            //
            // Giá trị ngoài tập bị 422, và kiosk chỉ hiện toast "Thanh toán thất
            // bại" chung chung, không để lại dấu ở log BE — nên một tập bị siết
            // nhầm ở đây trông giống hệt lỗi mạng (cùng bẫy với luật integer của
            // `expected_total_amount` ở trên).
            'metadata.split_mode' => ['nullable', 'string', OrderSplitMode::validationRule()],
            'metadata.bill_index' => ['nullable', 'integer', 'min:0'],
            'metadata.total_bills' => ['nullable', 'integer', 'min:1'],
            'metadata.label' => ['nullable', 'string', 'max:50'],
            // numeric (not integer) — kiosk computes this from order.total
            // which carries tax/service-charge decimals (e.g. 2297.5). Integer
            // rule rejected the wire value silently → 422 → "thanh toán không
            // được" with no backend trace.
            'metadata.expected_total_amount' => ['nullable', 'numeric', 'min:1'],
            'gateway_option_id' => ['nullable', 'uuid', 'exists:payment_gateway_options,id'],
            'gateway_connection_id' => ['nullable', 'uuid', 'exists:payment_gateway_connections,id'],
            'policy_revision' => ['required_with:gateway_option_id', 'integer', 'min:1'],
        ]);

        $device = $this->getDevice($request);
        $device->loadMissing('branch');
        $branch = $device->branch;

        if (! $branch) {
            abort(422, 'Device is not associated with an active branch.');
        }

        // Scope: order must belong to this branch + organization
        $order = CustomerOrder::where('id', $request->order_id)
            ->where('branch_id', $device->branch_id)
            ->where('organization_id', $device->organization_id)
            ->first();

        if (! $order) {
            abort(404);
        }

        // Kiosk initiates checkout — transition order to checkout if still open/dining
        $this->orders->promoteForPayment(new PromoteOrderForPaymentCommand(
            new MutationContext(
                (string) $device->organization_id,
                (string) $device->id,
                'kiosk-pay:'.$order->id,
                $request->header('Idempotency-Key') ?? (string) Str::uuid(),
                expectedVersion: 1,
            ),
            (string) $order->id,
        ));
        $order->refresh();

        $methodCode = (string) ($request->input('payment_method') ?? $request->input('method'));
        $paymentMethod = $this->posEnricher->resolveMethodByCode(
            $methodCode,
            (string) $device->organization_id,
            (string) $device->branch_id,
        ) ?? abort(422, 'Payment method not available');

        // Cash-style methods require the tendered amount up front so the
        // service can derive change. Guard here for a clean 422 (the service
        // otherwise throws a 500-level InvalidArgumentException).
        if ($paymentMethod->requires_tendered) {
            $tendered = $request->input('tendered_amount');
            if ($tendered === null || (int) $tendered < (int) $request->amount) {
                abort(422, 'Tendered amount must be provided and must be >= payment amount.');
            }
        }

        $payment = $this->paymentService->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $paymentMethod->id,
            'amount' => (float) $request->amount,
            'tendered_amount' => $request->filled('tendered_amount')
                ? (float) $request->input('tendered_amount')
                : null,
            'received_by_id' => $device->id,
            'organization_id' => $device->organization_id,
            'branch_id' => $device->branch_id,
            'brand_id' => $branch->brand->id,
            'idempotency_key' => $request->header('Idempotency-Key'),
            'metadata' => $request->input('metadata'),
            'orchestrator_transport' => self::channelForDevice($device)->value === 'self_regi' ? 'self_regi' : 'kiosk',
            'device_id' => (string) $device->id,
            'gateway_option_id' => $request->input('gateway_option_id'),
            'gateway_connection_id' => $request->input('gateway_connection_id'),
            'policy_revision' => $request->input('policy_revision'),
        ]);

        return response()->json([
            'data' => [
                'payment_id' => $payment->id,
                'reference_no' => $payment->payment_code,
                'status' => $payment->status->value,
                'method' => $paymentMethod->code,
                'qr_url' => null,
                'amount_paid' => (float) $payment->amount,
                'expires_at' => $payment->expires_at?->toIso8601String(),
                'confirm_type' => $paymentMethod->is_auto_confirm ? 'auto' : 'manual',
            ],
        ], 201, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * GET /kiosk/payments/{id}/status — poll payment status.
     */
    public function paymentStatus(Request $request, string $id): JsonResponse
    {
        $device = $this->getDevice($request);

        $payment = OrderPayment::where('id', $id)
            ->where('branch_id', $device->branch_id)
            ->where('organization_id', $device->organization_id)
            ->first();

        if (! $payment) {
            abort(404);
        }

        $kioskStatus = PaymentPollStatus::forKioskPoll($payment->status);

        return response()->json([
            'data' => [
                'id' => $payment->id,
                'status' => $kioskStatus,
            ],
        ]);
    }

    /**
     * POST /kiosk/payments/{payment}/confirm — confirm a pending payment.
     *
     * Used by manual-confirm methods (cash, e-money) where the kiosk records
     * the payment as pending then the device confirms after physical settlement.
     * Delegates to OrderPaymentService::confirm() which flips status to
     * 'succeeded' and stamps paid_at.
     */
    public function confirmPayment(Request $request, string $payment): JsonResponse
    {
        $terminal = $request->validate([
            'terminal_ref' => ['nullable', 'string', 'max:255'],
            'terminal_data' => ['nullable', 'array'],
        ]);

        $device = $this->getDevice($request);

        $record = OrderPayment::where('id', $payment)
            ->where('branch_id', $device->branch_id)
            ->where('organization_id', $device->organization_id)
            ->first();

        if (! $record) {
            abort(404);
        }

        // Persist the terminal settlement reference/payload onto the payment
        // for reconciliation before flipping to succeeded.
        $record = $this->paymentService->mergeMetadata($record, [
            'terminal_ref' => $terminal['terminal_ref'] ?? null,
            'terminal_data' => $terminal['terminal_data'] ?? null,
        ]);

        $record = $this->paymentService->confirm($record);

        return response()->json([
            'data' => [
                'id' => $record->id,
                'status' => $record->status->value,
                'paid_at' => $record->paid_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /kiosk/payments/{payment}/fail — mark a pending payment failed.
     *
     * Used when a terminal/auto-confirm method reports an unrecoverable error
     * (card declined, timeout, wallet cancel). Flips status to 'failed' so the
     * idempotency key can't be reused and the inbox shows the failure.
     */
    public function failPayment(Request $request, string $payment): JsonResponse
    {
        $input = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'error_code' => ['nullable', 'string', 'max:50'],
            'terminal_ref' => ['nullable', 'string', 'max:255'],
            'terminal_data' => ['nullable', 'array'],
        ]);

        $device = $this->getDevice($request);

        $record = OrderPayment::where('id', $payment)
            ->where('branch_id', $device->branch_id)
            ->where('organization_id', $device->organization_id)
            ->first();

        if (! $record) {
            abort(404);
        }

        // M12 (#555) — delegate to the locked service mutator. The failure
        // context is merged under the same row lock as the status flip so a
        // concurrent confirm() can't be stomped succeeded → failed.
        $record = $this->paymentService->fail($record, [
            'failure_reason' => $input['reason'] ?? null,
            'error_code' => $input['error_code'] ?? null,
            'terminal_ref' => $input['terminal_ref'] ?? null,
            'terminal_data' => $input['terminal_data'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'id' => $record->id,
                'status' => $record->status->value,
                'reason' => $input['reason'] ?? null,
                'error_code' => $input['error_code'] ?? null,
            ],
        ]);
    }

    /**
     * POST /kiosk/audit-logs — kiosk client emits a structured audit event.
     *
     * Closes the PCI-DSS Req 10.2 gap: the device knows its own lifecycle
     * (payment.initiated → submitted → confirmed/failed, crash recovery,
     * receipt print). Without this endpoint a kiosk crashing mid-payment
     * leaves no trail beyond the cloud's own payment-record state, so
     * reconciliation can't tell whether the device ever saw the result.
     *
     * Body:
     *   - event (required, string ≤100): "payment.initiated", "payment.crash", etc.
     *   - auditable_type (required): MUST be a registered morph alias
     *     (`OrderPayment`, `CustomerOrder`, `Device`, ...). Validating
     *     against the live morph map prevents storing rows that can't
     *     resolve via Eloquent's `morphTo` later AND blocks forgery of
     *     unknown classes. Use `Device` + the device's own id as
     *     fallback when the event isn't payment-anchored (e.g. crash).
     *   - auditable_id (required, string ≤36): resource id. NOT enforced
     *     as UUID because the kiosk crash helper sends "unknown" when
     *     no payment_id is in flight — accepting that string keeps
     *     crash-trail intact while still bounding column width.
     *   - metadata (optional, array): event-specific context. Hard
     *     ceiling at 16 KB JSON-encoded so a runaway stack trace
     *     can't blow the audit_logs table. Server runs a basic PAN
     *     deny-list (13–19 consecutive digits) so a misbehaving
     *     kiosk-side log statement can't accidentally store card
     *     numbers in cloud audit rows — PCI requires the cloud audit
     *     surface to stay PAN-free.
     *
     * Throttled by `kiosk-audit` named limiter (60/min/device_id) —
     * see AppServiceProvider for the device-keyed bucket. Two kiosks
     * behind the same branch NAT do NOT share the budget.
     */
    public function auditLog(Request $request): JsonResponse
    {
        // The kiosk emits logical names (Payment / Order / Terminal); map them
        // onto the registered morph aliases so the stored auditable_type still
        // resolves via Eloquent morphTo. Genuinely-unknown types still 422 —
        // this only widens the accepted set, it doesn't drop validation.
        $typeAliases = [
            'Payment' => 'OrderPayment',
            'Order' => 'CustomerOrder',
            'Terminal' => 'Device',
        ];
        $morphAliases = array_keys(Relation::morphMap());
        $acceptedTypes = array_values(array_unique([...$morphAliases, ...array_keys($typeAliases)]));

        $payload = $request->validate([
            'event' => ['required', 'string', 'max:100'],
            'auditable_type' => ['required', 'string', Rule::in($acceptedTypes)],
            'auditable_id' => ['required', 'string', 'max:36'],
            'metadata' => ['nullable', 'array'],
        ]);

        $payload['auditable_type'] = $typeAliases[$payload['auditable_type']] ?? $payload['auditable_type'];

        // Hard size cap — runaway stack traces can be 100 KB+; PCI
        // log retention with multi-year horizon means even tiny rows
        // accumulate. 16 KB is generous for legit events.
        $jsonLen = strlen(json_encode($payload['metadata'] ?? new \stdClass, JSON_UNESCAPED_UNICODE));
        if ($jsonLen > 16 * 1024) {
            throw ValidationException::withMessages([
                'metadata' => 'metadata payload too large (16 KB max)',
            ]);
        }

        // PAN deny-list: any unbroken 13–19 digit run looks like a
        // card number. Defensive — kiosks should never log PAN, but
        // a misbehaving client code path could leak via debug
        // metadata. Cloud audit_logs MUST stay PAN-free (PCI scope
        // expansion otherwise).
        $metadataAsString = json_encode($payload['metadata'] ?? [], JSON_UNESCAPED_UNICODE);
        if (preg_match('/\b\d{13,19}\b/', $metadataAsString)) {
            throw ValidationException::withMessages([
                'metadata' => 'metadata contains a digit sequence that looks like a card number',
            ]);
        }

        $device = $this->getDevice($request);

        // device_id captured into metadata so the audit row identifies
        // which kiosk submitted the event — `user_id` stays null because
        // the kiosk is a device, not a user. The overlay is the SECOND
        // arg to array_merge so a hostile client that submits its own
        // `device_id` in metadata gets overwritten by the authenticated
        // device id (verified by KioskAuditLogTest::client_cannot_forge_device_id).
        $metadata = array_merge(
            $payload['metadata'] ?? [],
            [
                'device_id' => $device->id,
                'branch_id' => $device->branch_id,
                'source' => 'kiosk',
            ],
        );

        // `recordOrFail`, KHÔNG phải `record`: endpoint này không có nghiệp vụ
        // nào khác ngoài dòng nó vừa ghi, và nó trả `id` + `recorded_at` của
        // dòng đó. Nuốt lỗi là trả 201 kèm một id không tồn tại (#1666).
        //
        // `user_id` NULL vì tác nhân là một THIẾT BỊ, không phải người; danh
        // tính thiết bị đi trong metadata ngay trên.
        //
        // AuditLogBaseModel dùng HasUuids → id tự sinh, và created_at có ngay
        // sau create() nên không cần `?->`.
        $log = app(AuditLogWriter::class)->recordOrFail(
            (string) $payload['auditable_type'],
            (string) $payload['auditable_id'],
            (string) $payload['event'],
            null,
            $metadata,
        );

        return response()->json([
            'data' => [
                'id' => $log->id,
                'recorded_at' => $log->created_at->toIso8601String(),
            ],
        ], 201);
    }

    private function getDevice(Request $request): Device
    {
        return $request->attributes->get('device');
    }

    /**
     * Plan 033 — by-items preview, kiosk surface. Scope check: the order
     * must belong to the kiosk device's branch.
     */
    #[OA\Get(
        path: '/api/v1/kiosk/orders/{customerOrder}/split-by-items/preview',
        summary: 'Kiosk: by-items split preview (read-only).',
        tags: ['Kiosk'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'allocations', in: 'query', required: false, description: 'URL-encoded JSON array of candidate allocations.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Preview shape'),
            new OA\Response(response: 403, description: 'Order not in kiosk branch'),
            new OA\Response(response: 404, description: 'Order not found'),
        ]
    )]
    public function splitByItemsPreview(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $device = $this->getDevice($request);
        if ((string) $customerOrder->branch_id !== (string) $device->branch_id) {
            abort(403, 'Order does not belong to this kiosk branch.');
        }

        $candidate = $this->parseAllocationsQuery($request);
        $result = app(CustomerOrderService::class)
            ->splitByItemsPreview($customerOrder, $candidate);

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
