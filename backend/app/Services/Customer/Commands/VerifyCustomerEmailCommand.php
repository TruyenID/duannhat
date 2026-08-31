<?php

namespace App\Services\Customer\Commands;

use App\Services\Customer\ValueObjects\CustomerVerificationPayload;
use App\Services\Customer\ValueObjects\CustomerVerificationSource;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class VerifyCustomerEmailCommand extends MutationCommand
{
    public string $customerId;

    public string $evidenceFingerprint;

    public string $authorityReference;

    public function __construct(MutationContext $context, string $customerId, public CustomerVerificationPayload $evidence, string $evidenceFingerprint, string $authorityReference)
    {
        parent::__construct($context);
        if ($context->organizationId !== null) {
            throw new \InvalidArgumentException('Email verification applies only to global customer accounts.');
        }
        $this->customerId = self::uuid($customerId, 'customerId');
        $this->authorityReference = self::safeToken($authorityReference, 'authorityReference', 2048);
        if ($evidence->source === CustomerVerificationSource::TrustedAdmin && $context->actorId === null) {
            throw new \InvalidArgumentException('Trusted-admin verification requires an authenticated actor.');
        } $this->evidenceFingerprint = self::verifiedFingerprint($evidenceFingerprint, 'evidenceFingerprint', $evidence);
    }
}
