<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerOrderItemResource;
use App\Http\Resources\CustomerOrderResource;
use App\Http\Resources\Workstation\WorkstationOrderResource;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\ProductSku;
use App\Models\Table;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\CustomerOrderTypeEnum;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\CustomerService;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Commands\ApproveOrderItemRefundCommand;
use App\Services\Order\Commands\BumpKitchenOrderItemStatusCommand;
use App\Services\Order\Commands\GhostCreateWorkstationOrderItemCommand;
use App\Services\Order\Commands\ReplayOfflineOrderCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Internal\OrderHoldStamp;
use App\Services\Order\Internal\OrderMutationContextFactory;
use App\Services\Order\Offline\SelectionWire;
use App\Services\Order\ValueObjects\OfflineOrderEvidence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class OrderController extends Controller
{
    private const IDEMPOTENCY_TTL_SECONDS = 86400;

    public function __construct(
        private readonly OrderMutationFacade $orders,
        private readonly CustomerOrderService $orderService,
        private readonly CustomerService $customerService,
        private readonly OrderHoldStamp $holdStamp,
    ) {}

    /**
     * #1097/#1114 — sync-UP endpoint for a SIGNED offline order. The
     * workstation posts the selection it signed plus the evidence envelope;
     * the facade's verifier authenticates the signature and re-prices the
     * selection from the claimed catalog revision — Cloud never trusts a
     * device-asserted price. Idempotent on order_id (a retried sync returns
     * the same order). Verification failures render as 422
     * OFFLINE_EVIDENCE_REJECTED with a machine reason_code; the workstation
     * keeps the row queued for operator handling (fail-closed, no order row).
     */
    public function replayOffline(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');

        $data = $request->validate([
            'order_id' => ['required', 'uuid'],
            'selection' => ['required', 'array'],
            'selection.lines' => ['required', 'array', 'min:1'],
            'evidence' => ['required', 'array'],
            'evidence.device_id' => ['required', 'string'],
            'evidence.issuer_id' => ['required', 'string'],
            'evidence.catalog_revision' => ['required', 'integer', 'min:1'],
            'evidence.issued_at' => ['required', 'string'],
            'evidence.expires_at' => ['required', 'string'],
            'evidence.key_id' => ['required', 'string'],
            'evidence.signature' => ['required', 'string'],
        ]);

        // The RAW selection, not $data['selection']: the nested
        // `selection.lines` rule makes validated() strip every selection key
        // it did not itself name (device_id, order_type, …), and a stripped
        // field would change the digest — the signature check would then
        // reject an honest order.
        try {
            $selection = SelectionWire::parse((array) $request->input('selection'));
        } catch (\Throwable $e) {
            abort(response()->json([
                'message' => 'Selection payload is malformed: '.$e->getMessage(),
                'error_code' => 'OFFLINE_SELECTION_MALFORMED',
            ], 422));
        }

        $evidence = new OfflineOrderEvidence(
            $data['evidence']['device_id'],
            $data['evidence']['issuer_id'],
            (int) $data['evidence']['catalog_revision'],
            $data['evidence']['issued_at'],
            $data['evidence']['expires_at'],
            $data['evidence']['key_id'],
            $data['evidence']['signature'],
        );

        $result = $this->orders->replayOffline(new ReplayOfflineOrderCommand(
            new MutationContext(
                organizationId: (string) $device->organization_id,
                actorId: null,
                correlationId: "workstation:replay-offline:{$data['order_id']}",
                idempotencyKey: $request->header('Idempotency-Key') ?? "replay-offline:{$data['order_id']}",
            ),
            $data['order_id'],
            (string) $device->branch_id,
            $selection,
            $selection->fingerprint(),
            $evidence,
        ));

        // `id` + `order_code` mirror the legacy create response so the
        // workstation's order.create write-back (cloud_id stamp + provisional
        // WS-#### → ORD-#### swap) works identically on both paths.
        $order = CustomerOrder::find($result->orderId);

        return response()->json(['data' => [
            'id' => $result->orderId,
            'order_id' => $result->orderId,
            'order_code' => $order?->order_code,
            'version' => $result->version,
            'item_count' => $result->itemCount,
        ]], 201);
    }

    /**
     * plan-045 — sync-UP endpoint for a LAN-created item refund. The workstation
     * drains its `order.item_refund` queue op here; Cloud runs the SAME
     * RefundService::refundItem (append negative line + condition + recompute)
     * and returns the reconciled order so the workstation adopts Cloud's figures.
     * Idempotent on the workstation side via client_order_item_id + Idempotency-Key.
     */
    public function refundItem(Request $request, CustomerOrder $customerOrder, string $item): JsonResponse
    {
        $device = $request->attributes->get('device');
        $device->loadMissing('branch');

        if ((string) $customerOrder->branch_id !== (string) $device->branch_id) {
            abort(403, 'Order not in workstation branch.');
        }

        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'client_order_item_id' => ['nullable', 'string'],
        ]);

        // Idempotency — a committed-but-lost response must NOT double-refund on
        // the queue re-drain. Same guard the KDS-bump + store() paths use: the
        // header replays the same reconciled order without appending a 2nd line.
        $idemKey = $request->header('Idempotency-Key');
        abort_unless($idemKey, 422, 'Idempotency-Key header required.');
        $cacheKey = "ws-refund:{$device->id}:{$idemKey}";
        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached);
        }
        // Durable belt-and-braces: if the workstation's refund line already
        // synced (its local UUID reached Cloud on a prior attempt whose response
        // was lost + cache-evicted), replay instead of appending a duplicate.
        $clientId = $validated['client_order_item_id'] ?? null;
        if ($clientId && $customerOrder->items()->where('id', $clientId)->exists()) {
            $order = $this->orderService->findById($customerOrder->id);
            $payload = (new CustomerOrderResource($order->loadMissing(['items', 'conditions'])))
                ->response()->getData(true);
            Cache::put($cacheKey, $payload, self::IDEMPOTENCY_TTL_SECONDS);

            return response()->json($payload);
        }

        // plan-047 T2.12 (#1090) — canonical facade; the workstation refund-line
        // UUID rides the command so queue-retry idempotency is preserved.
        $this->orders->approveItemRefund(new ApproveOrderItemRefundCommand(
            OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'refund-item', $clientId),
            (string) $customerOrder->id,
            (string) $item,
            (float) $validated['quantity'],
            (string) ($validated['reason'] ?? 'workstation refund'),
            $clientId,
        ));
        $order = $customerOrder->refresh();

        $payload = (new CustomerOrderResource($order->loadMissing(['items', 'conditions'])))
            ->response()->getData(true);
        Cache::put($cacheKey, $payload, self::IDEMPOTENCY_TTL_SECONDS);

        return response()->json($payload);
    }

    #[OA\Get(
        path: '/api/v1/workstation/orders',
        summary: 'List recent orders for the device branch (recovery + read)',
        description: 'Pulls orders the workstation can use to rebuild local state after a re-pair or crash. Filters by `since` (default 30 days ago) or `updated_since` for status-change-aware cursors, OR by `id` for an on-demand single-order projection (used by `PullOrderNow` to close the 5 s sync race when pos-web prints a freshly-created order). Limit 500 per call. Returns embedded `items`. Branch is resolved from the device token.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        parameters: [
            new OA\Parameter(name: 'since', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'), description: 'Filter orders created on or after this timestamp. Defaults to 30 days ago. Mutually exclusive with `updated_since` and `id`.'),
            new OA\Parameter(name: 'updated_since', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'), description: 'Filter orders updated (status-changed) on or after this timestamp. Mutually exclusive with `since` and `id`.'),
            new OA\Parameter(name: 'id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'), description: 'On-demand single-order projection. When set, ignores `since` / `updated_since` / `limit` and returns 0 or 1 orders matching the id within the device branch. Used by workstation force-pull (plan-038 T1.1).'),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 1000, default: 500)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of orders with items embedded.'),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
            new OA\Response(response: 403, description: 'Device type not allowed'),
            new OA\Response(response: 422, description: 'Validation failure (e.g., conflicting filter params)'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => ['nullable', 'date'],
            'updated_since' => ['nullable', 'date'],
            'id' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            // #3196 — không có nó thì feed này KHÔNG đi trang được: nhánh
            // `since` sắp `created_at` DESC và `since` là cận DƯỚI, nên không
            // có cách nào với tới phần cũ hơn trang đầu.
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $filterCount = (int) ! empty($validated['since'])
            + (int) ! empty($validated['updated_since'])
            + (int) ! empty($validated['id']);
        if ($filterCount > 1) {
            abort(422, 'Pass only one of `since`, `updated_since`, or `id`.');
        }

        $device = $request->attributes->get('device');
        $limit = $validated['limit'] ?? 500;
        $offset = (int) ($validated['offset'] ?? 0);

        $query = CustomerOrder::query()
            ->where('branch_id', $device->branch_id)
            // items.productSku.product is needed by CustomerOrderItem's
            // menu_item_name accessor — the table itself has no name snapshot.
            // orderItemToppings.toppingGroupItem.{product,toppingGroup} feeds
            // CustomerOrderItemResource's toppings[]: product → name,
            // toppingGroup → topping_group_name + modifier_type.
            // tables (reverse of Table.current_order_id) supplies table_id below,
            // since customer_orders.table_id isn't populated — the live link lives
            // on the Table serving the order.
            ->with([
                // #2713 — `name` on both ProductSku and Product is an Astrotomic
                // TRANSLATED attribute, so reading it resolves through the
                // `translations` relation. Eager-loading them turns two lazy
                // queries PER DISTINCT SKU/product into two flat ones; without
                // this the slim resource still pays the N+1 it exists to remove.
                'items.productSku.translations',
                'items.productSku.product.translations',
                'items.productSku.product',
                'items.orderItemToppings.toppingGroupItem.product',
                'items.orderItemToppings.toppingGroupItem.toppingGroup',
                'tables',
                // #2531 — snapshot fallback so `CustomerOrderResource` can still
                // show WHERE a closed dine-in order happened once `tables()`
                // (current occupant) has gone empty or moved to a new order.
                'table',
                // plan-045 — the condition ledger must sync DOWN so the
                // workstation's order_conditions mirror is populated from Cloud.
                'conditions',
                // #1282 — feeds CustomerOrderResource's `payment_summary`, the
                // label source for the 支払方法 line on a receipt whose payment
                // was taken online (no local `payments` row on the workstation).
                'payments.paymentMethod',
            ]);

        // `id` is the on-demand projection — bypass cursor + limit semantics and
        // return 0 or 1 rows. Workstation force-pull uses this to close the 5 s
        // sync race when pos-web prints an order Cloud has but local hasn't.
        if (! empty($validated['id'])) {
            $orders = $query->where('id', $validated['id'])->limit(1)->get();
            $orders->each(function (CustomerOrder $order): void {
                $order->setAttribute('table_id', $order->tables->first()?->id);
                $this->stampPaymentSummary($order);
            });

            return response()->json([
                // #2063 — đóng dấu cờ treo TRƯỚC khi serialize; đây là đường ĐỌC.
                'data' => WorkstationOrderResource::collection($this->holdStamp->stamp($orders)),
                'count' => $orders->count(),
                'cursor_field' => 'id',
                'generated_at' => now()->toIso8601String(),
            ]);
        }

        if (! empty($validated['updated_since'])) {
            $cursor = Carbon::parse($validated['updated_since']);
            $query->where('updated_at', '>=', $cursor);
            $cursorField = 'updated_at';
            $cursorValue = $cursor->toIso8601String();
            $orderDirection = 'asc';   // pull-DOWN: oldest first so cursor advances
        } else {
            $since = ! empty($validated['since'])
                ? Carbon::parse($validated['since'])
                : now()->subDays(30);
            $query->where('created_at', '>=', $since);
            $cursorField = 'created_at';
            $cursorValue = $since->toIso8601String();
            $orderDirection = 'desc';  // recovery: newest first (legacy behavior)
        }

        $orders = $query->orderBy($cursorField, $orderDirection)
            ->offset($offset)
            // +1 để BIẾT còn hay hết mà không phải chạy COUNT(*) riêng. Trang
            // thật vẫn đúng `limit` dòng; dòng thừa chỉ dùng làm tín hiệu.
            ->limit($limit + 1)
            ->get();

        // #3196 — `count` cũ là số dòng của TRANG, nên "đủ 500" và "còn nữa"
        // đọc y hệt nhau. Client vì thế không thể phát hiện mình vừa bị cắt,
        // và `Recover()` trả về một con số nghe như thành công.
        $hasMore = $orders->count() > $limit;
        $orders = $orders->take($limit);

        // customer_orders.table_id is unused — the table currently serving an
        // order is the reverse of Table.current_order_id. Feed table_id from
        // that relation so CustomerOrderResource exposes it (not null).
        $orders->each(function (CustomerOrder $order): void {
            $order->setAttribute('table_id', $order->tables->first()?->id);
            $this->stampPaymentSummary($order);
        });

        return response()->json([
            // #2063 — đường ĐỌC, xem chú thích ở nhánh trên.
            'data' => WorkstationOrderResource::collection($this->holdStamp->stamp($orders)),
            'count' => $orders->count(),
            // Client phải phân biệt được "hết" với "bị cắt". Xem #3196.
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + $limit : null,
            'since' => $cursorValue,            // always echo the cursor as `since` (backward compat)
            'cursor_field' => $cursorField,     // inform client which column was used
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * #1282/#2934 — hand the workstation the read-only payment projection, not
     * the payment rows.
     *
     * Method identity serves receipts; gross/net amounts serve revenue reports.
     * Money taken online (customer-web / Stripe / PayPay / konbini) is confirmed
     * here, so the workstation records no local drawer payment for it.
     *
     * The rows themselves stay behind: ~35 columns per payment (gateway
     * snapshots included) on every 60s tick buys nothing, and a client handed
     * real payment rows is one refactor away from reconciling its till against
     * online money that never entered the drawer.
     */
    private function stampPaymentSummary(CustomerOrder $order): void
    {
        $order->setAttribute(
            'payment_summary',
            CustomerOrderResource::buildPaymentSummary($order->payments),
        );
        $order->unsetRelation('payments');
    }

    #[OA\Post(
        path: '/api/v1/workstation/orders',
        summary: 'Sync UP an order from workstation to Cloud',
        description: 'Workstation owns orders locally (SQLite). Background sync worker pushes each new order here. Pass `Idempotency-Key` header so retries after network failures do not create duplicates — the same key returns the same Cloud order id. Branch/organization are resolved from the device token.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_type'],
            properties: [
                new OA\Property(property: 'client_order_id', type: 'string', format: 'uuid', nullable: true, description: "Workstation's local order UUID. Durable idempotency key: a re-sync maps to the same Cloud order without minting a second ORD-#### number (plan-041). Cloud is the authority for order_code."),
                new OA\Property(property: 'order_type', type: 'string', enum: ['spot', 'dine_in', 'takeaway']),
                new OA\Property(property: 'table_id', type: 'string', nullable: true, format: 'uuid'),
                new OA\Property(property: 'guest_count', type: 'integer', nullable: true),
                new OA\Property(property: 'customer_takeaway_name', type: 'string', nullable: true, maxLength: 255),
                new OA\Property(property: 'customer_takeaway_phone', type: 'string', nullable: true, maxLength: 50),
                new OA\Property(property: 'customer_phone', type: 'string', nullable: true, maxLength: 50, description: 'Phone of the linked loyalty/contact customer. The workstation sends this alongside `customer_id` because a LAN-minted `customer_id` is unknown to Cloud; Cloud find-or-creates the canonical customer by phone (org+branch scoped) and links that instead.'),
                new OA\Property(property: 'note', type: 'string', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Order created (or replayed on idempotency hit).'),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
            new OA\Response(response: 403, description: 'Device type not allowed'),
            new OA\Response(response: 422, description: 'Validation failure'),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // plan-041 — Cloud is the single authority for the gapless
            // ORD-#### code; it is minted here at insert, NOT supplied by the
            // workstation. `client_order_id` (the workstation's local order
            // UUID) is the durable idempotency key so a sync replay maps to the
            // same Cloud order without minting a second number. `order_code` is
            // still accepted-but-ignored as a bridge for old builds.
            'client_order_id' => ['nullable', 'uuid'],
            'order_code' => ['nullable', 'string', 'max:50'],
            'order_type' => ['required', 'string', 'in:'.implode(',', CustomerOrderTypeEnum::values())],
            // table_id (singular) kept for backward compat with old
            // workstation builds; table_ids[] is the canonical shape
            // matching POST /api/v1/pos/orders so multi-table merged
            // orders survive the LAN→Cloud sync. When both are present
            // the array wins.
            'table_id' => ['nullable', 'uuid'],
            'table_ids' => ['nullable', 'array'],
            'table_ids.*' => ['uuid', 'exists:tables,id'],
            // NOTE: no `exists:customers,id` here on purpose. In LAN mode the
            // workstation stamps a locally-minted customer UUID (from its own
            // /pos/customers/find-or-create) that Cloud has never seen; an
            // `exists` rule would 422 and the workstation dead-letters that as a
            // permanent data conflict, so the whole order never syncs UP. We
            // instead resolve the canonical Cloud customer below — by id when it
            // already exists in this org, otherwise find-or-create by phone.
            'customer_id' => ['nullable', 'uuid'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'customer_takeaway_name' => ['nullable', 'string', 'max:255'],
            'customer_takeaway_phone' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:1000'],
            // Initial status the workstation already assigned. Restricted to the
            // two creation-safe states: a staff-driven (POS / Handy) takeaway
            // arrives `open`, self-service (kiosk / customer) arrives `pending`.
            // Absent → the service defaults (takeaway → pending, else open),
            // preserving the behaviour of older workstation builds.
            'status' => ['sometimes', 'nullable', 'string', 'in:'.CustomerOrderStatusEnum::Open->value.','.CustomerOrderStatusEnum::Pending->value],
        ]);

        $device = $request->attributes->get('device');
        $device->loadMissing('branch');
        $branch = $device->branch;

        if (! $branch) {
            abort(422, 'Device is not associated with an active branch.');
        }

        $idemKey = $request->header('Idempotency-Key');

        if ($idemKey) {
            $cacheKey = $this->idempotencyCacheKey($device->id, $idemKey);
            $existingId = Cache::get($cacheKey);
            if ($existingId && ($existing = CustomerOrder::find($existingId))) {
                return $this->respond($existing, 201);
            }
        }

        $brandId = Brand::where('console_brand_id', $branch->console_brand_id)->value('id');

        $customerId = $this->resolveCustomerId($validated, $device, $brandId);

        // #484 — table assignment on sync-UP is best-effort and must NEVER
        // block the order. A workstation order already happened offline;
        // losing it to a 422 "table occupied" throws away real revenue. So:
        //   • takeaway / spot never hold a table (a table on a takeaway order
        //     is a source-side bug — drop it here),
        //   • dine_in links a table only when it is still free on Cloud; a
        //     table already occupied by ANOTHER order is dropped (logged), not
        //     stolen (would corrupt the live order) and not aborted (would
        //     strand this order forever).
        // The strict validateAndAssignTables() in CustomerOrderService::create
        // stays correct for the live customer/QR path — we simply pre-resolve
        // a conflict-free set here so it never aborts on a synced-UP order.
        $tableIds = [];
        if ($validated['order_type'] === CustomerOrderTypeEnum::DineIn->value) {
            $requestedTableIds = $validated['table_ids']
                ?? (! empty($validated['table_id']) ? [$validated['table_id']] : []);

            if ($requestedTableIds !== []) {
                $freeTableIds = Table::whereIn('id', $requestedTableIds)
                    ->whereNull('current_order_id')
                    ->pluck('id')
                    ->all();

                $droppedTableIds = array_values(array_diff($requestedTableIds, $freeTableIds));
                if ($droppedTableIds !== []) {
                    Log::warning('workstation order sync: dropped occupied/stale table link', [
                        'device_id' => $device->id,
                        'order_code' => $validated['order_code'] ?? null,
                        'dropped_table_ids' => $droppedTableIds,
                    ]);
                }

                $tableIds = $freeTableIds;
            }
        }

        $order = $this->orderService->create([
            // plan-041 — durable idempotency; Cloud mints the ORD-#### code.
            // Old builds without client_order_id fall back to the legacy
            // supplied `order_code` + Idempotency-Key cache below.
            'client_order_id' => $validated['client_order_id'] ?? null,
            'order_code' => $validated['order_code'] ?? null,
            'order_type' => $validated['order_type'],
            // Honor the workstation-assigned status when present; null lets
            // CustomerOrderService::create fall back to its type-based default
            // (isset() is false for null, so the default branch still runs).
            'status' => $validated['status'] ?? null,
            'organization_id' => $device->organization_id,
            'branch_id' => $device->branch_id,
            'brand_id' => $brandId,
            'table_ids' => $tableIds,
            'customer_id' => $customerId,
            'guest_count' => $validated['guest_count'] ?? null,
            'customer_takeaway_name' => $validated['customer_takeaway_name'] ?? null,
            'customer_takeaway_phone' => $validated['customer_takeaway_phone'] ?? null,
            'note' => $validated['note'] ?? null,
            // created_by_id is a User id everywhere else in the codebase
            // (StockTransaction.php:38 ties it to User via belongsTo; HQ/Shop
            // controllers populate it with $request->user()->id). The
            // workstation flow has no end-user — leave null, matching
            // CustomerQrOrderService.php:62.
            'created_by_id' => null,
        ]);

        if ($idemKey) {
            Cache::put(
                $this->idempotencyCacheKey($device->id, $idemKey),
                $order->id,
                self::IDEMPOTENCY_TTL_SECONDS,
            );
        }

        return $this->respond($order, 201);
    }

    /**
     * Resolve the canonical Cloud customer id for a synced-UP workstation order.
     *
     * The workstation may send:
     *  - a `customer_id` that already exists in this org (a customer pulled DOWN
     *    from Cloud, then linked on the LAN) — trust it as-is; or
     *  - a `customer_id` that is a workstation-local UUID Cloud has never seen
     *    (LAN find-or-create mints ids locally) — in that case fall back to the
     *    accompanying `customer_phone` and find-or-create the canonical customer
     *    (scoped to the device's org + branch), returning ITS id.
     *
     * Returns null when neither a resolvable id nor a phone is supplied (walk-in
     * order with no linked contact). This deliberately never throws: a stale
     * local id must not block the whole order from syncing.
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveCustomerId(array $validated, Device $device, ?string $brandId): ?string
    {
        $customerId = $validated['customer_id'] ?? null;

        if ($customerId !== null
            && Customer::where('organization_id', $device->organization_id)
                ->whereKey($customerId)
                ->exists()
        ) {
            return $customerId;
        }

        $phone = trim((string) ($validated['customer_phone'] ?? ''));
        if ($phone !== '') {
            [$customer] = $this->customerService->findOrCreateByPhone($phone, [
                'organization_id' => $device->organization_id,
                'branch_id' => $device->branch_id,
                'brand_id' => $brandId,
            ]);

            return $customer->id;
        }

        return null;
    }

    #[OA\Get(
        path: '/api/v1/workstation/orders/{customerOrder}',
        summary: 'Check a workstation order still exists on Cloud (recover-order existence check)',
        description: 'plan-042 GAP-2. Returns 200 with the order when it exists so the workstation refuses to duplicate it; 404 when it is gone so the workstation may safely re-create it. Branch-scoped to the device.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        parameters: [
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order exists'),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
            new OA\Response(response: 404, description: 'Order not found (gone) or different branch'),
        ],
    )]
    public function show(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $device = $request->attributes->get('device');
        // Branch-scope: a device may only probe its own branch's orders. A
        // mismatch is reported as 404 (same as "gone") so it never leaks
        // cross-branch existence.
        if ((string) $customerOrder->branch_id !== (string) $device->branch_id) {
            abort(404);
        }

        return response()->json(['data' => [
            'id' => $customerOrder->id,
            'order_code' => $customerOrder->order_code,
            'status' => $customerOrder->status,
        ]]);
    }

    #[OA\Patch(
        path: '/api/v1/workstation/orders/{customerOrder}/items/{item}/status',
        summary: 'Sync-UP KDS item status bump from workstation',
        description: 'Receives a KDS item bump that originated on LAN (workstation forwarding from KDS via sync queue). Requires `Idempotency-Key` header. `actor_kds_device_id` is logged to the `kds-bumps` daily channel (90-day retention) but not persisted as a column. Auth scope: workstation device token only.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        parameters: [
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['status'],
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['preparing', 'ready', 'served']),
                new OA\Property(property: 'actor_kds_device_id', type: 'string', nullable: true, maxLength: 100),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated item resource (or idempotency replay).'),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
            new OA\Response(response: 403, description: 'Device type not workstation, or branch mismatch'),
            new OA\Response(response: 404, description: 'Order or item not found'),
            new OA\Response(response: 409, description: 'Invalid status transition'),
            new OA\Response(response: 422, description: 'Validation failure or missing Idempotency-Key'),
        ],
    )]
    public function updateItemStatus(Request $request, CustomerOrder $customerOrder, string $item)
    {
        $device = $request->attributes->get('device');
        $device->loadMissing('branch');

        if ((string) $customerOrder->branch_id !== (string) $device->branch_id) {
            abort(403, 'Order not in workstation branch.');
        }

        $validated = $request->validate([
            // `pending` is a legal revert target: the domain layer allows any
            // active→active transition (#129), the Shop PATCH accepts it, and
            // both the KDS revert op and the POS StatusPicker can send it.
            // Rejecting it here 422'd (non-retryable) the LAN sync of every
            // revert-to-pending, silently losing the change.
            'status' => ['required', 'string', 'in:pending,preparing,ready,served'],
            'actor_kds_device_id' => ['nullable', 'string', 'max:100'],
            'item_snapshot' => ['nullable', 'array'],
            'item_snapshot.product_sku_id' => ['nullable', 'string', 'exists:product_skus,id'],
            'item_snapshot.quantity' => ['nullable', 'integer', 'min:1'],
            'item_snapshot.unit_price' => ['nullable', 'integer', 'min:0'],
            'item_snapshot.name' => ['nullable', 'string', 'max:500'],
            // Customer's kitchen note for the line. Without whitelisting it
            // here, $request->validate() strips it before the ghost-create
            // below, so an item first materialised on Cloud via a KDS bump
            // (before its own item-sync) permanently loses the note. This is
            // what dropped item notes on takeaway orders whose items reach
            // Cloud through the KDS-bump path rather than lifecycle addItems.
            'item_snapshot.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $idemKey = $request->header('Idempotency-Key');
        abort_unless($idemKey, 422, 'Idempotency-Key header required.');

        $cacheKey = "ws-kds-sync:bump:{$device->id}:{$idemKey}";
        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached);
        }

        // Workstation syncs order headers only (no items in POST /orders).
        // Items live in SQLite on the workstation and are referenced by their
        // local UUIDs. When a KDS bump arrives before the item has been created
        // on Cloud, ghost-create it from the snapshot carried in the request
        // so the status update can proceed without a separate item-sync pass.
        if (! $customerOrder->items()->where('id', $item)->exists()) {
            $snap = $validated['item_snapshot'] ?? [];
            $skuId = $snap['product_sku_id'] ?? null;

            // product_sku_id is NOT NULL in the schema — abort with 422 rather
            // than letting the INSERT crash with a DB constraint violation.
            abort_if($skuId === null, 422, 'item_snapshot.product_sku_id is required to ghost-create a missing order item.');

            // #902 — the ghost-create is a second workstation door that
            // persists a CustomerOrderItem from a client-supplied SKU. Gate it
            // with the same sellability rule as addItems / resolveAuthoritative
            // ItemPrices so a KDS bump cannot back-door a draft/inactive product
            // line onto Cloud.
            $ghostSku = ProductSku::with('product')->find($skuId);
            abort_if(
                $ghostSku === null || ! $ghostSku->isSellable(),
                422,
                'Cannot ghost-create an order item for a non-sellable product.',
            );

            $qty = (int) ($snap['quantity'] ?? 1);
            $price = (int) ($snap['unit_price'] ?? 0);

            // Use new + setAttribute to bypass $fillable so the workstation's
            // local UUID is preserved as the PK. CustomerOrderItem::create()
            // would strip 'id' via mass-assignment, causing HasUuids to
            // generate a different UUID — then updateItem() would 404.
            $this->orders->ghostCreateWorkstationItem(new GhostCreateWorkstationOrderItemCommand(
                OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'ghost-item', $idemKey),
                $customerOrder->id,
                $item,
                [
                    'product_sku_id' => $skuId,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'note' => $snap['note'] ?? null,
                ],
            ));
        }

        $this->orders->bumpKitchenItemStatus(new BumpKitchenOrderItemStatusCommand(
            OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'kds-bump', $idemKey),
            $customerOrder->id,
            $item,
            $validated['status'],
            $idemKey,
        ));
        $updated = $customerOrder->items()->findOrFail($item);

        Log::channel('kds-bumps')->info('workstation_sync_bump', [
            'order_id' => $customerOrder->id,
            'item_id' => $item,
            'new_status' => $validated['status'],
            'actor_kds_device_id' => $validated['actor_kds_device_id'] ?? null,
            'workstation_device_id' => $device->id,
        ]);

        $payload = (new CustomerOrderItemResource($updated->refresh()))
            ->response()->getData(true);
        Cache::put($cacheKey, $payload, self::IDEMPOTENCY_TTL_SECONDS);

        return response()->json($payload);
    }

    private function respond(CustomerOrder $order, int $status): JsonResponse
    {
        // plan-043 T3.2 — return the full CustomerOrderResource so the
        // sync-UP response carries the stamped per-line tax snapshots
        // (items[].{tax_type_id,tax_rate,tax_amount})
        // and order-level is_tax_included + ledger-projected tax_amount. The store path creates
        // an order shell with no items, so `items` is simply an empty array
        // here; a later addItems sync (or a `?id=` re-pull) carries the lines.
        // id / order_code / status remain present (all in the resource base),
        // so existing store-path assertions still pass. Keys are additive —
        // old workstations ignore unknown fields.
        return (new CustomerOrderResource($order->loadMissing(['items', 'conditions'])))
            ->response()
            ->setStatusCode($status);
    }

    private function idempotencyCacheKey(string $deviceId, string $idemKey): string
    {
        return "workstation:order:{$deviceId}:{$idemKey}";
    }
}
