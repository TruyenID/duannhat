<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Omnify\Enums\PaymentStatusEnum;
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

    $this->method = PaymentMethod::factory()->create([
        'code' => 'cash',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'is_auto_confirm' => false,
    ]);

    $this->order = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 3000,
        'paid_amount' => 0,
    ]);

    $this->pending = OrderPayment::factory()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->method->id,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'amount' => 3000,
        'status' => PaymentStatusEnum::Pending->value,
        'received_by_id' => $this->device->id,
    ]);
});

// ============================================================================
//  POST /kiosk/payments/{payment}/confirm
// ============================================================================

it('confirms a pending payment — status becomes succeeded and paid_at is stamped', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson("/api/v1/kiosk/payments/{$this->pending->id}/confirm")
        ->assertOk();

    expect($response->json('data.id'))->toBe($this->pending->id);
    expect($response->json('data.status'))->toBe('succeeded');
    expect($response->json('data.paid_at'))->not->toBeNull();

    $fresh = $this->pending->fresh();
    expect($fresh->status->value)->toBe('succeeded');
    expect($fresh->paid_at)->not->toBeNull();
});

it('rejects confirm on an already-succeeded payment with 409', function () {
    $this->pending->update(['status' => PaymentStatusEnum::Succeeded->value, 'paid_at' => now()]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson("/api/v1/kiosk/payments/{$this->pending->id}/confirm")
        ->assertStatus(409);
});

it('returns 404 when confirming a payment from another organization', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
    ]);
    $otherMethod = PaymentMethod::factory()->create([
        'code' => 'cash',
        'organization_id' => $otherOrgId,
        'branch_id' => $otherBranch->id,
    ]);
    $otherOrder = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $otherBranch->id,
        'brand_id' => $otherBrand->id,
        'organization_id' => $otherOrgId,
    ]);
    $otherDevice = Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => Str::random(32),
        'organization_id' => $otherOrgId,
        'branch_id' => $otherBranch->id,
    ]);
    $otherPayment = OrderPayment::factory()->create([
        'customer_order_id' => $otherOrder->id,
        'payment_method_id' => $otherMethod->id,
        'organization_id' => $otherOrgId,
        'branch_id' => $otherBranch->id,
        'brand_id' => $otherBrand->id,
        'status' => PaymentStatusEnum::Pending->value,
        'received_by_id' => $otherDevice->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson("/api/v1/kiosk/payments/{$otherPayment->id}/confirm")
        ->assertNotFound();
});

it('returns 401 when confirming without a device token', function () {
    $this->postJson("/api/v1/kiosk/payments/{$this->pending->id}/confirm")
        ->assertUnauthorized();
});

it('persists terminal_ref + terminal_data onto the payment metadata on confirm', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson("/api/v1/kiosk/payments/{$this->pending->id}/confirm", [
            'terminal_ref' => 'TXN-ABC-123',
            'terminal_data' => ['rrn' => '987654', 'approval' => 'A12'],
        ])
        ->assertOk();

    $meta = $this->pending->fresh()->metadata;
    expect($meta['terminal_ref'])->toBe('TXN-ABC-123');
    expect($meta['terminal_data'])->toBe(['rrn' => '987654', 'approval' => 'A12']);
});

// ============================================================================
//  POST /kiosk/payments/{payment}/fail
// ============================================================================

it('marks a pending payment as failed', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson("/api/v1/kiosk/payments/{$this->pending->id}/fail")
        ->assertOk();

    expect($response->json('data.id'))->toBe($this->pending->id);
    expect($response->json('data.status'))->toBe('failed');

    expect($this->pending->fresh()->status->value)->toBe('failed');
});

it('rejects fail on an already-succeeded payment with 409', function () {
    $this->pending->update(['status' => PaymentStatusEnum::Succeeded->value]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson("/api/v1/kiosk/payments/{$this->pending->id}/fail")
        ->assertStatus(409);
});

it('rejects fail on an already-failed payment with 409 (no double-fail)', function () {
    $this->pending->update(['status' => PaymentStatusEnum::Failed->value]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson("/api/v1/kiosk/payments/{$this->pending->id}/fail")
        ->assertStatus(409);
});

it('returns 404 when failing a payment from another branch', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $otherMethod = PaymentMethod::factory()->create([
        'code' => 'cash',
        'organization_id' => $this->orgId,
        'branch_id' => $otherBranch->id,
    ]);
    $otherOrder = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $otherBranch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $otherDevice = Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => Str::random(32),
        'organization_id' => $this->orgId,
        'branch_id' => $otherBranch->id,
    ]);
    $otherPayment = OrderPayment::factory()->create([
        'customer_order_id' => $otherOrder->id,
        'payment_method_id' => $otherMethod->id,
        'organization_id' => $this->orgId,
        'branch_id' => $otherBranch->id,
        'brand_id' => $this->brand->id,
        'status' => PaymentStatusEnum::Pending->value,
        'received_by_id' => $otherDevice->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson("/api/v1/kiosk/payments/{$otherPayment->id}/fail")
        ->assertNotFound();
});

it('returns 401 when failing without a device token', function () {
    $this->postJson("/api/v1/kiosk/payments/{$this->pending->id}/fail")
        ->assertUnauthorized();
});

it('captures reason + error_code + terminal_ref on fail', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson("/api/v1/kiosk/payments/{$this->pending->id}/fail", [
            'reason' => 'Card declined',
            'error_code' => 'DECLINED_51',
            'terminal_ref' => 'TXN-FAIL-9',
        ])
        ->assertOk();

    expect($response->json('data.status'))->toBe('failed');
    expect($response->json('data.reason'))->toBe('Card declined');
    expect($response->json('data.error_code'))->toBe('DECLINED_51');

    $meta = $this->pending->fresh()->metadata;
    expect($meta['failure_reason'])->toBe('Card declined');
    expect($meta['error_code'])->toBe('DECLINED_51');
    expect($meta['terminal_ref'])->toBe('TXN-FAIL-9');
});
