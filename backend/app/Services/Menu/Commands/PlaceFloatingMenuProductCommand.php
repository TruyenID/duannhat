<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\ValueObjects\MenuItemPayload;

final readonly class PlaceFloatingMenuProductCommand extends MutationCommand
{
    public string $sectionId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $sectionId, public MenuItemPayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->sectionId = self::uuid($sectionId, 'sectionId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
