<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\ValueObjects\FloatingScheduleTimeOverridePayload;

final readonly class OverrideFloatingMenuScheduleTimeCommand extends MutationCommand
{
    public string $scheduleId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $scheduleId, public FloatingScheduleTimeOverridePayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->scheduleId = self::uuid($scheduleId, 'scheduleId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
