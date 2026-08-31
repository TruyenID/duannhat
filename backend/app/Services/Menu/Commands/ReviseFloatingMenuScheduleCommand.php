<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\ValueObjects\MenuSchedulePayload;

final readonly class ReviseFloatingMenuScheduleCommand extends MutationCommand
{
    public string $sectionId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $sectionId, public MenuSchedulePayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->sectionId = self::uuid($sectionId, 'sectionId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
