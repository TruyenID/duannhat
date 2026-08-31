<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\ValueObjects\MenuSchedulePayload;

final readonly class UpsertBranchMenuScheduleOverrideCommand extends MutationCommand
{
    public string $menuId;

    public string $branchId;

    public string $masterScheduleId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $menuId, string $branchId, string $masterScheduleId, public MenuSchedulePayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->branchId = self::uuid($branchId, 'branchId');
        $this->masterScheduleId = self::uuid($masterScheduleId, 'masterScheduleId');
        if ($payload->masterScheduleId !== $this->masterScheduleId) {
            throw new \InvalidArgumentException('Override must reference the master schedule.');
        } $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
