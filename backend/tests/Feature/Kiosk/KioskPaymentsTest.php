<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use Illuminate\Support\Str;

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

    $this->deviceToken = Str::random(32);

    $this->device = Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    // QR payment method (not auto-confirm, no tendered required)
    $this->qrMethod = PaymentMethod::factory()->create([
        'code' => 'qr',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'is_auto_confirm' => false,
        'requires_tendered' => false,
    ]);

    $this->order = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 3000,
        'paid_amount' => 0,
    ]);
});

it('creates a pending payment and returns payment_id and reference_no', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'qr',
            'amount' => 3000,
        ])
        ->assertCreated();

    expect($response->json('data'))->toHaveKeys([
        'payment_id', 'reference_no', 'status', 'method', 'qr_url', 'amount_paid', 'expires_at', 'confirm_type',
    ]);
    expect($response->json('data.status'))->toBe('pending');
    expect($response->json('data.method'))->toBe('qr');
    expect($response->json('data.qr_url'))->toBeNull();
    expect($response->json('data.amount_paid'))->toBe(3000.0);

    expect(OrderPayment::where('customer_order_id', $this->order->id)->count())->toBe(1);
    expect(OrderPayment::first()->payment_method_id)->toBe($this->qrMethod->id);
});

it('accepts payment_method as an alias for method (kiosk contract)', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'payment_method' => 'qr',
            'amount' => 3000,
        ])
        ->assertCreated();

    expect($response->json('data.status'))->toBe('pending');
    expect($response->json('data.method'))->toBe('qr');
    expect(OrderPayment::first()->payment_method_id)->toBe($this->qrMethod->id);
});

it('rejects a payment with neither method nor payment_method', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'amount' => 3000,
        ])
        ->assertStatus(422);
});

it('resolves system-wide payment method (branch_id IS NULL) when no branch-scoped method exists', function () {
    $systemMethod = PaymentMethod::factory()->create([
        'code' => 'system_qr',
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_active' => true,
        'is_auto_confirm' => false,
        'requires_tendered' => false,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'system_qr',
            'amount' => 3000,
        ])
        ->assertCreated();

    expect($response->json('data.status'))->toBe('pending');
    expect(OrderPayment::first()->payment_method_id)->toBe($systemMethod->id);
});

it('uses device id as received_by_id', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'qr',
            'amount' => 3000,
        ])
        ->assertCreated();

    expect(OrderPayment::first()->received_by_id)->toBe($this->device->id);
});

it('returns 422 when method code does not exist for this branch', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'nonexistent',
            'amount' => 3000,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Payment method not available');
});

it('returns 422 when method is inactive', function () {
    PaymentMethod::factory()->create([
        'code' => 'disabled_method',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => false,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'disabled_method',
            'amount' => 3000,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Payment method not available');
});

it('returns 409 when order is already closed', function () {
    $closedOrder = CustomerOrder::factory()->closed()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 3000,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $closedOrder->id,
            'method' => 'qr',
            'amount' => 3000,
        ])
        ->assertConflict();
});

it('returns 422 when amount exceeds remaining balance', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'qr',
            'amount' => 9999,
        ])
        ->assertUnprocessable();
});

it('returns 404 when order belongs to a different branch', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
    ]);
    $otherOrder = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $otherBranch->id,
        'brand_id' => $otherBrand->id,
        'organization_id' => $otherOrgId,
        'total_amount' => 3000,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $otherOrder->id,
            'method' => 'qr',
            'amount' => 3000,
        ])
        ->assertNotFound();
});

it('returns expires_at and confirm_type in response for non-auto-confirm method', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'qr',
            'amount' => 3000,
        ])
        ->assertCreated();

    expect($response->json('data.expires_at'))->not->toBeNull();
    expect($response->json('data.confirm_type'))->toBe('manual');
});

it('returns confirm_type auto for auto-confirm method', function () {
    // Auto-confirm but does NOT require tendered (e.g. e-wallet tap)
    PaymentMethod::factory()->create([
        'code' => 'tap',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'is_auto_confirm' => true,
        'requires_tendered' => false,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'tap',
            'amount' => 3000,
        ])
        ->assertCreated();

    expect($response->json('data.confirm_type'))->toBe('auto');
    expect($response->json('data.expires_at'))->toBeNull();
});

it('returns same payment when Idempotency-Key header is repeated', function () {
    $idempotencyKey = (string) Str::uuid();

    $response1 = $this->withHeaders([
        'Authorization' => "Bearer {$this->deviceToken}",
        'Idempotency-Key' => $idempotencyKey,
    ])->postJson('/api/v1/kiosk/payments', [
        'order_id' => $this->order->id,
        'method' => 'qr',
        'amount' => 3000,
    ])->assertCreated();

    $response2 = $this->withHeaders([
        'Authorization' => "Bearer {$this->deviceToken}",
        'Idempotency-Key' => $idempotencyKey,
    ])->postJson('/api/v1/kiosk/payments', [
        'order_id' => $this->order->id,
        'method' => 'qr',
        'amount' => 3000,
    ])->assertCreated();

    expect($response1->json('data.payment_id'))->toBe($response2->json('data.payment_id'));
    expect(OrderPayment::where('customer_order_id', $this->order->id)->count())->toBe(1);
});

it('returns 422 when amount is zero', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'qr',
            'amount' => 0,
        ])
        ->assertUnprocessable();
});

it('accepts a cash payment with tendered_amount and records change', function () {
    $cash = PaymentMethod::factory()->create([
        'code' => 'cash',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'is_auto_confirm' => true,
        'requires_tendered' => true,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'cash',
            'amount' => 3000,
            'tendered_amount' => 5000,
        ])
        ->assertCreated();

    expect($response->json('data.status'))->toBe('succeeded');
    expect($response->json('data.method'))->toBe('cash');

    $payment = OrderPayment::where('customer_order_id', $this->order->id)->first();
    expect($payment->payment_method_id)->toBe($cash->id);
    expect((float) $payment->tendered_amount)->toBe(5000.0);
    expect((float) $payment->change_amount)->toBe(2000.0);
});

it('returns 422 for a cash payment missing tendered_amount', function () {
    PaymentMethod::factory()->create([
        'code' => 'cash',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'is_auto_confirm' => true,
        'requires_tendered' => true,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'cash',
            'amount' => 3000,
        ])
        ->assertUnprocessable();
});
