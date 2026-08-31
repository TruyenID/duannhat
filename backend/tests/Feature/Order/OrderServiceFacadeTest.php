<?php

use App\Exceptions\CouponException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Table;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Commands\AdvanceOrderItemKitchenCommand;
use App\Services\Order\Commands\ApplyOrderCouponCommand;
use App\Services\Order\Commands\BeginOrderPaymentCommand;
use App\Services\Order\Commands\CancelOrderCommand;
use App\Services\Order\Commands\ChangeOrderSplitModeCommand;
use App\Services\Order\Commands\ChangeOrderTableCommand;
use App\Services\Order\Commands\CheckoutOrderCommand;
use App\Services\Order\Commands\CloseOrderCommand;
use App\Services\Order\Commands\CommitOrderConfirmationCommand;
use App\Services\Order\Commands\ConfirmOrderCommand;
use App\Services\Order\Commands\ExpireOrderCommand;
use App\Services\Order\Commands\PromoteOrderForPaymentCommand;
use App\Services\Order\Commands\RefreshOrderPaymentCacheCommand;
use App\Services\Order\Commands\RemoveOrderItemCommand;
use App\Services\Order\Commands\RevertOrderItemKitchenCommand;
use App\Services\Order\Commands\ReviseOrderHeaderCommand;
use App\Services\Order\Commands\SettleOrderIfPaidCommand;
use App\Services\Order\Commands\StampOrderStripeIntentCommand;
use App\Services\Order\Commands\VoidAwaitingConfirmationOrderCommand;
use App\Services\Order\Commands\VoidOrderCommand;
use App\Services\Order\Commands\VoidOrderItemCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Enums\OrderSplitMode;
use App\Services\Order\OrderService;
use App\Services\Order\Results\OrderSettlementResult;
use App\Services\Order\ValueObjects\OrderHeaderPatch;
use App\Services\Order\ValueObjects\OrderTableSetPayload;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Behavioral contract for the Plan 047 T2.12 OrderService mutation facade.
 *
 * OrderService is a half-wired facade: the migrated lifecycle commands delegate
 * to the legacy implementation behind EloquentOrderPersistence, while the paths
 * still awaiting T2.13 throw explicit LogicException stubs. This suite pins both
 * halves so any regression — or the moment codex wires a stub — trips a test.
 */
beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->org->id]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->org->id,
        'console_brand_id' => $this->brand->console_brand_id,
        'currency' => 'JPY',
    ]);
    // A made_to_order SKU + oversell-enabled warehouse so close()/settlement
    // paths run without a pre-seeded StockLevel.
    Warehouse::factory()->create([
        'organization_id' => $this->org->id,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'allow_negative_sales' => true,
        'auto_approve_stock_out' => true,
    ]);
    $pt = ProductType::factory()->create(['organization_id' => $this->org->id, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'inventory_mode' => 'made_to_order',
    ]);

    $this->facade = app(OrderMutationFacade::class);
});

function orderFacadeCtx(?string $orgId): MutationContext
{
    return new MutationContext($orgId, null, (string) Str::uuid(), (string) Str::uuid(), expectedVersion: 1);
}

function facadeOrder(array $overrides = []): CustomerOrder
{
    return CustomerOrder::factory()->create(array_merge([
        'organization_id' => test()->org->id,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
        'order_type' => 'takeaway',
    ], $overrides));
}

function facadeOrderItem(CustomerOrder $order, string $status = 'pending', float $price = 500): CustomerOrderItem
{
    return $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 1,
        'unit_price' => $price,
        'original_unit_price' => $price,
        'subtotal' => $price,
        'status' => $status,
        'served_at' => $status === 'served' ? now() : null,
        'tax_rate' => 0,
    ]);
}

// =========================================================================
//  Wiring
// =========================================================================

it('binds OrderMutationFacade to OrderService', function () {
    expect($this->facade)->toBeInstanceOf(OrderService::class);
});

// =========================================================================
//  Wired lifecycle commands delegate to the legacy implementation
// =========================================================================

it('confirms a pending order (Pending → Open) and reports the change', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Pending]);

    $result = $this->facade->confirm(new ConfirmOrderCommand(orderFacadeCtx($this->org->id), $order->id));

    expect($result->changed)->toBeTrue()
        ->and($result->aggregateId)->toBe($order->id)
        ->and($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Open);
});

it('checks out an open order with an active item (→ Checkout)', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Open, 'total_amount' => 500]);
    facadeOrderItem($order, 'served');

    $result = $this->facade->checkout(new CheckoutOrderCommand(orderFacadeCtx($this->org->id), $order->id));

    expect($result->changed)->toBeTrue()
        ->and($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Checkout);
});

