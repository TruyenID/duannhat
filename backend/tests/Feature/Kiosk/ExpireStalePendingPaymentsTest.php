<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
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

    $this->method = PaymentMethod::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->order = CustomerOrder::factory()->paying()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 3000,
    ]);
});

it('expires pending payments past their deadline', function () {
    $payment = OrderPayment::factory()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->method->id,
        'amount' => 3000,
        'status' => 'pending',
        'expires_at' => now()->subMinutes(1),
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    $this->artisan('payments:expire-stale')->assertSuccessful();

    expect($payment->fresh()->status->value)->toBe('failed');
});

it('does not expire pending payments before their deadline', function () {
    $payment = OrderPayment::factory()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->method->id,
        'amount' => 3000,
        'status' => 'pending',
        'expires_at' => now()->addMinutes(10),
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    $this->artisan('payments:expire-stale')->assertSuccessful();

    expect($payment->fresh()->status->value)->toBe('pending');
});

// Issue #532 — the sweep must re-check status under lock. If a concurrent
// confirm() flips the payment to `succeeded` (and closes the order) in the
// window between the sweep's outer SELECT and its per-row UPDATE, the sweep
// must NOT clobber it back to `failed`. We simulate that interleave with a
// DB::listen hook that confirms the payment the moment the sweep's SELECT runs.
it('does not clobber a payment confirmed during the sweep window (#532)', function () {
    $payment = OrderPayment::factory()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->method->id,
        'amount' => 3000,
        'status' => 'pending',
        'expires_at' => now()->subMinutes(1),
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    $raced = false;
    DB::listen(function ($query) use (&$raced, $payment) {
        // Fire once, only on the sweep's stale-select (the only query that
        // references expires_at), simulating a concurrent confirm committing
        // right after the sweep has snapshotted the pending row.
        if (! $raced
            && stripos($query->sql, 'select') === 0
            && str_contains($query->sql, 'order_payments')
            && str_contains($query->sql, 'expires_at')) {
            $raced = true;
            OrderPayment::query()->whereKey($payment->id)->update([
                'status' => 'succeeded',
                'paid_at' => now(),
            ]);
        }
    });

    $this->artisan('payments:expire-stale')->assertSuccessful();

    expect($raced)->toBeTrue();
    // Must remain succeeded — the confirmed payment was NOT expired.
    expect($payment->fresh()->status->value)->toBe('succeeded');
});

it('does not touch succeeded payments', function () {
    $payment = OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->method->id,
        'amount' => 3000,
        'expires_at' => now()->subMinutes(30),
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    $this->artisan('payments:expire-stale')->assertSuccessful();

    expect($payment->fresh()->status->value)->toBe('succeeded');
});
