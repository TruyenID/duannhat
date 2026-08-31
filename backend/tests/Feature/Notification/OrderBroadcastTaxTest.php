<?php

use App\Events\OrderItemAdded;
use App\Events\OrderPaymentRecorded;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Services\Customer\OrderPricingCalculator;
use Illuminate\Support\Str;

/**
 * plan-043 T5.8 — broadcast consumer check. After the per-rate engine
 * refactor, OrderItemAdded / OrderPaymentRecorded must still emit correct
 * scalar values (subtotal / total / paid_amount / remaining_amount) for a
 * mixed-rate order. The events are additive — no new tax keys — but the
 * numbers must reconcile with the once-per-group engine output.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'currency_code' => 'JPY',
        'service_charge_rate' => 0,
        'prices_include_tax' => false,
    ]);
});

/**
 * Build a tax-excluded mixed-rate order: bentō ¥1,000 @8% + beer ¥500 @10%
 * (spec §6.1 proof case). Prices it through the engine and persists scalar
 * totals so the events read the authoritative once-per-group figures.
 */
function makeMixedRateOrder(bool $withTableSession = false): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'BC-'.Str::random(5),
        'order_type' => 'takeaway',
        'status' => 'open',
        'is_tax_included' => false,
        'subtotal' => 0,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 0,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        ...($withTableSession ? ['table_session_id' => (string) Str::uuid()] : []),
    ]);

    $bentoSku = ProductSku::factory()->create(['selling_price' => 1000]);
    $beerSku = ProductSku::factory()->create(['selling_price' => 500]);

    CustomerOrderItem::create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $bentoSku->id,
        'quantity' => 1,
        'unit_price' => 1000,
        'original_unit_price' => 1000,
        'subtotal' => 1000,
        'tax_rate' => 8,
        'tax_amount' => 0,
        'status' => 'pending',
    ]);
    CustomerOrderItem::create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $beerSku->id,
        'quantity' => 1,
        'unit_price' => 500,
        'original_unit_price' => 500,
        'subtotal' => 500,
        'tax_rate' => 10,
        'tax_amount' => 0,
        'status' => 'pending',
    ]);

    $setting = ShopOrderSetting::where('branch_id', $order->branch_id)->first();
    $pricing = app(OrderPricingCalculator::class)->forOrder($order->fresh('items'), $setting);
    $order->update([
        'subtotal' => $pricing->subtotal,
        'tax_amount' => $pricing->taxAmount,
        'service_charge' => $pricing->serviceCharge,
        'total_amount' => $pricing->totalAmount,
    ]);

    return $order->fresh('items');
}

it('OrderItemAdded emits correct subtotal + total for a mixed-rate order', function () {
    $order = makeMixedRateOrder(withTableSession: true);

    // 8% group ¥1,000 → tax 80 · 10% group ¥500 → tax 50 · total 1,630.
    expect((float) $order->subtotal)->toBe(1500.0)
        ->and((float) $order->tax_amount)->toBe(130.0)
        ->and((float) $order->total_amount)->toBe(1630.0);

    $payload = (new OrderItemAdded($order))->broadcastWith();

    expect($payload['order_id'])->toBe($order->id)
        ->and($payload['items_count'])->toBe(2)
        ->and($payload['subtotal'])->toBe(1500.0)
        ->and($payload['total'])->toBe(1630.0);
});

it('OrderPaymentRecorded emits correct paid + remaining for a partial payment', function () {
    $order = makeMixedRateOrder();

    // Pay ¥630 of the ¥1,630 order → remaining ¥1,000.
    $order->update(['paid_amount' => 630]);
    $payment = OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id,
        'amount' => 630,
        'organization_id' => test()->orgId,
    ]);

    $payload = (new OrderPaymentRecorded($order->fresh(), $payment))->broadcastWith();

    expect($payload['order_id'])->toBe($order->id)
        ->and($payload['payment_id'])->toBe($payment->id)
        ->and($payload['paid_amount'])->toBe(630.0)
        ->and($payload['remaining_amount'])->toBe(1000.0);
});
