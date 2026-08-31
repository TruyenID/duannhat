<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class ReorderFloatingMenuSchedulesCommand extends MutationCommand
{
    public string $sectionId;

    /** @var list<string> */
    public array $scheduleIds;

    public function __construct(MutationContext $context, string $sectionId, array $scheduleIds)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->sectionId = self::uuid($sectionId, 'sectionId');
        $this->scheduleIds = self::uniqueOrdered(array_map(static fn (string $id): string => self::uuid($id, 'scheduleId'), $scheduleIds), static fn (string $id): string => $id, 'scheduleIds');
        if ($this->scheduleIds === []) {
            throw new \InvalidArgumentException('scheduleIds cannot be empty.');
        }
    }
}
