<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class ToggleMenuSkuCommand extends MutationCommand
{
    public string $menuId;

    public string $menuProductSkuId;

    public function __construct(MutationContext $context, string $menuId, string $menuProductSkuId, public bool $active)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->menuProductSkuId = self::uuid($menuProductSkuId, 'menuProductSkuId');
    }
}
