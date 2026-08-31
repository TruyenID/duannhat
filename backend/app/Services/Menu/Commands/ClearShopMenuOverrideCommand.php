<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class ClearShopMenuOverrideCommand extends MutationCommand
{
    public string $menuId;

    public string $branchId;

    public string $menuItemId;

    public function __construct(MutationContext $context, string $menuId, string $branchId, string $menuItemId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->branchId = self::uuid($branchId, 'branchId');
        $this->menuItemId = self::uuid($menuItemId, 'menuItemId');
    }
}
