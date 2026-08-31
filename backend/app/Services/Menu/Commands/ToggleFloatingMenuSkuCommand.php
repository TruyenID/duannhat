<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class ToggleFloatingMenuSkuCommand extends MutationCommand
{
    public string $sectionId;

    public string $menuProductSkuId;

    public function __construct(MutationContext $context, string $sectionId, string $menuProductSkuId, public bool $active)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->sectionId = self::uuid($sectionId, 'sectionId');
        $this->menuProductSkuId = self::uuid($menuProductSkuId, 'menuProductSkuId');
    }
}
