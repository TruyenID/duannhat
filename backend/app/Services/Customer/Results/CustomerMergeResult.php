<?php

namespace App\Services\Customer\Results;

use App\Services\DomainMutation\MutationCommand;

final readonly class CustomerMergeResult
{
    public string $sourceCustomerId;

    public string $targetCustomerId;

    public function __construct(string $sourceCustomerId, string $targetCustomerId, public bool $merged)
    {
        $this->sourceCustomerId = MutationCommand::uuid($sourceCustomerId, 'sourceCustomerId');
        $this->targetCustomerId = MutationCommand::uuid($targetCustomerId, 'targetCustomerId');
    }
}
