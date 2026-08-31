<?php

namespace App\Services\Payment\Orchestration\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use App\Services\Payment\Orchestration\Enums\PaymentObligation;
use App\Services\Payment\Orchestration\Enums\TenderKind;

final readonly class PaymentTenderPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $paymentMethodId;

    public ?string $tillSessionId;

    public ?string $debtPaymentId;

    public ?string $allocationId;

    public ?string $reference;

    public function __construct(string $paymentMethodId, public TenderKind $method, public int $tipMinor = 0, public ?int $tenderedMinor = null, ?string $reference = null, ?string $tillSessionId = null, public PaymentObligation $obligation = PaymentObligation::Immediate, ?string $allocationId = null, ?string $debtPaymentId = null)
    {
        $this->paymentMethodId = MutationCommand::uuid($paymentMethodId, 'paymentMethodId');
        $this->tillSessionId = MutationCommand::nullableUuid($tillSessionId, 'tillSessionId');
        $this->debtPaymentId = MutationCommand::nullableUuid($debtPaymentId, 'debtPaymentId');
        $this->allocationId = MutationCommand::nullableUuid($allocationId, 'allocationId');
        $this->reference = $reference === null ? null : MutationCommand::safeToken($reference, 'reference', 255);
        if ($tipMinor < 0 || ($tenderedMinor !== null && $tenderedMinor < 0)) {
            throw new \InvalidArgumentException('Payment tender values are invalid.');
        }
        if (($obligation === PaymentObligation::Split) !== ($allocationId !== null)
            || ($obligation === PaymentObligation::DebtSettlement) !== ($this->debtPaymentId !== null)) {
            throw new \InvalidArgumentException('Tender method, obligation, split allocation, and debt tuple is inconsistent.');
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'payment_method_id' => $this->paymentMethodId, 'method' => $this->method->value,
            'tip_minor' => $this->tipMinor, 'tendered_minor' => $this->tenderedMinor,
            'reference' => $this->reference,
            'till_session_id' => $this->tillSessionId, 'obligation' => $this->obligation->value,
            'allocation_id' => $this->allocationId,
            'debt_payment_id' => $this->debtPaymentId,
        ];
    }
}
