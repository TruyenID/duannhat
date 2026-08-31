<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\ValueObjects\MenuItemPayload;

final readonly class PlaceMenuProductCommand extends MutationCommand
{
    public string $menuId;

    public string $sectionId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $menuId, string $sectionId, public MenuItemPayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->sectionId = self::uuid($sectionId, 'sectionId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
