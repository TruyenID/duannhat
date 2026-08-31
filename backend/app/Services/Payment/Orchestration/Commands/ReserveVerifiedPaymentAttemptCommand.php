<?php

namespace App\Services\Payment\Orchestration\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\VerifiedObjectRegistry;
use App\Services\Payment\Orchestration\ValueObjects\PaymentSplitPlan;
use App\Services\Payment\Orchestration\ValueObjects\PaymentTenderPayload;
use App\Services\Payment\Orchestration\ValueObjects\VerifiedPaymentPreparation;

final readonly class ReserveVerifiedPaymentAttemptCommand extends MutationCommand
{
    public string $attemptId;

    private function __construct(MutationContext $context, string $attemptId, public int $amountMinor, public string $currencyCode, public VerifiedPaymentPreparation $verification, public ?PaymentTenderPayload $tender = null, public ?PaymentSplitPlan $splitPlan = null)
    {
        parent::__construct($context);
        $verification->assertTrusted();
        $this->attemptId = self::uuid($attemptId, 'attemptId');
        if ($amountMinor !== $verification->money->minorAmount
            || strtoupper($currencyCode) !== $verification->money->currency
            || $this->attemptId !== $verification->attemptId
            || $context->expectedVersion !== $verification->expectedOrderVersion
            || $context->organizationId !== $verification->organizationId
            || $context->actorId !== $verification->actorId
            || $context->correlationId !== $verification->correlationId
            || $context->idempotencyKeyHash !== $verification->idempotencyKeyHash
            || $tender?->fingerprint() !== $verification->tenderFingerprint
            || $splitPlan?->fingerprint() !== $verification->splitPlanFingerprint) {
            throw new \InvalidArgumentException('Reserved attempt does not match verified preparation.');
        }
    }

    public static function fromVerifiedPreparation(MutationContext $context, string $attemptId, VerifiedPaymentPreparation $verification, ?PaymentTenderPayload $tender = null, ?PaymentSplitPlan $splitPlan = null): self
    {
        $command = new self($context, $attemptId, $verification->money->minorAmount, $verification->money->currency, $verification, $tender, $splitPlan);
        VerifiedObjectRegistry::derive($command, $verification, 'payment.verified_preparation', 'payment.reserve_verified_attempt');

        return $command;
    }

    public function assertTrusted(): void
    {
        VerifiedObjectRegistry::assertSealed($this, 'payment.reserve_verified_attempt');
    }
}
