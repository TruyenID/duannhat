<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\ShopOrderSetting;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * #876 — direct payment on Handy, gated by the per-shop
 * `handy_allow_direct_payment` toggle (default OFF).
 *
 * OFF must 403 with a machine code even if a stale app build still shows the
 * pay button; ON settles auto-confirm tenders through the SAME
 * OrderPaymentService machinery every other channel uses.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->handyToken = Str::random(64);
    Device::factory()->create([
        'type' => 'handy',
        'status' => 'active',
        'device_token' => $this->handyToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->cash = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);

    $this->order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'open',
        'total_amount' => 3000,
        'paid_amount' => 0,
    ]);
});

function handyPay(array $body = []): TestResponse
{
    return test()
        ->withHeaders(['Authorization' => 'Bearer '.test()->handyToken])
        ->postJson('/api/v1/handy/orders/'.test()->order->id.'/payments', array_merge([
            'payment_method_id' => (string) test()->cash->id,
            'amount' => 3000,
        ], $body));
}

function enableHandyPayment(): void
{
    ShopOrderSetting::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'handy_allow_direct_payment' => true,
    ]);
}

it('403s with HANDY_PAYMENT_DISABLED while the toggle is OFF (default)', function () {
    handyPay()
        ->assertForbidden()
        ->assertJsonPath('code', 'HANDY_PAYMENT_DISABLED');

    expect(OrderPayment::count())->toBe(0);
});

it('settles a cash payment at the table when the toggle is ON', function () {
    enableHandyPayment();

    handyPay()
        ->assertCreated()
        ->assertJsonPath('data.status', 'succeeded');

    $payment = OrderPayment::firstOrFail();
    expect((float) $payment->amount)->toBe(3000.0)
        ->and((string) $payment->customer_order_id)->toBe((string) $this->order->id);
});

it('rejects a non-auto-confirm tender with HANDY_METHOD_NOT_ALLOWED', function () {
    enableHandyPayment();
    $card = PaymentMethod::factory()->create([
        'organization_id' => $this->orgId,
        'code' => 'card-terminal',
        'is_auto_confirm' => false,
        'requires_tendered' => false,
    ]);

    handyPay(['payment_method_id' => (string) $card->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'HANDY_METHOD_NOT_ALLOWED');
});

it('exposes the toggle through GET /handy/settings/order', function () {
    enableHandyPayment();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->handyToken])
        ->getJson('/api/v1/handy/settings/order')
        ->assertOk()
        ->assertJsonPath('data.handy_allow_direct_payment', true);
});

it('round-trips the toggle through the shop settings PATCH', function () {
    $user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($user, $this->orgId);
    $this->branch->update(['slug' => 'handy-pay-shop']);

    $this->actingAs($user)
        ->patchJson('/api/v1/shops/handy-pay-shop/settings/order', ['handy_allow_direct_payment' => true])
        ->assertOk()
        ->assertJsonPath('data.handy_allow_direct_payment', true);

    $this->actingAs($user)
        ->getJson('/api/v1/shops/handy-pay-shop/settings/order')
        ->assertOk()
        ->assertJsonPath('data.handy_allow_direct_payment', true);
});
