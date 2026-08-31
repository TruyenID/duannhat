<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class ToggleMenuProductCommand extends MutationCommand
{
    public string $menuId;

    public string $menuProductId;

    public function __construct(MutationContext $context, string $menuId, string $menuProductId, public bool $active)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->menuProductId = self::uuid($menuProductId, 'menuProductId');
    }
}
