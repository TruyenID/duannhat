<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class BackfillMenuSkuPlacementsCommand extends MutationCommand
{
    public function __construct(MutationContext $context, public int $catalogRevision)
    {
        parent::__construct($context);
        if ($catalogRevision < 1) {
            throw new \InvalidArgumentException('catalogRevision must be positive.');
        }
    }
}
