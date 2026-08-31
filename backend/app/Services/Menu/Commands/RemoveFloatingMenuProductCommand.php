<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class RemoveFloatingMenuProductCommand extends MutationCommand
{
    public string $sectionId;

    public string $menuProductId;

    public function __construct(MutationContext $context, string $sectionId, string $menuProductId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->sectionId = self::uuid($sectionId, 'sectionId');
        $this->menuProductId = self::uuid($menuProductId, 'menuProductId');
    }
}
