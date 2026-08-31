<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\ValueObjects\MenuSkuOverridePayload;

final readonly class OverrideFloatingMenuSkuPriceCommand extends MutationCommand
{
    public string $sectionId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $sectionId, public MenuSkuOverridePayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        if (! $payload->priceOverridden) {
            throw new \InvalidArgumentException('Floating price override must set priceOverridden.');
        }$this->sectionId = self::uuid($sectionId, 'sectionId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
