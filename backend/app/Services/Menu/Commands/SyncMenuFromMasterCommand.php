<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class SyncMenuFromMasterCommand extends MutationCommand
{
    public string $menuId;

    public string $masterMenuId;

    public function __construct(MutationContext $context, string $menuId, string $masterMenuId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->masterMenuId = self::uuid($masterMenuId, 'masterMenuId');
        if ($this->menuId === $this->masterMenuId) {
            throw new \InvalidArgumentException('A menu cannot synchronize from itself.');
        }
    }
}
