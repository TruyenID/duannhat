<?php

namespace App\Services\Customer\Commands;

use App\Services\Customer\ValueObjects\TenantCustomerProfilePatch;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class ReviseTenantCustomerProfileCommand extends MutationCommand
{
    public string $customerId;

    public string $profileFingerprint;

    public function __construct(MutationContext $context, string $customerId, public TenantCustomerProfilePatch $payload, string $profileFingerprint)
    {
        parent::__construct($context);
        if ($context->organizationId === null || $context->actorId === null) {
            throw new \InvalidArgumentException('Tenant profile requires tenant scope and authenticated actor.');
        }
        $this->customerId = self::uuid($customerId, 'customerId');
        $this->profileFingerprint = self::verifiedFingerprint($profileFingerprint, 'profileFingerprint', $payload);
    }
}