it('voids an open order (→ Voided) through the facade', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Open]);

    $result = $this->facade->void(new VoidOrderCommand(orderFacadeCtx($this->org->id), $order->id, 'wrong table'));

    expect($result->changed)->toBeTrue()
        ->and($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Voided)
        ->and($order->fresh()->void_reason)->toBe('wrong table');
});

it('cancels an open order (→ Voided) through the facade', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Open]);

    $result = $this->facade->cancel(new CancelOrderCommand(orderFacadeCtx($this->org->id), $order->id, 'customer left'));

    expect($result->changed)->toBeTrue()
        ->and($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Voided);
});

it('expires an overdue takeaway order (→ Expired)', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Paying, 'order_type' => 'takeaway']);
    facadeOrderItem($order, 'served');

    $result = $this->facade->expire(new ExpireOrderCommand(orderFacadeCtx($this->org->id), $order->id, 'payment_timeout'));

    expect($result->changed)->toBeTrue()
        ->and($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Expired);
});

it('revises the order header (guest count + note) through the facade', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Open, 'guest_count' => 1]);

    $result = $this->facade->reviseHeader(new ReviseOrderHeaderCommand(
        orderFacadeCtx($this->org->id),
        $order->id,
        new OrderHeaderPatch(guestCount: 4, note: 'window seat'),
    ));

    expect($result->changed)->toBeTrue()
        ->and($order->fresh()->guest_count)->toBe(4)
        ->and($order->fresh()->note)->toBe('window seat');
});

it('changes the split mode (by-people) on a paying order', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Paying, 'total_amount' => 1000, 'guest_count' => 2]);

    $result = $this->facade->changeSplitMode(new ChangeOrderSplitModeCommand(
        orderFacadeCtx($this->org->id),
        $order->id,
        OrderSplitMode::Even,
        2,
    ));

    expect($result->changed)->toBeTrue();
});

it('closes an order with items through the facade (→ Closed)', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Paying, 'total_amount' => 1000, 'paid_amount' => 1000]);
    facadeOrderItem($order, 'served');

    $result = $this->facade->close(new CloseOrderCommand(orderFacadeCtx($this->org->id), $order->id));

    expect($result->changed)->toBeTrue()
        ->and($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Closed);
});

// =========================================================================
//  Kitchen / item commands
// =========================================================================

it('advances a kitchen item (pending → preparing)', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Open]);
    $item = facadeOrderItem($order, 'pending');

    $result = $this->facade->advanceKitchenItem(new AdvanceOrderItemKitchenCommand(
        orderFacadeCtx($this->org->id),
        $order->id,
        $item->id,
        OrderItemStatusEnum::Pending,
        OrderItemStatusEnum::Preparing,
        now()->toIso8601String(),
    ));

    expect($result->changed)->toBeTrue()
        ->and($item->fresh()->status)->toBe(OrderItemStatusEnum::Preparing);
});

it('reverts a kitchen item back to pending (regression guard: targetStatus, not status)', function () {
    // EloquentOrderPersistence::revertKitchenItem read $command->status, but the
    // command only exposes targetStatus — the wrong property name made the path
    // throw on every call. This proves the corrected read.
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Open]);
    $item = facadeOrderItem($order, 'preparing');

    $result = $this->facade->revertKitchenItem(new RevertOrderItemKitchenCommand(
        orderFacadeCtx($this->org->id),
        $order->id,
        $item->id,
        OrderItemStatusEnum::Pending,
        'kitchen undo',
    ));

    expect($result->changed)->toBeTrue()
        ->and($item->fresh()->status)->toBe(OrderItemStatusEnum::Pending);
});

it('voids a kitchen item (pending item on an open order → Voided)', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Open]);
    $item = facadeOrderItem($order, 'pending');

    $result = $this->facade->voidKitchenItem(new VoidOrderItemCommand(
        orderFacadeCtx($this->org->id),
        $order->id,
        $item->id,
        OrderItemStatusEnum::Pending,
        'out of stock',
        now()->toIso8601String(),
    ));

    expect($result->changed)->toBeTrue()
        ->and($item->fresh()->status)->toBe(OrderItemStatusEnum::Voided);
});

