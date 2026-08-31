<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class SyncFloatingMenuSectionFromMasterCommand extends MutationCommand
{
    public string $sectionId;

    public string $masterSectionId;

    public function __construct(MutationContext $context, string $sectionId, string $masterSectionId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->sectionId = self::uuid($sectionId, 'sectionId');
        $this->masterSectionId = self::uuid($masterSectionId, 'masterSectionId');
        if ($this->sectionId === $this->masterSectionId) {
            throw new \InvalidArgumentException('A branch floating section cannot synchronize from itself.');
        }
    }
}
