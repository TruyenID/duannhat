<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class CloneFloatingMenuSectionToBranchCommand extends MutationCommand
{
    public string $masterSectionId;

    public string $newSectionId;

    public string $branchId;

    public function __construct(MutationContext $context, string $masterSectionId, string $newSectionId, string $branchId)
    {
        parent::__construct($context);
        $this->masterSectionId = self::uuid($masterSectionId, 'masterSectionId');
        $this->newSectionId = self::uuid($newSectionId, 'newSectionId');
        $this->branchId = self::uuid($branchId, 'branchId');
    }
}