it('removes an item through the facade (soft-void, keeps the row)', function () {
    // writerRemoveItem delegates to voidItem — the line is voided, not deleted,
    // preserving the audit/refund trail; the other line stays active.
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Open]);
    $keep = facadeOrderItem($order, 'pending');
    $drop = facadeOrderItem($order, 'pending');

    $result = $this->facade->removeItem(new RemoveOrderItemCommand(
        orderFacadeCtx($this->org->id),
        $order->id,
        $drop->id,
        'mistap',
    ));

    expect($result->changed)->toBeTrue()
        ->and($drop->fresh()->status)->toBe(OrderItemStatusEnum::Voided)
        ->and($keep->fresh()->status)->toBe(OrderItemStatusEnum::Pending);
});

// =========================================================================
//  settleIfPaid — money-critical, and the minor-amount currency contract
// =========================================================================

it('does not settle an under-paid order and reports the outstanding minor amount', function () {
    $order = facadeOrder([
        'status' => CustomerOrderStatusEnum::Paying,
        'total_amount' => 1000,
        'paid_amount' => 400,
    ]);

    $result = $this->facade->settleIfPaid(new SettleOrderIfPaidCommand(orderFacadeCtx($this->org->id), $order->id));

    expect($result)->toBeInstanceOf(OrderSettlementResult::class)
        ->and($result->settled)->toBeFalse()
        ->and($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Paying)
        // JPY is zero-decimal: ¥400 == 400 minor units, NOT 40000.
        ->and($result->settledAmountMinor)->toBe(400)
        ->and($result->currencyCode)->toBe('JPY');
});

it('does not settle right below the tolerance boundary', function () {
    // isPaidEnough: paid >= total - 2*step. JPY step = ¥1, so tolerance = ¥2.
    $order = facadeOrder([
        'status' => CustomerOrderStatusEnum::Paying,
        'total_amount' => 1000,
        'paid_amount' => 997,
    ]);

    $result = $this->facade->settleIfPaid(new SettleOrderIfPaidCommand(orderFacadeCtx($this->org->id), $order->id));

    expect($result->settled)->toBeFalse()
        ->and($result->settledAmountMinor)->toBe(997);
});

it('honours the currency exponent for the settled minor amount (USD, 2 decimals)', function () {
    $this->branch->update(['currency' => 'USD']);
    $order = facadeOrder([
        'status' => CustomerOrderStatusEnum::Paying,
        'total_amount' => 10,
        'paid_amount' => 4,
    ]);

    $result = $this->facade->settleIfPaid(new SettleOrderIfPaidCommand(orderFacadeCtx($this->org->id), $order->id));

    // $4.00 == 400 cents (exponent 2).
    expect($result->settled)->toBeFalse()
        ->and($result->settledAmountMinor)->toBe(400)
        ->and($result->currencyCode)->toBe('USD');
});

it('honours a three-decimal currency exponent (BHD)', function () {
    $this->branch->update(['currency' => 'BHD']);
    $order = facadeOrder([
        'status' => CustomerOrderStatusEnum::Paying,
        'total_amount' => 5,
        'paid_amount' => 1,
    ]);

    $result = $this->facade->settleIfPaid(new SettleOrderIfPaidCommand(orderFacadeCtx($this->org->id), $order->id));

    // 1.000 BHD == 1000 fils (exponent 3) — the old ×100 would report 100.
    expect($result->settledAmountMinor)->toBe(1000)
        ->and($result->currencyCode)->toBe('BHD');
});

it('falls back to zero minor units when the paid amount cannot be expressed in the currency', function () {
    // ¥0.50 has no zero-decimal representation; fromMajor() returns null and the
    // snapshot degrades to 0 rather than crashing the settlement.
    $order = facadeOrder([
        'status' => CustomerOrderStatusEnum::Paying,
        'total_amount' => 1000,
        'paid_amount' => 0.5,
    ]);

    $result = $this->facade->settleIfPaid(new SettleOrderIfPaidCommand(orderFacadeCtx($this->org->id), $order->id));

    expect($result->settled)->toBeFalse()
        ->and($result->settledAmountMinor)->toBe(0);
});

it('settles a fully-paid order, closes it, and snapshots the correct minor amount', function () {
    $order = facadeOrder([
        'status' => CustomerOrderStatusEnum::Paying,
        'total_amount' => 1000,
        'paid_amount' => 1000,
    ]);
    facadeOrderItem($order, 'served', 1000);

    $result = $this->facade->settleIfPaid(new SettleOrderIfPaidCommand(orderFacadeCtx($this->org->id), $order->id));

    expect($result->settled)->toBeTrue()
        ->and($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Closed)
        // ¥1000 == 1000 minor units, NOT 100000.
        ->and($result->settledAmountMinor)->toBe(1000)
        ->and($result->currencyCode)->toBe('JPY');
});

