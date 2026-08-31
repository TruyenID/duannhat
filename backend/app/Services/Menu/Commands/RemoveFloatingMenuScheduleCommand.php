<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class RemoveFloatingMenuScheduleCommand extends MutationCommand
{
    public string $sectionId;

    public string $scheduleId;

    public function __construct(MutationContext $context, string $sectionId, string $scheduleId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->sectionId = self::uuid($sectionId, 'sectionId');
        $this->scheduleId = self::uuid($scheduleId, 'scheduleId');
    }
}
