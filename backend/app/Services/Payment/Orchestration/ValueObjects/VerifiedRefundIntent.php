<?php

namespace App\Services\Payment\Orchestration\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\VerificationAuthority;
use App\Services\DomainMutation\VerifiedObjectRegistry;
use App\Services\Payment\Orchestration\Commands\RequestPaymentRefundCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentAuthorityVerificationPort;
use App\Services\Payment\Orchestration\Enums\RefundReason;

final readonly class VerifiedRefundIntent implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $orderId;

    public string $orderPaymentId;

    public string $attemptId;

    public int $amountMinor;

    public RefundReason $reason;

    public string $currencyCode;

    public string $providerPaymentReference;

    public string $providerConnectionIdentity;

    public string $refundId;

    public string $refundRequestId;

    public string $branchId;

    public string $organizationId;

    public string $actorId;

    public string $correlationId;

    public string $idempotencyKeyHash;

    public int $expectedVersion;

    public string $authorizationReference;

    public string $payloadFingerprint;

    public string $requestFingerprint;

    private function __construct(RequestPaymentRefundCommand $command, string $providerPaymentReference, string $providerConnectionIdentity, public RefundVerificationEvidence $verification)
    {
        $payload = $command->payload;
        $this->orderId = $payload->orderId;
        $this->orderPaymentId = $payload->orderPaymentId;
        $this->attemptId = $payload->attemptId;
        $this->amountMinor = $payload->amountMinor;
        $this->currencyCode = $payload->currencyCode;
        $this->reason = $payload->reason;
        $this->refundId = $command->refundId;
        $this->refundRequestId = $command->refundRequestId;
        $this->branchId = $command->branchId;
        $this->organizationId = $command->context->organizationId ?? throw new \LogicException('Verified refund tenant is missing.');
        $this->actorId = $command->context->actorId ?? throw new \LogicException('Verified refund actor is missing.');
        $this->correlationId = $command->context->correlationId;
        $this->idempotencyKeyHash = $command->context->idempotencyKeyHash;
        $this->expectedVersion = $command->context->expectedVersion ?? throw new \LogicException('Verified refund version is missing.');
        $this->authorizationReference = $command->authorizationReference;
        $this->payloadFingerprint = $command->payloadFingerprint;
        $this->requestFingerprint = $command->requestFingerprint;
        $this->providerPaymentReference = MutationCommand::safeToken($providerPaymentReference, 'providerPaymentReference', 255);
        $this->providerConnectionIdentity = MutationCommand::safeToken($providerConnectionIdentity, 'providerConnectionIdentity', 255);
    }

    public static function issue(PaymentAuthorityVerificationPort $verifier, VerificationAuthority $authority, RequestPaymentRefundCommand $command, string $providerPaymentReference, string $providerConnectionIdentity, RefundVerificationEvidence $verification): self
    {
        $value = new self($command, $providerPaymentReference, $providerConnectionIdentity, $verification);
        VerifiedObjectRegistry::seal($value, $verifier, $authority, 'payment.verified_refund', PaymentAuthorityVerificationPort::class);

        return $value;
    }

    public function assertTrusted(): void
    {
        VerifiedObjectRegistry::assertSealed($this, 'payment.verified_refund');
    }

    public function jsonSerialize(): array
    {
        return ['order_id' => $this->orderId, 'order_payment_id' => $this->orderPaymentId, 'attempt_id' => $this->attemptId, 'amount_minor' => $this->amountMinor, 'currency_code' => $this->currencyCode, 'reason' => $this->reason->value, 'refund_id' => $this->refundId, 'refund_request_id' => $this->refundRequestId, 'branch_id' => $this->branchId, 'organization_id' => $this->organizationId, 'actor_id' => $this->actorId, 'correlation_id' => $this->correlationId, 'idempotency_key_hash' => $this->idempotencyKeyHash, 'expected_version' => $this->expectedVersion, 'authorization_reference' => $this->authorizationReference, 'payload_fingerprint' => $this->payloadFingerprint, 'provider_payment_reference' => $this->providerPaymentReference, 'provider_connection_identity' => $this->providerConnectionIdentity, 'verification' => $this->verification, 'request_fingerprint' => $this->requestFingerprint];
    }
}
