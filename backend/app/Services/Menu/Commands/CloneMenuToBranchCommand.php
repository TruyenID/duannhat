<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class CloneMenuToBranchCommand extends MutationCommand
{
    public string $sourceMenuId;

    public string $newMenuId;

    public string $branchId;

    public function __construct(MutationContext $context, string $sourceMenuId, string $newMenuId, string $branchId)
    {
        parent::__construct($context);
        $this->sourceMenuId = self::uuid($sourceMenuId, 'sourceMenuId');
        $this->newMenuId = self::uuid($newMenuId, 'newMenuId');
        $this->branchId = self::uuid($branchId, 'branchId');
    }
}
