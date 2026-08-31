<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\ValueObjects\MenuSchedulePayload;

final readonly class CreateMenuScheduleCommand extends MutationCommand
{
    public string $menuId;

    public string $scheduleFingerprint;

    public function __construct(MutationContext $context, string $menuId, public MenuSchedulePayload $payload, string $scheduleFingerprint)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->scheduleFingerprint = self::verifiedFingerprint($scheduleFingerprint, 'scheduleFingerprint', $payload);
    }
}
