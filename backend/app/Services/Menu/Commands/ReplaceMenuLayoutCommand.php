<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\RevisionPayloadMode;
use App\Services\Menu\Enums\MenuLayoutMutation;
use App\Services\Menu\ValueObjects\MenuLayoutPayload;

final readonly class ReplaceMenuLayoutCommand extends MutationCommand
{
    public string $menuId;

    public string $layoutFingerprint;

    public function __construct(MutationContext $context, string $menuId, public MenuLayoutPayload $payload, string $layoutFingerprint, public MenuLayoutMutation $operation, public RevisionPayloadMode $mode = RevisionPayloadMode::FullReplacement)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->layoutFingerprint = self::verifiedFingerprint($layoutFingerprint, 'layoutFingerprint', $payload);
    }

    public function assertOperation(MenuLayoutMutation $expected): void
    {
        if ($this->operation !== $expected) {
            throw new \LogicException('Menu layout route does not match canonical operation.');
        }
    }
}
