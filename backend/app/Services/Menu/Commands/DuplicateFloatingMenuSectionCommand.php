<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class DuplicateFloatingMenuSectionCommand extends MutationCommand
{
    public string $sourceSectionId;

    public string $newSectionId;

    public function __construct(MutationContext $context, string $sourceSectionId, string $newSectionId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->sourceSectionId = self::uuid($sourceSectionId, 'sourceSectionId');
        $this->newSectionId = self::uuid($newSectionId, 'newSectionId');
        if ($this->sourceSectionId === $this->newSectionId) {
            throw new \InvalidArgumentException('Floating duplicate requires a new identity.');
        }
    }
}
