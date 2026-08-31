<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\ValueObjects\FloatingMenuSectionPayload;

final readonly class CreateFloatingMenuSectionCommand extends MutationCommand
{
    public string $sectionId;

    public ?string $branchId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $sectionId, ?string $branchId, public FloatingMenuSectionPayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        $this->sectionId = self::uuid($sectionId, 'sectionId');
        $this->branchId = self::nullableUuid($branchId, 'branchId');
        if ($this->branchId !== $payload->branchId) {
            throw new \InvalidArgumentException('Outer branchId must match floating section ownership.');
        }
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
