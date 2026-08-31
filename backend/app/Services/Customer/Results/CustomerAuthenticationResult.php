<?php

namespace App\Services\Customer\Results;

use App\Services\Customer\ValueObjects\CustomerAccessTokenSecret;
use App\Services\DomainMutation\MutationCommand;

final readonly class CustomerAuthenticationResult
{
    public string $customerId;

    public string $tokenId;

    public function __construct(string $customerId, string $tokenId, public CustomerAccessTokenSecret $token)
    {
        $this->customerId = MutationCommand::uuid($customerId, 'customerId');
        $this->tokenId = MutationCommand::safeToken($tokenId, 'tokenId', 255);
    }
}
