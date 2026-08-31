<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\ValueObjects\MenuSectionPayload;

final readonly class CreateMenuSectionCommand extends MutationCommand
{
    public string $menuId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $menuId, public MenuSectionPayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
