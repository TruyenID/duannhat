<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use Illuminate\Support\Str;

/**
 * #1282 — the workstation pull-DOWN must carry the payment-method identity of a
 * Cloud-confirmed payment, or the printed receipt has no 支払方法 line at all.
 *
 * An online payment (customer-web / Stripe / PayPay / konbini) is confirmed
 * here, never on the workstation, so the workstation has no local `payments`
 * row to name the method from. `payment_summary` is that label source — and it
 * is deliberately a summary, not the payment rows: the workstation must never
 * materialize these as local payments, because that table drives the Z-report /
 * 精算 and the plan-044 gap-reconciliation panel.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);

    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'total_amount' => 3000,
        'paid_amount' => 3000,
    ]);

    $this->pull = fn () => $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->getJson('/api/v1/workstation/orders');
});

it('carries the method identity of a settled payment', function () {
    $method = PaymentMethod::factory()->create([
        'code' => 'card',
        'name' => 'カード',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    OrderPayment::factory()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $method->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'amount' => 3000,
        'status' => 'succeeded',
        'paid_at' => now(),
    ]);

    $summary = ($this->pull)()->assertOk()->json('data.0.payment_summary');

    expect($summary)->toHaveCount(1);
    expect($summary[0]['payment_method_id'])->toBe($method->id);
    expect($summary[0]['payment_method_code'])->toBe('card');
    expect($summary[0]['payment_method_name'])->toBe('カード');
    expect((float) $summary[0]['amount'])->toBe(3000.0);
    expect((float) $summary[0]['net_amount'])->toBe(3000.0);
    expect($summary[0]['refunds'])->toBe([]);
});

it('lists every method of a split-paid order so the whole-order slip can name them all', function () {
    foreach ([['cash', 1000], ['qr', 2000]] as [$code, $amount]) {
        $method = PaymentMethod::factory()->create([
            'code' => $code,
            'name' => strtoupper($code),
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
        ]);

        OrderPayment::factory()->create([
            'customer_order_id' => $this->order->id,
            'payment_method_id' => $method->id,
            'branch_id' => $this->branch->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'amount' => $amount,
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);
    }

    $summary = ($this->pull)()->assertOk()->json('data.0.payment_summary');

    expect(collect($summary)->pluck('payment_method_code')->sort()->values()->all())
        ->toBe(['cash', 'qr']);
});

it('omits unsettled payments and the negative reversal row', function () {
    $method = PaymentMethod::factory()->create([
        'code' => 'cash',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $common = [
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $method->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ];

    $original = OrderPayment::factory()->create($common + [
        'amount' => 1000,
        'status' => 'refunded',
        'paid_at' => now(),
    ]);

    // A refund is its own negative row pointing at the original — it names no
    // new method and must not appear.
    OrderPayment::factory()->create($common + [
        'amount' => -1000,
        'status' => 'succeeded',
        'refund_of_id' => $original->id,
        'paid_at' => now(),
    ]);

    OrderPayment::factory()->create($common + ['amount' => 500, 'status' => 'pending']);
    OrderPayment::factory()->create($common + ['amount' => 500, 'status' => 'failed']);

    $summary = ($this->pull)()->assertOk()->json('data.0.payment_summary');

    // Only the refunded ORIGINAL — a 赤伝 still has to state how it was paid.
    expect($summary)->toHaveCount(1);
    expect((float) $summary[0]['amount'])->toBe(1000.0);
    expect((float) $summary[0]['net_amount'])->toBe(0.0);
    expect($summary[0]['status'])->toBe('refunded');
    expect($summary[0]['refunds'])->toHaveCount(1);
    expect((float) $summary[0]['refunds'][0]['amount'])->toBe(-1000.0);
    expect($summary[0]['refunds'][0]['paid_at'])->not->toBeNull();
});

it('ships the residual net amount after a partial online refund', function () {
    $method = PaymentMethod::factory()->create([
        'code' => 'stripe',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $common = [
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $method->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ];
    $original = OrderPayment::factory()->create($common + [
        'amount' => 3000,
        'status' => 'refunded',
        'paid_at' => now(),
    ]);
    OrderPayment::factory()->create($common + [
        'amount' => -800,
        'status' => 'succeeded',
        'refund_of_id' => $original->id,
        'paid_at' => now(),
    ]);

    $summary = ($this->pull)()->assertOk()->json('data.0.payment_summary');

    expect($summary)->toHaveCount(1)
        ->and((float) $summary[0]['amount'])->toBe(3000.0)
        ->and((float) $summary[0]['net_amount'])->toBe(2200.0)
        ->and($summary[0]['status'])->toBe('refunded')
        ->and($summary[0]['refunds'])->toHaveCount(1)
        ->and((float) $summary[0]['refunds'][0]['amount'])->toBe(-800.0);
});

it('returns an empty summary for an order with no settled payment', function () {
    expect(($this->pull)()->assertOk()->json('data.0.payment_summary'))->toBe([]);
});

it('names a method whose row carries no translations', function () {
    // Astrotomic resolves a translatable attribute ONLY through the
    // `translations` relation and yields null when it is empty — the trap that
    // once made the workstation print "Store" for a branch named 新宿店. The
    // resource must fall back to the base column.
    $method = PaymentMethod::factory()->create([
        'code' => 'transfer',
        'name' => 'Bank transfer',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $method->translations()->delete();

    OrderPayment::factory()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $method->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'amount' => 3000,
        'status' => 'succeeded',
        'paid_at' => now(),
    ]);

    $summary = ($this->pull)()->assertOk()->json('data.0.payment_summary');

    expect($summary[0]['payment_method_name'])->toBe('Bank transfer');
});
