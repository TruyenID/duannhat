<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class PromoteApprovedMenusCommand extends MutationCommand
{
    public function __construct(MutationContext $context, public int $approvalRevision)
    {
        parent::__construct($context);
        if ($approvalRevision < 1) {
            throw new \InvalidArgumentException('approvalRevision must be positive.');
        }
    }
}
