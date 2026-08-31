<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Events\OrderItemAdded;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerOrderResource;
use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderPayment;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\OrderPaymentService;
use App\Services\Order\Commands\ApplyWorkstationOrderCouponCommand;
use App\Services\Order\Commands\CheckoutWorkstationOrderCommand;
use App\Services\Order\Commands\ConfirmOrderCommand;
use App\Services\Order\Commands\MergeOrderTablesCommand;
use App\Services\Order\Commands\PatchWorkstationOrderCommand;
use App\Services\Order\Commands\PatchWorkstationOrderItemCommand;
use App\Services\Order\Commands\ReleaseWorkstationOrderCouponCommand;
use App\Services\Order\Commands\SoftDeleteWorkstationOrderCommand;
use App\Services\Order\Commands\SoftDeleteWorkstationOrderItemCommand;
use App\Services\Order\Commands\SyncWorkstationOrderItemsCommand;
use App\Services\Order\Commands\UnmergeOrderTableCommand;
use App\Services\Order\Commands\VoidWorkstationOrderCommand;
use App\Services\Order\Commands\VoidWorkstationOrderItemCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Coupon\OrderCouponService;
use App\Services\Order\Internal\OrderMutationContextFactory;
use App\Services\Order\ValueObjects\CouponDiscountTerms;
use App\Services\Order\ValueObjects\OrderTableSetPayload;
use App\Services\Order\WorkstationOrderPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Workstation-flow order lifecycle — replays the LAN-offline cashier
 * actions (update / void / checkout / items / apply-coupon / release-
 * coupon) against the workstation-paired branch's order rows.
 *
 * Auth: device.auth:workstation. The cashier identity rides in payload
 * (`actor_id`) so the audit trail records the person, not the kiosk.
 *
 * Idempotency: every endpoint uses workstation-supplied keys (action+id)
 * and is a no-op when the target state already holds. A retry after a
 * flaky network does NOT duplicate items, double-void, or apply a coupon
 * twice — this is the contract the sync_queue worker relies on.
 */
class OrderLifecycleController extends Controller
{
    public function __construct(
        private readonly OrderMutationFacade $orders,
        private readonly CustomerOrderService $orderService,
        private readonly OrderPaymentService $paymentService,
        private readonly WorkstationOrderPricingService $pricing,
        private readonly OrderCouponService $coupons,
    ) {}

    public function update(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);

        if ($this->skipIfTerminalOrder($request, $customerOrder, 'update')) {
            return $this->respond($customerOrder);
        }

        $data = $request->validate([
            'guest_count' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'customer_id' => ['nullable', 'uuid'],
            'order_type' => ['nullable', 'string', 'in:dine_in,takeaway,spot'],
        ]);

