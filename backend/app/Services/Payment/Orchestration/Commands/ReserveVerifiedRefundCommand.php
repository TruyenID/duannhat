<?php

namespace App\Services\Payment\Orchestration\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\VerifiedObjectRegistry;
use App\Services\Payment\Orchestration\ValueObjects\VerifiedRefundIntent;

/** Internal persistence command produced only after PaymentAuthorityVerificationPort succeeds. */
final readonly class ReserveVerifiedRefundCommand extends MutationCommand
{
    public string $refundId;

    public string $refundRequestId;

    private function __construct(MutationContext $context, public VerifiedRefundIntent $intent)
    {
        parent::__construct($context);
        $intent->assertTrusted();
        if ($context->organizationId !== $intent->organizationId
            || $context->actorId !== $intent->actorId
            || $context->correlationId !== $intent->correlationId
            || $context->idempotencyKeyHash !== $intent->idempotencyKeyHash
            || $context->expectedVersion !== $intent->expectedVersion) {
            throw new \InvalidArgumentException('Refund reservation context does not match verified refund intent.');
        }
        $this->refundId = $intent->refundId;
        $this->refundRequestId = $intent->refundRequestId;
    }

    public static function fromVerifiedIntent(MutationContext $context, VerifiedRefundIntent $intent): self
    {
        $command = new self($context, $intent);
        VerifiedObjectRegistry::derive($command, $intent, 'payment.verified_refund', 'payment.reserve_verified_refund');

        return $command;
    }

    public function assertTrusted(): void
    {
        VerifiedObjectRegistry::assertSealed($this, 'payment.reserve_verified_refund');
    }
}
