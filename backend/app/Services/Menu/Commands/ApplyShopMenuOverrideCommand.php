<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\ValueObjects\ShopMenuOverridePayload;

final readonly class ApplyShopMenuOverrideCommand extends MutationCommand
{
    public string $menuId;

    public string $branchId;

    public string $overrideFingerprint;

    public function __construct(MutationContext $context, string $menuId, string $branchId, public ShopMenuOverridePayload $payload, string $overrideFingerprint)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->branchId = self::uuid($branchId, 'branchId');
        if ($this->branchId !== $payload->branchId) {
            throw new \InvalidArgumentException('Outer branchId must match the override payload branchId.');
        }
        $this->overrideFingerprint = self::verifiedFingerprint($overrideFingerprint, 'overrideFingerprint', $payload);
    }
}
