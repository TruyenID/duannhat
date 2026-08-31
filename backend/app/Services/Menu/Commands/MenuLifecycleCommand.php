<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\Enums\MenuLifecycleAction;

final readonly class MenuLifecycleCommand extends MutationCommand
{
    public string $menuId;

    public ?string $reason;

    public function __construct(MutationContext $context, string $menuId, public MenuLifecycleAction $action, ?string $reason = null)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->reason = $reason === null ? null : self::safeToken($reason, 'reason', 500);
    }

    public function assertAction(MenuLifecycleAction $expected): void
    {
        if ($this->action !== $expected) {
            throw new \LogicException('Menu lifecycle route does not match authorized action.');
        }
    }
}
