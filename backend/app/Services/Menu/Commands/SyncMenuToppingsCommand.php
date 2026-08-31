<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\ValueObjects\MenuToppingSyncPayload;

final readonly class SyncMenuToppingsCommand extends MutationCommand
{
    public string $menuId;

    public string $menuProductId;

    public string $payloadFingerprint;

    public function __construct(MutationContext $context, string $menuId, string $menuProductId, public MenuToppingSyncPayload $payload, string $payloadFingerprint)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->menuProductId = self::uuid($menuProductId, 'menuProductId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
