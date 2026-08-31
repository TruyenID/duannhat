<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class ResetMenuSkuPriceCommand extends MutationCommand
{
    public string $menuId;

    public string $menuProductSkuId;

    public function __construct(MutationContext $context, string $menuId, string $menuProductSkuId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->menuProductSkuId = self::uuid($menuProductSkuId, 'menuProductSkuId');
    }
}
