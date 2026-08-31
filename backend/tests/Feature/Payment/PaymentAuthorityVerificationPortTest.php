<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\DomainMutation\MutationContext;
use App\Services\Payment\Orchestration\Commands\RecordPaymentTenderCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentAuthorityVerificationPort;
use App\Services\Payment\Orchestration\Contracts\PaymentMutationFacade;
use App\Services\Payment\Orchestration\Enums\TenderKind;
use App\Services\Payment\Orchestration\Internal\EloquentPaymentAuthorityVerificationPort;
use App\Services\Payment\Orchestration\ValueObjects\PaymentTenderPayload;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgA = Organization::factory()->create();
    $this->orgB = Organization::factory()->create();
    $this->brandA = Brand::factory()->create(['console_organization_id' => $this->orgA->console_organization_id]);
    $this->branchA = Branch::factory()->create([
        'console_organization_id' => $this->orgA->console_organization_id,
        'console_brand_id' => $this->brandA->console_brand_id,
        'currency' => 'JPY',
    ]);
    $this->actorId = (string) Str::uuid();
    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgA->id,
        'branch_id' => null,
        'type' => 'cash',
    ]);
    $this->foreignMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgB->id,
        'branch_id' => null,
        'type' => 'cash',
    ]);
});

function paymentCtx(Organization $org, string $actorId, string $idempotency = 'pay-idem-1'): MutationContext
{
    return new MutationContext($org->id, $actorId, (string) Str::uuid(), $idempotency, expectedVersion: 1);
}

it('binds payment authority verification to the eloquent adapter', function () {
    expect(app(PaymentAuthorityVerificationPort::class))
        ->toBeInstanceOf(EloquentPaymentAuthorityVerificationPort::class);
});

it('resolves an in-scope cash tender through the authority port', function () {
    $ctx = paymentCtx($this->orgA, $this->actorId);
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgA->id,
        'brand_id' => $this->brandA->id,
        'branch_id' => $this->branchA->id,
        'status' => CustomerOrderStatusEnum::Checkout,
        'total_amount' => 1000,
    ]);

    $command = new RecordPaymentTenderCommand(
        $ctx,
        (string) Str::uuid(),
        $order->id,
        $this->branchA->id,
        1000,
        'JPY',
        new PaymentTenderPayload($this->cashMethod->id, TenderKind::Cash, tenderedMinor: 1000),
        authorizationReference: 'method-proof-1',
    );

    $evidence = app(PaymentAuthorityVerificationPort::class)->resolveTenderMethod($command);

    expect($evidence->paymentMethodId)->toBe($this->cashMethod->id)
        ->and($evidence->requiresTenderedAmount)->toBeTrue()
        ->and($evidence->allowsChange)->toBeTrue();
});

it('rejects cross-organization payment methods during tender resolution', function () {
    $ctx = paymentCtx($this->orgA, $this->actorId);
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgA->id,
        'brand_id' => $this->brandA->id,
        'branch_id' => $this->branchA->id,
        'status' => CustomerOrderStatusEnum::Checkout,
    ]);

    $command = new RecordPaymentTenderCommand(
        $ctx,
        (string) Str::uuid(),
        $order->id,
        $this->branchA->id,
        500,
        'JPY',
        new PaymentTenderPayload($this->foreignMethod->id, TenderKind::Cash, tenderedMinor: 500),
        authorizationReference: 'method-proof-foreign',
    );

    expect(fn () => app(PaymentAuthorityVerificationPort::class)->resolveTenderMethod($command))
        ->toThrow(InvalidArgumentException::class, 'outside tenant scope');
});

it('records a cash tender through the orchestrator facade', function () {
    $ctx = paymentCtx($this->orgA, $this->actorId, 'orch-tender-1');
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgA->id,
        'brand_id' => $this->brandA->id,
        'branch_id' => $this->branchA->id,
        'status' => CustomerOrderStatusEnum::Checkout,
        'total_amount' => 1000,
        'paid_amount' => 0,
    ]);

    $paymentId = (string) Str::uuid();
    $result = app(PaymentMutationFacade::class)->recordTender(new RecordPaymentTenderCommand(
        $ctx,
        $paymentId,
        $order->id,
        $this->branchA->id,
        1000,
        'JPY',
        new PaymentTenderPayload($this->cashMethod->id, TenderKind::Cash, tenderedMinor: 1000),
        authorizationReference: 'orch-tender-proof',
    ));

    $payment = OrderPayment::query()
        ->where('customer_order_id', $order->id)
        ->where('idempotency_key', $ctx->revealIdempotencyKey())
        ->first();

    expect($result->outcome->state->value)->toBe('succeeded')
        ->and($payment)->not->toBeNull()
        ->and((float) $payment->amount)->toBe(1000.0);
});
