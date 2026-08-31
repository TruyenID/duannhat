<?php

namespace App\Services\Customer\Results;

use App\Services\DomainMutation\MutationCommand;

final readonly class AuthenticatedCustomerEvidence
{
    public string $customerId;

    public string $authenticationEventId;

    public function __construct(string $customerId, string $authenticationEventId)
    {
        $this->customerId = MutationCommand::uuid($customerId, 'customerId');
        $this->authenticationEventId = MutationCommand::safeToken($authenticationEventId, 'authenticationEventId', 255);
    }
}
