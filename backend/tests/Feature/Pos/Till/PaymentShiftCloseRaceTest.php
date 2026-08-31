<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Till;
use App\Models\TillSession;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\OrderPaymentService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/*
 * #555 M3 — close()↔payment race. ResolveOpenTillSession resolves the till
 * session id BEFORE OrderPaymentService::create acquires the per-order lock.
 * A concurrent close()/abandon()/expire can settle the shift in that window;
 * create() must re-validate the session still owns the till before stamping
 * the payment, or the money is orphaned against an already-reconciled shift.
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
    $this->till = Till::factory()->create([
        'till_code' => 'MAIN',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $this->method = PaymentMethod::factory()->create([
        'code' => 'cash',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'is_auto_confirm' => true,
    ]);
    $this->order = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 3000,
        'paid_amount' => 0,
    ]);
});

function makeSession(string $state): TillSession
{
    return TillSession::factory()->{$state}()->create([
        'till_id' => test()->till->id,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 100_000,
    ]);
}

function payload(string $sessionId): array
{
    return [
        'customer_order_id' => test()->order->id,
        'payment_method_id' => test()->method->id,
        'amount' => 3000,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'till_session_id' => $sessionId,
    ];
}

it('rejects a payment stamped to a shift that settled in the race window', function () {
    $session = makeSession('settled');

    expect(fn () => app(OrderPaymentService::class)->create(payload($session->id)))
        ->toThrow(function (HttpResponseException $e) {
            expect($e->getResponse()->getStatusCode())->toBe(409);
            expect($e->getResponse()->getData(true)['code'])->toBe('NO_OPEN_SHIFT');
        });

    // No payment row was written — the money was NOT orphaned.
    expect(OrderPayment::where('customer_order_id', $this->order->id)->count())->toBe(0);
});

it('rejects a payment stamped to an abandoned shift', function () {
    $session = makeSession('abandoned');

    expect(fn () => app(OrderPaymentService::class)->create(payload($session->id)))
        ->toThrow(HttpResponseException::class);

    expect(OrderPayment::where('customer_order_id', $this->order->id)->count())->toBe(0);
});

it('accepts a payment stamped to a still-open shift and records the session id', function () {
    $session = makeSession('open');
    $this->till->update(['current_session_id' => $session->id]);

    $payment = app(OrderPaymentService::class)->create(payload($session->id));

    expect($payment->status)->toBe(PaymentStatusEnum::Succeeded);
    expect($payment->till_session_id)->toBe($session->id);
});
