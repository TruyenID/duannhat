<?php

namespace App\Services\Customer\Commands;

use App\Services\Customer\ValueObjects\GlobalCustomerProfilePatch;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class ReviseGlobalCustomerProfileCommand extends MutationCommand
{
    public string $customerId;

    public string $profileFingerprint;

    public string $sessionReference;

    public function __construct(MutationContext $context, string $customerId, public GlobalCustomerProfilePatch $payload, string $profileFingerprint, string $sessionReference)
    {
        parent::__construct($context);
        if ($context->organizationId !== null) {
            throw new \InvalidArgumentException('Global profile cannot carry tenant scope.');
        }
        $this->customerId = self::uuid($customerId, 'customerId');
        if ($context->actorId !== $this->customerId) {
            throw new \InvalidArgumentException('Global profile revision requires the authenticated customer as actor.');
        }
        $this->profileFingerprint = self::verifiedFingerprint($profileFingerprint, 'profileFingerprint', $payload);
        $this->sessionReference = self::safeToken($sessionReference, 'sessionReference', 255);
    }
}
