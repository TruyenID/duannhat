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

    $this->order = CustomerOrder::factory()->paying()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 3000,
        'paid_amount' => 0,
    ]);

    $this->paymentMethod = PaymentMethod::factory()->create([
        'code' => 'qr',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'is_auto_confirm' => false,
        'requires_tendered' => false,
    ]);
});

it('returns pending status when payment is pending', function () {
    $payment = OrderPayment::factory()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->paymentMethod->id,
        'amount' => 3000,
        'status' => 'pending',
        'received_by_id' => $this->device->id,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/payments/{$payment->id}/status")
        ->assertOk();

    expect($response->json('data.id'))->toBe($payment->id);
    expect($response->json('data.status'))->toBe('pending');
});

it('returns paid status when payment succeeded', function () {
    $payment = OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->paymentMethod->id,
        'amount' => 3000,
        'received_by_id' => $this->device->id,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/payments/{$payment->id}/status")
        ->assertOk();

    expect($response->json('data.status'))->toBe('paid');
});

it('returns failed status when payment is refunded', function () {
    $payment = OrderPayment::factory()->refunded()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->paymentMethod->id,
        'amount' => 3000,
        'received_by_id' => $this->device->id,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/payments/{$payment->id}/status")
        ->assertOk();

    expect($response->json('data.status'))->toBe('failed');
});

it('returns failed status when payment has failed status', function () {
    $payment = OrderPayment::factory()->failed()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->paymentMethod->id,
        'amount' => 3000,
        'received_by_id' => $this->device->id,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/payments/{$payment->id}/status")
        ->assertOk();

    expect($response->json('data.status'))->toBe('failed');
});

it('returns 404 when payment belongs to a different branch', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
    ]);
    $otherOrder = CustomerOrder::factory()->paying()->create([
        'branch_id' => $otherBranch->id,
        'brand_id' => $otherBrand->id,
        'organization_id' => $otherOrgId,
        'total_amount' => 3000,
    ]);
    $otherMethod = PaymentMethod::factory()->create([
        'organization_id' => $otherOrgId,
        'branch_id' => $otherBranch->id,
        'is_active' => true,
    ]);
    $otherPayment = OrderPayment::factory()->create([
        'customer_order_id' => $otherOrder->id,
        'payment_method_id' => $otherMethod->id,
        'amount' => 3000,
        'status' => 'pending',
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $otherOrgId,
        'branch_id' => $otherBranch->id,
        'brand_id' => $otherBrand->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/payments/{$otherPayment->id}/status")
        ->assertNotFound();
});
