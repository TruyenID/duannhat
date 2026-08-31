<?php

namespace App\Services\Payment\Orchestration\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\VerifiedObjectRegistry;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Orchestration\Enums\PaymentObligation;
use App\Services\Payment\Orchestration\ValueObjects\PaymentSplitPlan;
use App\Services\Payment\Orchestration\ValueObjects\PaymentTenderPayload;
use App\Services\Payment\Orchestration\ValueObjects\ResolvedPaymentMethodEvidence;
use InvalidArgumentException;

/** Internal command which can only be derived from authority-verified tender evidence. */
final readonly class RecordResolvedPaymentTenderCommand extends MutationCommand
{
    public Money $amount;

    private function __construct(
        RecordPaymentTenderCommand $request,
        public PaymentTenderPayload $tender,
        public ResolvedPaymentMethodEvidence $method,
        public ?PaymentSplitPlan $splitPlan,
        public ?int $changeMinor,
        public string $resolvedFingerprint,
    ) {
        parent::__construct($request->context);
        $method->assertTrusted();
        $this->orderPaymentId = $request->orderPaymentId;
        $this->orderId = $request->orderId;
        $this->branchId = $request->branchId;
        $this->amount = new Money($request->amountMinor, $request->currencyCode);

        if (! hash_equals($method->requestFingerprint, $request->requestFingerprint)
            || ! hash_equals($resolvedFingerprint, $request->requestFingerprint)
            || $method->paymentMethodId !== $tender->paymentMethodId
            || $method->tenderKind !== $tender->method
            || $method->obligation !== $tender->obligation) {
            throw new InvalidArgumentException('Resolved method capability is not bound to this tender request.');
        }
        if ($method->requiresTenderedAmount && $tender->tenderedMinor === null) {
            throw new InvalidArgumentException('Resolved payment method requires tendered amount.');
        }
        if ($changeMinor !== null && ($changeMinor < 0 || ! $method->allowsChange)) {
            throw new InvalidArgumentException('Change must be non-negative and supported by the resolved method.');
        }
        if ($tender->tenderedMinor !== null && $tender->tenderedMinor < $this->amount->minorAmount + $tender->tipMinor) {
            throw new InvalidArgumentException('Tendered money cannot be less than amount plus tip.');
        }
        $expectedChange = $tender->tenderedMinor === null ? null : $tender->tenderedMinor - $this->amount->minorAmount - $tender->tipMinor;
        if ($changeMinor !== $expectedChange && ! ($changeMinor === null && $expectedChange === 0)) {
            throw new InvalidArgumentException('Change does not reconcile with tendered money.');
        }
        $isDebt = in_array($tender->obligation, [PaymentObligation::Debt, PaymentObligation::DebtSettlement], true);
        if (($isDebt && ! $method->allowsDebt) || ($tender->obligation === PaymentObligation::DebtSettlement) !== ($tender->debtPaymentId !== null)) {
            throw new InvalidArgumentException('Debt capability and debt settlement tuple are inconsistent.');
        }
    }

    public string $orderPaymentId;

    public string $orderId;

    public string $branchId;

    public static function fromVerifiedMethod(RecordPaymentTenderCommand $request, ResolvedPaymentMethodEvidence $method, ?int $changeMinor = null): self
    {
        $command = new self($request, $request->tender, $method, $request->splitPlan, $changeMinor, $request->requestFingerprint);
        VerifiedObjectRegistry::derive($command, $method, 'payment.resolved_method', 'payment.record_resolved_tender');

        return $command;
    }

    public function assertTrusted(): void
    {
        VerifiedObjectRegistry::assertSealed($this, 'payment.record_resolved_tender');
    }
}
