<?php

namespace App\Services\Payment\Orchestration\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Payment\Orchestration\ValueObjects\RefundRequestPayload;
use InvalidArgumentException;

final readonly class RequestPaymentRefundCommand extends MutationCommand
{
    public string $refundId;

    public string $attemptId;

    public string $refundRequestId;

    public string $payloadFingerprint;

    public string $requestFingerprint;

    public string $branchId;

    public string $authorizationReference;

    public function __construct(MutationContext $context, string $refundId, string $branchId, string $refundRequestId, public RefundRequestPayload $payload, string $payloadFingerprint, string $authorizationReference)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->refundId = self::uuid($refundId, 'refundId');
        $this->attemptId = $payload->attemptId;
        $this->branchId = self::uuid($branchId, 'branchId');
        $this->refundRequestId = self::uuid($refundRequestId, 'refundRequestId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
        if ($context->organizationId === null || $context->actorId === null) {
            throw new InvalidArgumentException('Refund requires tenant and actor context.');
        }
        $this->authorizationReference = self::safeToken($authorizationReference, 'authorizationReference', 255);
        $this->requestFingerprint = hash('sha256', json_encode([
            $this->refundId,
            $this->refundRequestId,
            $this->attemptId,
            $this->branchId,
            $this->payloadFingerprint,
            $context->organizationId,
            $context->actorId,
            $context->correlationId,
            $context->idempotencyKeyHash,
            $context->expectedVersion,
            $this->authorizationReference,
        ], JSON_THROW_ON_ERROR));
    }
}
