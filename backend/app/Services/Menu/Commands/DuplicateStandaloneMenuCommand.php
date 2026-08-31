<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class DuplicateStandaloneMenuCommand extends MutationCommand
{
    public string $sourceMenuId;

    public string $newMenuId;

    public function __construct(MutationContext $context, string $sourceMenuId, string $newMenuId)
    {
        parent::__construct($context);
        $this->sourceMenuId = self::uuid($sourceMenuId, 'sourceMenuId');
        $this->newMenuId = self::uuid($newMenuId, 'newMenuId');
    }
}
