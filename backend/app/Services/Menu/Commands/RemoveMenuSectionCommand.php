<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class RemoveMenuSectionCommand extends MutationCommand
{
    public string $menuId;

    public string $sectionId;

    public function __construct(MutationContext $context, string $menuId, string $sectionId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->sectionId = self::uuid($sectionId, 'sectionId');
    }
}
