<?php

/**
 * A4 — prepay vs pay-after close gating. A kiosk takeaway order is prepay
 * (pay before the kitchen makes it), so it must close on full payment even
 * while items are still unserved. Dine-in used to keep a served-before-close
 * guard, but plan-024 changed it: a full payment auto-marks unserved items
 * served and closes (trust the payment signal) rather than blocking the pay
 * flow with 409. Both order types now close on full payment.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\OrderClosingService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->deviceToken = Str::random(32);
    $this->device = Device::factory()->create([
        'type' => 'kiosk', 'status' => 'active', 'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId, 'branch_id' => $this->branch->id,
    ]);
    $this->method = PaymentMethod::factory()->create([
        'code' => 'cash', 'organization_id' => $this->orgId, 'branch_id' => $this->branch->id,
        'is_active' => true, 'is_auto_confirm' => false,
    ]);
    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
    ]);
    // Default inventory_mode = made_to_order → close() does no stock movement.
    $this->sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 1000, 'is_active' => true]);
});

function kpcSeedOrderWithUnservedItem(string $orderType): CustomerOrder
{
    $order = CustomerOrder::factory()->checkout()->create([
        'order_type' => $orderType,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        'subtotal' => 1000,
        'total_amount' => 1000,
        'paid_amount' => 0,
    ]);
    $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 1,
        'unit_price' => 1000,
        'original_unit_price' => 1000,
        'subtotal' => 1000,
        'status' => 'preparing', // deliberately NOT served
        'tax_rate' => 0,
    ]);

    return $order->refresh();
}

function kpcPendingPayment(CustomerOrder $order): OrderPayment
{
    return OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => test()->method->id,
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'amount' => 1000,
        'status' => PaymentStatusEnum::Pending->value,
        'received_by_id' => test()->device->id,
    ]);
}

it('closes a takeaway (prepay) order on full payment even with unserved items', function () {
    $order = kpcSeedOrderWithUnservedItem('takeaway');
    $payment = kpcPendingPayment($order);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson("/api/v1/kiosk/payments/{$payment->id}/confirm")
        ->assertOk();

    expect($order->fresh()->status->value)->toBe('closed');
});

it('closes despite a JPY rounding gap (paid ¥2 < total) + reconciles paid to total', function () {
    // total_amount is roundUpToStep(subtotal+tax+service), so a client can pay a
    // couple yen under it. Before the tolerance fix the order stuck at `paying`
    // (never "Hoàn thành"). isPaidEnough() tolerates the rounding gap; close()
    // then absorbs the remainder into paid_amount.
    $order = CustomerOrder::factory()->checkout()->create([
        'order_type' => 'takeaway',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'subtotal' => 1062.50,
        'total_amount' => 1224,
        'paid_amount' => 0,
    ]);
    $order->items()->create([
        'product_sku_id' => $this->sku->id, 'quantity' => 1, 'unit_price' => 1224, 'original_unit_price' => 1224,
        'subtotal' => 1224, 'status' => 'preparing',
        'tax_rate' => 0,
    ]);

    $payment = OrderPayment::factory()->create([
        'customer_order_id' => $order->id, 'payment_method_id' => $this->method->id,
        'organization_id' => $this->orgId, 'branch_id' => $this->branch->id, 'brand_id' => $this->brand->id,
        'amount' => 1222, // ¥2 under total — rounding gap
        'status' => PaymentStatusEnum::Pending->value, 'received_by_id' => $this->device->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson("/api/v1/kiosk/payments/{$payment->id}/confirm")
        ->assertOk();

    $fresh = $order->fresh();
    expect($fresh->status->value)->toBe('closed');
    expect((float) $fresh->paid_amount)->toBe(1224.0); // remainder absorbed
});

it('still rejects a real underpayment beyond the rounding tolerance', function () {
    $order = CustomerOrder::factory()->checkout()->create([
        'order_type' => 'takeaway',
        'branch_id' => $this->branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
        'subtotal' => 1000, 'total_amount' => 1000, 'paid_amount' => 0,
    ]);
    $order->items()->create([
        'product_sku_id' => $this->sku->id, 'quantity' => 1, 'unit_price' => 1000, 'original_unit_price' => 1000,
        'subtotal' => 1000, 'status' => 'preparing',
        'tax_rate' => 0,
    ]);
    // ¥100 short — far beyond the ¥2 tolerance → must NOT close.
    expect(OrderClosingService::isPaidEnough(
        tap($order)->fill(['paid_amount' => 900])
    ))->toBeFalse();
});

it('auto-serves unserved items and closes a dine-in order on full payment (plan-024)', function () {
    // plan-024: a full payment is treated as the customer having received the
    // food, so OrderClosingService auto-marks unserved dine-in items as served
    // and closes — instead of the old abort(409), which jammed the kiosk pay
    // flow (see OrderClosingService::close, "trust payment signal").
    $order = kpcSeedOrderWithUnservedItem('dine_in');
    $payment = kpcPendingPayment($order);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson("/api/v1/kiosk/payments/{$payment->id}/confirm")
        ->assertOk();

    $order->refresh();
    expect($order->status->value)->toBe('closed');
    // The previously-unserved item is auto-marked served at close time.
    expect($order->items()->where('status', '!=', 'served')->whereNull('voided_at')->count())->toBe(0);
});
