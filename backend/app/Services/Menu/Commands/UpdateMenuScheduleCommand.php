<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\ValueObjects\MenuSchedulePayload;

final readonly class UpdateMenuScheduleCommand extends MutationCommand
{
    public string $menuId;

    public string $scheduleId;

    public string $scheduleFingerprint;

    public function __construct(MutationContext $context, string $menuId, string $scheduleId, public MenuSchedulePayload $payload, string $scheduleFingerprint)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->scheduleId = self::uuid($scheduleId, 'scheduleId');
        if ($this->scheduleId !== $payload->scheduleId) {
            throw new \InvalidArgumentException('Outer scheduleId must match payload.');
        }$this->scheduleFingerprint = self::verifiedFingerprint($scheduleFingerprint, 'scheduleFingerprint', $payload);
    }
}
