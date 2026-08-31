<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class DeleteMenuScheduleCommand extends MutationCommand
{
    public string $menuId;

    public string $scheduleId;

    public function __construct(MutationContext $context, string $menuId, string $scheduleId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->scheduleId = self::uuid($scheduleId, 'scheduleId');
    }
}