        $patch = array_filter($data, fn ($v) => $v !== null);
        if ($patch) {
            // The #1270 transaction now lives in OrderService::patchWorkstationOrder,
            // where the consistency boundary belongs (#1666).
            $this->orders->patchWorkstationOrder(new PatchWorkstationOrderCommand(
                OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'patch-order'),
                $customerOrder->id,
                $patch,
            ));
        }

        return $this->respond($customerOrder->fresh());
    }

    public function delete(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);

        if (! $customerOrder->trashed()) {
            $this->orders->softDeleteWorkstationOrder(new SoftDeleteWorkstationOrderCommand(
                OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'soft-delete-order'),
                $customerOrder->id,
            ));
        }

        return response()->json(['data' => null], 204);
    }

    public function void(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);

        $data = $request->validate([
            'void_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($this->statusValue($customerOrder) === CustomerOrderStatusEnum::Voided->value) {
            return $this->respond($customerOrder);
        }

        $this->orders->voidWorkstationOrder(new VoidWorkstationOrderCommand(
            OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'void-order'),
            $customerOrder->id,
            $data['void_reason'] ?? 'voided_by_workstation',
        ));

        return $this->respond($customerOrder->fresh());
    }

    /**
     * Replay a LAN "accept order" (pending|confirmed → open) — the staff
     * acknowledged a customer-submitted takeaway at the POS counter so the
     * order can flow through the regular checkout pipeline.
     *
     * Idempotent by status, never a 4xx on state: any status outside
     * pending|confirmed means the intent is already satisfied (open or
     * further) or moot (voided/closed) — a queue retry arriving after later
     * transitions must drain, not dead-letter.
     */
    public function confirm(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);

        $confirmable = [
            CustomerOrderStatusEnum::Pending->value,
            CustomerOrderStatusEnum::Confirmed->value,
        ];
        if (! in_array($this->statusValue($customerOrder), $confirmable, true)) {
            return $this->respond($customerOrder);
        }

        $this->orders->confirm(new ConfirmOrderCommand(
            OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'confirm-order'),
            $customerOrder->id,
        ));

        return $this->respond($customerOrder->fresh());
    }

    public function checkout(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);

        $data = $request->validate([
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->orders->checkoutWorkstationOrder(new CheckoutWorkstationOrderCommand(
            OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'checkout-order'),
            $customerOrder->id,
            isset($data['discount_amount']) ? (float) $data['discount_amount'] : null,
        ));

        return $this->respond($customerOrder->fresh());
    }

    public function init(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);

        if ($this->skipIfTerminalOrder($request, $customerOrder, 'init')) {
            return $this->respond($customerOrder);
        }

        $data = $request->validate([
            'guest_count' => ['nullable', 'integer', 'min:0'],
            'table_id' => ['nullable', 'uuid'],
            'table_ids' => ['nullable', 'array'],
            'table_ids.*' => ['uuid'],
        ]);

        $patch = [];
        if (isset($data['guest_count'])) {
            $patch['guest_count'] = $data['guest_count'];
        }
        if (isset($data['table_id'])) {
            $patch['table_id'] = $data['table_id'];
        }
        if ($patch) {
            // Same as `update` above — the transaction is the service's (#1666).
            $this->orders->patchWorkstationOrder(new PatchWorkstationOrderCommand(
                OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'init-order'),
                $customerOrder->id,
                $patch,
            ));
        }

        // tables[] sync is left to a dedicated /tables endpoint in P5.

        return $this->respond($customerOrder->fresh());
    }

    public function addItems(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);

        if ($this->skipIfTerminalOrder($request, $customerOrder, 'addItems')) {
            return $this->respond($customerOrder);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'uuid'],
            'items.*.product_sku_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            // #2622 — số đơn vị máy trạm ĐÃ gửi bếp cho dòng này. Không có rule
            // ở đây thì `$request->validate()` STRIP nó và tầng service không
            // bao giờ thấy field, tức tính năng chết im lặng trên đường HTTP.
            // Không kẹp cận trên ở đây: `quantity` của cùng dòng mới là cận, và
            // nó nằm trong payload chứ không phải trong rule — service clamp
            // [0, quantity] (WritesCustomerOrders::transportWorkstationSyncItems).
            'items.*.printed_quantity' => ['nullable', 'integer', 'min:0'],
            // unit_price is still accepted for shape-compat but NEVER trusted —
            // the server resolves the authoritative price below (plan-040 H17).
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.original_unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.applied_promotion_id' => ['nullable', 'uuid'],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
            // Plan 015 toppings — snapshotted per line so shop/HQ render the exact
            // configured line the LAN cashier sold. The base item price is resolved
            // server-side (H17); topping surcharge prices ride in from the
            // workstation (Cloud does not re-resolve toppings for this flow).
            'items.*.toppings' => ['nullable', 'array'],
            'items.*.toppings.*.topping_group_item_id' => ['required', 'uuid'],
            'items.*.toppings.*.product_sku_id' => ['nullable', 'uuid'],
            'items.*.toppings.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.toppings.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.toppings.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        // plan-040 H17: a LAN client is untrusted. Resolve each item's price
        // SERVER-SIDE from the order's branch menu (fallback to the SKU's own
        // selling_price for off-menu SKUs, matching the Cloud addItems path),
        // reject unknown SKUs (422), and override any client-supplied unit_price.
        // quantity > 0 is already enforced by the `integer|min:1` rule above.
        $authoritativePrices = $this->pricing->resolveAuthoritativeItemPrices($customerOrder, $data['items']);

        $this->orders->syncWorkstationItems(new SyncWorkstationOrderItemsCommand(
            OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'sync-items'),
            $customerOrder->id,
            $data['items'],
            $authoritativePrices,
        ));
        $fresh = $customerOrder->fresh();

        // Fan out to any dine-in session devices (parity with the Cloud addItems
        // path). No-op for spot/takeaway orders without a table session.
        if ($fresh->table_session_id) {
            event(new OrderItemAdded($fresh));
        }

        return $this->respond($fresh);
    }

    public function updateItem(Request $request, CustomerOrder $customerOrder, CustomerOrderItem $item): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);
        if ($item->customer_order_id !== $customerOrder->id) {
            abort(404);
        }

        if ($this->skipIfTerminalOrder($request, $customerOrder, 'updateItem')) {
            return $this->respond($customerOrder->fresh());
        }

        // #1148 decision — a line's SKU is immutable: void + re-add is the
        // only path to a different variant. Reject loudly (not a silent
        // strip); the domain writer also 409s defense-in-depth.
        if ($request->hasAny(['product_sku_id', 'menu_product_sku_id'])) {
            abort(409, 'Item SKU cannot be edited in place. Void the line and add a new item instead.');
        }

        $data = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
            'toppings' => ['nullable', 'array'],
            'toppings.*.topping_group_item_id' => ['required_with:toppings', 'uuid'],
            'toppings.*.product_sku_id' => ['nullable', 'uuid'],
            'toppings.*.quantity' => ['nullable', 'integer', 'min:1'],
            'toppings.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'toppings.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Preserve explicit null/empty values: LAN edit mode uses them to
        // clear an old note or replace all toppings with an empty selection.
        $patch = array_intersect_key($data, $request->all());
        if ($patch) {
            $this->orders->patchWorkstationItem(new PatchWorkstationOrderItemCommand(
                OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'patch-item'),
                $customerOrder->id,
                $item->id,
                $patch,
            ));
        }

        return $this->respond($customerOrder->fresh());
    }

    public function deleteItem(Request $request, CustomerOrder $customerOrder, CustomerOrderItem $item): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);
        if ($item->customer_order_id !== $customerOrder->id) {
            abort(404);
        }

        $itemStatus = $item->status instanceof OrderItemStatusEnum
            ? $item->status->value
            : (string) $item->status;

        if ($itemStatus !== OrderItemStatusEnum::Voided->value) {
            $this->orders->softDeleteWorkstationItem(new SoftDeleteWorkstationOrderItemCommand(
                OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'soft-delete-item'),
                $customerOrder->id,
                $item->id,
            ));
        }

        return $this->respond($customerOrder->fresh());
    }

    public function voidItem(Request $request, CustomerOrder $customerOrder, CustomerOrderItem $item): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);
        if ($item->customer_order_id !== $customerOrder->id) {
            abort(404);
        }

        $data = $request->validate([
            'void_reason' => ['nullable', 'string', 'max:2000'],
            // plan-051 — optional VoidReason master id; Cloud resolves it
            // (brand + active) and drives the stock-compensation truth table.
            'void_reason_id' => ['nullable', 'uuid'],
        ]);

        $this->orders->voidWorkstationItem(new VoidWorkstationOrderItemCommand(
            OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'void-item'),
            $customerOrder->id,
            $item->id,
            $data['void_reason'] ?? 'voided_by_workstation',
            $data['void_reason_id'] ?? null,
        ));

        return $this->respond($customerOrder->fresh());
    }

    public function applyCoupon(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);

        if ($this->skipIfTerminalOrder($request, $customerOrder, 'applyCoupon')) {
            return $this->respond($customerOrder);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'customer_id' => ['nullable', 'uuid'],
            'downgrade_exclusive_promotions' => ['nullable', 'boolean'],
        ]);

        $coupon = Coupon::query()
            ->where('code', strtoupper($data['code']))
            ->where('organization_id', $customerOrder->organization_id)
            ->first();

        if (! $coupon) {
            abort(response()->json([
                'message' => "Coupon {$data['code']} not found.",
                'code' => 'COUPON_NOT_FOUND',
            ], 404));
        }

        // Cap re-check on Cloud side — the workstation already pre-validated
        // against its local replica, but the authoritative cap lives here.
        if ($coupon->usage_limit_total !== null && $coupon->times_used >= $coupon->usage_limit_total) {
            abort(response()->json([
                'message' => 'Coupon usage limit exceeded.',
                'code' => 'COUPON_USAGE_EXCEEDED',
            ], 422));
        }

        // Idempotent: if the order already has this coupon attached, return
        // its current state.
        if ($customerOrder->coupon_id === $coupon->id) {
            return $this->respond($customerOrder);
        }

        $discount = $this->pricing->computeCouponDiscount(
            CouponDiscountTerms::of($coupon->discount_type, $coupon->discount_value, $coupon->max_discount_cap),
            (float) $customerOrder->subtotal,
        );

        // #1686 — the order write and the redemption row are ONE use case
        // (coupon bound with no redemption row = a broken usage cap and broken
        // reporting), so the transaction that makes them one belongs to the
        // module that owns the use case, not to this adapter. See
        // OrderCouponService::applyWorkstationCoupon, and ADR 0001 §4.
        $this->coupons->applyWorkstationCoupon(
            new ApplyWorkstationOrderCouponCommand(
                OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'apply-coupon'),
                $customerOrder->id,
                $coupon->id,
                $coupon->code,
                $discount,
            ),
            $customerOrder,
        );

        return $this->respond($customerOrder->fresh());
    }

    public function releaseCoupon(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);

        if ($this->skipIfTerminalOrder($request, $customerOrder, 'releaseCoupon')) {
            return $this->respond($customerOrder);
        }

        if ($customerOrder->coupon_id === null) {
            return $this->respond($customerOrder);
        }

        $this->orders->releaseWorkstationCoupon(new ReleaseWorkstationOrderCouponCommand(
            OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'release-coupon'),
            $customerOrder->id,
        ));

        return $this->respond($customerOrder->fresh());
    }

    /**
     * Attach an additional table to a dine-in order. Reuses
     * CustomerOrderService::mergeTable() so the business rules
     * (already-bound check, table availability, dine-in only) match the
     * cashier-driven /pos endpoint exactly.
     */
    public function mergeTable(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);

        if ($this->skipIfTerminalOrder($request, $customerOrder, 'mergeTable')) {
            return $this->respond($customerOrder);
        }

        $data = $request->validate([
            'table_id' => ['required', 'uuid', 'exists:tables,id'],
        ]);

        // Idempotent: if the table is already bound to this order, return
        // current state. tables() is reverse HasMany on Table.current_order_id
        // so we filter by the table's PK directly.
        $alreadyBound = $customerOrder->tables()
            ->where('id', $data['table_id'])
            ->exists();
        if ($alreadyBound) {
            return $this->respond($customerOrder->fresh());
        }

        // plan-047 T2.12 (#1090) — the typed command models the RESULTING
        // table set; already-bound ids are no-ops so a queue retry is safe.
        $tableSet = new OrderTableSetPayload([
            ...$customerOrder->tables()->pluck('id')->map(fn ($id) => (string) $id)->all(),
            (string) $data['table_id'],
        ]);
        $this->orders->mergeTables(new MergeOrderTablesCommand(
            OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'merge-table'),
            (string) $customerOrder->id,
            $tableSet,
            $tableSet->fingerprint(),
        ));

        return $this->respond($customerOrder->refresh());
    }

    /**
     * Detach a previously merged table. Service layer rejects unmerging
     * the primary table with a 409; we surface it as-is so pos-web shows
     * the same copy as the cashier path.
     */
    public function unmergeTable(Request $request, CustomerOrder $customerOrder): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);

        if ($this->skipIfTerminalOrder($request, $customerOrder, 'unmergeTable')) {
            return $this->respond($customerOrder);
        }

        $data = $request->validate([
            'table_id' => ['required', 'uuid', 'exists:tables,id'],
        ]);

        // Idempotent: if the table isn't bound, return current state.
        $bound = $customerOrder->tables()
            ->where('id', $data['table_id'])
            ->exists();
        if (! $bound) {
            return $this->respond($customerOrder->fresh());
        }

        // plan-047 T2.12 (#1090) — canonical facade; the primary-table 409
        // still surfaces from the shared write path, same copy as /pos.
        $this->orders->unmergeTable(new UnmergeOrderTableCommand(
            OrderMutationContextFactory::fromWorkstationRequest($request, $customerOrder, 'unmerge-table'),
            (string) $customerOrder->id,
            (string) $data['table_id'],
        ));

        return $this->respond($customerOrder->refresh());
    }

    /**
     * Refund a payment recorded against this branch's order. Workstation
     * supplies the local `refund_id` so a queue retry after network failure
     * doesn't double-refund. Workstation also pre-computed the amount and
     * note locally; Cloud trusts those numbers and re-applies the same
     * money math the service would have run for an online refund.
     */
    public function refundPayment(Request $request, CustomerOrder $customerOrder, OrderPayment $payment): JsonResponse
    {
        $this->assertSameBranch($request, $customerOrder);
        if ($payment->customer_order_id !== $customerOrder->id) {
            abort(404);
        }

        $data = $request->validate([
            'refund_id' => ['required', 'uuid'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Idempotency key: workstation supplies its local refund_id. We
        // stamp it onto the refund payment's reference_no so a queue
        // retry can short-circuit on lookup instead of double-refunding
        // (paymentService::refund flips the original to status=refunded,
        // so the second real call would 409).
        $existing = OrderPayment::where('refund_of_id', $payment->id)
            ->where('reference_no', $data['refund_id'])
            ->first();
        if ($existing) {
            $refundedTotal = (float) OrderPayment::where('refund_of_id', $payment->id)
                ->sum(DB::raw('ABS(amount)'));

            return response()->json(['data' => [
                'id' => $payment->id,
                'refund_id' => $existing->id,
                'amount' => (float) $payment->amount,
                'refunded_amount' => $refundedTotal,
            ]]);
        }

        // The workstation's refund_id is the idempotency key the lookup above
        // reads, so it is passed INTO the refund and written in the same
        // transaction as the ledger row. Writing it afterwards left a window
        // where a crash produced an unkeyed refund that the retry would repeat.
        $refund = $this->paymentService->refund($payment, [
            'amount' => $data['amount'] ?? null,
            'note' => $data['note'] ?? null,
            'reference_no' => $data['refund_id'],
        ]);

        // Refund model stores the receipt as a NEW negative-amount payment
        // row pointing back via refund_of_id (matches Cloud); compute the
        // refunded total by summing those rows so the response shape lines
        // up with what pos-web's receipt screen displays.
        $refundedTotal = (float) OrderPayment::where('refund_of_id', $payment->id)
            ->sum(DB::raw('ABS(amount)'));

        return response()->json(['data' => [
            'id' => $payment->id,
            'refund_id' => $refund->id,
            'amount' => (float) $payment->amount,
            'refunded_amount' => $refundedTotal,
        ]]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /** #900 — closed / voided / expired orders are replay sinks only. */
    private function orderIsMutable(CustomerOrder $order): bool
    {
        return ! in_array($this->statusValue($order), [
            CustomerOrderStatusEnum::Closed->value,
            CustomerOrderStatusEnum::Voided->value,
            CustomerOrderStatusEnum::Expired->value,
        ], true);
    }

    /**
     * @return bool true when the caller should no-op and return current state
     */
    private function skipIfTerminalOrder(Request $request, CustomerOrder $order, string $action): bool
    {
        if ($this->orderIsMutable($order)) {
            return false;
        }

        Log::warning('workstation skipped mutation on terminal order', [
            'order_id' => $order->id,
            'action' => $action,
            'status' => $this->statusValue($order),
            'device_id' => $request->user()?->id,
        ]);

        return true;
    }

    /**
     * Eloquent casts CustomerOrder::status to CustomerOrderStatusEnum, so
     * `$order->status === 'voided'` always fails (enum vs string). Coerce
     * to the string value before comparing.
     */
    private function statusValue(CustomerOrder $order): string
    {
        $s = $order->status;

        return $s instanceof \BackedEnum ? $s->value : (string) $s;
    }

    private function assertSameBranch(Request $request, CustomerOrder $order): void
    {
        $device = $request->attributes->get('device');
        if ($order->branch_id !== $device?->branch_id
            || $order->organization_id !== $device?->organization_id) {
            abort(404);
        }
    }

    private function respond(CustomerOrder $order): JsonResponse
    {
        $order->load(['items', 'tables', 'customer', 'payments', 'conditions']);

        return response()->json([
            'data' => (new CustomerOrderResource($order))->toArray(request()),
        ]);
    }
}
