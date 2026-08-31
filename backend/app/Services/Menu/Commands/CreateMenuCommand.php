<?php

namespace App\Services\Menu\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\ValueObjects\MenuDefinitionPayload;

final readonly class CreateMenuCommand extends MutationCommand
{
    public string $menuId;

    public string $brandId;

    public ?string $branchId;

    public string $definitionFingerprint;

    public function __construct(MutationContext $context, string $menuId, string $brandId, ?string $branchId, public MenuDefinitionPayload $payload, string $definitionFingerprint)
    {
        parent::__construct($context);
        $this->menuId = self::uuid($menuId, 'menuId');
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->branchId = self::nullableUuid($branchId, 'branchId');
        $this->definitionFingerprint = self::verifiedFingerprint($definitionFingerprint, 'definitionFingerprint', $payload);
    }
}
