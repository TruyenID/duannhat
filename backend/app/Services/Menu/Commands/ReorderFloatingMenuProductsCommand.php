<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\ValueObjects\FloatingProductOrderPayload;

final readonly class ReorderFloatingMenuProductsCommand extends MutationCommand
{
    public string $sectionId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $sectionId, public FloatingProductOrderPayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->sectionId = self::uuid($sectionId, 'sectionId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
