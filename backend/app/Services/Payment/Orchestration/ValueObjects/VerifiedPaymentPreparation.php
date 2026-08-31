<?php

namespace App\Services\Payment\Orchestration\ValueObjects;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\VerificationAuthority;
use App\Services\DomainMutation\VerifiedObjectRegistry;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Orchestration\Commands\PreparePaymentCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentAuthorityVerificationPort;

final readonly class VerifiedPaymentPreparation implements \JsonSerializable
{
    public string $orderId;

    public string $attemptId;

    public string $branchId;

    public string $connectionId;

    public string $optionId;

    /**
     * The resolved `payment_gateway_connection_options.id` (the org-scoped
     * enabled join row) that `optionId` (a catalog `payment_gateway_options.id`)
     * maps to on this connection. This — not `optionId` — is what
     * `payment_attempts.connection_option_id` foreign-keys to.
     */
    public string $connectionOptionId;

    public string $provider;

    public string $organizationId;

    public string $actorId;

    public string $correlationId;

    public string $idempotencyKeyHash;

    public string $requestFingerprint;

    public ?string $tenderFingerprint;

    public ?string $splitPlanFingerprint;

    private function __construct(PreparePaymentCommand $command, string $provider, string $connectionOptionId, public ResolvedPaymentMethodEvidence $paymentMethod, public Money $money)
    {
        $paymentMethod->assertTrusted();
        $this->orderId = $command->orderId;
        $this->attemptId = $command->attemptId;
        $this->branchId = $command->branchId;
        $this->connectionId = $command->connectionId;
        $this->optionId = $command->optionId;
        $this->connectionOptionId = MutationCommand::uuid($connectionOptionId, 'connectionOptionId');
        $this->provider = MutationCommand::safeToken($provider, 'provider', 64);
        $this->policyRevision = $command->policyRevision;
        $this->expectedOrderVersion = $command->context->expectedVersion ?? throw new \LogicException('Expected order version is missing.');
        $this->organizationId = $command->context->organizationId ?? throw new \LogicException('Tenant is missing.');
        $this->actorId = $command->context->actorId ?? throw new \LogicException('Actor is missing.');
        $this->correlationId = $command->context->correlationId;
        $this->idempotencyKeyHash = $command->context->idempotencyKeyHash;
        $this->requestFingerprint = $command->requestFingerprint;
        $this->tenderFingerprint = $command->tender?->fingerprint();
        $this->splitPlanFingerprint = $command->splitPlan?->fingerprint();
        if (! hash_equals($paymentMethod->requestFingerprint, $command->requestFingerprint)) {
            throw new \InvalidArgumentException('Resolved method is not bound to this preparation request.');
        }
        if ($money->minorAmount !== $command->amountMinor || $money->currency !== $command->currencyCode) {
            throw new \InvalidArgumentException('Verified money must match the preparation request.');
        }
    }

    public int $policyRevision;

    public int $expectedOrderVersion;

    public static function issue(PaymentAuthorityVerificationPort $verifier, VerificationAuthority $authority, PreparePaymentCommand $command, string $provider, string $connectionOptionId, ResolvedPaymentMethodEvidence $paymentMethod): self
    {
        $value = new self($command, $provider, $connectionOptionId, $paymentMethod, new Money($command->amountMinor, $command->currencyCode));
        VerifiedObjectRegistry::seal($value, $verifier, $authority, 'payment.verified_preparation', PaymentAuthorityVerificationPort::class);

        return $value;
    }

    public function assertTrusted(): void
    {
        VerifiedObjectRegistry::assertSealed($this, 'payment.verified_preparation');
    }

    public function jsonSerialize(): array
    {
        return ['attempt_id' => $this->attemptId, 'order_id' => $this->orderId, 'branch_id' => $this->branchId, 'connection_id' => $this->connectionId, 'option_id' => $this->optionId, 'connection_option_id' => $this->connectionOptionId, 'provider' => $this->provider, 'policy_revision' => $this->policyRevision, 'expected_order_version' => $this->expectedOrderVersion, 'organization_id' => $this->organizationId, 'actor_id' => $this->actorId, 'correlation_id' => $this->correlationId, 'idempotency_key_hash' => $this->idempotencyKeyHash, 'request_fingerprint' => $this->requestFingerprint, 'tender_fingerprint' => $this->tenderFingerprint, 'split_plan_fingerprint' => $this->splitPlanFingerprint, 'payment_method' => $this->paymentMethod, 'money' => $this->money];
    }
}
