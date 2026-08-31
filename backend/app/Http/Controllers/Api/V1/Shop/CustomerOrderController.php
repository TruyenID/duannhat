<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Events\OrderEditingEnded;
use App\Events\OrderEditingStarted;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\ApplyCouponRequest;
use App\Http\Requests\CustomerOrderInitRequest;
use App\Http\Requests\CustomerOrderStoreRequest;
use App\Http\Requests\CustomerOrderUpdateRequest;
use App\Http\Requests\OrderCheckoutRequest;
use App\Http\Requests\RefundOrderItemRequest;
use App\Http\Resources\CustomerOrderResource;
use App\Models\CouponRedemption;
use App\Models\CustomerOrder;
use App\Omnify\Enums\CustomerOrderTypeEnum;
use App\Services\Customer\CustomerOrderService;
use App\Services\Order\Commands\ApplyOrderCouponCommand;
use App\Services\Order\Commands\ApproveOrderItemRefundCommand;
use App\Services\Order\Commands\ChangeOrderItemsBatchCommand;
use App\Services\Order\Commands\ChangeOrderItemsCommand;
use App\Services\Order\Commands\CheckoutOrderCommand;
use App\Services\Order\Commands\ConfirmOrderCommand;
use App\Services\Order\Commands\ContinueTableOrderCommand;
use App\Services\Order\Commands\InitializeOrderCommand;
use App\Services\Order\Commands\MergeOrderTablesCommand;
use App\Services\Order\Commands\RemoveOrderItemCommand;
use App\Services\Order\Commands\ReopenOrderCommand;
use App\Services\Order\Commands\ReviseOrderHeaderCommand;
use App\Services\Order\Commands\SetStaffEditLockCommand;
use App\Services\Order\Commands\UnmergeOrderTableCommand;
use App\Services\Order\Commands\VoidOrderCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Coupon\OrderCouponService;
use App\Services\Order\Enums\OrderItemMutation;
use App\Services\Order\Internal\OrderMutationContextFactory;
use App\Services\Order\ValueObjects\OrderHeaderPatch;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use App\Services\Order\ValueObjects\OrderSelectionPayload;
use App\Services\Order\ValueObjects\OrderTableSetPayload;
use App\Services\Order\ValueObjects\OrderToppingSelectionPayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class CustomerOrderController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly CustomerOrderService $service,
        private readonly OrderMutationFacade $orders,
    ) {}

    // =========================================================================
    //  Plan-021 — POS: Continue table order (auto-close + create new)
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/orders/continue-table',
        summary: 'Auto-close old order and create new order for table',
        description: 'POS endpoint: if table has an active order (open/dining/checkout), close it first, then create a new order with items. Idempotent — safe to call even if table is already free.',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['table_ids', 'items'],
                properties: [
                    new OA\Property(property: 'table_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), description: 'Table IDs to assign to new order'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                        required: ['product_sku_id', 'quantity'],
                        properties: [
                            new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'menu_product_sku_id', type: 'string', format: 'uuid', nullable: true),
                            new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                            new OA\Property(property: 'note', type: 'string', nullable: true),
                        ]
                    )),
                    new OA\Property(property: 'order_type', type: 'string', enum: ['spot', 'dine_in', 'takeaway'], default: 'dine_in'),
                    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'guest_count', type: 'integer', minimum: 1, nullable: true),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'New order created (old order closed if exists)', content: new OA\JsonContent(ref: '#/components/schemas/CustomerOrder')),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function continueTable(CustomerOrderStoreRequest $request): JsonResponse
    {
        $this->authorize('create', CustomerOrder::class);

        // Validate items array (not in base request)
        $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_sku_id' => ['required', 'uuid', 'exists:product_skus,id'],
            'items.*.menu_product_sku_id' => ['nullable', 'uuid', 'exists:menu_product_skus,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.note' => ['nullable', 'string'],
        ]);

        $validated = $request->validated();

        // Legacy contract: continue-table without tables is a 422, not a 500.
        if (empty($validated['table_ids'])) {
            abort(422, 'table_ids is required for continue-table operation.');
        }

        // plan-047 T2.12 (#1090) — take-over through the typed facade. The
        // persistence funnel keeps the #554 close-vs-void decision, coupon
        // release, and table rebinding on the legacy single writer.
        $payload = new OrderSelectionPayload(
            lines: array_map(static fn (array $item): OrderLineSelectionPayload => new OrderLineSelectionPayload(
                (string) Str::uuid(),
                $item['menu_product_sku_id'] ?? null,
                (int) $item['quantity'],
                note: $item['note'] ?? null,
                productSkuId: $item['product_sku_id'],
            ), $request->input('items', [])),
            orderType: CustomerOrderTypeEnum::from($validated['order_type'] ?? CustomerOrderTypeEnum::DineIn->value),
            customerId: $validated['customer_id'] ?? null,
            guestCount: $validated['guest_count'] ?? null,
            tableIds: array_map(strval(...), $validated['table_ids']),
            note: $validated['note'] ?? null,
        );

        $result = $this->orders->continueTable(new ContinueTableOrderCommand(
            OrderMutationContextFactory::forStaffCreate($this->getOrganizationId(), (string) $request->user()->id),
            (string) $request->attributes->get('shop_id'),
            $payload,
            $payload->fingerprint(),
        ));

        return response()->json(new CustomerOrderResource($this->service->findById($result->aggregateId)));
    }

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/orders',
        summary: 'List branch orders',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'dining', 'checkout', 'paying', 'closed', 'voided'])),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Search by order code, customer name, or phone'),
            new OA\Parameter(name: 'order_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['spot', 'dine_in', 'takeaway'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated order list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerOrder')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        // Device-authenticated requests (POS) skip the user-policy check —
        // scope is validated by AuthenticateDevice + ResolvePosShop.
        if (! app()->bound('current_device')) {
            $this->authorize('viewAny', CustomerOrder::class);
        }

        $orders = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'branch_id' => $request->attributes->get('shop_id'),
            'status' => $request->input('status'),
            'order_type' => $request->input('order_type'),
            // Per-table history (POS): filter to orders linked to this table via
            // the tables pivot. CustomerOrderService::list applies it through
            // whereHas('tables').
            'table_id' => $request->input('table_id'),
            'search' => $request->input('search'),
            'customer_id' => $request->input('customer_id'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return CustomerOrderResource::collection($orders);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/orders',
        summary: 'Create an order header (no items)',
        description: 'Creates a new order header. Items are added separately via POST /orders/{id}/items. Status is always open. order_type defaults to spot when null.',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'order_type', type: 'string', enum: ['spot', 'dine_in', 'takeaway'], nullable: true, description: 'Defaults to spot when null'),
                new OA\Property(property: 'customer_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'table_ids', type: 'array', nullable: true, items: new OA\Items(type: 'string', format: 'uuid'), description: 'Tables to assign. If provided, all must be free or reserved.'),
                new OA\Property(property: 'guest_count', type: 'integer', nullable: true, minimum: 1),
                new OA\Property(property: 'note', type: 'string', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Order created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(CustomerOrderStoreRequest $request): JsonResponse
    {
        $this->authorize('create', CustomerOrder::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['branch_id'] = $request->attributes->get('shop_id');
        $data['brand_id'] = $request->attributes->get('brand_id');
        $data['created_by_id'] = $request->user()->id;

        $order = $this->service->create($data);

        return (new CustomerOrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}',
        summary: 'Show order detail with items, payments, and tables',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order detail', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request, CustomerOrder $customerOrder): CustomerOrderResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('view', $customerOrder);

        $order = $this->service->findById($customerOrder->id);

        return new CustomerOrderResource($order);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}',
        summary: 'Delete order',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
        ]
    )]
    public function destroy(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('delete', $customerOrder);

        $this->service->delete($customerOrder);

        return response()->json(null, 204);
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}',
        summary: 'Update order header (general, last-write-wins)',
        description: 'Update order header fields. Only works on open orders. Overwrites existing values.',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'guest_count', type: 'integer', nullable: true, minimum: 1),
                new OA\Property(property: 'note', type: 'string', nullable: true),
                new OA\Property(property: 'customer_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'order_type', type: 'string', enum: ['spot', 'dine_in', 'takeaway'], nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Order updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 409, description: 'Order is past open status'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(CustomerOrderUpdateRequest $request, CustomerOrder $customerOrder): CustomerOrderResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('update', $customerOrder);

        // plan-047 T2.12 (#1090) — header revision through the facade. The
        // patch carries order_type so the plan-043 tax re-resolve still fires
        // on a takeaway/dine-in flip.
        $validated = $request->validated();
        $this->orders->reviseHeader(new ReviseOrderHeaderCommand(
            OrderMutationContextFactory::fromOrder($customerOrder, actorId: auth()->id() ? (string) auth()->id() : null),
            (string) $customerOrder->id,
            new OrderHeaderPatch(
                customerId: $validated['customer_id'] ?? null,
                guestCount: $validated['guest_count'] ?? null,
                note: $validated['note'] ?? null,
                orderType: isset($validated['order_type']) ? CustomerOrderTypeEnum::from($validated['order_type']) : null,
            ),
        ));

        return new CustomerOrderResource($customerOrder->refresh());
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/init',
        summary: 'Update after init (first-write-wins)',
        description: 'Assign tables and guest_count to an open order. Tables are only assigned if the order has no tables yet. Guest count is only set if the DB value is null. Idempotent — safe to retry.',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'table_ids', type: 'array', nullable: true, items: new OA\Items(type: 'string', format: 'uuid'), description: 'Only processed if order has no tables yet'),
                new OA\Property(property: 'guest_count', type: 'integer', nullable: true, minimum: 1, description: 'Only saved if DB value is currently null'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Order init updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 409, description: 'Order is not in open status'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function init(CustomerOrderInitRequest $request, CustomerOrder $customerOrder): CustomerOrderResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('update', $customerOrder);

        // plan-047 T2.12 (#1090) — the command now CARRIES the payload
        // (table_ids + guest_count); legacy initOrder keeps first-write-wins.
        $validated = $request->validated();
        $this->orders->initialize(new InitializeOrderCommand(
            OrderMutationContextFactory::fromOrder($customerOrder, actorId: auth()->id() ? (string) auth()->id() : null),
            (string) $customerOrder->id,
            array_map(strval(...), $validated['table_ids'] ?? []),
            $validated['guest_count'] ?? null,
        ));

        return new CustomerOrderResource($customerOrder->refresh());
    }

    // =========================================================================
    //  Workflow
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/confirm',
        summary: 'Confirm a pending order (pending -> open)',
        description: 'Staff acknowledges a customer-submitted takeaway order so it can flow through the regular checkout pipeline. Idempotent: returns 409 if not in `pending` state.',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order confirmed', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 409, description: 'Invalid status transition'),
        ]
    )]
    public function confirm(Request $request, CustomerOrder $customerOrder): CustomerOrderResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('checkout', $customerOrder);

        // plan-047 T2.12 (#1090) — canonical facade; markConfirmed delegates
        // to the same legacy confirmOrder underneath.
        $this->orders->confirm(new ConfirmOrderCommand(
            OrderMutationContextFactory::fromOrder($customerOrder, actorId: auth()->id() ? (string) auth()->id() : null),
            (string) $customerOrder->id,
        ));

        return new CustomerOrderResource($customerOrder->refresh());
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/checkout',
        summary: 'Checkout order (open/dining -> checkout)',
        description: 'Finalize the order for payment. Optionally override discount, service charge, and tax.',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'discount_amount', type: 'number', minimum: 0, nullable: true),
                new OA\Property(property: 'service_charge', type: 'number', minimum: 0, nullable: true),
                new OA\Property(property: 'tax_amount', type: 'number', minimum: 0, nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Order checked out', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 409, description: 'Invalid status transition'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function checkout(OrderCheckoutRequest $request, CustomerOrder $customerOrder): CustomerOrderResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('checkout', $customerOrder);

        // plan-047 T2.12 (#1090) — discount_amount rides the typed command in
        // the same major-unit contract the request validated; null keeps the
        // order's current discount, as legacy did.
        $validated = $request->validated();
        $discount = $validated['discount_amount'] ?? null;
        $this->orders->checkout(new CheckoutOrderCommand(
            OrderMutationContextFactory::fromOrder($customerOrder, actorId: auth()->id() ? (string) auth()->id() : null),
            (string) $customerOrder->id,
            $discount === null ? null : (float) $discount,
            $validated['discount_reason'] ?? null,
        ));

        return new CustomerOrderResource($customerOrder->refresh());
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/void',
        summary: 'Void order',
        description: 'Void the entire order. All non-voided items are also voided. Tables are released.',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['void_reason'],
            properties: [
                new OA\Property(property: 'void_reason', type: 'string', description: 'Reason for voiding the order'),
                new OA\Property(property: 'void_reason_id', type: 'string', format: 'uuid', nullable: true, description: '#1283 — a VoidReason master row of the order\'s brand (active). Drives the plan-051 stock-compensation truth table for every line the void touches. Absent = the conservative branch: a deducted line is never restocked on an unknown reason.'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Order voided', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 409, description: 'Cannot void a closed order'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    /**
     * #2479 — mở lại một bill vừa chốt nhầm (`checkout` → `open`).
     *
     * Uỷ quyền dùng chung ability `checkout`: ai chốt được đơn thì mở lại được.
     * CỐ Ý không dựng ability riêng cấp quản lý — chạm nhầm là chuyện thường ở
     * quầy, và bắt gọi quản lý mỗi lần sẽ đẩy nhân viên sang huỷ-rồi-gõ-lại, một
     * đường để lại dấu vết TỆ HƠN. Lý do bắt buộc + audit là cái kiểm soát.
     */
    public function reopen(Request $request, CustomerOrder $customerOrder): CustomerOrderResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('checkout', $customerOrder);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->orders->reopen(new ReopenOrderCommand(
            OrderMutationContextFactory::fromOrder($customerOrder, actorId: auth()->id() ? (string) auth()->id() : null),
            (string) $customerOrder->id,
            (string) $data['reason'],
        ));

        return new CustomerOrderResource($customerOrder->refresh());
    }

    public function voidOrder(Request $request, CustomerOrder $customerOrder): CustomerOrderResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('void', $customerOrder);

        $request->validate([
            'void_reason' => ['required', 'string'],
            // #1283 — optional VoidReason master row of the order's brand
            // (active). Drives the stock-compensation truth table, so a whole
            // order voided under `on_add`/`on_preparing` timing can return its
            // material instead of losing it silently.
            'void_reason_id' => ['nullable', 'uuid'],
        ]);

        // plan-047 T2.12 (#1090) — canonical facade, same reason string.
        $this->orders->void(new VoidOrderCommand(
            OrderMutationContextFactory::fromOrder($customerOrder, actorId: auth()->id() ? (string) auth()->id() : null),
            (string) $customerOrder->id,
            (string) $request->input('void_reason'),
            $request->input('void_reason_id'),
        ));

        return new CustomerOrderResource($customerOrder->refresh());
    }

    /**
     * plan-034 — POS staff acquires a soft-lock on the order so dine-in
     * customer devices stop adding items while staff fixes things.
     *
     * The lock is timestamp-based (`editing_by_staff_at`) so it
     * auto-expires after 60s if staff's POS crashes / loses network and
     * never calls /end-edit — without that fallback the customer would
     * be locked out forever.
     *
     * Idempotent: re-acquiring just refreshes the timestamp.
     */
    public function startEdit(Request $request, CustomerOrder $customerOrder): CustomerOrderResource
    {
        if (! app()->bound('current_device')) {
            $this->authorizeOrganization($customerOrder);
            $this->authorize('update', $customerOrder);
        }

        $this->orders->setStaffEditLock(new SetStaffEditLockCommand(
            OrderMutationContextFactory::fromOrder($customerOrder),
            $customerOrder->id,
            now(),
        ));

        event(new OrderEditingStarted($customerOrder));

        return new CustomerOrderResource($customerOrder->refresh());
    }

    /**
     * plan-034 — release the soft-lock so customer devices can write
     * again. Idempotent: clearing an already-cleared lock is a no-op.
     */
    public function endEdit(Request $request, CustomerOrder $customerOrder): CustomerOrderResource
    {
        if (! app()->bound('current_device')) {
            $this->authorizeOrganization($customerOrder);
            $this->authorize('update', $customerOrder);
        }

        $this->orders->setStaffEditLock(new SetStaffEditLockCommand(
            OrderMutationContextFactory::fromOrder($customerOrder),
            $customerOrder->id,
            null,
        ));

        event(new OrderEditingEnded($customerOrder));

        return new CustomerOrderResource($customerOrder->refresh());
    }

    // =========================================================================
    //  Items
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/items',
        summary: 'Add one or more items to an open order',
        description: 'Returns the updated order with items + tables loaded and recomputed totals, so POS clients can refresh cart and tab-chip in one round trip.',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['items'],
            properties: [
                new OA\Property(property: 'items', type: 'array', minItems: 1, items: new OA\Items(
                    required: ['product_sku_id', 'quantity'],
                    properties: [
                        new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'menu_product_sku_id', type: 'string', format: 'uuid', nullable: true, description: 'Pin price to a specific menu line override.'),
                        new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                        new OA\Property(property: 'note', type: 'string', nullable: true),
                        // Plan 015 — topping selections per item.
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
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Items added; body is the updated order with items + tables and recomputed totals', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 409, description: 'Order not in open status'),
        ]
    )]
    public function addItem(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('update', $customerOrder);

        $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_sku_id' => ['required', 'string', 'exists:product_skus,id'],
            // Optional: the EXACT menu line the staff picked from, so the
            // service uses the correct override price when the SKU appears
            // in multiple active menus.
            'items.*.menu_product_sku_id' => ['nullable', 'string', 'exists:menu_product_skus,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.note' => ['nullable', 'string'],
            // Plan 015 — topping selections per item. Group-level rules
            // (effective_min/max_select, max_qty_per_item, group_attached) are
            // enforced inside CustomerOrderService::addItems because they
            // depend on the parent product's ProductToppingGroup pivot, which
            // FormRequest validators can't see cleanly.
            'items.*.toppings' => ['nullable', 'array'],
            'items.*.toppings.*.topping_group_item_id' => [
                'required_with:items.*.toppings.*',
                'string',
                'exists:topping_group_items,id',
            ],
            // NOT NULL by Phase 2 contract — every topping product carries at
            // least one default SKU. Frontend resolves it before submit.
            'items.*.toppings.*.product_sku_id' => [
                'required_with:items.*.toppings.*',
                'string',
                'exists:product_skus,id',
            ],
            'items.*.toppings.*.quantity' => ['required_with:items.*.toppings.*', 'integer', 'min:1'],
            'items.*.toppings.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        // plan-047 T2.12 phase 2 (#1090) — each item rides the typed facade.
        // The selection anchors on the explicit menu line when the client sent
        // one, else on the product SKU (the resolver applies the #514
        // lowest-menu-price rule / off-menu fallback, same as legacy). The
        // whole batch is all-or-nothing so a failing line rolls back every line
        // — identical to the legacy batch. #1666: that boundary lives in
        // `changeItemsBatch()` now, not in this endpoint.
        $commands = [];

        foreach ($request->input('items') as $item) {
            $payload = new OrderLineSelectionPayload(
                (string) Str::uuid(),
                $item['menu_product_sku_id'] ?? null,
                (int) $item['quantity'],
                array_map(static fn (array $t) => new OrderToppingSelectionPayload(
                    (string) $t['topping_group_item_id'],
                    (string) $t['product_sku_id'],
                    (int) $t['quantity'],
                    $t['note'] ?? null,
                ), $item['toppings'] ?? []),
                $item['note'] ?? null,
                $item['product_sku_id'],
            );
            $commands[] = new ChangeOrderItemsCommand(
                OrderMutationContextFactory::fromOrder($customerOrder, actorId: auth()->id() ? (string) auth()->id() : null),
                (string) $customerOrder->id,
                OrderItemMutation::Add,
                $payload->fingerprint(),
                $payload,
            );
        }

        $this->orders->changeItemsBatch(new ChangeOrderItemsBatchCommand(
            OrderMutationContextFactory::fromOrder($customerOrder, actorId: auth()->id() ? (string) auth()->id() : null),
            $commands,
        ));

        $order = $this->service->findById($customerOrder->id);

        return (new CustomerOrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/items/{item}',
        summary: 'Update item (SKU/options, quantity, note, status, or toppings)',
        description: 'SKU/options, quantity, note, and toppings can only be changed when item is pending and order is open. A replacement SKU must belong to the same parent product; the server re-resolves its menu price, promotion, tax, and topping snapshots atomically. Status transitions follow the item state machine. Returns the updated order with items + tables loaded and recomputed totals.',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid', description: 'Replacement variant SKU. Must belong to the original line product.'),
                new OA\Property(property: 'menu_product_sku_id', type: 'string', format: 'uuid', nullable: true, description: 'Exact active menu SKU row used to resolve price and menu-level tax.'),
                new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                new OA\Property(property: 'note', type: 'string', nullable: true),
                new OA\Property(property: 'status', type: 'string', enum: ['pending', 'preparing', 'ready', 'served']),
                // Plan 016 — atomic-replace topping selections on a pending line.
                new OA\Property(property: 'toppings', type: 'array', nullable: true, description: 'Plan 016 — atomic replace of OrderItemTopping rows with fresh price snapshots. Pending lines only; rejected on preparing+.', items: new OA\Items(
                    required: ['topping_group_item_id', 'product_sku_id', 'quantity'],
                    properties: [
                        new OA\Property(property: 'topping_group_item_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                        new OA\Property(property: 'note', type: 'string', nullable: true, maxLength: 255),
                    ],
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Item updated; body is the updated order with items + tables and recomputed totals', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 409, description: 'Invalid status transition or order not in valid state (e.g. attempting to edit toppings on a preparing+ line)'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function updateItem(Request $request, CustomerOrder $customerOrder, string $itemId): CustomerOrderResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('update', $customerOrder);

        // #1148 decision — a line's SKU is immutable: a different variant is
        // a different dish, so the only path is void (with reason) + add. The
        // selection keys 409 in the domain writer; reject them loudly here so
        // clients get an actionable message instead of a silent strip.
        if ($request->hasAny(['product_sku_id', 'menu_product_sku_id'])) {
            abort(409, 'Item SKU cannot be edited in place. Void the line and add a new item instead.');
        }

        $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'note' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:pending,preparing,ready,served'],
            // Plan 016 — toppings replace on pending line. Same shape as
            // addItem's per-item toppings; nullable means "clear all".
            'toppings' => ['sometimes', 'nullable', 'array'],
            'toppings.*.topping_group_item_id' => [
                'required_with:toppings.*',
                'string',
                'exists:topping_group_items,id',
            ],
            'toppings.*.product_sku_id' => [
                'required_with:toppings.*',
                'string',
                'exists:product_skus,id',
            ],
            'toppings.*.quantity' => ['required_with:toppings.*', 'integer', 'min:1'],
            'toppings.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        // plan-047 T2.12 phase 2 (#1090) — field router. quantity/note ride
        // the typed Revise command; a request carrying `status` (kitchen
        // lifecycle) or `toppings` (replace) stays on the legacy path until
        // those concerns get their own commands — logged so the residue is
        // measurable before the split.
        $data = $request->only([
            'product_sku_id',
            'menu_product_sku_id',
            'quantity',
            'note',
            'status',
            'toppings',
        ]);
        if (
            ! array_key_exists('status', $data)
            && ! array_key_exists('toppings', $data)
            && ! array_key_exists('product_sku_id', $data)
            && ! array_key_exists('menu_product_sku_id', $data)
        ) {
            $payload = new OrderLineSelectionPayload(
                (string) $itemId,
                null,
                (int) ($data['quantity'] ?? $customerOrder->items()->whereKey($itemId)->value('quantity')),
                [],
                $data['note'] ?? null,
                (string) $customerOrder->items()->whereKey($itemId)->value('product_sku_id'),
            );
            $this->orders->changeItems(new ChangeOrderItemsCommand(
                OrderMutationContextFactory::fromOrder($customerOrder, actorId: auth()->id() ? (string) auth()->id() : null),
                (string) $customerOrder->id,
                OrderItemMutation::Revise,
                $payload->fingerprint(),
                $payload,
                (string) $itemId,
            ));
        } else {
            Log::info('order.update_item.legacy_path', ['keys' => array_keys($data)]);
            $this->service->updateItem($customerOrder, $itemId, $data);
        }

        $order = $this->service->findById($customerOrder->id);

        return new CustomerOrderResource($order);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/items/{item}/void',
        summary: 'Void an item',
        description: 'Void a single item within an open order. Only pending items can be voided. Returns the updated order with items + tables loaded and recomputed totals — the voided item still appears inside data.items[] with status = voided.',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'void_reason', type: 'string', nullable: true, description: 'Free-text reason / note. Required when void_reason_id is absent; also required (as the note) when the picked reason has requires_note=true.'),
                new OA\Property(property: 'void_reason_id', type: 'string', format: 'uuid', nullable: true, description: 'plan-051 — a VoidReason master row of the order\'s brand (active). Drives the stock-compensation truth table (restock/waste/none).'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Item voided; body is the updated order with items + tables and recomputed totals', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 409, description: 'Item status not in the shop\'s voidable matrix (ITEM_STATUS_NOT_VOIDABLE) or order not open'),
            new OA\Response(response: 422, description: 'Validation failed / VOID_REASON_INVALID / VOID_NOTE_REQUIRED / junk reason on a prepared item'),
        ]
    )]
    public function voidItem(Request $request, CustomerOrder $customerOrder, string $itemId): CustomerOrderResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('update', $customerOrder);

        $request->validate([
            // plan-051 — a picked VoidReason may replace the free text; the
            // writer enforces "real reason" (#1148) and requires_note itself.
            'void_reason' => ['required_without:void_reason_id', 'nullable', 'string'],
            'void_reason_id' => ['nullable', 'uuid'],
        ]);

        $this->service->voidItem($customerOrder, $itemId, $request->only('void_reason', 'void_reason_id'));

        $order = $this->service->findById($customerOrder->id);

        return new CustomerOrderResource($order);
    }

    /**
     * plan-045 — refund N units of a line by appending a negative-value line
     * (never mutating the original). Returns the updated order (new refund line,
     * lowered totals, refund condition). Guard failures surface as structured
     * RefundException (422/409). Uses the 'update' ability like voidItem —
     * device-authed POS is already branch-scoped; role gating is a UI concern.
     */
    public function refundItem(RefundOrderItemRequest $request, CustomerOrder $customerOrder, string $itemId): JsonResponse
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('refund', $customerOrder);

        // plan-047 T2.12 (#1090) — canonical facade; quantity stays float so
        // fractional (weight-sold) refunds keep working.
        $this->orders->approveItemRefund(new ApproveOrderItemRefundCommand(
            OrderMutationContextFactory::fromOrder($customerOrder, actorId: auth()->id() ? (string) auth()->id() : null),
            (string) $customerOrder->id,
            (string) $itemId,
            (float) $request->validated('quantity'),
            (string) $request->validated('reason'),
        ));

        return (new CustomerOrderResource($customerOrder->refresh()))->response()->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/items/{item}',
        summary: 'Remove item from open order',
        description: 'Removes an item from an open order (delegates to voidItem internally). Returns the updated order with items + tables loaded and recomputed totals — not 204 No Content — so POS clients refresh cart and tab-chip in one round trip.',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item removed; body is the updated order with items + tables and recomputed totals', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 409, description: 'Order not in open status'),
        ]
    )]
    public function removeItem(Request $request, CustomerOrder $customerOrder, string $itemId): CustomerOrderResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('update', $customerOrder);

        // plan-047 T2.12 (#1090) — canonical facade; same legacy write path.
        $this->orders->removeItem(new RemoveOrderItemCommand(
            OrderMutationContextFactory::fromOrder($customerOrder, actorId: auth()->id() ? (string) auth()->id() : null),
            (string) $customerOrder->id,
            (string) $itemId,
            'shop item removal',
        ));

        $order = $this->service->findById($customerOrder->id);

        return new CustomerOrderResource($order);
    }

    // =========================================================================
    //  Split Bill
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/split-bill',
        summary: 'Calculate split bill amounts',
        description: 'Returns per-person amounts for splitting the bill. Uses guest_count by default, or the split_count query param.',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'split_count', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 2)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Split bill calculation', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'total_amount', type: 'string'),
                new OA\Property(property: 'remaining_amount', type: 'string'),
                new OA\Property(property: 'split_count', type: 'integer'),
                new OA\Property(property: 'per_person_amount', type: 'string'),
                new OA\Property(property: 'per_person_amounts', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'rounding_note', type: 'string', nullable: true),
            ])),
            new OA\Response(response: 409, description: 'Order not in checkout/paying status'),
            new OA\Response(response: 422, description: 'Invalid split count'),
        ]
    )]
    public function splitBill(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('view', $customerOrder);

        $splitCount = $request->input('split_count')
            ? $request->integer('split_count')
            : null;

        $result = $this->service->splitBill($customerOrder, $splitCount);

        return response()->json($result);
    }

    /**
     * Plan 033 — Read-only by-items preview: surfaces `units_claimed`,
     * `units_remaining`, and (optionally) per-bill calculator totals for a
     * candidate allocation passed via `?allocations=<url-encoded JSON>`.
     * Caps URL length to 4 KB; payloads above that drop the `preview_bills`
     * section and return the base remaining-items shape.
     */
    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/split-by-items/preview',
        summary: 'Preview by-items split allocations (read-only).',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'allocations', in: 'query', required: false, description: 'URL-encoded JSON array of candidate allocations.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Preview shape'),
            new OA\Response(response: 403, description: 'Cashier not in shop scope'),
            new OA\Response(response: 404, description: 'Order not found in shop'),
        ]
    )]
    public function splitByItemsPreview(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('view', $customerOrder);

        $candidate = $this->parseAllocationsQuery($request);
        $result = $this->service->splitByItemsPreview($customerOrder, $candidate);

        return response()->json(['data' => $result]);
    }

    /**
     * Decode the optional `?allocations=…` query string. Caps payload size
     * to 4 KB; invalid or oversized payloads return null so the service
     * returns the base remaining-items shape without per-bill preview.
     *
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

    // =========================================================================
    //  Table Management
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/merge-table',
        summary: 'Merge an additional table into the order',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['table_id'],
            properties: [
                new OA\Property(property: 'table_id', type: 'string', format: 'uuid'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Table merged', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 409, description: 'Table already occupied or order not in open status'),
            new OA\Response(response: 422, description: 'Table must be free or reserved'),
        ]
    )]
    public function mergeTable(Request $request, CustomerOrder $customerOrder): CustomerOrderResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('update', $customerOrder);

        $request->validate([
            'table_id' => ['required', 'string', 'exists:tables,id'],
        ]);

        // plan-047 T2.12 (#1090) — the typed command models the RESULTING
        // table set; ids already bound to this order are no-ops on replay.
        $tableSet = new OrderTableSetPayload([
            ...$customerOrder->tables()->pluck('id')->map(fn ($id) => (string) $id)->all(),
            (string) $request->input('table_id'),
        ]);
        $this->orders->mergeTables(new MergeOrderTablesCommand(
            OrderMutationContextFactory::fromOrder($customerOrder, actorId: auth()->id() ? (string) auth()->id() : null),
            (string) $customerOrder->id,
            $tableSet,
            $tableSet->fingerprint(),
        ));

        return new CustomerOrderResource($customerOrder->refresh());
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/unmerge-table',
        summary: 'Remove a merged table from the order',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['table_id'],
            properties: [
                new OA\Property(property: 'table_id', type: 'string', format: 'uuid'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Table unmerged', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerOrder'),
            ])),
            new OA\Response(response: 409, description: 'Cannot unmerge last table from dine-in order'),
        ]
    )]
    public function unmergeTable(Request $request, CustomerOrder $customerOrder): CustomerOrderResource
    {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('update', $customerOrder);

        $request->validate([
            'table_id' => ['required', 'string', 'exists:tables,id'],
        ]);

        // plan-047 T2.12 (#1090) — canonical facade; the primary-table 409
        // surfaces unchanged from the shared write path.
        $this->orders->unmergeTable(new UnmergeOrderTableCommand(
            OrderMutationContextFactory::fromOrder($customerOrder, actorId: auth()->id() ? (string) auth()->id() : null),
            (string) $customerOrder->id,
            (string) $request->input('table_id'),
        ));

        return new CustomerOrderResource($customerOrder->refresh());
    }

    // =========================================================================
    //  Coupon (plan-019)
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/apply-coupon',
        summary: 'Apply a coupon code to an order',
        description: 'Atomic apply per plan-019 endpoint #8 — validates window/scope/exhaustion + per-customer limit, increments times_used under lockForUpdate, writes a CouponRedemption row. Returns the updated order with discount_amount + total_amount recomputed.',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['code'],
            properties: [
                new OA\Property(property: 'code', type: 'string', maxLength: 50),
                new OA\Property(property: 'customer_id', type: 'string', format: 'uuid', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Coupon applied — order resource + applied_coupon meta'),
            new OA\Response(response: 404, description: 'coupon_not_found'),
            new OA\Response(response: 409, description: 'order_not_modifiable'),
            new OA\Response(response: 422, description: 'coupon_paused / coupon_expired / coupon_min_subtotal_not_met / coupon_exhausted / customer_required / coupon_already_used_by_customer / coupon_branch_not_eligible / coupon_excluded_by_active_promotion'),
        ]
    )]
    public function applyCoupon(
        ApplyCouponRequest $request,
        CustomerOrder $customerOrder,
    ): JsonResponse {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('applyCoupon', $customerOrder);

        $data = $request->validated();

        // plan-047 T2.12 (#1090) — coupon apply through the typed facade; the
        // persistence funnel keeps CouponService::apply the single writer.
        $this->orders->applyCoupon(new ApplyOrderCouponCommand(
            OrderMutationContextFactory::fromOrder($customerOrder, actorId: auth()->id() ? (string) auth()->id() : null),
            (string) $customerOrder->id,
            (string) $data['code'],
            $data['customer_id'] ?? null,
            'pos',
            (bool) ($data['downgrade_exclusive_promotions'] ?? false),
        ));
        $order = $customerOrder->refresh();

        $redemption = $order->coupon_id
            ? CouponRedemption::where('customer_order_id', $order->id)
                ->whereNull('released_at')
                ->latest('redeemed_at')
                ->first()
            : null;

        return response()->json([
            'data' => (new CustomerOrderResource($this->service->findById($order->id)))->resolve($request),
            'meta' => [
                'applied_coupon' => $redemption === null ? null : [
                    'code' => $order->coupon_code_snapshot,
                    'discount_type' => $redemption->coupon_snapshot['discount_type'] ?? null,
                    'discount_value' => $redemption->coupon_snapshot['discount_value'] ?? null,
                    'discount_applied_amount' => (string) $redemption->discount_applied_amount,
                ],
            ],
        ], 200);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/orders/{customerOrder}/coupon',
        summary: 'Release the coupon currently applied to an order',
        description: 'Decrements times_used, sets released_at, clears coupon_id + coupon_code_snapshot + discount_amount. Idempotent — already-released redemptions return success.',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Coupon released — updated CustomerOrderResource'),
            new OA\Response(response: 409, description: 'order_not_modifiable'),
            new OA\Response(response: 422, description: 'no_coupon_applied'),
        ]
    )]
    public function releaseCoupon(
        Request $request,
        CustomerOrder $customerOrder,
        OrderCouponService $coupons,
    ): CustomerOrderResource {
        $this->authorizeOrganization($customerOrder);
        $this->authorize('releaseCoupon', $customerOrder);

        $order = $coupons->release($customerOrder);

        return new CustomerOrderResource($this->service->findById($order->id));
    }
}