it('promotes confirmed orders to checkout for device payment collection', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Confirmed]);

    $result = $this->facade->promoteForPayment(new PromoteOrderForPaymentCommand(orderFacadeCtx($this->org->id), $order->id));

    expect($result->changed)->toBeTrue()
        ->and($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Checkout);
});

it('begins paying from checkout and is idempotent when already paying', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Checkout]);

    $this->facade->beginPaying(new BeginOrderPaymentCommand(orderFacadeCtx($this->org->id), $order->id));

    expect($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Paying);

    $this->facade->beginPaying(new BeginOrderPaymentCommand(orderFacadeCtx($this->org->id), $order->id));

    expect($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Paying);
});

it('stamps stripe_payment_intent_id through the order boundary', function () {
    $order = facadeOrder();

    $this->facade->stampStripeIntent(new StampOrderStripeIntentCommand(
        orderFacadeCtx($this->org->id),
        $order->id,
        'pi_test_intent_123',
    ));

    expect($order->fresh()->stripe_payment_intent_id)->toBe('pi_test_intent_123');
});

it('refreshes paid_amount cache from the payment ledger', function () {
    $order = facadeOrder(['paid_amount' => 0, 'total_amount' => 5000]);

    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'organization_id' => $this->org->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'amount' => 2500,
        'status' => PaymentStatusEnum::Succeeded,
    ]);

    $this->facade->refreshPaymentCache(new RefreshOrderPaymentCacheCommand(orderFacadeCtx($this->org->id), $order->id));

    expect((float) $order->fresh()->paid_amount)->toBe(2500.0);
});

// =========================================================================
//  Not-yet-wired stubs fail loudly (flip these when codex reaches T2.13)
// =========================================================================

it('commits awaiting_confirmation to pending through the facade', function () {
    $order = facadeOrder([
        'status' => CustomerOrderStatusEnum::AwaitingConfirmation,
        'confirmation_due_at' => now()->addMinutes(5),
    ]);

    $result = $this->facade->commitConfirmation(new CommitOrderConfirmationCommand(
        orderFacadeCtx($this->org->id),
        $order->id,
    ));

    expect($result->changed)->toBeTrue()
        ->and($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Pending)
        ->and($order->fresh()->confirmation_due_at)->toBeNull();
});

it('voids awaiting_confirmation orders with a typed reason', function () {
    $order = facadeOrder([
        'status' => CustomerOrderStatusEnum::AwaitingConfirmation,
        'confirmation_due_at' => now()->addMinutes(5),
    ]);

    $this->facade->voidAwaitingConfirmation(new VoidAwaitingConfirmationOrderCommand(
        orderFacadeCtx($this->org->id),
        $order->id,
        'customer_cancelled_during_confirmation',
    ));

    expect($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Voided)
        ->and($order->fresh()->void_reason)->toBe('customer_cancelled_during_confirmation');
});

// =========================================================================
//  changeTable — typed table replacement (T2.12 phase 2, #1090)
// =========================================================================

function facadeTable(string $status = 'free', ?string $orderId = null): Table
{
    $zone = Zone::factory()->for(test()->branch, 'branch')->create([
        'organization_id' => test()->org->id,
    ]);

    return Table::factory()->for(test()->branch, 'branch')->for($zone, 'zone')->create([
        'organization_id' => test()->org->id,
        'status' => $status,
        'current_order_id' => $orderId,
    ]);
}

function changeTableCmd(CustomerOrder $order, array $tableIds): ChangeOrderTableCommand
{
    $payload = new OrderTableSetPayload($tableIds);

    return new ChangeOrderTableCommand(
        orderFacadeCtx(test()->org->id),
        $order->id,
        $payload,
        $payload->fingerprint(),
    );
}

it('moves an open order from table A to table B — A ends FREE, B ends OCCUPIED and owned', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Open, 'order_type' => 'dine_in']);
    $a = facadeTable('occupied', $order->id);
    $order->forceFill(['table_id' => $a->id])->save();
    $b = facadeTable('free');

    $result = $this->facade->changeTable(changeTableCmd($order, [$b->id]));

    expect($result->changed)->toBeTrue()
        ->and($a->fresh()->current_order_id)->toBeNull()
        ->and($a->fresh()->status->value)->toBe('free')
        ->and($b->fresh()->current_order_id)->toBe($order->id)
        ->and($b->fresh()->status->value)->toBe('occupied')
        // Denormalized pointer follows the move.
        ->and($order->fresh()->table_id)->toBe($b->id);
});

