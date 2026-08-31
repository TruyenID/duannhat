<?php

namespace App\Services\Customer\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class IssueCustomerAccessTokenCommand extends MutationCommand
{
    public string $customerId;

    public string $tokenName;

    public string $sessionReference;

    public function __construct(MutationContext $context, string $customerId, string $tokenName, string $sessionReference)
    {
        parent::__construct($context);
        $this->customerId = self::uuid($customerId, 'customerId');
        if ($context->organizationId !== null) {
            throw new \InvalidArgumentException('Customer access tokens belong to global accounts.');
        }
        if ($context->actorId !== $this->customerId) {
            throw new \InvalidArgumentException('Token issuance requires the authenticated customer as actor.');
        }
        $this->tokenName = self::safeToken($tokenName, 'tokenName', 100);
        $this->sessionReference = self::safeToken($sessionReference, 'sessionReference', 255);
    }
}
