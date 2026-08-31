<?php

/**
 * Plan 048 Gate 5 (T5.2) — workstation replay matrix: confirm / fail.
 *
 * payment.create replay (same Idempotency-Key → one row) and payment.attribute
 * convergence are already pinned by WorkstationPaymentsTest and
 * WorkstationGapClaimSyncTest; this file completes the matrix with the
 * confirm/fail replay cells and the M12 (#555) succeeded-wins race rule —
 * exactly the ops a workstation retries after an offline window (P048-D2).
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Organization;
use App\Models\PaymentMethod;
use Illuminate\Support\Str;

uses()->group('payment', 'workstation');

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

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    // Non-auto-confirm tender → sync UP lands `pending`, then the workstation
    // replays confirm/fail after the terminal resolves.
    PaymentMethod::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'code' => 'e_wallet',
        'type' => 'e_wallet',
        'is_active' => true,
        'is_auto_confirm' => false,
        'requires_tendered' => false,
    ]);

    $customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $order = CustomerOrder::create([
        'order_code' => 'ORD-WS048-'.Str::random(4),
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'subtotal' => 900,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 900,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'checkout_at' => now(),
        'customer_id' => $customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->paymentId = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'e_wallet',
        'amount' => 900,
    ])->assertCreated()->json('data.id');

    $this->orderId = $order->id;

    $this->wsPost = fn (string $action) => $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->postJson("/api/v1/workstation/payments/{$this->paymentId}/{$action}");
});

it('T5.2: confirm replay is money-idempotent — deterministic 409, ledger unchanged', function () {
    $first = ($this->wsPost)('confirm')->assertOk();
    expect($first->json('data.status'))->toBe('succeeded');

    // Transport contract: a replayed confirm on a settled payment answers a
    // deterministic 409 (the WS client treats it as already-applied) and must
    // not move any money or re-fire settlement side effects.
    ($this->wsPost)('confirm')->assertStatus(409);

    $status = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->getJson("/api/v1/workstation/payments/{$this->paymentId}/status")
        ->assertOk()
        ->json('data.status');

    expect($status)->toBe('succeeded')
        ->and((float) CustomerOrder::findOrFail($this->orderId)->paid_amount)->toBe(900.0);
});

it('T5.2: fail replay is money-idempotent — deterministic 409, still no money recorded', function () {
    ($this->wsPost)('fail')->assertOk();
    ($this->wsPost)('fail')->assertStatus(409);

    $status = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->getJson("/api/v1/workstation/payments/{$this->paymentId}/status")
        ->assertOk()
        ->json('data.status');

    expect($status)->toBe('failed')
        ->and((float) CustomerOrder::findOrFail($this->orderId)->paid_amount)->toBe(0.0);
});

it('T5.2: M12 succeeded-wins — a late fail replay cannot stomp a confirmed payment', function () {
    ($this->wsPost)('confirm')->assertOk();

    $lateFail = ($this->wsPost)('fail');

    // Whatever the transport answers (2xx no-op or 4xx rejection), the ledger
    // truth must hold: the payment stays succeeded and the money stays counted.
    expect($lateFail->status())->toBeLessThan(500);

    $status = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->getJson("/api/v1/workstation/payments/{$this->paymentId}/status")
        ->assertOk()
        ->json('data.status');

    expect($status)->toBe('succeeded')
        ->and((float) CustomerOrder::findOrFail($this->orderId)->paid_amount)->toBe(900.0);
});
