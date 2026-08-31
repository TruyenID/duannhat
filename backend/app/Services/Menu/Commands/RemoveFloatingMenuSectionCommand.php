<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class RemoveFloatingMenuSectionCommand extends MutationCommand
{
    public string $sectionId;

    public function __construct(MutationContext $context, string $sectionId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->sectionId = self::uuid($sectionId, 'sectionId');
    }
}
