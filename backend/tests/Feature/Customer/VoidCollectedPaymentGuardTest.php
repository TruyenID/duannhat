<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * #547 — voiding an order that already collected money (split-bill cash
 * auto-confirms → succeeded) used to orphan the payment: still succeeded,
 * still counted into the shift's revenue + expected_cash, no refund, no trace.
 *
 * Both cloud void paths must now refuse the void while any money is
 * net-collected (structured 409 `void_blocked_collected_payment`) so staff
 * refund first. Refunds net paid_amount back to 0, which re-permits the void.
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
    $this->method = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

function vcpOrder(string $status = 'paying', float $total = 800): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'ORD-'.Str::random(6),
        'order_type' => 'dine_in',
        'status' => $status,
        'subtotal' => $total, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => $total, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

function vcpPayment(CustomerOrder $order, float $amount, string $status = 'succeeded', ?string $refundOf = null): OrderPayment
{
    return OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => test()->method->id,
        'amount' => $amount,
        'status' => $status,
        'paid_at' => now(),
        'refund_of_id' => $refundOf,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
    ]);
}

// ── Cloud CustomerOrderService::voidOrder ────────────────────────────────────

it('blocks cloud void when the order has a collected (succeeded) payment', function () {
    $order = vcpOrder();
    vcpPayment($order, 300); // partial cash, succeeded

    try {
        app(CustomerOrderService::class)->voidOrder($order, ['void_reason' => 'mistake']);
        $this->fail('Expected the void to be blocked.');
    } catch (HttpResponseException $e) {
        $resp = $e->getResponse();
        expect($resp->getStatusCode())->toBe(409);
        $body = json_decode($resp->getContent(), true);
        expect($body['code'])->toBe('void_blocked_collected_payment')
            ->and($body['collected_amount'])->toBe('300.00');
    }

    // Order untouched — not voided, payment still there.
    expect($order->fresh()->status->value)->toBe('paying');
});

it('allows cloud void when the order has no collected payments', function () {
    $order = vcpOrder();

    $result = app(CustomerOrderService::class)->voidOrder($order, ['void_reason' => 'mistake']);

    expect($result->status->value)->toBe('voided');
});

it('allows cloud void after the collected payment is fully refunded (nets to 0)', function () {
    $order = vcpOrder();
    $paid = vcpPayment($order, 300, 'refunded');   // original, marked refunded
    vcpPayment($order, -300, 'succeeded', $paid->id); // negative refund row

    $result = app(CustomerOrderService::class)->voidOrder($order, ['void_reason' => 'refunded then void']);

    expect($result->status->value)->toBe('voided');
});

it('ignores pending payments (no cash actually collected) and permits the void', function () {
    $order = vcpOrder();
    vcpPayment($order, 300, 'pending');

    $result = app(CustomerOrderService::class)->voidOrder($order, ['void_reason' => 'abandoned card']);

    expect($result->status->value)->toBe('voided');
});

// ── Workstation OrderLifecycleController::void (LAN replay) ───────────────────

it('blocks the workstation LAN void when the order has a collected payment', function () {
    $token = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $order = vcpOrder();
    vcpPayment($order, 300);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson("/api/v1/workstation/orders/{$order->id}/void", [
            'void_reason' => 'lan void',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'void_blocked_collected_payment')
        ->assertJsonPath('collected_amount', '300.00');

    expect($order->fresh()->status->value)->toBe('paying');
});
