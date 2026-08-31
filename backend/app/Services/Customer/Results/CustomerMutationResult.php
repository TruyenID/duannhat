<?php

namespace App\Services\Customer\Results;

use App\Services\DomainMutation\MutationCommand;

/** Customer has no persisted aggregate version yet; do not manufacture one. */
final readonly class CustomerMutationResult
{
    public string $customerId;

    public function __construct(string $customerId, public bool $changed)
    {
        $this->customerId = MutationCommand::uuid($customerId, 'customerId');
    }
}
