<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\TillTenderType;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * #1156 — optional `tender_key` on POST /payments.
 *
 * The cashier picks a sub-brand behind the generic 決済端末 method; the key
 * stamps onto order_payments.tender_key so 精算 can split expected amounts
 * per brand automatically. Contract under test:
 *
 *   - NEVER required — a payment without tender_key behaves exactly as today.
 *   - When present it must be an ACTIVE till_tender_types row of the order's
 *     org (org-wide or the order's own branch) — anything else is a 422.
 *   - Persisted through BOTH write paths (auto-confirm orchestrator funnel
 *     and the pending ledger row) and echoed by OrderPaymentResource.
 *   - Pure attribution: never changes any amount.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'tender-key-shop',
        'is_active' => true,
    ]);

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->manager, $this->orgId);

    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
    $this->cardMethod = PaymentMethod::factory()->card()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    // Org vocabulary: credit + paypay active, ghost_pay inactive.
    foreach ([['credit', true], ['paypay', true], ['ghost_pay', false]] as [$key, $active]) {
        TillTenderType::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => null,
            'tender_key' => $key,
            'category' => 'qr',
            'is_active' => $active,
        ]);
    }
});

function seedTenderKeyOrder(int $total = 1000): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'ORD-TK-'.Str::random(4),
        'order_type' => 'dine_in',
        'status' => 'checkout',
        'subtotal' => $total,
        'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(), 'checkout_at' => now(),
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

function payTenderKeyOrder(CustomerOrder $order, array $overrides = [])
{
    return test()->actingAs(test()->manager)->postJson(
        '/api/v1/shops/'.test()->shop->slug."/orders/{$order->id}/payments",
        array_merge([
            'payment_method_id' => test()->cardMethod->id,
            'amount' => (float) $order->total_amount,
        ], $overrides),
    );
}

// =========================================================================
//  Optionality — absence must never block
// =========================================================================

it('creates a payment WITHOUT tender_key exactly as before (null on the row)', function () {
    $order = seedTenderKeyOrder();

    payTenderKeyOrder($order)->assertCreated()
        ->assertJsonPath('data.tender_key', null);

    expect(OrderPayment::query()->latest('created_at')->first()->tender_key)->toBeNull();
});

// =========================================================================
//  Persistence — both funnels
// =========================================================================

it('persists tender_key on a pending (non auto-confirm) payment and echoes it in the resource', function () {
    $order = seedTenderKeyOrder();

    payTenderKeyOrder($order, ['tender_key' => 'paypay'])
        ->assertCreated()
        ->assertJsonPath('data.tender_key', 'paypay');

    $payment = OrderPayment::query()->latest('created_at')->first();
    expect($payment->tender_key)->toBe('paypay')
        ->and((float) $payment->amount)->toBe((float) $order->total_amount);
});

it('persists tender_key through the auto-confirm (cash-method) funnel too', function () {
    $order = seedTenderKeyOrder();

    payTenderKeyOrder($order, [
        'payment_method_id' => $this->cashMethod->id,
        'tendered_amount' => (float) $order->total_amount,
        'tender_key' => 'credit',
    ])->assertCreated()
        ->assertJsonPath('data.tender_key', 'credit');

    expect(OrderPayment::query()->latest('created_at')->first()->tender_key)->toBe('credit');
});

it('accepts a branch-scoped tender key belonging to the order own branch', function () {
    TillTenderType::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'tender_key' => 'shop_voucher',
        'category' => 'qr',
        'is_active' => true,
    ]);
    $order = seedTenderKeyOrder();

    payTenderKeyOrder($order, ['tender_key' => 'shop_voucher'])
        ->assertCreated()
        ->assertJsonPath('data.tender_key', 'shop_voucher');
});

// =========================================================================
//  Validation — must exist + be active, tenant-scoped
// =========================================================================

it('rejects a tender_key that does not exist', function () {
    $order = seedTenderKeyOrder();

    payTenderKeyOrder($order, ['tender_key' => 'nonexistent'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tender_key']);

    expect(OrderPayment::count())->toBe(0);
});

it('rejects an inactive tender_key', function () {
    $order = seedTenderKeyOrder();

    payTenderKeyOrder($order, ['tender_key' => 'ghost_pay'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tender_key']);
});

it('rejects another organization tender_key (tenant scoped)', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    TillTenderType::factory()->create([
        'organization_id' => $otherOrgId,
        'branch_id' => null,
        'tender_key' => 'foreign_pay',
        'category' => 'qr',
        'is_active' => true,
    ]);
    $order = seedTenderKeyOrder();

    payTenderKeyOrder($order, ['tender_key' => 'foreign_pay'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tender_key']);
});

it('rejects a tender_key scoped to a DIFFERENT branch of the same org', function () {
    $otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'other-tender-shop',
    ]);
    TillTenderType::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $otherShop->id,
        'tender_key' => 'other_branch_only',
        'category' => 'qr',
        'is_active' => true,
    ]);
    $order = seedTenderKeyOrder();

    payTenderKeyOrder($order, ['tender_key' => 'other_branch_only'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tender_key']);
});

// =========================================================================
//  Attribution only — never moves money
// =========================================================================

it('does not change any amount — tender_key is attribution only', function () {
    $order = seedTenderKeyOrder(2500);

    payTenderKeyOrder($order, ['tender_key' => 'paypay'])->assertCreated();

    $payment = OrderPayment::query()->latest('created_at')->first();
    expect((float) $payment->amount)->toBe(2500.0)
        ->and((float) $payment->tip_amount)->toBe(0.0);
});