it('is idempotent: replaying the already-bound set reports changed=false and touches nothing', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Open, 'order_type' => 'dine_in']);
    $a = facadeTable('occupied', $order->id);
    $order->forceFill(['table_id' => $a->id])->save();

    $result = $this->facade->changeTable(changeTableCmd($order, [$a->id]));

    expect($result->changed)->toBeFalse()
        ->and($a->fresh()->current_order_id)->toBe($order->id);
});

it('409s when the target table is occupied by ANOTHER party — never steals a live table', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Open, 'order_type' => 'dine_in']);
    $a = facadeTable('occupied', $order->id);
    $other = facadeOrder(['status' => CustomerOrderStatusEnum::Open, 'order_type' => 'dine_in']);
    $b = facadeTable('occupied', $other->id);

    expect(fn () => $this->facade->changeTable(changeTableCmd($order, [$b->id])))
        ->toThrow(HttpException::class);

    // The failed move must not have released table A mid-flight.
    expect($a->fresh()->current_order_id)->toBe($order->id)
        ->and($b->fresh()->current_order_id)->toBe($other->id);
});

it('grows the set: keeping A while adding B binds both without rebinding A', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Open, 'order_type' => 'dine_in']);
    $a = facadeTable('occupied', $order->id);
    $order->forceFill(['table_id' => $a->id])->save();
    $b = facadeTable('free');

    $this->facade->changeTable(changeTableCmd($order, [$a->id, $b->id]));

    expect($a->fresh()->current_order_id)->toBe($order->id)
        ->and($b->fresh()->current_order_id)->toBe($order->id)
        ->and($order->fresh()->table_id)->toBe($a->id);
});

it('409s on a PAYING order — tables never move under a bill being settled', function () {
    $order = facadeOrder(['status' => CustomerOrderStatusEnum::Paying, 'order_type' => 'dine_in']);
    $b = facadeTable('free');

    expect(fn () => $this->facade->changeTable(changeTableCmd($order, [$b->id])))
        ->toThrow(HttpException::class);
});

// =========================================================================
//  applyCoupon — typed coupon apply (T2.12 phase 2, #1090)
// =========================================================================

it('applies a 10% coupon to a ¥200,000 bill: exactly ¥20,000 off, counter bumped, redemption recorded', function () {
    $order = facadeOrder([
        'status' => CustomerOrderStatusEnum::Open,
        'subtotal' => 200000,
        'total_amount' => 200000,
    ]);
    $coupon = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->org->id,
        'code' => 'FACADE10',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'max_discount_cap' => 50000,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $result = $this->facade->applyCoupon(new ApplyOrderCouponCommand(
        orderFacadeCtx($this->org->id),
        $order->id,
        'facade10', // case-insensitive, exactly like the legacy transports
    ));

    $fresh = $order->fresh();
    expect($result->changed)->toBeTrue()
        ->and((float) $fresh->discount_amount)->toBe(20000.0)
        ->and($fresh->coupon_code_snapshot)->toBe('FACADE10')
        ->and($coupon->fresh()->times_used)->toBe(1)
        ->and(CouponRedemption::where('customer_order_id', $order->id)->whereNull('released_at')->count())->toBe(1);
});

it('rejects an unknown code with CouponException — the bill is untouched', function () {
    $order = facadeOrder([
        'status' => CustomerOrderStatusEnum::Open,
        'subtotal' => 5000,
        'discount_amount' => 0,
        'total_amount' => 5000,
    ]);

    expect(fn () => $this->facade->applyCoupon(new ApplyOrderCouponCommand(
        orderFacadeCtx($this->org->id),
        $order->id,
        'NO-SUCH-CODE',
    )))->toThrow(CouponException::class);

    expect((float) $order->fresh()->discount_amount)->toBe(0.0)
        ->and($order->fresh()->coupon_id)->toBeNull();
});

it('refuses to discount a CLOSED bill — settled money never changes', function () {
    $order = facadeOrder([
        'status' => CustomerOrderStatusEnum::Closed,
        'subtotal' => 5000,
        'total_amount' => 5000,
    ]);

    expect(fn () => $this->facade->applyCoupon(new ApplyOrderCouponCommand(
        orderFacadeCtx($this->org->id),
        $order->id,
        'FACADE10',
    )))->toThrow(Exception::class);

    expect((float) $order->fresh()->total_amount)->toBe(5000.0);
});
