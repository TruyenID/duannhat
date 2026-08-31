<?php

namespace App\Services\Payment\Orchestration\ValueObjects;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\VerificationAuthority;
use App\Services\DomainMutation\VerifiedObjectRegistry;
use App\Services\Payment\Orchestration\Commands\PreparePaymentCommand;
use App\Services\Payment\Orchestration\Commands\RecordPaymentTenderCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentAuthorityVerificationPort;
use App\Services\Payment\Orchestration\Enums\PaymentObligation;
use App\Services\Payment\Orchestration\Enums\TenderKind;

/** Returned only by PaymentAuthorityVerificationPort after resolving the persisted PaymentMethod revision. */
final readonly class ResolvedPaymentMethodEvidence implements \JsonSerializable
{
    public string $paymentMethodId;

    private function __construct(string $paymentMethodId, public int $revision, public bool $requiresTenderedAmount, public bool $allowsChange, public bool $allowsDebt, public ?TenderKind $tenderKind, public ?PaymentObligation $obligation, public string $requestFingerprint)
    {
        $this->paymentMethodId = MutationCommand::uuid($paymentMethodId, 'paymentMethodId');
        if ($revision < 1) {
            throw new \InvalidArgumentException('Payment method revision must be positive.');
        }
    }

    public static function issue(PaymentAuthorityVerificationPort $verifier, VerificationAuthority $authority, RecordPaymentTenderCommand $command, int $revision, bool $requiresTenderedAmount, bool $allowsChange, bool $allowsDebt): self
    {
        $value = new self($command->tender->paymentMethodId, $revision, $requiresTenderedAmount, $allowsChange, $allowsDebt, $command->tender->method, $command->tender->obligation, $command->requestFingerprint);
        VerifiedObjectRegistry::seal($value, $verifier, $authority, 'payment.resolved_method', PaymentAuthorityVerificationPort::class);

        return $value;
    }

    public static function issueForPreparation(PaymentAuthorityVerificationPort $verifier, VerificationAuthority $authority, PreparePaymentCommand $command, string $paymentMethodId, int $revision, bool $requiresTenderedAmount, bool $allowsChange, bool $allowsDebt): self
    {
        $value = new self($paymentMethodId, $revision, $requiresTenderedAmount, $allowsChange, $allowsDebt, $command->tender?->method, $command->tender?->obligation, $command->requestFingerprint);
        VerifiedObjectRegistry::seal($value, $verifier, $authority, 'payment.resolved_method', PaymentAuthorityVerificationPort::class);

        return $value;
    }

    public function assertTrusted(): void
    {
        VerifiedObjectRegistry::assertSealed($this, 'payment.resolved_method');
    }

    public function jsonSerialize(): array
    {
        return ['payment_method_id' => $this->paymentMethodId, 'revision' => $this->revision, 'requires_tendered_amount' => $this->requiresTenderedAmount, 'allows_change' => $this->allowsChange, 'allows_debt' => $this->allowsDebt, 'tender_kind' => $this->tenderKind?->value, 'obligation' => $this->obligation?->value, 'request_fingerprint' => $this->requestFingerprint];
    }
}
