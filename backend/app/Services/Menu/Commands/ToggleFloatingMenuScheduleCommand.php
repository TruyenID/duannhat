<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class ToggleFloatingMenuScheduleCommand extends MutationCommand
{
    public string $scheduleId;

    public function __construct(MutationContext $context, string $scheduleId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->scheduleId = self::uuid($scheduleId, 'scheduleId');
    }
}
