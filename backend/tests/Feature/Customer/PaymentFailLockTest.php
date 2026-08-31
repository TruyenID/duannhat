<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\OrderPaymentService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * #555 M12 — confirm()/fail() race. fail() used to run a read-check-write
 * against an UNLOCKED row in the Workstation/Kiosk controllers, so a
 * concurrent confirm() could flip the row to succeeded between the read and
 * the failing write, stomping succeeded → failed. fail() now runs under the
 * same row lock as confirm() and re-checks status, and recomputes the order's
 * paid_amount cache from the ledger.
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
    $this->method = PaymentMethod::factory()->create([
        'code' => 'cash',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'is_auto_confirm' => false,
    ]);
    $this->order = CustomerOrder::factory()->paying()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 3000,
        'paid_amount' => 3000,
    ]);
});

function pendingPayment(): OrderPayment
{
    return OrderPayment::factory()->create([
        'customer_order_id' => test()->order->id,
        'payment_method_id' => test()->method->id,
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'amount' => 3000,
        'status' => PaymentStatusEnum::Pending->value,
        'received_by_id' => (string) Str::uuid(),
    ]);
}

it('refuses to fail a succeeded payment (no succeeded → failed stomp)', function () {
    $succeeded = OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->method->id,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'amount' => 3000,
        'received_by_id' => (string) Str::uuid(),
    ]);

    expect(fn () => app(OrderPaymentService::class)->fail($succeeded))
        ->toThrow(function (HttpException $e) {
            expect($e->getStatusCode())->toBe(409);
        });

    expect($succeeded->fresh()->status)->toBe(PaymentStatusEnum::Succeeded);
});

it('fails a pending payment and merges the failure metadata under the lock', function () {
    $pending = pendingPayment();

    $result = app(OrderPaymentService::class)->fail($pending, [
        'failure_reason' => 'card_declined',
        'error_code' => 'DECLINE',
        'ignored_null' => null,
    ]);

    expect($result->status)->toBe(PaymentStatusEnum::Failed);

    $meta = $pending->fresh()->metadata;
    expect($meta['failure_reason'])->toBe('card_declined');
    expect($meta['error_code'])->toBe('DECLINE');
    expect($meta)->not->toHaveKey('ignored_null');
});

it('leaves paid_amount untouched when failing a pending payment (pending never counted)', function () {
    $pending = pendingPayment();

    app(OrderPaymentService::class)->fail($pending);

    // A succeeded 3000 payment already backs paid_amount; recompute must keep it.
    OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->method->id,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'amount' => 3000,
        'received_by_id' => (string) Str::uuid(),
    ]);

    // Fail a fresh pending — the recompute must not double-count or drop the
    // already-succeeded amount.
    $another = pendingPayment();
    app(OrderPaymentService::class)->fail($another);

    expect((float) $this->order->fresh()->paid_amount)->toBe(3000.0);
});
