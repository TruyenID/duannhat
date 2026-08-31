<?php

namespace App\Http\Controllers\Api\V1\Handy;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerOrderResource;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\MenuProductResource;
use App\Http\Resources\ShopMenuByDayResource;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Menu;
use App\Models\PaymentMethod;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\OrderPaymentService;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Commands\ChangeOrderItemsBatchCommand;
use App\Services\Order\Commands\ChangeOrderItemsCommand;
use App\Services\Order\Commands\PromoteOrderForPaymentCommand;
use App\Services\Order\Commands\RemoveOrderItemCommand;
use App\Services\Order\Commands\VoidOrderCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Enums\OrderItemMutation;
use App\Services\Order\Internal\OrderMutationContextFactory;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use App\Services\Order\ValueObjects\OrderToppingSelectionPayload;
use App\Services\Pos\TillSessionService;
use App\Services\Product\MenuService;
use App\Services\Promotion\MenuPromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HandyController extends Controller
{
    public function __construct(
        private readonly CustomerOrderService $orderService,
        private readonly MenuService $menuService,
        private readonly MenuPromotionService $promotionService,
        private readonly OrderMutationFacade $orders,
    ) {}

    /**
     * GET /handy/me — device info + branch.
     */
    public function me(Request $request): DeviceResource
    {
        $device = $this->device($request);
        $device->load('branch');

        return new DeviceResource($device);
    }

    /**
     * GET /handy/tables — active tables for this device's branch.
     */
    public function tables(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $tables = Table::where('branch_id', $device->branch_id)
            ->where('is_active', true)
            ->with(['zone:id,name'])
            ->select(['id', 'code', 'name', 'seat_count', 'status', 'zone_id', 'current_order_id', 'call_requested_at'])
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $tables]);
    }

    /**
     * GET /handy/menus/by-day/{dayOfWeek} — active branch menus for the given day.
     */
    public function menusByDay(Request $request, int $dayOfWeek): AnonymousResourceCollection
    {
        $device = $this->device($request);

        $menus = $this->menuService->listActiveBranchMenusForShopByDay($device->branch_id, $dayOfWeek, [
            'per_page' => 100,
        ]);

        return ShopMenuByDayResource::collection($menus);
    }

    /**
     * GET /handy/menus/{menu}/products — products in a menu.
     */
    public function menuProducts(Request $request, string $menuId): AnonymousResourceCollection
    {
        $device = $this->device($request);

        $menu = Menu::where('branch_id', $device->branch_id)->findOrFail($menuId);

        $products = $this->menuService->listBranchMenuProducts($menu, [
            'search' => $request->input('search'),
            'per_page' => min($request->integer('per_page', 100), 100),
        ]);

        $items = [];
        foreach ($products->items() as $mp) {
            if (! $mp->product) {
                continue;
            }
            $items[] = [
                'product_id' => $mp->product_id,
                'category_ids' => [],
            ];
        }
        $promotionMap = $this->promotionService->resolveActivePromotionsForMenu((string) $device->branch_id, $items);
        foreach ($products->items() as $mp) {
            $promo = $promotionMap[$mp->product_id] ?? null;
            if ($promo === null) {
                continue;
            }
            $mp->setAttribute('active_promotion_overlay', [
                'id' => $promo->id,
                'discount_percent' => (float) $promo->discount_percent,
                'stacking_mode' => $promo->stacking_mode instanceof \BackedEnum
                    ? $promo->stacking_mode->value
                    : (string) $promo->stacking_mode,
                'ends_at' => null,
            ]);
        }

        return MenuProductResource::collection($products);
    }

    /**
     * GET /handy/orders — open/dining orders for this branch.
     */
    public function orders(Request $request): AnonymousResourceCollection
    {
        $device = $this->device($request);

        $orders = $this->orderService->list([
            'branch_id' => $device->branch_id,
            'status' => $request->input('status', 'open,dining'),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 100), 100),
        ]);

        return CustomerOrderResource::collection($orders);
    }

    /**
     * GET /handy/orders/{order} — single order.
     */
    public function showOrder(Request $request, string $orderId): CustomerOrderResource
    {
        $device = $this->device($request);
        $order = $this->resolveOrder($device, $orderId);

        return new CustomerOrderResource($this->orderService->findById($order->id));
    }

    /**
     * POST /handy/orders — create order header.
     */
    public function createOrder(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $request->validate([
            'order_type' => ['nullable', 'string', 'in:spot,dine_in,takeaway'],
            'table_ids' => ['nullable', 'array'],
            'table_ids.*' => ['uuid', 'exists:tables,id'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string'],
        ]);

        $device->loadMissing('branch');

        $brandId = Brand::where('console_brand_id', $device->branch?->console_brand_id)->value('id');

        $data = $request->only(['order_type', 'table_ids', 'guest_count', 'note']);
        $data['branch_id'] = $device->branch_id;
        $data['organization_id'] = $device->organization_id;
        $data['brand_id'] = $brandId;

        $order = $this->orderService->create($data);

        return (new CustomerOrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /handy/orders/{order}/items — add items to order.
     */
    public function addItems(Request $request, string $orderId): JsonResponse
    {
        $device = $this->device($request);
        $order = $this->resolveOrder($device, $orderId);

        $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_sku_id' => ['required', 'string', 'exists:product_skus,id'],
            'items.*.menu_product_sku_id' => ['nullable', 'string', 'exists:menu_product_skus,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.note' => ['nullable', 'string'],
            'items.*.toppings' => ['nullable', 'array'],
            'items.*.toppings.*.topping_group_item_id' => ['required_with:items.*.toppings.*', 'string', 'exists:topping_group_items,id'],
            'items.*.toppings.*.product_sku_id' => ['required_with:items.*.toppings.*', 'string', 'exists:product_skus,id'],
            'items.*.toppings.*.quantity' => ['required_with:items.*.toppings.*', 'integer', 'min:1'],
            'items.*.toppings.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        // plan-047 T2.12 phase 2 (#1090) — typed facade per item; the batch is
        // all-or-nothing, matching the legacy contract. The handy device is the
        // acting identity.
        //
        // #1666 — that boundary now lives in `changeItemsBatch()`: whether
        // adding three items is one act is a property of the write, not of the
        // endpoint that happens to ask for it.
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
                OrderMutationContextFactory::fromOrder($order, actorId: $device->id ? (string) $device->id : null),
                (string) $order->id,
                OrderItemMutation::Add,
                $payload->fingerprint(),
                $payload,
            );
        }

        $this->orders->changeItemsBatch(new ChangeOrderItemsBatchCommand(
            OrderMutationContextFactory::fromOrder($order, actorId: $device->id ? (string) $device->id : null),
            $commands,
        ));

        $updated = $this->orderService->findById($order->id);

        return (new CustomerOrderResource($updated))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /handy/orders/{order}/items/{item} — update item quantity/note.
     */
    public function updateItem(Request $request, string $orderId, string $itemId): CustomerOrderResource
    {
        $device = $this->device($request);
        $order = $this->resolveOrder($device, $orderId);

        $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'note' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:pending,preparing,ready,served'],
        ]);

        // plan-047 T2.12 phase 2 (#1090) — field router. quantity/note ride
        // the typed Revise command; a request carrying `status` (kitchen
        // lifecycle) or `toppings` (replace) stays on the legacy path until
        // those concerns get their own commands — logged so the residue is
        // measurable before the split.
        $data = $request->only(['quantity', 'note', 'status']);
        if (! array_key_exists('status', $data) && ! array_key_exists('toppings', $data)) {
            $payload = new OrderLineSelectionPayload(
                (string) $itemId,
                null,
                (int) ($data['quantity'] ?? $order->items()->whereKey($itemId)->value('quantity')),
                [],
                $data['note'] ?? null,
                (string) $order->items()->whereKey($itemId)->value('product_sku_id'),
            );
            $this->orders->changeItems(new ChangeOrderItemsCommand(
                OrderMutationContextFactory::fromOrder($order, actorId: $device->id ? (string) $device->id : null),
                (string) $order->id,
                OrderItemMutation::Revise,
                $payload->fingerprint(),
                $payload,
                (string) $itemId,
            ));
        } else {
            Log::info('order.update_item.legacy_path', ['keys' => array_keys($data)]);
            $this->orderService->updateItem($order, $itemId, $data);
        }

        return new CustomerOrderResource($this->orderService->findById($order->id));
    }

    /**
     * DELETE /handy/orders/{order}/items/{item} — remove item.
     */
    public function removeItem(Request $request, string $orderId, string $itemId): CustomerOrderResource
    {
        $device = $this->device($request);
        $order = $this->resolveOrder($device, $orderId);

        // plan-047 T2.12 (#1090) — canonical facade; same legacy write path.
        $this->orders->removeItem(new RemoveOrderItemCommand(
            OrderMutationContextFactory::fromOrder($order, actorId: $device->id ? (string) $device->id : null),
            (string) $order->id,
            (string) $itemId,
            'handy item removal',
        ));

        return new CustomerOrderResource($this->orderService->findById($order->id));
    }

    /**
     * DELETE /handy/orders/{order} — void an order.
     * Blocked if any item is already preparing, ready, or served.
     */
    public function voidOrder(Request $request, string $orderId): CustomerOrderResource
    {
        $device = $this->device($request);
        $order = $this->resolveOrder($device, $orderId);

        $request->validate([
            'void_reason' => ['required', 'string', 'max:255'],
        ]);

        $inProgress = $order->items()
            ->whereIn('status', ['preparing', 'ready', 'served'])
            ->exists();

        if ($inProgress) {
            abort(409, 'Cannot void order: some items are already being prepared or served.');
        }

        // plan-047 T2.12 (#1090) — canonical facade, same reason string.
        $this->orders->void(new VoidOrderCommand(
            OrderMutationContextFactory::fromOrder($order, actorId: $device->id ? (string) $device->id : null),
            (string) $order->id,
            (string) $request->input('void_reason'),
        ));

        return new CustomerOrderResource($order->refresh());
    }

    /**
     * GET /handy/settings/order — order settings for this branch.
     */
    public function orderSettings(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $settings = ShopOrderSetting::firstOrNew(['branch_id' => $device->branch_id]);

        return response()->json(['data' => $settings]);
    }

    /**
     * POST /handy/orders/{order}/payments — #876, settle an order at the table.
     *
     * Server-side gated by the per-shop `handy_allow_direct_payment` toggle
     * (default OFF): a toggled-off shop 403s even a stale app build that still
     * shows the pay button. v1 accepts AUTO-CONFIRM tenders only (cash /
     * e-money settled on the spot) — terminal-driven methods need the
     * workstation bridge and stay register-only.
     *
     * Rides the same OrderPaymentService::create machinery as the other
     * device channels: overpay guard, plan-044 till-session attribution
     * (current in-progress shift, else NULL gap payment adopted by the next
     * shift's carry-over), auto-tender for requires_tendered methods.
     */
    public function createPayment(Request $request, string $order): JsonResponse
    {
        $device = $this->device($request);

        $setting = ShopOrderSetting::query()->where('branch_id', $device->branch_id)->first();
        if (! (bool) ($setting?->handy_allow_direct_payment)) {
            abort(response()->json([
                'message' => 'Direct payment on Handy is disabled for this shop.',
                'code' => 'HANDY_PAYMENT_DISABLED',
            ], 403));
        }

        // Unlike the item-mutation resolver, payment must also reach an order
        // already mid-payment (paying) so a second split tender can land.
        $customerOrder = CustomerOrder::where('branch_id', $device->branch_id)
            ->whereIn('status', [
                CustomerOrderStatusEnum::Open->value,
                CustomerOrderStatusEnum::Dining->value,
                CustomerOrderStatusEnum::Checkout->value,
                CustomerOrderStatusEnum::Paying->value,
            ])
            ->findOrFail($order);

        $validated = $request->validate([
            'payment_method_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $paymentMethod = PaymentMethod::query()
            ->where('id', $validated['payment_method_id'])
            ->where('organization_id', $device->organization_id)
            ->first();
        if ($paymentMethod === null) {
            abort(404, 'Payment method not found.');
        }
        if (! $paymentMethod->is_auto_confirm) {
            abort(response()->json([
                'message' => 'Only auto-confirm tenders (cash / e-money) can be settled on Handy.',
                'code' => 'HANDY_METHOD_NOT_ALLOWED',
            ], 422));
        }

        $this->orders->promoteForPayment(new PromoteOrderForPaymentCommand(
            new MutationContext(
                (string) $device->organization_id,
                (string) $device->id,
                'handy-pay:'.$customerOrder->id,
                $request->header('Idempotency-Key') ?? (string) Str::uuid(),
                expectedVersion: 1,
            ),
            (string) $customerOrder->id,
        ));
        $customerOrder->refresh();

        $tipAmount = (float) ($validated['tip_amount'] ?? 0);
        $branch = Branch::query()->with('brand')->find($device->branch_id);

        $createData = [
            'customer_order_id' => $customerOrder->id,
            'payment_method_id' => $paymentMethod->id,
            'amount' => (float) $validated['amount'],
            'tip_amount' => $tipAmount,
            'received_by_id' => $device->id,
            'organization_id' => $device->organization_id,
            'branch_id' => $device->branch_id,
            'brand_id' => $branch?->brand?->id,
            'idempotency_key' => $request->header('Idempotency-Key'),
            'till_session_id' => app(TillSessionService::class)->resolveSyncedSessionId(
                null,
                (string) $device->branch_id,
                openOnly: false,
            ),
            'orchestrator_transport' => 'pos',
            'device_id' => (string) $device->id,
        ];

        // Handy staff already handled the physical change at the table —
        // auto-tender amount + tip so requires_tendered (cash) passes the
        // `tendered >= amount + tip` guard (#817 B4).
        if ($paymentMethod->requires_tendered) {
            $createData['tendered_amount'] = (float) $validated['amount'] + $tipAmount;
        }

        $payment = app(OrderPaymentService::class)->create($createData);

        return response()->json(['data' => [
            'id' => $payment->id,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'order_status' => (string) $customerOrder->fresh()->status->value,
        ]], 201);
    }

    private function device(Request $request): Device
    {
        return $request->attributes->get('device');
    }

    private function resolveOrder(Device $device, string $orderId): CustomerOrder
    {
        return CustomerOrder::where('branch_id', $device->branch_id)
            ->whereIn('status', [
                CustomerOrderStatusEnum::Open->value,
                CustomerOrderStatusEnum::Dining->value,
                CustomerOrderStatusEnum::Checkout->value,
            ])
            ->findOrFail($orderId);
    }
}
