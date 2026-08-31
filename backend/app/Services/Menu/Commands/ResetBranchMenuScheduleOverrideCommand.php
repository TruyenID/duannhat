<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class ResetBranchMenuScheduleOverrideCommand extends MutationCommand
{
    public string $menuId;

    public string $branchId;

    public string $masterScheduleId;

    public function __construct(MutationContext $context, string $menuId, string $branchId, string $masterScheduleId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->branchId = self::uuid($branchId, 'branchId');
        $this->masterScheduleId = self::uuid($masterScheduleId, 'masterScheduleId');
    }
}
