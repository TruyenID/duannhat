<?php

namespace App\Http\Controllers\Api\V1\Kds;

use App\Exceptions\KdsRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Resources\Kds\KdsDeviceResource;
use App\Http\Resources\Kds\KdsItemResource;
use App\Http\Resources\Kds\KdsOrderResource;
use App\Models\CustomerOrder;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\Kds\KdsAggregateMeta;
use App\Services\Kds\KdsBusinessRules;
use App\Services\Order\Commands\BumpKitchenOrderItemStatusCommand;
use App\Services\Order\Commands\RunKitchenBatchCommand;
use App\Services\Order\Commands\StampKitchenItemTimestampCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Internal\OrderMutationContextFactory;
use App\Services\Order\ValueObjects\ActingDeviceTenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class KdsController extends Controller
{
    private const IDEMPOTENCY_TTL_SECONDS = 86400;

    public function __construct(
        private readonly OrderMutationFacade $orders,
        private readonly KdsBusinessRules $rules,
    ) {}

    #[OA\Get(
        path: '/api/v1/kds/me',
        summary: 'KDS device info',
        description: 'Returns the KDS device record (with token stripped) plus branch/organization context. Used by kds-web to display the device name + branch on dashboard chrome and as a 30s heartbeat poll for revoke detection.',
        tags: ['KDS'],
        security: [['deviceToken' => []]],
        responses: [
            new OA\Response(response: 200, description: 'KDS device record (token stripped)'),
            new OA\Response(response: 401, description: 'Missing/invalid token'),
            new OA\Response(response: 403, description: 'Device type is not kds'),
        ]
    )]
    public function me(Request $request): KdsDeviceResource
    {
        $device = $request->attributes->get('device');
        $device->loadMissing('branch');

        return new KdsDeviceResource($device);
    }

    #[OA\Patch(
        path: '/api/v1/kds/orders/{customerOrder}/items/{item}/status',
        summary: 'Bump kitchen item status',
        description: 'Advances item through pending→preparing→ready→served lifecycle. KDS controller restricts to [preparing, ready, served] — voided goes through POS, pending is the initial state and not set by KDS. Service-level allows free transitions between active statuses.',
        tags: ['KDS'],
        security: [['deviceToken' => []]],
        parameters: [
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated item'),
            new OA\Response(response: 403, description: 'Branch mismatch'),
            new OA\Response(response: 404, description: 'Order/item not found'),
            new OA\Response(response: 409, description: 'Invalid transition'),
            new OA\Response(response: 422, description: 'Validation (status not in [preparing,ready,served])'),
        ]
    )]
    public function updateItemStatus(Request $request, CustomerOrder $customerOrder, string $item)
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:preparing,ready,served'],
        ]);

        // Mark this request as originating from the gen-1 PATCH endpoint so
        // downstream helpers can attach a via_gen1 flag to audit log entries.
        $request->attributes->set('kds_via_gen1', true);

        // Re-dispatch to the corresponding gen-2 operation handler so all
        // business rules, throttle, audit log, and idempotency are applied
        // consistently without duplication.
        $response = match ($validated['status']) {
            'preparing' => $this->markPreparing($request, $customerOrder, $item),
            'ready' => $this->markReady($request, $customerOrder, $item),
            'served' => $this->markServed($request, $customerOrder, $item),
        };

        $successorPath = $this->successorPathForStatus($validated['status'], $customerOrder->id, $item);

        return $response
            ->header('Deprecation', 'true')
            ->header('Sunset', 'Sat, 12 Jul 2026 00:00:00 GMT')
            ->header('Link', "<{$successorPath}>; rel=\"successor-version\"");
    }

    private function successorPathForStatus(string $status, string $orderId, string $itemId): string
    {
        $op = match ($status) {
            'preparing' => 'mark-preparing',
            'ready' => 'mark-ready',
            'served' => 'mark-served',
        };

        return url("/api/v1/kds/orders/{$orderId}/items/{$itemId}/{$op}");
    }

    // =========================================================================
    //  Phase 3 — Operation-oriented endpoints (gen-2)
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/kds/orders/{customerOrder}/items/{item}/mark-preparing',
        summary: 'Mark item as preparing',
        description: 'Transitions a pending item to preparing status. Idempotency-Key required.',
        tags: ['KDS'],
        security: [['deviceToken' => []]],
        parameters: [
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated item'),
            new OA\Response(response: 401, description: 'Missing/invalid token'),
            new OA\Response(response: 403, description: 'Branch mismatch (KDS_E006)'),
            new OA\Response(response: 404, description: 'Item not found (KDS_E007)'),
            new OA\Response(response: 409, description: 'Order finalized (KDS_E001)'),
            new OA\Response(response: 422, description: 'Idempotency-Key missing'),
            new OA\Response(response: 429, description: 'Throttled (KDS_E005)'),
        ]
    )]
    public function markPreparing(Request $request, CustomerOrder $customerOrder, string $item): JsonResponse
    {
        return $this->withKdsOperation($request, $customerOrder, $item, 'mark-preparing', function ($itemModel, $idemKey) use ($request, $customerOrder) {
            // Forward-only: pending → preparing. Blocks backward/skip drags.
            $this->rules->assertForwardTransition($itemModel, OrderItemStatusEnum::Pending, OrderItemStatusEnum::Preparing);

            $this->orders->bumpKitchenItemStatus(new BumpKitchenOrderItemStatusCommand(
                OrderMutationContextFactory::fromKdsRequest($request, $customerOrder, 'mark-preparing', $idemKey),
                $customerOrder->id,
                $itemModel->id,
                'preparing',
                $idemKey,
            ));
            $updated = $itemModel->fresh();

            if ($updated->started_preparing_at === null) {
                $this->orders->stampKitchenTimestamp(new StampKitchenItemTimestampCommand(
                    OrderMutationContextFactory::fromKdsRequest($request, $customerOrder, 'stamp-preparing', $idemKey),
                    $customerOrder->id,
                    $itemModel->id,
                    'started_preparing_at',
                ));
                $updated = $updated->fresh();
            }

            $updated->loadMissing(['orderItemToppings', 'productSku.product']);

            return (new KdsItemResource($updated))->response()->getData(true);
        });
    }

    #[OA\Post(
        path: '/api/v1/kds/orders/{customerOrder}/items/{item}/mark-ready',
        summary: 'Mark item as ready',
        description: 'Transitions a preparing item to ready status. Requires all toppings to be ready first. Idempotency-Key required.',
        tags: ['KDS'],
        security: [['deviceToken' => []]],
        parameters: [
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated item'),
            new OA\Response(response: 401, description: 'Missing/invalid token'),
            new OA\Response(response: 403, description: 'Branch mismatch (KDS_E006)'),
            new OA\Response(response: 404, description: 'Item not found (KDS_E007)'),
            new OA\Response(response: 409, description: 'Order finalized (KDS_E001) or toppings not ready (KDS_E004)'),
            new OA\Response(response: 422, description: 'Idempotency-Key missing'),
            new OA\Response(response: 429, description: 'Throttled (KDS_E005)'),
        ]
    )]
    public function markReady(Request $request, CustomerOrder $customerOrder, string $item): JsonResponse
    {
        return $this->withKdsOperation($request, $customerOrder, $item, 'mark-ready', function ($itemModel, $idemKey) use ($request, $customerOrder) {
            // Forward-only: preparing → ready. Blocks pending-skip / served-resurrect.
            $this->rules->assertForwardTransition($itemModel, OrderItemStatusEnum::Preparing, OrderItemStatusEnum::Ready);

            // Parent cannot be marked ready while any of its toppings are
            // still unready — surfaced as KDS_E004.
            $itemModel->loadMissing('orderItemToppings');
            $this->rules->assertToppingsParentReady($itemModel);

            $this->orders->bumpKitchenItemStatus(new BumpKitchenOrderItemStatusCommand(
                OrderMutationContextFactory::fromKdsRequest($request, $customerOrder, 'mark-ready', $idemKey),
                $customerOrder->id,
                $itemModel->id,
                'ready',
                $idemKey,
            ));
            $updated = $itemModel->fresh();

            // Set ready_at timestamp — service does not manage this column.
            // First-write-wins (COALESCE) to match started_preparing_at above
            // and the workstation LAN mirror: a revert→re-ready keeps the
            // original ready anchor so aging + anti-misclick stay consistent
            // across cloud and workstation.
            if ($updated->ready_at === null) {
                $this->orders->stampKitchenTimestamp(new StampKitchenItemTimestampCommand(
                    OrderMutationContextFactory::fromKdsRequest($request, $customerOrder, 'stamp-ready', $idemKey),
                    $customerOrder->id,
                    $itemModel->id,
                    'ready_at',
                ));
                $updated = $updated->fresh();
            }
            $updated->loadMissing(['orderItemToppings', 'productSku.product']);

            return (new KdsItemResource($updated))->response()->getData(true);
        });
    }

    #[OA\Post(
        path: '/api/v1/kds/orders/{customerOrder}/items/{item}/mark-served',
        summary: 'Mark item as served',
        description: 'Transitions a ready item to served status. Anti-misclick: requires item has been ready for at least 30s. Idempotency-Key required.',
        tags: ['KDS'],
        security: [['deviceToken' => []]],
        parameters: [
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated item'),
            new OA\Response(response: 401, description: 'Missing/invalid token'),
            new OA\Response(response: 403, description: 'Branch mismatch (KDS_E006)'),
            new OA\Response(response: 404, description: 'Item not found (KDS_E007)'),
            new OA\Response(response: 409, description: 'Order finalized (KDS_E001), item not ready (KDS_E002), or too soon after ready (KDS_E003)'),
            new OA\Response(response: 422, description: 'Idempotency-Key missing'),
            new OA\Response(response: 429, description: 'Throttled (KDS_E005)'),
        ]
    )]
    public function markServed(Request $request, CustomerOrder $customerOrder, string $item): JsonResponse
    {
        return $this->withKdsOperation($request, $customerOrder, $item, 'mark-served', function ($itemModel, $idemKey) use ($request, $customerOrder) {
            // Forward-only: ready → served. A `preparing` item carrying a stale
            // ready_at (from a prior revert) must not slip straight to served.
            $this->rules->assertForwardTransition($itemModel, OrderItemStatusEnum::Ready, OrderItemStatusEnum::Served);
            $this->rules->assertMarkServedAllowed($itemModel);

            $this->orders->bumpKitchenItemStatus(new BumpKitchenOrderItemStatusCommand(
                OrderMutationContextFactory::fromKdsRequest($request, $customerOrder, 'mark-served', $idemKey),
                $customerOrder->id,
                $itemModel->id,
                'served',
                $idemKey,
            ));
            $updated = $itemModel->fresh();
            $updated->loadMissing(['orderItemToppings', 'productSku.product']);

            return (new KdsItemResource($updated))->response()->getData(true);
        });
    }

    #[OA\Post(
        path: '/api/v1/kds/orders/{customerOrder}/items/{item}/revert',
        summary: 'Revert item to an earlier status',
        description: 'Backward transition for a kitchen item. Accepts `to: pending|preparing`. Served is terminal and cannot be reverted.',
        tags: ['KDS'],
        security: [['deviceToken' => []]],
        parameters: [
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\JsonContent(
                required: ['to'],
                properties: [new OA\Property(property: 'to', type: 'string', enum: ['pending', 'preparing'])],
            )]
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated item'),
            new OA\Response(response: 401, description: 'Missing/invalid token'),
            new OA\Response(response: 403, description: 'Branch mismatch (KDS_E006)'),
            new OA\Response(response: 404, description: 'Item not found (KDS_E007)'),
            new OA\Response(response: 409, description: 'Cannot revert terminal status (KDS_E002)'),
            new OA\Response(response: 422, description: 'Idempotency-Key missing or invalid `to` value'),
            new OA\Response(response: 429, description: 'Throttled (KDS_E005)'),
        ]
    )]
    public function revert(Request $request, CustomerOrder $customerOrder, string $item): JsonResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'string', 'in:pending,preparing'],
        ]);

        return $this->withKdsOperation($request, $customerOrder, $item, 'revert', function ($itemModel, $idemKey) use ($request, $customerOrder, $validated) {
            $statusOrder = ['pending' => 0, 'preparing' => 1, 'ready' => 2, 'served' => 3];
            $current = $itemModel->status instanceof \BackedEnum
                ? $itemModel->status->value
                : (string) $itemModel->status;
            $target = $validated['to'];

            if (in_array($current, ['served', 'voided'], true)) {
                throw new KdsRuleViolation(
                    'KDS_E002',
                    "Cannot revert from {$current} — this status is terminal.",
                    ['item_id' => $itemModel->id, 'current_status' => $current],
                );
            }

            if (! isset($statusOrder[$current]) || ! isset($statusOrder[$target])) {
                throw new KdsRuleViolation(
                    'KDS_E002',
                    'Invalid status for revert.',
                    ['item_id' => $itemModel->id, 'current_status' => $current, 'target_status' => $target],
                );
            }

            if ($statusOrder[$target] >= $statusOrder[$current]) {
                throw new KdsRuleViolation(
                    'KDS_E002',
                    "Revert must go backward: current={$current}, target={$target}.",
                    ['item_id' => $itemModel->id, 'current_status' => $current, 'target_status' => $target],
                );
            }

            $this->orders->bumpKitchenItemStatus(new BumpKitchenOrderItemStatusCommand(
                OrderMutationContextFactory::fromKdsRequest($request, $customerOrder, 'revert', $idemKey),
                $customerOrder->id,
                $itemModel->id,
                $target,
                $idemKey,
            ));
            $updated = $itemModel->fresh();
            $updated->loadMissing(['orderItemToppings', 'productSku.product']);

            return (new KdsItemResource($updated))->response()->getData(true);
        });
    }

    #[OA\Post(
        path: '/api/v1/kds/orders/{customerOrder}/bump-all',
        summary: 'Bulk advance all items in a given scope',
        description: 'scope=pending advances all pending items to preparing; scope=preparing advances all preparing items to ready. Returns updated KdsOrderResource.',
        tags: ['KDS'],
        security: [['deviceToken' => []]],
        parameters: [
            new OA\Parameter(name: 'customerOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\JsonContent(
                required: ['scope'],
                properties: [new OA\Property(property: 'scope', type: 'string', enum: ['pending', 'preparing'])],
            )]
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated order'),
            new OA\Response(response: 401, description: 'Missing/invalid token'),
            new OA\Response(response: 403, description: 'Branch mismatch (KDS_E006)'),
            new OA\Response(response: 409, description: 'Order finalized (KDS_E001)'),
            new OA\Response(response: 422, description: 'Idempotency-Key missing or invalid scope'),
        ]
    )]
    public function bumpAll(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $device = $request->attributes->get('device');
        $idemKey = $request->header('Idempotency-Key');
        abort_unless($idemKey, 422, 'Idempotency-Key header required.');

        $validated = $request->validate([
            'scope' => ['required', 'string', 'in:pending,preparing'],
        ]);

        $cacheKey = "kds:bump-all:{$device->id}:{$idemKey}";
        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached);
        }

        $this->rules->assertBranchOwnership($customerOrder, ActingDeviceTenancy::of($device));
        $this->rules->assertOrderActive($customerOrder);

        $scope = $validated['scope'];
        $target = $scope === 'pending' ? 'preparing' : 'ready';

        $items = $customerOrder->items()
            ->where('status', $scope)
            ->with('orderItemToppings')
            ->get();

        // KDS_E004 — bump-all must honour the same toppings-parent-ready guard
        // as the single mark-ready endpoint. Pre-scan so the batch is atomic:
        // if any parent still has an unready topping, the whole bump-all fails
        // (the transaction never opens) rather than silently marking a parent
        // ready ahead of its toppings.
        if ($target === 'ready') {
            foreach ($items as $itemModel) {
                $this->rules->assertToppingsParentReady($itemModel);
            }
        }

        // #1666 — the run is assembled here (this is the side holding the loaded
        // items, so it can see which timestamps are still unset) and executed as
        // ONE batch by the service. A half-applied bump-all is a ticket whose
        // status and timestamps disagree.
        $commands = [];
        $affectedIds = [];

        foreach ($items as $itemModel) {
            $itemIdempotency = "{$idemKey}:{$itemModel->id}";

            $commands[] = new BumpKitchenOrderItemStatusCommand(
                OrderMutationContextFactory::fromKdsRequest($request, $customerOrder, 'bump-all', $itemIdempotency),
                $customerOrder->id,
                $itemModel->id,
                $target,
                $itemIdempotency,
            );

            // First-write-wins timestamps — mirror the single-item ops.
            if ($target === 'preparing' && $itemModel->started_preparing_at === null) {
                $commands[] = new StampKitchenItemTimestampCommand(
                    OrderMutationContextFactory::fromKdsRequest($request, $customerOrder, 'bump-all-preparing', $itemIdempotency),
                    $customerOrder->id,
                    $itemModel->id,
                    'started_preparing_at',
                );
            }

            if ($target === 'ready' && $itemModel->ready_at === null) {
                $commands[] = new StampKitchenItemTimestampCommand(
                    OrderMutationContextFactory::fromKdsRequest($request, $customerOrder, 'bump-all-ready', $itemIdempotency),
                    $customerOrder->id,
                    $itemModel->id,
                    'ready_at',
                );
            }

            $affectedIds[] = $itemModel->id;
        }

        // Scope rỗng (không món nào đang chờ) là một no-op HỢP LỆ trả 200, nên
        // ở đây không dựng lô. `RunKitchenBatchCommand` từ chối lô rỗng và giữ
        // nguyên như vậy: một lô không có việc gì để làm là lỗi lập trình ở mọi
        // chỗ gọi khác — Handy/Shop đều validate `items` là `min:1`.
        if ($commands !== []) {
            $this->orders->runKitchenBatch(new RunKitchenBatchCommand(
                OrderMutationContextFactory::fromKdsRequest($request, $customerOrder, 'bump-all', $idemKey),
                $commands,
            ));
        }

        Log::channel('kds-bumps')->info('kds_direct_bump_all', [
            'op' => 'kds_direct_bump_all',
            'order_id' => $customerOrder->id,
            'device_id' => $device->id,
            'scope' => $scope,
            'target' => $target,
            'idempotency_key' => $idemKey,
            'affected_item_ids' => $affectedIds,
            'affected_count' => count($affectedIds),
        ]);

        $customerOrder->load(['items.productSku.product', 'items.orderItemToppings', 'tables.zone']);
        $payload = (new KdsOrderResource($customerOrder))->response()->getData(true);

        Cache::put($cacheKey, $payload, self::IDEMPOTENCY_TTL_SECONDS);

        return response()->json($payload);
    }

    // =========================================================================
    //  Shared helper — shared KDS operation pattern
    // =========================================================================

    /**
     * Execute a KDS item operation with shared guard + idempotency pattern.
     *
     * Validates Idempotency-Key, checks cache for replay, asserts branch
     * ownership and order active, finds item (throws KDS_E007 if missing),
     * asserts throttle, delegates to $work callback, caches result, logs audit.
     *
     * @param  callable(object $itemModel, string $idemKey): array  $work
     */
    private function withKdsOperation(
        Request $request,
        CustomerOrder $order,
        string $itemId,
        string $opName,
        callable $work,
    ): JsonResponse {
        $device = $request->attributes->get('device');
        $idemKey = $request->header('Idempotency-Key');
        abort_unless($idemKey, 422, 'Idempotency-Key header required.');

        $cacheKey = "kds:{$opName}:{$device->id}:{$idemKey}";
        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached);
        }

        $this->rules->assertBranchOwnership($order, ActingDeviceTenancy::of($device));
        $this->rules->assertOrderActive($order);

        $itemModel = $order->items()->find($itemId);
        if (! $itemModel) {
            throw new KdsRuleViolation('KDS_E007', 'Item not found in order.', ['item_id' => $itemId]);
        }

        $this->rules->assertNotThrottled($itemModel, $device->id);

        $result = $work($itemModel, $idemKey);

        $opSlug = str_replace('-', '_', $opName);
        Log::channel('kds-bumps')->info("kds_direct_{$opSlug}", [
            'op' => "kds_direct_{$opSlug}",
            'order_id' => $order->id,
            'item_id' => $itemId,
            'device_id' => $device->id,
            'idempotency_key' => $idemKey,
            'via_gen1' => $request->attributes->get('kds_via_gen1', false),
        ]);

        Cache::put($cacheKey, $result, self::IDEMPOTENCY_TTL_SECONDS);

        return response()->json($result);
    }

    #[OA\Get(
        path: '/api/v1/kds/orders',
        summary: 'Active orders for branch',
        description: 'Returns customer orders in active statuses (open/dining/checkout/paying) scoped to the KDS device branch. Items eager-loaded.',
        tags: ['KDS'],
        security: [['deviceToken' => []]],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 500, default: 200)),
        ],
        responses: [new OA\Response(response: 200, description: 'Active orders list')]
    )]
    public function orders(Request $request)
    {
        $device = $request->attributes->get('device');
        $limit = (int) $request->query('limit', 200);
        $limit = max(1, min(500, $limit));

        $orders = CustomerOrder::query()
            // #845 defense-in-depth: scope by BOTH org and branch so a device
            // paired to a cross-tenant branch can never read another org's
            // orders even if branch ownership were somehow bypassed.
            ->where('organization_id', $device->organization_id)
            ->where('branch_id', $device->branch_id)
            ->whereIn('status', [
                CustomerOrderStatusEnum::Open,
                CustomerOrderStatusEnum::Dining,
                CustomerOrderStatusEnum::Checkout,
                CustomerOrderStatusEnum::Paying,
            ])
            ->with(['items.productSku.product', 'items.orderItemToppings', 'tables.zone'])
            ->orderBy('opened_at', 'asc')
            ->limit($limit)
            ->get();

        return KdsOrderResource::collection($orders)->additional([
            'meta' => KdsAggregateMeta::compute($orders),
        ]);
    }
}
