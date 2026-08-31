<?php

namespace App\Services\Customer\Results;

use App\Services\DomainMutation\MutationCommand;

final readonly class CustomerResolvedResult
{
    public string $customerId;

    public function __construct(string $customerId, public bool $created)
    {
        $this->customerId = MutationCommand::uuid($customerId, 'customerId');
    }
}
